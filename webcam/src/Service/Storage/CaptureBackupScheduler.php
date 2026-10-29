<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Closure;
use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Maintenance\MaintenanceSchedule;

/**
 * Startet bei Bedarf einen gezielten Kamera-DB-Backup-Lauf.
 *
 * Ein Capture soll nicht auf das Backup warten. Vor dem asynchronen Start wird
 * deshalb anhand der Statusdateien geprüft, ob ein heutiger Voll- oder
 * Zusatzlauf die Kamera bereits abdeckt. Ein stabiler flock pro Kamera macht
 * die Stundenreservierung atomar; kein globaler Stundenmarker sperrt Nachbarn.
 */
final class CaptureBackupScheduler
{
  private readonly Closure $launcher;
  private readonly Closure $clock;

  /** @param null|Closure(string,string):bool $launcher Slug, Reservierungstoken */
  public function __construct(private readonly App $app, ?Closure $launcher = null, ?Closure $clock = null)
  {
    $this->launcher = $launcher ?? $this->spawn(...);
    $this->clock = $clock ?? static fn(): int => time();
  }

  /** Prueft Deckung/Plan, reserviert atomar diesen Slug und startet nur bei Bedarf. */
  public function schedule(Camera $camera): void
  {
    try {
      $stateDirectory = $this->app->paths()->statePath('backup');
      $fullState = $this->app->paths()->statePath('backup_db.json');
      $partialState = $this->app->paths()->statePath('backup_partial.json');
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('backup state unavailable', ['error' => $exception->getMessage()]);
      return;
    }
    if (!is_dir($stateDirectory)
      && !mkdir($stateDirectory, 0775, true)
      && !is_dir($stateDirectory)
    ) {
      $this->app->logger()->warning('backup state dir unavailable', [
        'dir' => $stateDirectory,
        'camera' => $camera->slug(),
      ]);
      return;
    }

    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $now = ($this->clock)();
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->format('Y-m-d');
    if ($this->statusCoversCamera($fullState, $camera, $today, $timezone)
      || $this->statusCoversCamera($partialState, $camera, $today, $timezone)
      || $this->statusCoversCamera($stateDirectory . '/camera-' . $camera->slug() . '.json', $camera, $today, $timezone)
    ) {
      return;
    }

    // Der Tageswechsel allein macht die erfolgreiche Sicherung von gestern
    // nicht unbrauchbar. Dem explizit geplanten Vollbackup Vorrang lassen,
    // statt davor fuenf zusaetzliche DB-/Sidecar-Laeufe anzustossen.
    if ($this->canWaitForDailyBackup($camera, $now, $timezone, [
      $fullState, $partialState, $stateDirectory . '/camera-' . $camera->slug() . '.json',
    ])) {
      return;
    }

    $path = $stateDirectory . '/capture-' . $camera->slug() . '.lock';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
      $this->app->logger()->warning('backup trigger lock unavailable', ['camera' => $camera->slug()]);
      return;
    }
    $token = bin2hex(random_bytes(16));
    try {
      if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
        if (!$wouldBlock) {
          throw new RuntimeException('cannot lock backup trigger');
        }
        return;
      }
      $raw = stream_get_contents($handle, 1024);
      if ($raw === false) {
        throw new RuntimeException('cannot read backup trigger');
      }
      $state = json_decode($raw, true);
      $previous = is_array($state) ? ($state['ts'] ?? 0) : 0;
      if (is_int($previous) && $previous > $now - 3600 && $previous <= $now) {
        return;
      }
      $claim = json_encode(['ts' => $now, 'token' => $token], JSON_THROW_ON_ERROR);
      if (!ftruncate($handle, 0) || !rewind($handle)
        || fwrite($handle, $claim) !== strlen($claim) || !fflush($handle)
      ) {
        throw new RuntimeException('cannot write backup trigger');
      }
    } catch (Throwable $exception) {
      $this->app->logger()->warning('backup trigger claim failed', [
        'camera' => $camera->slug(), 'error' => $exception->getMessage(),
      ]);
      return;
    } finally {
      // Vor spawn freigeben: ein sofort am Ausfuehrungslock abgewiesenes Kind
      // muss seine Reservierung bereits loeschen koennen.
      fclose($handle);
    }

    try {
      if (($this->launcher)($camera->slug(), $token)) {
        return;
      }
    } catch (Throwable $exception) {
      $this->app->logger()->warning('backup command spawn exception', ['error' => $exception->getMessage()]);
    }
    $this->releaseClaim($camera->slug(), $token);
    $this->app->logger()->warning('backup command spawn failed', ['camera' => $camera->slug()]);
  }

  /**
   * Nur bei bestaetigter Vortagssicherung warten, maximal bis zur geplanten
   * Startzeit plus Laufzeitbudget. Ohne Plan/Backup oder nach Fristablauf bleibt
   * der bisherige pro-Kamera-Fallback aktiv. Keine neue Uhrzeit/DB-Konfiguration.
   * @param list<string> $stateFiles
   */
  private function canWaitForDailyBackup(Camera $camera, int $now, DateTimeZone $timezone, array $stateFiles): bool
  {
    $configured = $this->app->camerasMeta()['maintenance_scripts'] ?? null;
    if (!is_array($configured) || !is_array($configured[MaintenanceSchedule::BACKUP_DAILY] ?? null)) {
      return false;
    }
    $settings = MaintenanceSchedule::normalize($configured)[MaintenanceSchedule::BACKUP_DAILY];
    if (!$settings['enabled'] || $settings['path'] !== 'bin/backup_daily.php') {
      return false;
    }
    // Ein gezielter CLI-Lauf ist kein Vollbackup aller Kameras.
    foreach ($settings['arguments'] as $argument) {
      if ($argument === '--slug' || str_starts_with($argument, '--slug=')) {
        return false;
      }
    }
    $localNow = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
    [$hour, $minute] = array_map('intval', explode(':', $settings['time']));
    $deadline = $localNow->setTime($hour, $minute)->getTimestamp() + $settings['timeout_seconds'];
    if ($now >= $deadline) {
      return false;
    }
    $previousRun = $localNow->modify('-1 day')->setTime($hour, $minute);
    $yesterday = $previousRun->format('Y-m-d');
    foreach ($stateFiles as $path) {
      if ($this->statusCoversCamera($path, $camera, $yesterday, $timezone, $previousRun->getTimestamp())) {
        return true;
      }
    }
    return false;
  }

  /** Kein Upload wartet auf die eigentliche, global flock-geschuetzte Sicherung. */
  private function spawn(string $slug, string $token): bool
  {
    $projectRoot = realpath(__DIR__ . '/../../..');
    $script = $projectRoot !== false ? $projectRoot . '/bin/backup_daily.php' : '';
    $php = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
    if ($script === '' || !is_file($script) || !is_readable($script) || !is_executable($php)) {
      return false;
    }

    // Der Slug wird als einzelnes Shellargument escaped. Das Programm läuft
    // absichtlich abgekoppelt; Status und Fehler schreibt backup_daily selbst.
    $command = sprintf(
      '%s %s --slug=%s --capture-trigger=%s >> %s 2>&1 < /dev/null &',
      escapeshellarg($php),
      escapeshellarg($script),
      escapeshellarg($slug),
      escapeshellarg($token),
      escapeshellarg($this->app->paths()->logPath('backup_capture.log'))
    );
    // PHP_BINARY kann unter mod_php Apache selbst sein. CLI gezielt waehlen
    // und die Datenbank-Trennung auch ohne vererbte Apache-SetEnv bewahren.
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    foreach (['camera_config_db' => 'WEBCAM_CONFIG_DB', 'camera_db_path' => 'WEBCAM_CAMERA_DB_PATH', 'deferred_image_root' => 'WEBCAM_DEFERRED_IMAGE_ROOT', 'deferred_image_backup_root' => 'WEBCAM_DEFERRED_IMAGE_BACKUP_ROOT', 'deferred_image_guard' => 'WEBCAM_DEFERRED_IMAGE_GUARD'] as $key => $name) {
      $value = $this->app->config($key);
      if (is_string($value) && $value !== '') {
        $environment[$name] = $value;
      }
    }
    $process = proc_open(['/bin/sh', '-c', $command], [
      0 => ['file', '/dev/null', 'r'],
      1 => ['file', '/dev/null', 'a'],
      2 => ['file', '/dev/null', 'a'],
    ], $pipes, $projectRoot, $environment);
    return is_resource($process) && proc_close($process) === 0;
  }

  /** Nur die eigene Reservierung freigeben, nie eine neuere oder den Lock-Inode loeschen. */
  public function releaseClaim(string $slug, string $token): void
  {
    $path = $this->app->paths()->statePath('backup/capture-' . $slug . '.lock');
    $handle = fopen($path, 'c+');
    if ($handle === false) {
      $this->app->logger()->warning('backup trigger release open failed', ['camera' => $slug]);
      return;
    }
    try {
      // Nur kurze Dateioperationen unter diesem Lock, keine Backup-Ausfuehrung.
      $deadline = microtime(true) + 1.0;
      while (!flock($handle, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
          throw new RuntimeException('backup trigger release lock timeout');
        }
        usleep(10000);
      }
      $state = json_decode((string) stream_get_contents($handle, 1024), true);
      if (is_array($state) && ($state['token'] ?? null) === $token
        && (!ftruncate($handle, 0) || !fflush($handle))
      ) {
        throw new RuntimeException('cannot clear backup trigger');
      }
    } catch (Throwable $exception) {
      $this->app->logger()->warning('backup trigger release failed', [
        'camera' => $slug, 'error' => $exception->getMessage(),
      ]);
    } finally {
      fclose($handle);
    }
  }

  /** Versteht aktuelle ok_slugs- und historische Voll-Lauf-Statusdateien. */
  private function statusCoversCamera(
    string $stateFile,
    Camera $camera,
    string $today,
    DateTimeZone $timezone,
    int $minimumTimestamp = 0
  ): bool {
    if (!is_file($stateFile)) {
      return false;
    }
    $raw = file_get_contents($stateFile);
    if ($raw === false) {
      $this->app->logger()->warning('backup state read failed', [
        'path' => $stateFile,
        'camera' => $camera->slug(),
      ]);
      return false;
    }
    $state = json_decode($raw, true);
    if (!is_array($state) || !isset($state['ts']) || !is_numeric($state['ts'])) {
      return false;
    }
    if ((int) $state['ts'] < $minimumTimestamp) {
      return false;
    }
    $stateDay = (new DateTimeImmutable('@' . (int) $state['ts']))
      ->setTimezone($timezone)
      ->format('Y-m-d');
    if ($stateDay !== $today) {
      return false;
    }

    $slugs = isset($state['ok_slugs']) && is_array($state['ok_slugs'])
      ? array_map('strval', $state['ok_slugs'])
      : [];
    if ($slugs !== []) {
      return in_array($camera->slug(), $slugs, true);
    }
    return ($state['status'] ?? '') === 'ok' && ($state['scope'] ?? 'full') === 'full';
  }
}
