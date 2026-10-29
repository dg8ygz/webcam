#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Koordiniert das tägliche Datenbank- und Sidecar-Backup.
 *
 * Leseführung:
 * 1. Dieser Einstieg validiert Scope, Tages-Deduplizierung und Lauf-Lock.
 * 2. DailyDatabaseBackup sichert Config- und Kamera-SQLite-Dateien.
 * 3. SidecarBackup kopiert die physischen Wetter-/EXIF-Dateien des Vortags.
 * 4. BackupRunState veröffentlicht den atomaren Full- oder Partial-Status.
 *
 * Usage: php bin/backup_daily.php [--timestamp=YYYYmmdd_HHMMSS] [--slug=<camera>] [--force]
 */

use Webcam\App;
use Webcam\Service\Backup\BackupRunState;
use Webcam\Service\Backup\DailyDatabaseBackup;
use Webcam\Service\Backup\SidecarBackup;
use Webcam\Service\Backup\SqliteBackupService;
use Webcam\Service\Storage\CaptureBackupScheduler;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  exit(backupDailyMain($_SERVER['argv'] ?? []));
}

function backupDailyMain(array $argv, ?App $app = null): int
{
  if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php bin/backup_daily.php [--timestamp=YYYYmmdd_HHMMSS] [--slug=<camera>] [--force]\n";
    echo "  --timestamp  Optional timestamp suffix (default: current time)\n";
    echo "  --slug       Optional single camera slug\n";
    echo "  --force      Run even if a backup already ran today\n";
    echo "Also backs up the previous day's physical weather/EXIF text sidecars.\n";
    return 0;
  }

  /** @var App $app */
  $app ??= require __DIR__ . '/../bootstrap.php';
  require_once __DIR__ . '/../include/fs_helpers.php';

  array_shift($argv);
  $timestampArgument = null;
  $force = false;
  $requestedSlugs = [];
  $captureTrigger = null;
  foreach ($argv as $argument) {
    if (str_starts_with($argument, '--capture-trigger=')) {
      $captureTrigger = substr($argument, strlen('--capture-trigger='));
      continue;
    }
    if (str_starts_with($argument, '--timestamp=')) {
      $timestampArgument = substr($argument, strlen('--timestamp='));
      continue;
    }
    if (str_starts_with($argument, '--slug=')) {
      $requestedSlugs[] = substr($argument, strlen('--slug='));
      continue;
    }
    if ($argument === '--force') {
      $force = true;
    }
  }

  $isFullRun = $requestedSlugs === [];
  if ($captureTrigger !== null
    && (count($requestedSlugs) !== 1 || !preg_match('/^[a-f0-9]{32}$/D', $captureTrigger))
  ) {
    fwrite(STDERR, "[ERROR] capture trigger requires one camera and a valid reservation token\n");
    return 2;
  }
  $runState = new BackupRunState($app);
  $skipReason = $runState->skipReasonForToday();
  if ($isFullRun && $skipReason !== null && !$force) {
    echo "[SKIP] {$skipReason} (use --force to run anyway)\n";
    return 0;
  }

  $knownSlugs = array_map(
    static fn($camera): string => $camera->slug(),
    $app->cameras()->all()
  );
  $unknownSlugs = array_values(array_diff(array_unique($requestedSlugs), $knownSlugs));
  if ($unknownSlugs !== []) {
    fwrite(STDERR, '[ERROR] unknown camera slug(s): ' . implode(', ', $unknownSlugs) . "\n");
    return 2;
  }

  try {
    $runLock = $runState->acquireLock();
  } catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] backup lock unavailable: ' . $exception->getMessage() . "\n");
    return 1;
  }
  if ($runLock === null) {
    if ($captureTrigger !== null) {
      // Uebersprungen ist NICHT gesichert. Beim naechsten Capture dieser
      // Kamera erneut versuchen statt eine volle Stunde zu unterdruecken.
      (new CaptureBackupScheduler($app))->releaseClaim($requestedSlugs[0], $captureTrigger);
    }
    echo "[SKIP] another backup_daily process is already running\n";
    return 0;
  }

  $timestamp = $timestampArgument ?: date('Ymd_His');
  $databaseBackup = new DailyDatabaseBackup($app, new SqliteBackupService());
  try {
    $configOk = $databaseBackup->backupConfig($timestamp);
    $cameraStats = $databaseBackup->backupCameras($timestamp, $requestedSlugs);
    $sidecarStats = backupPreviousDaySidecars($app, $requestedSlugs);
    $runState->writeStatus(
      $timestamp,
      $configOk,
      $cameraStats,
      $isFullRun,
      $requestedSlugs,
      $sidecarStats
    );

    echo "Backup completed at {$timestamp}\n";
    return $configOk && $cameraStats['failed'] === 0 && $sidecarStats['failed'] === 0
      ? 0
      : 1;
  } finally {
    $runState->releaseLock($runLock);
  }
}

