<?php
declare(strict_types=1);

use Webcam\Infrastructure\Storage\CameraDataStore;

$app = require __DIR__ . '/bootstrap.php';

// Öffentliche Wetterauswertung: definierte Messgrößen aus den Wetterzeilen der
// Kamera-DB lesen, Werte auf plausible Bereiche begrenzen, Zeitreihen/Aggregate
// aufbauen und als Diagrammseite rendern. Bildarchive werden dafür nicht gescannt.
$timezone = new DateTimeZone((string) $app->config('timezone', 'Europe/Berlin'));
$pageConfig = [
  'days' => 180,
  'camera_order' => ['bielefeld', 'steinhagen', 'stromberg', 'sparrenburg'],
  'metrics' => \Webcam\Service\Weather\WeatherMetrics::definitions(),
];

$requestedDays = isset($_GET['days']) && is_numeric($_GET['days']) ? (int) $_GET['days'] : (int) $pageConfig['days'];
$days = max(1, min(366, $requestedDays));
$today = new DateTimeImmutable('today', $timezone);
$startDay = $today->modify('-' . ($days - 1) . ' days');
$endDay = $today->modify('+1 day');
$fromTs = $startDay->getTimestamp();
$toTs = $endDay->getTimestamp() - 1;
$dayKeys = weather_page_day_keys($startDay, $days);
$relativePrefix = weather_page_relative_prefix($app);
$meta = $app->camerasMeta();
$heroText = (string) ($meta['overview_hero'] ?? $app->config('overview_hero', ''));

$selectedCamera = null;
if (array_key_exists('camera', $_GET)) {
  $slug = $_GET['camera'];
  if (!is_string($slug) || strlen($slug) > 128 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
    || !($selectedCamera = $app->cameras()->find($slug))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kamera nicht gefunden.\n";
    exit;
  }
  if ($selectedCamera->isTest()) header('X-Robots-Tag: noindex, nofollow');
}
// Direct camera access is allowed; global lists must still hide test cameras.
$cameras = $selectedCamera ? [$selectedCamera] : $app->cameras()->listed();
$cameraOrder = array_flip($pageConfig['camera_order'] ?? []);
usort($cameras, static function ($a, $b) use ($cameraOrder): int {
  $aSlug = method_exists($a, 'slug') ? $a->slug() : '';
  $bSlug = method_exists($b, 'slug') ? $b->slug() : '';
  $aIndex = $cameraOrder[$aSlug] ?? 999;
  $bIndex = $cameraOrder[$bSlug] ?? 999;
  if ($aIndex === $bIndex) {
    return strcmp($aSlug, $bSlug);
  }
  return $aIndex <=> $bIndex;
});

$metricGroups = [];
$weatherSources = [];
foreach ($cameras as $camera) {
  $cameraData = weather_page_load_camera_data($app, $camera, $pageConfig['metrics'], $dayKeys, $fromTs, $toTs, $timezone);
  $weatherSources[] = $cameraData['source'] ?? 'unavailable';
  if ($cameraData['has_weather'] === false) {
    continue;
  }
  foreach ($cameraData['metrics'] as $metricKey => $row) {
    $metricGroups[$metricKey]['definition'] = $pageConfig['metrics'][$metricKey];
    $metricGroups[$metricKey]['rows'][] = $row;
  }
}

foreach ($metricGroups as $metricKey => &$group) {
  $values = [];
  foreach ($group['rows'] ?? [] as $row) {
    foreach ($row['values'] as $value) {
      if ($value !== null) {
        $values[] = $value;
      }
    }
  }
  $group['min'] = $values ? min($values) : null;
  $group['max'] = $values ? max($values) : null;
}
unset($group);

$metricGroups = array_filter(
  $metricGroups,
  static fn(array $group): bool => !empty($group['rows']) && $group['min'] !== null && $group['max'] !== null
);

