#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Weather\WeatherQualityAnalyzer;

/**
 * CLI-Koordinator für die Wetterqualitätsprüfung.
 *
 * Optionen, Zeitraum und Kameraauswahl bleiben hier sichtbar. SQL-Hydrierung,
 * Plausibilitätsregeln und Markertransaktion liegen im WeatherQualityAnalyzer.
 * Ohne --write öffnet CameraDataStore die Kamera-DB weiterhin read-only.
 */

$args = $_SERVER['argv'] ?? [];
array_shift($args);

$help = false;
$write = false;
$verbose = false;
$includeWithoutCommand = false;
$hours = 24;
$hoursExplicit = false;
$previousDay = false;
$minRepeat = 24;
$slugs = [];

foreach ($args as $arg) {
  if ($arg === '--help' || $arg === '-h') {
    $help = true;
  } elseif ($arg === '--write') {
    $write = true;
  } elseif ($arg === '--verbose' || $arg === '-v') {
    $verbose = true;
  } elseif ($arg === '--include-without-command') {
    $includeWithoutCommand = true;
  } elseif ($arg === '--previous-day') {
    $previousDay = true;
  } elseif (str_starts_with($arg, '--hours=')) {
    $hours = max(1, (int) substr($arg, strlen('--hours=')));
    $hoursExplicit = true;
  } elseif (str_starts_with($arg, '--min-repeat=')) {
    $minRepeat = max(2, (int) substr($arg, strlen('--min-repeat=')));
  } elseif (str_starts_with($arg, '--slug=')) {
    $slug = trim(substr($arg, strlen('--slug=')));
    if ($slug !== '') {
      $slugs[$slug] = true;
    }
  } else {
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(2);
  }
}

if ($previousDay && $hoursExplicit) {
  fwrite(STDERR, "--previous-day and --hours cannot be combined\n");
  exit(2);
}

if ($help) {
  echo "Usage: php bin/check_weather_quality.php [--slug=<cam> ...] [--hours=N|--previous-day] [--min-repeat=N] [--write] [--verbose|-v]\n";
  echo "Checks recent weather DB rows. Exit 0=OK, 1=WARN, 2=ERROR.\n";
  echo "Without --write this is a dry run. With --write it stores markers in weather_quality_flags.\n";
  echo "--previous-day checks the complete previous local calendar day.\n";
  echo "Default: --hours=24 --min-repeat=24. Cameras without weather commands are skipped unless explicitly selected.\n";
  exit(0);
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$analyzer = new WeatherQualityAnalyzer();

$now = time();
$toTs = $now;
$periodLabel = $hours . 'h window';
if ($previousDay) {
  $today = new DateTimeImmutable('today');
  $previousDayStart = $today->modify('-1 day');
  $fromTs = $previousDayStart->getTimestamp();
  $toTs = $today->getTimestamp() - 1;
  $periodLabel = 'previous day ' . $previousDayStart->format('Y-m-d');
} else {
  $fromTs = $now - ($hours * 3600);
}
$checkedAt = $now;
$exitCode = 0;

printf(
  "Weather quality check (%s, min-repeat %d, %s)\n",
  $periodLabel,
  $minRepeat,
  $write ? 'write' : 'dry-run'
);

foreach ($app->cameras()->all() as $camera) {
  if (!$camera instanceof Camera) {
    continue;
  }
  $slug = $camera->slug();
  $selected = $slugs === [] || isset($slugs[$slug]);
  if (!$selected) {
    continue;
  }
  if ($slugs === [] && !$includeWithoutCommand && $camera->weatherCommands() === []) {
    if ($verbose) {
      printf("[%s] SKIP: no weather command configured\n", $slug);
    }
    continue;
  }

  try {
    $store = new CameraDataStore($app, $camera, null, !$write);
    $pdo = $store->pdo();
    $analyzer->configureWeatherQualityPdo($pdo, $write);
    $rows = $analyzer->loadWeatherQualityRows($pdo, $fromTs, $toTs);
    $flags = $analyzer->evaluateWeatherQualityRows($rows, $minRepeat);
    if ($write) {
      $analyzer->syncWeatherQualityFlags($pdo, array_column($rows, 'capture_id'), $flags, $checkedAt);
    }
  } catch (Throwable $e) {
    printf("[%s] ERROR: %s\n", $slug, $e->getMessage());
    $exitCode = max($exitCode, 2);
    continue;
  }

  $captures = count($rows);
  $weather = 0;
  foreach ($rows as $row) {
    if (($row['line'] ?? '') !== '') {
      $weather++;
    }
  }

  $summary = $analyzer->summarizeWeatherQualityFlags($flags);
  if ($flags !== []) {
    printf(
      "[%s] WARN: captures=%d weather=%d missing=%d flags=%d%s\n",
      $slug,
      $captures,
      $weather,
      $captures - $weather,
      count($flags),
      $summary !== '' ? ' ' . $summary : ''
    );
    $exitCode = max($exitCode, 1);
    if ($verbose) {
      foreach (array_slice($flags, 0, 20) as $flag) {
        printf(
          "  - %s %s %s: %s\n",
          date('Y-m-d H:i', $flag['slot_ts']),
          $flag['flag'],
          $flag['metric'] ?? '-',
          $flag['reason']
        );
      }
      if (count($flags) > 20) {
        printf("  ... %d weitere Flags\n", count($flags) - 20);
      }
    }
  } else {
    printf("[%s] OK: captures=%d weather=%d missing=0\n", $slug, $captures, $weather);
  }
}

exit($exitCode);