/**
 * Sichert die physischen Sidecars des abgeschlossenen Vortags. Die eigentliche
 * Pfadprüfung und Kopierlogik liegt bereits im eigenständigen SidecarBackup.
 *
 * @param list<string> $requestedSlugs
 * @return array{total:int,copied:int,unchanged:int,failed:int,target_base:string,dates:list<string>,ok_slugs:list<string>,failed_slugs:list<string>}
 */
function backupPreviousDaySidecars(App $app, array $requestedSlugs = []): array
{
  $timezone = new DateTimeZone((string) $app->config('timezone', 'UTC'));
  $day = (new DateTimeImmutable('now', $timezone))
    ->setTime(0, 0)
    ->sub(new DateInterval('P1D'));
  try {
    $stats = (new SidecarBackup($app))->backupDays([$day], $requestedSlugs);
    printf(
      "[%s] sidecars %s: copied=%d unchanged=%d failed=%d target=%s\n",
      $stats['failed'] === 0 ? 'OK' : 'FAIL',
      $day->format('Y-m-d'),
      $stats['copied'],
      $stats['unchanged'],
      $stats['failed'],
      $stats['target_base']
    );
    return $stats;
  } catch (Throwable $exception) {
    fwrite(STDERR, '[WARN] sidecar backup failed: ' . $exception->getMessage() . "\n");
    $failedSlugs = $requestedSlugs !== []
      ? $requestedSlugs
      : array_map(static fn($camera): string => $camera->slug(), $app->cameras()->all());
    return [
      'total' => 0,
      'copied' => 0,
      'unchanged' => 0,
      'failed' => 1,
      'target_base' => 'unavailable',
      'dates' => [$day->format('Y-m-d')],
      'ok_slugs' => [],
      'failed_slugs' => $failedSlugs,
    ];
  }
}

// ---------------------------------------------------------------------------
// Schmale Kompatibilitätsfunktionen
// ---------------------------------------------------------------------------
// Tests und reconcile_capture_images.php verwenden diese historischen Namen.
// Die Fachlogik liegt trotzdem ausschließlich in den neuen Serviceklassen.

function backupSqliteDatabase(string $source, string $destination, string $label): bool
{
  return (new SqliteBackupService())->backup($source, $destination, $label);
}

function pruneBackups(string $directory, string $prefix, int $keep): void
{
  (new SqliteBackupService())->prune($directory, $prefix, $keep);
}

function writeBackupStatus(
  App $app,
  string $timestamp,
  bool $configOk,
  array $cameraStats,
  bool $isFullRun = true,
  array $requestedSlugs = [],
  ?array $sidecarStats = null
): void {
  (new BackupRunState($app))->writeStatus(
    $timestamp,
    $configOk,
    $cameraStats,
    $isFullRun,
    $requestedSlugs,
    $sidecarStats
  );
}
