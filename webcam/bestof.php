<?php
declare(strict_types=1);

/**
 * Globale Best-Of-Seite: fuehrt die vorhandenen Galerien aller Kameras
 * chronologisch zusammen, ohne Bilder zu kopieren oder eine globale DB zu bauen.
 */

$app = require __DIR__ . '/bootstrap.php';
$cameras = $app->cameras()->listed();
$perPage = 48;
$selectedYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
$selectedCamera = isset($_GET['camera']) ? trim((string) $_GET['camera']) : null;
$selectedCamera = $selectedCamera !== '' ? $selectedCamera : null;
if ($selectedCamera !== null && $app->cameras()->findListed($selectedCamera) === null) {
  http_response_code(404);
  echo $app->renderer()->render('error', [
    'app' => $app,
    'pageTitle' => 'Kamera nicht gefunden',
    'message' => 'Die gewählte Kamera existiert nicht.',
  ]);
  exit;
}

$bestOfPage = $app->bestOfGallery()->globalPage(
  $cameras,
  1,
  $perPage,
  $selectedYear,
  $selectedCamera
);

header('Cache-Control: no-store, no-cache, must-revalidate');
echo $app->renderer()->render('bestof', [
  'app' => $app,
  'pageTitle' => 'Globales Best Of',
  'bodyClass' => 'page-global-bestof',
  'mainClass' => 'main-content main-content--global-bestof',
  'scripts' => ['js/global-bestof.js'],
  'bestOfPage' => $bestOfPage,
  'cameraOptions' => $cameras,
  'selectedYear' => $selectedYear,
  'selectedCamera' => $selectedCamera,
  'perPage' => $perPage,
]);