// Forecast locations are intentionally fewer than the camera measurement sites.
// Bielefeld uses the Sparrenburg camera coordinates; keep the public-list guard.
$forecastLocations = [];
if ($selectedCamera === null) {
  $listedSlugs = array_fill_keys(array_map(static fn($camera): string => $camera->slug(), $cameras), true);
  foreach (['sparrenburg' => 'Bielefeld', 'steinhagen' => 'Steinhagen', 'stromberg' => 'Stromberg'] as $slug => $title) {
    if (isset($listedSlugs[$slug])) {
      $forecastLocations[] = ['slug' => $slug, 'title' => $title];
    }
  }
}

header('X-Webcam-Weather-Source: ' . implode(',', array_unique($weatherSources)));

echo $app->renderer()->render('weather', [
  'app' => $app,
  'pageTitle' => 'Wetterdaten',
  'bodyClass' => 'page-weather',
  'mainClass' => 'main-content main-content--weather',
  'metrics' => $metricGroups,
  'selectedCamera' => $selectedCamera,
  'dayKeys' => $dayKeys,
  'days' => $days,
  'startDate' => $startDay,
  'endDate' => $today,
  'cameraBase' => $relativePrefix . 'camera/',
  'heroText' => $heroText,
  'relativePrefix' => $relativePrefix,
  'forecastLocations' => $forecastLocations,
  'forecastTimezone' => $timezone->getName(),
  'styles' => $selectedCamera ? [] : ['css/explore.css'],
  'scripts' => $selectedCamera ? [] : ['js/weather-forecast.js'],
]);

/**
 * @return list<string>
 */
function weather_page_day_keys(DateTimeImmutable $startDay, int $days): array
{
  $keys = [];
  for ($i = 0; $i < $days; $i++) {
    $keys[] = $startDay->modify('+' . $i . ' days')->format('Y-m-d');
  }
  return $keys;
}

function weather_page_relative_prefix(\Webcam\App $app): string
{
  return webcam_document_relative_prefix((string) $app->config('base_url', ''));
}

/**
 * @param array<string,array<string,mixed>> $metricDefinitions
 * @param list<string> $dayKeys
 * @return array{has_weather:bool,metrics:array<string,array<string,mixed>>}
 */
