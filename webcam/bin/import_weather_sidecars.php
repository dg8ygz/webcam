#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Weather\WeatherSidecarImporter;
use Webcam\Service\Weather\WeatherSidecarParser;

/**
 * CLI-Koordinator für den Wetter-Sidecar-Import.
 *
 * Diese Datei validiert ausschließlich Optionen, Kamera und Zielpfade.
 * Dateisuche/Plausibilität liegen im Parser, DB-Batches im Import-Service.
 * Ohne --write bleibt der komplette Lauf read-only.
 */
function importWeatherSidecarsMain(array $arguments): int
{
  array_shift($arguments);
  if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo "Usage: php bin/import_weather_sidecars.php --slug=<cam> [--year=YYYY ...] [--month=MM ...] [--base=/path/to/<slug>] [--write] [--batch=N] [--verbose]\n";
    echo "  --slug     Camera slug to import.\n";
    echo "  --year     Optional year filter, repeatable.\n";
    echo "  --month    Optional month filter 1-12, repeatable.\n";
    echo "  --base     Optional camera archive root. Defaults to configured archivePath(<slug>).\n";
    echo "  --write    Update DB. Without this flag only reports what would be imported.\n";
    echo "  --batch    Commit after N imported files in write mode (default: 1000).\n";
    echo "  --verbose  Print periodic progress.\n";
    return 0;
  }

  /** @var \Webcam\App $app */
  $app = require __DIR__ . '/../bootstrap.php';
  $slug = null;
  $years = [];
  $months = [];
  $baseOverride = null;
  $write = false;
  $verbose = false;
  $batchSize = 1000;

  // Die CLI akzeptiert wiederholte Jahres-/Monatsfilter. Unbekannte Optionen
  // werden absichtlich nicht ignoriert, damit Tippfehler keinen großen
  // unbeabsichtigten Archivlauf starten.
  foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--slug=')) {
      $slug = trim(substr($argument, strlen('--slug=')));
    } elseif (str_starts_with($argument, '--year=')) {
      $years[] = (int) substr($argument, strlen('--year='));
    } elseif (str_starts_with($argument, '--month=')) {
      $months[] = (int) substr($argument, strlen('--month='));
    } elseif (str_starts_with($argument, '--base=')) {
      $baseOverride = rtrim(substr($argument, strlen('--base=')), DIRECTORY_SEPARATOR);
    } elseif (str_starts_with($argument, '--batch=')) {
      $batchSize = max(1, (int) substr($argument, strlen('--batch=')));
    } elseif ($argument === '--write') {
      $write = true;
    } elseif ($argument === '--verbose' || $argument === '-v') {
      $verbose = true;
    } else {
      fwrite(STDERR, "Unknown argument: {$argument}\n");
      return 1;
    }
  }

  if ($slug === null || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
    fwrite(STDERR, "Missing or invalid --slug=<cam>\n");
    return 1;
  }
  $camera = $app->cameras()->find($slug);
  if ($camera === null) {
    fwrite(STDERR, "Unknown camera slug: {$slug}\n");
    return 1;
  }

  $base = $baseOverride ?: $app->paths()->archivePath($slug);
  $baseReal = realpath($base);
  if ($baseReal === false || !is_dir($baseReal)) {
    fwrite(STDERR, "Archive base not found: {$base}\n");
    return 1;
  }
  $dbPath = rtrim($app->paths()->cameraDbPath($slug), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db.sqlite';
  if (!is_file($dbPath)) {
    fwrite(STDERR, "Camera DB not found: {$dbPath}\n");
    return 1;
  }

  $yearFilter = [];
  foreach ($years as $year) {
    if ($year > 0) {
      $yearFilter[$year] = true;
    }
  }
  $monthFilter = [];
  foreach ($months as $month) {
    if ($month >= 1 && $month <= 12) {
      $monthFilter[$month] = true;
    }
  }

  // Erst nach vollständig geprüften Pfaden startet der Fachservice. Das
  // Schreibrecht wird ausschließlich über den expliziten --write-Schalter
  // weitergereicht; Dry-Runs öffnen keine Batchwriter-Transaktion.
  $result = (new WeatherSidecarImporter(new WeatherSidecarParser()))->run(
    $baseReal,
    $dbPath,
    new DateTimeZone((string) $app->config('timezone', 'UTC')),
    $yearFilter,
    $monthFilter,
    $write,
    $batchSize,
    $verbose
  );

  printf("Weather sidecar import (%s) for %s\n", $write ? 'write' : 'dry-run', $slug);
  printf("base=%s\ndb=%s\nyears=%s\n", $baseReal, $dbPath, $result['years'] ? implode(',', $result['years']) : '-');
  foreach ($result['stats'] as $key => $value) {
    printf("%s=%d\n", $key, $value);
  }
  return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  exit(importWeatherSidecarsMain($_SERVER['argv'] ?? []));
}
