<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

// Read-only Statusseite: State jeder Kamera laden, Alter gegen das konfigurierte
// Intervall bewerten und die fertigen Severity-Zeilen an templates/status.php
// übergeben. Ein letzter Erfolg wird nicht als Beweis für lückenlose Slots gewertet.
$statusService = $app->statusService();
$rows = [];
$now = time();

foreach ($app->cameras()->listed() as $camera) {
  $state = $statusService->state($camera);
  $interval = (int)($camera->capture()['interval_seconds'] ?? 600);
  $lastSuccess = isset($state['last_success_at']) ? (int)$state['last_success_at'] : null;
  $lastCapture = isset($state['last_capture_at']) ? (int)$state['last_capture_at'] : null;
  $age = $lastSuccess ? $now - $lastSuccess : null;

  $severity = 'unknown';
  if ($age === null) {
    $severity = 'unknown';
  } elseif ($age <= $interval * 2) {
    $severity = 'ok';
  } elseif ($age <= $interval * 3) {
    $severity = 'warn';
  } else {
    $severity = 'error';
  }
  if (($state['weather_quality']['status'] ?? 'ok') === 'error') {
    $severity = 'error';
  }
  if (!empty($state['duplicate_active'])) {
    $severity = 'error';
  }

  $rows[] = [
    'camera' => $camera,
    'state' => $state,
    'age' => $age,
    'severity' => $severity,
    'last_capture' => $lastCapture,
    'last_success' => $lastSuccess,
    'interval' => $interval,
  ];
}

usort($rows, static function (array $a, array $b): int {
  return strcmp($a['camera']->title(), $b['camera']->title());
});

$meta = $app->camerasMeta();
$heroText = (string) ($meta['overview_hero'] ?? $app->config('overview_hero', ''));
$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);

echo $app->renderer()->render('status', [
  'app' => $app,
  'pageTitle' => 'Webcam Status',
  'bodyClass' => 'page-status',
  'mainClass' => 'main-content main-content--status',
  'rows' => $rows,
  'modalCamera' => null,
  'heroText' => $heroText,
  'relativePrefix' => $relativePrefix,
  'styles' => ['vendor/bootstrap/bootstrap.min.css', 'vendor/fontawesome/css/all.min.css'],
  'scripts' => ['vendor/bootstrap/bootstrap.bundle.min.js', 'js/status.js'],
]);
