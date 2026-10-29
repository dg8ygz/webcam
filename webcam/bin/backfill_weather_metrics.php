#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Infrastructure\Storage\CameraWeatherMetricsRepository;

require dirname(__DIR__) . '/autoload.php';

// No bootstrap/config fallback: the operator must name ONE approved camera DB.
// Run as its existing storage owner, not with newly guessed ownership.
$options = getopt('', ['db:', 'backup-dir:', 'apply', 'help']);
foreach (array_slice($argv, 1) as $argument) {
  if (!in_array($argument, ['--apply', '--help'], true)
    && !preg_match('/^--(?:db|backup-dir)=.+$/D', $argument)) {
    fwrite(STDERR, "Unknown option; use --help (path options require =).\n");
    exit(2);
  }
}
if (isset($options['help']) || !isset($options['db'], $options['backup-dir'], $options['apply'])) {
  fwrite(STDERR, "Usage: php bin/backfill_weather_metrics.php --db=/absolute/camera/db.sqlite --backup-dir=/local/backup-directory --apply\n");
  exit(isset($options['help']) ? 0 : 2);
}
$lock = null;
try {
  $path = $options['db'];
  $backupDir = $options['backup-dir'];
  if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || !is_writable($path)
    || !is_string($backupDir) || !str_starts_with($backupDir, '/') || !is_dir($backupDir) || !is_writable($backupDir)) {
    throw new RuntimeException('Explicit writable database and existing local backup directory required');
  }
  umask(0077);
  $path = realpath($path);
  $lock = fopen($path . '.weather-metrics.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another weather backfill is running');
  }
  $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('PRAGMA busy_timeout=2000; PRAGMA foreign_keys=ON');
  if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Source database quick_check failed');
  }
  $foreignKeys = $pdo->query('PRAGMA foreign_key_check');
  $hasLegacyOrphans = $foreignKeys->fetch() !== false;
  $foreignKeys->closeCursor();
  if ($hasLegacyOrphans) {
    fwrite(STDERR, "WARNING: Source already has foreign-key violations. Raw rows stay unchanged; only captures joined by the existing weather page are projected.\n");
  }
  // SQLite's online backup includes committed WAL without a full VACUUM rebuild.
  $backup = rtrim($backupDir, '/') . '/' . basename(dirname($path)) . '-weather-'
    . date('Ymd_His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
  $source = new SQLite3($path, SQLITE3_OPEN_READONLY);
  $destination = new SQLite3($backup, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
  $source->busyTimeout(2000);
  if (!$source->backup($destination)) {
    throw new RuntimeException('SQLite online backup failed; source not migrated');
  }
  $destination->close();
  $source->close();
  $check = new PDO('sqlite:file:' . $backup . '?mode=ro');
  if ($check->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Backup quick_check failed; source not migrated');
  }
  $check = null;
  fwrite(STDOUT, "Verified backup: {$backup}\n");
  $metrics = new CameraWeatherMetricsRepository($pdo);
  $metrics->install();
  $processed = 0;
  $deadline = hrtime(true) / 1e9 + 120;
  do {
    $count = $metrics->backfillBatch();
    $processed += $count;
    if ($count > 0 && hrtime(true) / 1e9 >= $deadline) {
      throw new RuntimeException('Backfill time budget reached; raw fallback remains safe, rerun to finish');
    }
  } while ($count > 0);
  if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok'
    || $pdo->query('PRAGMA foreign_key_check(weather_measurements)')->fetch() !== false
    || $pdo->query('PRAGMA foreign_key_check(weather_measurements_dirty)')->fetch() !== false) {
    throw new RuntimeException('Post-migration integrity check failed; retain backup and investigate');
  }
  fwrite(STDOUT, "OK: {$processed} weather captures processed; quick_check and projection foreign keys passed.\n");
} catch (Throwable $error) {
  fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
  exit(1);
} finally {
  if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
