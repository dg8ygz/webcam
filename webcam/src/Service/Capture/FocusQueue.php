<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use RuntimeException;

/** One short-lived focus command per camera. Dispatch is not an execution ACK. */
final class FocusQueue
{
  public const PROTOCOL = 'camera-rpi-focus-v1';
  public const MODES = ['--', '-', 'auto', '+', '++'];

  public function __construct(private readonly string $directory) {}

  /** Verlangt eine Helper-Capability der letzten 30 Minuten, keinen SSH-Nachweis. */
  public function available(string $slug, ?int $now = null): bool
  {
    $now ??= time();
    return $this->locked($slug, static fn(array &$state): bool => ($state['capable_at'] ?? 0) <= $now
      && $now - ($state['capable_at'] ?? 0) < 1800);
  }

  /** Merkt einen erlaubten relativen Fokus vor; Pending/2s-Pause ergeben Konflikt. */
  public function request(string $slug, string $mode, ?int $now = null): void
  {
    if (!in_array($mode, self::MODES, true)) throw new RuntimeException('Ungueltiger Fokusmodus', 400);
    $now ??= time();
    $this->locked($slug, function (array &$state) use ($mode, $now): void {
      if ($now - ($state['capable_at'] ?? 0) >= 1800 || ($state['capable_at'] ?? 0) > $now) {
        throw new RuntimeException('Kein aktueller gphoto2-Helper mit Fokusunterstuetzung angemeldet', 409);
      }
      if ($this->pending($state, $now) || $now - ($state['requested_at'] ?? 0) < 2) {
        throw new RuntimeException('Fokus bereits vorgemerkt; bitte Verarbeitung abwarten', 409);
      }
      $state['requested_at'] = $now;
      $state['mode'] = $mode;
    });
  }

  /** Called ONLY after camera authentication. Non-capable clients never receive focus. */
  public function poll(string $slug, string $protocol, ?int $now = null): ?string
  {
    $now ??= time();
    return $this->locked($slug, function (array &$state) use ($protocol, $now): ?string {
      $state['capable_at'] = $protocol === self::PROTOCOL ? $now : 0;
      if ($protocol !== self::PROTOCOL || !$this->pending($state, $now)) return null;
      $mode = $state['mode'];
      unset($state['mode']); // At-most-once dispatch; never repeat a relative movement automatically.
      return "focus\n{$now}\n{$mode}\n";
    });
  }

  /** Nur noch nicht zugestellte Auftraege mit einem Alter unter 15 Minuten gelten. */
  private function pending(array $state, int $now): bool
  {
    $age = $now - ($state['requested_at'] ?? 0);
    return isset($state['mode']) && $age >= 0 && $age < 900;
  }

  /**
   * Liest, validiert und aendert den Kamerastatus unter demselben nichtblockierenden
   * Dateilock. Den Inode nicht ersetzen: parallele Polls muessen denselben sperren.
   */
  private function locked(string $slug, callable $operation): mixed
  {
    if (strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
      throw new RuntimeException('Ungueltige Kamera', 400);
    }
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
      throw new RuntimeException('Fokusverzeichnis nicht verfuegbar');
    }
    $handle = @fopen($this->directory . '/' . $slug . '.json', 'c+');
    if ($handle === false) throw new RuntimeException('Fokusstatus nicht verfuegbar');
    try {
      if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('Fokusstatus belegt', 409);
      $raw = stream_get_contents($handle, 2049);
      $state = $raw === '' ? [] : json_decode((string) $raw, true);
      if (!is_array($state) || strlen((string) $raw) > 2048
        || (isset($state['capable_at']) && !is_int($state['capable_at']))
        || (isset($state['requested_at']) && !is_int($state['requested_at']))
        || (isset($state['mode']) && !in_array($state['mode'], self::MODES, true))) {
        throw new RuntimeException('Fokusstatus ungueltig');
      }
      $before = $state;
      $result = $operation($state);
      if ($state !== $before) {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
          throw new RuntimeException('Fokusstatus nicht speicherbar');
        }
      }
      return $result;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }
}
