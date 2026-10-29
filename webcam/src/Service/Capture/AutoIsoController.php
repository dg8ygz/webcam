<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use RuntimeException;
use Webcam\Domain\Camera;

/** One bounded feedback decision/command per camera, independent of capture slots. */
final class AutoIsoController
{
  private const TTL = 900;

  public function __construct(private readonly string $directory) {}

  public function observe(Camera $camera, array $exposure, ?float $luminance, int $capturedAt, ?int $now = null): ?array
  {
    $config = AutoIsoPolicy::normalize($camera->capture()['auto_iso'] ?? null);
    if ($camera->mode()->value === 'pull-snapshot'
      || ($config['mode'] === 'off' && !is_file($this->path($camera->slug())))) return null;
    $now ??= time();
    return $this->locked($camera->slug(), function (array &$state) use ($camera, $config, $exposure, $luminance, $capturedAt, $now): ?array {
      // Late spool uploads, pre-command pictures and repeated/out-of-order slots
      // must never steer the current camera, nor overwrite a newer decision.
      if ($capturedAt <= 0 || $capturedAt > $now || $now - $capturedAt >= self::TTL
        || $capturedAt <= ($state['last_capture'] ?? 0)
        || $capturedAt <= ($state['last_dispatch'] ?? 0)) return null;
      if (($config['mode'] === 'off' || ($config['night_ev_max'] ?? 0) === 0)
        && !empty($state['ev_engaged'])) {
        $bias = $exposure['bias'] ?? null;
        if ((is_int($bias) || is_float($bias)) && is_finite((float) $bias) && abs($bias) <= 0.05
          && preg_match('/EOS/i', (string) ($exposure['model'] ?? ''))) $state['ev_engaged'] = false;
      }
      if ($config['mode'] === 'off') return null;
      $state['last_capture'] = $capturedAt;
      unset($state['pending']);
      $state['decision'] = AutoIsoPolicy::decide($config, $exposure, $luminance)
        + ['captured_at' => $capturedAt, 'evaluated_at' => $now];
      $ev = NightExposurePolicy::decide($config, $exposure, $luminance, $camera->location(), $now);
      $state['decision'] += $ev;
      // Fresh EXIF, not successful delivery, acknowledges the actual setting.
      if (isset($ev['bias'])) $state['ev_engaged'] = abs($ev['bias']) > 0.05;
      if ($ev['target_ev'] !== null) $state['decision']['target_iso'] = null;
      $state['state'] = $config['mode'] === 'observe' ? 'observed'
        : (isset($state['decision']['iso']) ? 'hold' : 'skipped');
      if ($config['mode'] === 'auto' && ($state['decision']['target_iso'] !== null || $ev['target_ev'] !== null)) {
        if (!$this->recentlyPolled($state, $now)) {
          $state['state'] = 'helper_unavailable';
        } else {
          $state['pending'] = ['iso' => $state['decision']['target_iso'] ?? 0, 'ev' => $ev['target_ev'], 'created_at' => $now,
            'captured_at' => $capturedAt, 'config' => $config];
          $state['state'] = $this->coolingDown($camera, $state, $now) ? 'cooldown' : 'queued';
        }
      }
      return $state['decision'] + ['state' => $state['state']];
    });
  }

  /** Call only after authentication. Dispatch is NOT an execution acknowledgement. */
  public function poll(Camera $camera, ?int $now = null): ?string
  {
    $config = AutoIsoPolicy::normalize($camera->capture()['auto_iso'] ?? null);
    $now ??= time();
    // Explicit per-camera Auto mode authorizes the existing ISOEXP contract.
    // No state writes for cameras with the default off setting.
    if ($config['mode'] === 'off' && !is_file($this->path($camera->slug()))) return null;
    return $this->locked($camera->slug(), function (array &$state) use ($camera, $config, $now): ?string {
      $state['polled_at'] = $now;
      // A morning reset must not depend on another successful image upload.
      // Retry at most once per ten minutes until fresh EXIF confirms zero; an EV-only
      // command leaves ISO unchanged. Pull sources never control hardware.
      if (!empty($state['ev_engaged']) && $camera->mode()->value !== 'pull-snapshot'
        && (($config['night_ev_max'] ?? 0) === 0 || $config['mode'] === 'off'
          || !NightExposurePolicy::isNight($camera->location(), $now))) {
        unset($state['pending']);
        if ($config['mode'] === 'observe' || $now - ($state['last_ev_reset'] ?? 0) < 600) return null;
        $state['last_ev_reset'] = $now;
        $state['state'] = 'day_reset_dispatched';
        if (isset($state['decision']) && is_array($state['decision'])) {
          $state['decision']['target_iso'] = null;
          $state['decision']['target_ev'] = 0;
          $state['decision']['ev_reason'] = 'day_zero';
        }
        return "command\n{$now}\nwebcam_isoexp 0 0\n";
      }
      $pending = $state['pending'] ?? null;
      if ($pending === null) return null;
      if ($config['mode'] !== 'auto' || $camera->mode()->value === 'pull-snapshot'
        || $pending['config'] !== $config) {
        unset($state['pending']);
        $state['state'] = 'cancelled';
        return null;
      }
      if ($now < $pending['created_at'] || $now - $pending['captured_at'] >= self::TTL) {
        unset($state['pending']);
        $state['state'] = 'expired';
        return null;
      }
      if ($this->coolingDown($camera, $state, $now)) {
        $state['state'] = 'cooldown';
        return null;
      }
      // Recheck the solar window at dispatch: queued night commands may not
      // cross dawn, even if the originating capture was still at night.
      if (($pending['ev'] ?? 0) > 0 && !NightExposurePolicy::isNight($camera->location(), $now)) {
        unset($state['pending']);
        $state['state'] = 'night_command_cancelled';
        return null;
      }
      unset($state['pending']);
      $state['last_dispatch'] = $now;
      $state['state'] = 'dispatched';
      if (isset($pending['ev']) && $pending['ev'] > 0) $state['ev_engaged'] = true;
      $evArgument = isset($pending['ev']) ? ' ' . $pending['ev'] : '';
      return "command\n{$now}\nwebcam_isoexp {$pending['iso']}{$evArgument}\n";
    });
  }

