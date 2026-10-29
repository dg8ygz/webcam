<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use Webcam\App;

/**
 * Leichter interner Zeitgeber fuer taegliche Wartungsscripte.
 *
 * Die externen Webcam-Helper fragen include/command.php ohnehin regelmaessig
 * ab. Nach einer bereits gesendeten wait-Antwort nutzt dieser Tick die aktuelle
 * Serverzeit, prueft die frei konfigurierten Startzeiten und startet bei Bedarf
 * den eigentlichen Runner als getrennten Prozess. Backup oder Wetterpruefung
 * halten dadurch weder den Helper noch einen Apache-Worker minutenlang offen.
 */
final class MaintenanceTick
{
  private const THROTTLE_SECONDS = 20;

  /**
   * Prueft hoechstens alle 20 Sekunden und startet den Runner nur bei Faelligkeit.
   *
   * @param null|callable():bool $launcher Testbarer Ersatz fuer den Prozessstart.
   */
  public static function trigger(App $app, ?int $now = null, ?callable $launcher = null): bool
  {
    $disabled = getenv('WEBCAM_MAINTENANCE_TICK_DISABLE');
    if (is_string($disabled) && filter_var($disabled, FILTER_VALIDATE_BOOL)) {
      return false;
    }

    $now ??= time();
    $handle = null;
    $locked = false;
    try {
      $stateDir = $app->paths()->statePath('maintenance-scheduler');
      if (!is_dir($stateDir) && !mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
        throw new RuntimeException("cannot create maintenance state directory: {$stateDir}");
      }

      $tickPath = $stateDir . DIRECTORY_SEPARATOR . 'tick.lock';
      $handle = fopen($tickPath, 'c+');
      if ($handle === false) {
        throw new RuntimeException("cannot open maintenance tick lock: {$tickPath}");
      }
      if (!flock($handle, LOCK_EX | LOCK_NB)) {
        return false;
      }
      $locked = true;

      $raw = stream_get_contents($handle);
      $lastTick = is_string($raw) && trim($raw) !== '' ? (int) trim($raw) : 0;
      if ($lastTick > 0 && ($now - $lastTick) < self::THROTTLE_SECONDS) {
        return false;
      }
      if (!ftruncate($handle, 0)
        || !rewind($handle)
        || fwrite($handle, (string) $now) === false
        || !fflush($handle)
      ) {
        throw new RuntimeException("cannot update maintenance tick: {$tickPath}");
      }

      if (!self::hasDueTask($app, $now)) {
        return false;
      }

      $started = $launcher !== null ? (bool) $launcher() : self::launchRunner($app);
      if (!$started) {
        $app->logger()->warning('maintenance runner launch failed');
      }
      return $started;
    } catch (Throwable $exception) {
      $app->logger()->warning('maintenance tick failed', ['error' => $exception->getMessage()]);
      return false;
    } finally {
      if (is_resource($handle)) {
        if ($locked) {
          flock($handle, LOCK_UN);
        }
        fclose($handle);
      }
    }
  }

  public static function hasDueTask(App $app, int $now): bool
  {
    $settings = MaintenanceSchedule::normalize($app->camerasMeta()['maintenance_scripts'] ?? null);
    $timezone = new DateTimeZone((string) $app->config('timezone', 'UTC'));
    $dateTime = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
    $scheduledDays = [];
    foreach (array_keys($settings) as $task) {
      $statePath = $app->paths()->statePath('maintenance-scheduler/' . $task . '.json');
      $scheduledDays[$task] = self::scheduledDay($statePath);
    }
    return self::dueTasks($settings, $scheduledDays, $dateTime) !== [];
  }

  /**
   * Ermittelt faellige Tasks nur aus Zeitplan, Tagesstatus und aktueller Zeit.
   *
   * @param array<string,array{enabled:bool,time:string}> $settings
   * @param array<string,?string> $scheduledDays
   * @return list<string>
   */
  public static function dueTasks(
    array $settings,
    array $scheduledDays,
    DateTimeImmutable $dateTime
  ): array {
    $currentDay = $dateTime->format('Y-m-d');
    $currentTime = $dateTime->format('H:i');
    $due = [];
    foreach ($settings as $task => $taskSettings) {
      if (!$taskSettings['enabled'] || $currentTime < $taskSettings['time']) {
        continue;
      }
      if (($scheduledDays[$task] ?? null) !== $currentDay) {
        $due[] = $task;
      }
    }
    return $due;
  }

  private static function scheduledDay(string $statePath): ?string
  {
    if (!is_file($statePath)) {
      return null;
    }
    $raw = file_get_contents($statePath);
    if (!is_string($raw)) {
      return null;
    }
    try {
      $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
      return null;
    }
    if (!is_array($state) || !is_string($state['scheduled_day'] ?? null)) {
      return null;
    }
    return $state['scheduled_day'];
  }

  /** Startet den blockierenden Runner abgekoppelt vom Webrequest. */
  private static function launchRunner(App $app): bool
  {
    $root = dirname(__DIR__, 3);
    $php = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
    $runner = $root . '/bin/maintenance_scheduler.php';
    $logPath = $app->paths()->logPath('maintenance_scheduler.log');
    if (!is_executable($php) || !is_file($runner)) {
      throw new RuntimeException('maintenance runner executable missing');
    }

    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
      throw new RuntimeException("cannot create maintenance log directory: {$logDir}");
    }

    $command = escapeshellarg($php)
      . ' ' . escapeshellarg($runner)
      . ' >> ' . escapeshellarg($logPath)
      . ' 2>&1 < /dev/null &';
    $descriptors = [
      0 => ['file', '/dev/null', 'r'],
      1 => ['file', '/dev/null', 'a'],
      2 => ['file', '/dev/null', 'a'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
      $environment = [];
    }
    $configDb = $app->config('camera_config_db');
    if (is_string($configDb) && $configDb !== '') {
      $environment['WEBCAM_CONFIG_DB'] = $configDb;
    }
    $cameraDbPath = $app->config('camera_db_path');
    if (is_string($cameraDbPath) && $cameraDbPath !== '') {
      $environment['WEBCAM_CAMERA_DB_PATH'] = $cameraDbPath;
    }

    $process = proc_open(
      ['/bin/sh', '-c', $command],
      $descriptors,
      $pipes,
      $root,
      $environment,
      ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
      return false;
    }
    return proc_close($process) === 0;
  }
}
