#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;

$app = require __DIR__ . '/../bootstrap.php';

// Read-only Healthcheck: vergleicht Backup-/Job-State und jüngste Capture-Slots
// mit den konfigurierten Altersgrenzen. Er schreibt weder Kamera-DB noch Bilder;
// der Exit-Code macht Warnungen für Monitoring sichtbar.
$maxAgeHours = 2;
$slotGapWindowHours = 2;
$slotGapGraceSeconds = 120;
$verbose = false;
$help = false;

foreach ($argv as $arg) {
  if ($arg === '--help' || $arg === '-h') {
    $help = true;
  } elseif ($arg === '--verbose' || $arg === '-v') {
    $verbose = true;
  } elseif (str_starts_with($arg, '--max-age-hours=')) {
    $maxAgeHours = (int) substr($arg, strlen('--max-age-hours='));
  } elseif (str_starts_with($arg, '--slot-gap-window-hours=')) {
    $slotGapWindowHours = (int) substr($arg, strlen('--slot-gap-window-hours='));
  } elseif (str_starts_with($arg, '--slot-gap-grace-seconds=')) {
    $slotGapGraceSeconds = (int) substr($arg, strlen('--slot-gap-grace-seconds='));
  }
}

if ($help) {
  echo "Usage: php bin/check_cron_health.php [--max-age-hours=N] [--slot-gap-window-hours=N] [--slot-gap-grace-seconds=N] [--verbose|-v] [--help|-h]\n";
  echo "Prüft letzte Aufnahmen aller Kameras und DB-Backups. Exit 0=OK, 1=WARN, 2=CRIT.\n";
  exit(0);
}

$cameras = $app->cameras()->all();
$now = time();
$exitCode = 0;

printf("Cron health check (max age %d h)\n", $maxAgeHours);

foreach ($cameras as $camera) {
  $slug = $camera->slug();
  try {
    $store = new CameraDataStore($app, $camera, null, true);
    $pdo = $store->pdo();
    $row = $pdo->query('SELECT MAX(slot_ts) AS ts FROM captures')->fetch(PDO::FETCH_ASSOC);
    $ts = isset($row['ts']) ? (int)$row['ts'] : 0;
  } catch (Throwable $e) {
    printf("[%s] CRIT: %s\n", $slug, $e->getMessage());
    $exitCode = max($exitCode, 2);
    continue;
  }

  if ($ts <= 0) {
    printf("[%s] WARN: keine Aufnahmen gefunden\n", $slug);
    $exitCode = max($exitCode, 1);
    continue;
  }

  $ageHours = ($now - $ts) / 3600;
  if ($verbose) {
    printf("[%s] DEBUG: last slot_ts=%d (%s)\n", $slug, $ts, date('c', $ts));
  }
  if ($ageHours > $maxAgeHours) {
    printf("[%s] WARN: letzte Aufnahme %.1f h alt (%s)\n", $slug, $ageHours, date('Y-m-d H:i', $ts));
    $exitCode = max($exitCode, 1);
  } else {
    printf("[%s] OK: letzte Aufnahme %s (%.1f h)\n", $slug, date('Y-m-d H:i', $ts), $ageHours);
  }

  if ($camera->mode()->value === 'active-capture' && $slotGapWindowHours > 0) {
    $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
    $lastExpectedSlot = intdiv(max(0, $now - max(0, $slotGapGraceSeconds)), $interval) * $interval;
    $windowStart = $lastExpectedSlot - max(1, $slotGapWindowHours) * 3600;
    $windowStart = intdiv($windowStart + $interval - 1, $interval) * $interval;
    if ($windowStart <= $lastExpectedSlot) {
      $stmt = $pdo->prepare('SELECT slot_ts FROM captures WHERE slot_ts BETWEEN :from AND :to ORDER BY slot_ts');
      $stmt->execute([':from' => $windowStart, ':to' => $lastExpectedSlot]);
      $seen = [];
      foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slotTs) {
        $seen[(int) $slotTs] = true;
      }
      $missing = [];
      for ($slotTs = $windowStart; $slotTs <= $lastExpectedSlot; $slotTs += $interval) {
        if (!isset($seen[$slotTs])) {
          $missing[] = $slotTs;
        }
      }
      if ($missing) {
        $labels = array_map(static fn(int $slotTs): string => date('H:i', $slotTs), $missing);
        printf("[%s] WARN: fehlende active-capture Slots in den letzten %d h: %s\n", $slug, $slotGapWindowHours, implode(', ', $labels));
        $exitCode = max($exitCode, 1);
      } elseif ($verbose) {
        printf("[%s] OK: keine active-capture Slot-Lücken seit %s\n", $slug, date('H:i', $windowStart));
      }
    }
  }

  // Backup age check (optional)
  $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
  $backupDirs = [
    $projectRoot . '/var/backups/' . $slug, // primary (backup_daily.php)
    $app->paths()->statePath('backup/db/' . $slug), // fallback
    $app->paths()->storagePath('archive/' . $slug . '/db_backups'), // legacy/HDD
  ];

  $latest = null;
  $latestDir = null;
  foreach ($backupDirs as $dir) {
    if (!is_dir($dir)) {
      continue;
    }
    foreach (glob($dir . '/*.sqlite*') ?: [] as $file) {
      $mtime = filemtime($file);
      if ($mtime !== false && ($latest === null || $mtime > $latest)) {
        $latest = $mtime;
        $latestDir = $dir;
      }
    }
  }

  if ($latest === null) {
    printf("[%s] WARN: keine DB-Backups in %s\n", $slug, implode(', ', $backupDirs));
    $exitCode = max($exitCode, 1);
  } elseif (($now - $latest) > 86400) {
    printf("[%s] WARN: letztes DB-Backup älter als 24h (%s) in %s\n", $slug, date('Y-m-d H:i', $latest), $latestDir ?? 'unknown');
    $exitCode = max($exitCode, 1);
  } elseif ($verbose) {
    printf("[%s] OK: letztes DB-Backup %s in %s\n", $slug, date('Y-m-d H:i', $latest), $latestDir ?? 'unknown');
  }
}

exit($exitCode);