  public function status(Camera $camera, ?int $now = null): array
  {
    $config = AutoIsoPolicy::normalize($camera->capture()['auto_iso'] ?? null);
    if (!is_file($this->path($camera->slug()))) return ['mode' => $config['mode'], 'poll_recent' => false, 'state' => 'idle'];
    return $this->locked($camera->slug(), function (array &$state) use ($config, $now): array {
      $now ??= time();
      $visible = $state;
      unset($visible['pending']);
      if (isset($state['pending']) && $now - $state['pending']['captured_at'] >= self::TTL) $visible['state'] = 'expired';
      return ['mode' => $config['mode'], 'poll_recent' => $this->recentlyPolled($state, $now)] + $visible;
    });
  }

  private function coolingDown(Camera $camera, array $state, int $now): bool
  {
    return $now - ($state['last_dispatch'] ?? 0)
      < max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
  }

  private function recentlyPolled(array $state, int $now): bool
  {
    $stamp = $state['polled_at'] ?? 0;
    return $stamp > 0 && $stamp <= $now && $now - $stamp < self::TTL;
  }

  private function path(string $slug): string
  {
    if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new RuntimeException('Ungueltige Kamera');
    return $this->directory . '/' . $slug . '.json';
  }

  /** Stable inode and nonblocking lock serialize upload/poll; damaged state fails closed. */
  private function locked(string $slug, callable $operation): mixed
  {
    $path = $this->path($slug);
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) throw new RuntimeException('ISO-Verzeichnis nicht verfuegbar');
    $handle = @fopen($path, 'c+');
    if ($handle === false) throw new RuntimeException('ISO-Status nicht verfuegbar');
    try {
      if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('ISO-Status belegt');
      $raw = stream_get_contents($handle, 4097);
      $state = $raw === '' ? [] : json_decode((string) $raw, true);
      if (!is_array($state) || strlen((string) $raw) > 4096) throw new RuntimeException('ISO-Status ungueltig');
      foreach (['polled_at', 'last_capture', 'last_dispatch', 'last_ev_reset'] as $key) {
        if (isset($state[$key]) && (!is_int($state[$key]) || $state[$key] < 0)) throw new RuntimeException('ISO-Zeitstempel ungueltig');
      }
      if (isset($state['pending'])) {
        $p = $state['pending'];
        if (!is_array($p) || !in_array($p['iso'] ?? null, [0, ...AutoIsoPolicy::STEPS], true)
          || !is_int($p['created_at'] ?? null) || !is_int($p['captured_at'] ?? null)
          || !is_array($p['config'] ?? null) || AutoIsoPolicy::normalize($p['config']) !== $p['config']
          || ($p['iso'] !== 0 && ($p['iso'] < $p['config']['min_iso'] || $p['iso'] > $p['config']['max_iso']))
          || (isset($p['ev']) && (!is_int($p['ev']) || $p['ev'] < 0 || $p['ev'] > ($p['config']['night_ev_max'] ?? 0)))
          || ($p['iso'] === 0 && !isset($p['ev']))) throw new RuntimeException('ISO-Auftrag ungueltig');
      }
      $before = $state;
      $result = $operation($state);
      if ($before !== $state) {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('ISO-Status nicht speicherbar');
      }
      return $result;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }
}
