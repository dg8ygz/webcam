<?php
declare(strict_types=1);
$app = require __DIR__ . '/bootstrap.php';
$prefix = webcam_document_relative_prefix(trim((string) $app->config('base_url', ''), '/'));
$listed = $app->cameras()->listed();
// As on the existing camera page, a known direct test-camera link may use it;
// public navigation must never enumerate hidden cameras.
$requested = is_string($_GET['camera'] ?? null) ? $app->cameras()->find($_GET['camera']) : null;
if ($requested !== null && $requested->isTest()) { $listed[] = $requested; }
$cameras = array_map(static fn($camera): array => [
  'slug' => $camera->slug(), 'title' => $camera->title(),
], $listed);
echo $app->renderer()->render('explore', [
  'app' => $app, 'pageTitle' => 'Mehrkamera', 'bodyClass' => 'page-explore',
  'cameras' => $cameras, 'relativePrefix' => $prefix,
  'timezone' => (string) $app->config('timezone', 'Europe/Berlin'),
  'styles' => ['css/explore.css'], 'scripts' => ['js/explore.js'],
]);
