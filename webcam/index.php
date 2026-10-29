<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';

// Öffentliche Übersicht: Presenter-Daten aller Kameras laden, daraus Marker und
// Sichtfelder bauen und templates/overview.php rendern. Diese Seite liest nur;
// relative Prefixe halten Assets und Kameralinks proxy-/mount-kompatibel.
$presenter = $app->cameraPresenter();
$cameras = $presenter->overviewList();

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);
$basePrefix = $basePathValue === '' ? '' : '/' . $basePathValue;

$meta = $app->camerasMeta();
$mapConfig = $meta['overview_map'] ?? [
  'lat' => 51.96,
  'lng' => 8.53,
  'zoom' => 11,
  'height_px' => 220,
];

$normalizeMapAsset = static function (?string $path) use ($relativePrefix, $basePrefix): string {
  $path = is_string($path) ? trim($path) : '';
  if ($path === '') {
    return '';
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  if ($path[0] === '/' && $basePrefix !== '' && str_starts_with($path, $basePrefix . '/')) {
    return $relativePrefix . ltrim(substr($path, strlen($basePrefix)), '/');
  }
  return $relativePrefix . ltrim($path, '/');
};

$overviewBySlug = [];
foreach ($cameras as $camera) {
  $slug = (string) ($camera['slug'] ?? '');
  if ($slug !== '') {
    $overviewBySlug[$slug] = $camera;
  }
}

$mapMarkers = [];
foreach ($app->cameras()->listed() as $camera) {
  $location = $camera->location();
  $overview = $overviewBySlug[$camera->slug()] ?? [];
  $lastCapture = $overview['last_capture'] ?? null;
  $mapMarkers[] = [
    'slug' => $camera->slug(),
    'title' => $camera->title(),
    'lat' => $location['latitude'] ?? 0,
    'lng' => $location['longitude'] ?? 0,
    'bearing' => $location['orientation_deg'] ?? 0,
    'fov' => $location['fov_deg'] ?? ($location['fov'] ?? null),
    'thumbUrl' => $normalizeMapAsset($overview['current_image_url'] ?? ''),
    'lastCapture' => $lastCapture instanceof DateTimeImmutable ? $lastCapture->format('d.m.Y H:i') : '',
  ];
}


echo $app->renderer()->render('overview', [
  'app' => $app,
  'pageTitle' => 'Webcam-Übersicht',
  'bodyClass' => 'page-overview',
  'cameras' => $cameras,
  // Leaflet wird vom gemeinsamen Layout vor overview.js geladen. Marker und
  // Karteneinstellungen gelangen als JSON-Daten ins Template; ausführbarer
  // JavaScript-Code wird hier nicht mehr aus PHP-Strings zusammengesetzt.
  'enableLeaflet' => true,
  // Kleine, verantwortungsbezogene Module werden vor dem schlanken
  // Seitencontroller geladen; es ist kein Bundler oder Build-Schritt nötig.
  'scripts' => [
    'js/overview-map.js',
    'js/overview-recent.js',
    'js/overview-cards.js',
    'js/overview-lightbox.js',
    'js/overview.js',
  ],
  'mapMarkers' => $mapMarkers,
]);
