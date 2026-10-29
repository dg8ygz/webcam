#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Baut Kamera-SQLite-Datenbanken aus Cache, Archiv oder einem Legacy-Root auf.
 *
 * Leseführung:
 * 1. Dieser Einstieg validiert ausschließlich CLI-Optionen und wählt Kameras.
 * 2. CameraDatabaseMigrationSource findet die angeforderten Quellslots.
 * 3. CameraDatabaseMigrationPayloadBuilder prüft Dateien und baut DB-Payloads.
 * 4. CameraDatabaseRebuildManager tauscht einen vollständigen Rebuild atomar.
 *
 * Ohne --rebuild werden fehlende Slots ergänzt. Der Soll-Livepfad liegt unter
 * /var/lib/webcam/camera-db/<slug>/db.sqlite, nicht im Cache.
 */

use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Storage\CameraDatabaseMigrationPayloadBuilder;
use Webcam\Service\Storage\CameraDatabaseMigrationSource;
use Webcam\Service\Storage\CameraDatabaseRebuildManager;

$args = $_SERVER['argv'] ?? [];
array_shift($args);

$usage = <<<TXT
Usage: php bin/migrate_camera_db.php [--slug=<cam>] [--year=YYYY] [--month=MM] [--day=DD] [--overwrite] [--legacy-root=<path>] [--db-path=<path>] [--rebuild] [--verbose]
  --slug           Optional camera slug (repeatable).
  --year           Optional year filter (repeatable).
  --month          Optional month filter (repeatable).
  --day            Optional day filter (repeatable, requires --month).
  --overwrite      Overwrite existing rows for slot_ts.
  --legacy-root    Read legacy files from alternate base path.
  --db-path        Write into an alternate camera DB path.
  --rebuild        Recreate schema and atomically replace the target DB.
  --verbose|-v     Verbose output.
TXT;

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo $usage . "\n";
  exit(0);
}

$app = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/fs_helpers.php';

$requestedSlugs = [];
$years = [];
$months = [];
$days = [];
$overwrite = false;
$verbose = false;
$legacyRoot = null;
$rebuild = false;
$dbPathOverride = null;

foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $requestedSlugs[] = substr($arg, strlen('--slug='));
    continue;
  }
  if (str_starts_with($arg, '--year=')) {
    $years[] = (int) substr($arg, strlen('--year='));
    continue;
  }
  if (str_starts_with($arg, '--month=')) {
    $months[] = (int) substr($arg, strlen('--month='));
    continue;
  }
  if (str_starts_with($arg, '--day=')) {
    $days[] = (int) substr($arg, strlen('--day='));
    continue;
  }
  if ($arg === '--overwrite') {
    $overwrite = true;
    continue;
  }
  if ($arg === '--verbose' || $arg === '-v') {
    $verbose = true;
    continue;
  }
  if ($arg === '--rebuild') {
    $rebuild = true;
    continue;
  }
  if (str_starts_with($arg, '--legacy-root=')) {
    $legacyRoot = substr($arg, strlen('--legacy-root='));
    continue;
  }
  if (str_starts_with($arg, '--db-path=')) {
    $dbPathOverride = substr($arg, strlen('--db-path='));
    continue;
  }

  fwrite(STDERR, "Unrecognized argument: {$arg}\n");
  exit(1);
}

$cameras = $app->cameras()->all();
if ($requestedSlugs !== []) {
  $cameras = array_values(array_filter(
    $cameras,
    static fn($camera): bool => in_array($camera->slug(), $requestedSlugs, true)
  ));
}

$payloadBuilder = new CameraDatabaseMigrationPayloadBuilder($app);
$source = new CameraDatabaseMigrationSource($app, $app->archiveBrowser(), $payloadBuilder);
$rebuildManager = new CameraDatabaseRebuildManager();

foreach ($cameras as $camera) {
  $slug = $camera->slug();
  $store = new CameraDataStore($app, $camera, $dbPathOverride);
  $targetDbPath = $store->dbPath();
  $workingDbPath = null;

  try {
    // Ein Rebuild schreibt zuerst in eine private lokale DB. Erst nach dem
    // erfolgreichen Import wird diese Datei in einem getrennten Schritt aktiv.
    if ($rebuild) {
      $workingDbPath = $rebuildManager->allocateStagingDatabase($camera, $verbose);
      $store = new CameraDataStore($app, $camera, $workingDbPath);
      $store->ensureWritableSchema();
    } elseif ($dbPathOverride !== null && !is_file($targetDbPath)) {
      $store->ensureWritableSchema();
    }

    $yearList = $source->availableYears($camera, $legacyRoot);
    if ($years !== []) {
      $yearList = array_values(array_intersect($yearList, $years));
    }
    if ($yearList === []) {
      fwrite(STDOUT, "[SKIP] {$slug}: keine Jahre gefunden\n");
      continue;
    }

    fwrite(STDOUT, "[INFO] {$slug}: beginne Backfill für Jahre " . implode(',', $yearList) . "\n");

    foreach ($yearList as $year) {
      $entries = $source->loadYearEntries(
        $camera,
        $year,
        $legacyRoot,
        $months,
        $days,
        $verbose
      );
      if ($entries === []) {
        fwrite(STDOUT, "  [WARN] {$slug} {$year}: keine Einträge\n");
        continue;
      }

      $count = 0;
      foreach ($entries as $entry) {
        $slotTimestamp = (int) ($entry['timestamp'] ?? 0);
        if ($slotTimestamp <= 0) {
          continue;
        }
        if (!$overwrite && !$rebuild && $store->findCaptureIdBySlot($slotTimestamp) !== null) {
          continue;
        }

        $payload = $payloadBuilder->build(
          $camera,
          $entry,
          $entry['relative_path'] ?? null,
          $slotTimestamp
        );
        if ($payload === null) {
          continue;
        }

        $store->recordCapture($payload);
        $count++;
        if ($verbose && $count % 100 === 0) {
          fwrite(STDOUT, "    {$slug} {$year}: {$count} Einträge\n");
        }
      }

      fwrite(STDOUT, "  [OK] {$slug} {$year}: importiert\n");
      if (!$rebuild && $dbPathOverride === null) {
        ensureWwwDataOwner($store->dbPath(), $verbose, $slug);
      }
    }

    if ($rebuild && $workingDbPath !== null) {
      $store->close();
      $rebuildManager->activate($targetDbPath, $workingDbPath, $verbose, $slug);
    }
  } finally {
    if ($workingDbPath !== null && is_file($workingDbPath) && !unlink($workingDbPath)) {
      fwrite(STDERR, "[WARN] {$slug}: cannot remove staging DB {$workingDbPath}\n");
    }
  }
}

fwrite(STDOUT, "Fertig.\n");