function weather_page_load_camera_data(
  \Webcam\App $app,
  \Webcam\Domain\Camera $camera,
  array $metricDefinitions,
  array $dayKeys,
  int $fromTs,
  int $toTs,
  DateTimeZone $timezone
): array {
  $store = new CameraDataStore($app, $camera, null, true);
  $dbPath = $store->dbPath();
  if (!is_file($dbPath)) {
    return ['has_weather' => false, 'metrics' => []];
  }

  $daily = [];
  $sampleCounts = array_fill_keys(array_keys($metricDefinitions), 0);
  $latest = [];
  $slotTs = null;
  $slotLines = [];

  $processSlot = static function (?int $ts, array $lines) use (
    &$daily,
    &$sampleCounts,
    &$latest,
    $metricDefinitions,
    $timezone
  ): void {
    if ($ts === null || $lines === []) {
      return;
    }
    $values = weather_page_extract_metrics(implode("\n", $lines), $metricDefinitions);
    if ($values === []) {
      return;
    }
    $dayKey = (new DateTimeImmutable('@' . $ts))->setTimezone($timezone)->format('Y-m-d');
    foreach ($values as $metricKey => $value) {
      $daily[$metricKey][$dayKey]['sum'] = ($daily[$metricKey][$dayKey]['sum'] ?? 0.0) + $value;
      $daily[$metricKey][$dayKey]['count'] = ($daily[$metricKey][$dayKey]['count'] ?? 0) + 1;
      $sampleCounts[$metricKey] = ($sampleCounts[$metricKey] ?? 0) + 1;
      $latest[$metricKey] = ['value' => $value, 'timestamp' => $ts];
    }
  };

  try {
    $pdo = $store->pdo();
    $aggregate = (new \Webcam\Infrastructure\Storage\CameraWeatherMetricsRepository($pdo))->aggregate($dayKeys, $timezone);
    if ($aggregate !== null) {
      $daily = $aggregate['daily'];
      $sampleCounts = $aggregate['sample_counts'];
      $latest = $aggregate['latest'];
    } else {
      // Explicit compatibility path until the approved SQLite backfill is complete.
      $weatherQualityFilter = '';
      if (weather_page_quality_table_exists($pdo)) {
        $weatherQualityFilter = ' AND NOT EXISTS (SELECT 1 FROM weather_quality_flags q WHERE q.capture_id = w.capture_id)';
        $activeWeatherQualityFrom = weather_page_active_quality_from($pdo);
        if ($activeWeatherQualityFrom !== null) {
          $weatherQualityFilter .= ' AND c.slot_ts < ' . $activeWeatherQualityFrom;
        }
      }
      $stmt = $pdo->prepare(
        'SELECT c.slot_ts, w.value
           FROM captures c
           JOIN weather_entries w ON w.capture_id = c.id
          WHERE c.slot_ts BETWEEN :from_ts AND :to_ts' . $weatherQualityFilter . '
          ORDER BY c.slot_ts ASC, w.position ASC, w.id ASC'
      );
      $stmt->execute([
        ':from_ts' => $fromTs,
        ':to_ts' => $toTs,
      ]);
      while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $currentTs = (int) ($row['slot_ts'] ?? 0);
        if ($currentTs <= 0) {
          continue;
        }
        if ($slotTs !== null && $currentTs !== $slotTs) {
          $processSlot($slotTs, $slotLines);
          $slotLines = [];
        }
        $slotTs = $currentTs;
        $value = trim((string) ($row['value'] ?? ''));
        if ($value !== '') {
          $slotLines[] = $value;
        }
      }
      $processSlot($slotTs, $slotLines);
    }
  } catch (Throwable $exception) {
    $app->logger()->warning('weather page camera query failed', [
      'camera' => $camera->slug(),
      'error' => $exception->getMessage(),
    ]);
    return ['has_weather' => false, 'metrics' => []];
  } finally {
    $store->close();
  }

  $rows = [];
  foreach ($metricDefinitions as $metricKey => $definition) {
    $values = [];
    $dailyValues = [];
    foreach ($dayKeys as $dayKey) {
      $bucket = $daily[$metricKey][$dayKey] ?? null;
      if (!$bucket || (int) ($bucket['count'] ?? 0) <= 0) {
        $dailyValues[] = null;
        continue;
      }
      $value = (float) $bucket['sum'] / (int) $bucket['count'];
      $dailyValues[] = $value;
      $values[] = $value;
    }
    if ($values === []) {
      continue;
    }
    $rows[$metricKey] = [
      'camera_slug' => $camera->slug(),
      'camera_title' => $camera->title(),
      'values' => $dailyValues,
      'min' => min($values),
      'max' => max($values),
      'avg' => array_sum($values) / count($values),
      'latest' => $latest[$metricKey]['value'] ?? end($values),
      'latest_ts' => $latest[$metricKey]['timestamp'] ?? null,
      'sample_count' => $sampleCounts[$metricKey] ?? 0,
      'day_count' => count($values),
      'definition' => $definition,
    ];
  }

  return [
    'source' => $aggregate !== null ? 'sqlite-numeric' : 'legacy-raw',
    'has_weather' => $rows !== [],
    'metrics' => $rows,
  ];
}

function weather_page_quality_table_exists(PDO $pdo): bool
{
  $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'weather_quality_flags' LIMIT 1");
  return $stmt !== false && $stmt->fetchColumn() !== false;
}

function weather_page_active_quality_from(PDO $pdo): ?int
{
  $stmt = $pdo->prepare(
    "SELECT MIN(c.slot_ts)
       FROM captures c
       JOIN weather_quality_flags q ON q.capture_id = c.id
      WHERE c.slot_ts >= :from_ts
        AND q.flag IN ('frozen_value', 'out_of_range')"
  );
  $stmt->execute([':from_ts' => time() - 86400]);
  $value = $stmt->fetchColumn();
  if ($value === false || $value === null) {
    return null;
  }

  $timestamp = (int) $value;
  return $timestamp > 0 ? $timestamp : null;
}

/**
 * @param array<string,array<string,mixed>> $metricDefinitions
 * @return array<string,float>
 */
function weather_page_extract_metrics(string $text, array $metricDefinitions): array
{
  return \Webcam\Service\Weather\WeatherMetrics::extract($text, $metricDefinitions);
}
