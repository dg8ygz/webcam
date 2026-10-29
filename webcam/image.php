<?php
declare(strict_types=1);

use Webcam\Service\Storage\DeferredImages;

/** Freigegebenes Archiv-JPEG. Keine Dateinamen/Filter aus dem Request uebernehmen. */
require_once __DIR__ . '/autoload.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
  http_response_code(405); header('Allow: GET, HEAD'); exit;
}
$relative = $_GET['image'] ?? null;
$parts = is_string($relative) ? DeferredImages::parse($relative) : null;
$legacy = $_GET['legacy'] ?? null;
if ($parts === null && !is_string($legacy)) { http_response_code(404); exit; }
try {
  $app ??= require __DIR__ . '/bootstrap.php';
  if ($parts === null) {
    $relative = $app->deferredImages()->fromLegacyPath($legacy);
    $parts = $relative !== null ? DeferredImages::parse($relative) : null;
  }
  if ($parts === null) { http_response_code(404); exit; }
  $camera = $app->cameras()->find($parts[0]);
  if (!$camera) { http_response_code(404); exit; }
  if (!$app->deferredImages()->available($camera, $relative)) { http_response_code(404); exit; }
  $path = $app->deferredImages()->resolve($camera, $relative, true);
  $handle = fopen($path, 'rb');
  if (!$handle) throw new RuntimeException('Rendered image unavailable');
  try {
    $stat = fstat($handle);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . $stat['size']);
    header('Cache-Control: public, max-age=86400, immutable');
    if ($method !== 'HEAD') fpassthru($handle);
  } finally { fclose($handle); }
} catch (Throwable $error) {
  if (isset($app)) $app->logger()->warning('deferred image unavailable', ['error' => $error->getMessage()]);
  http_response_code(503); header('Retry-After: 5'); header('Cache-Control: no-store');
}
