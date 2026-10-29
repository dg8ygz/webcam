<?php
declare(strict_types=1);

/**
 * Geschützte Overlay-Auslieferung.
 *
 * Slug und Dateiname dürfen keine Verzeichnisse enthalten. Nach realpath werden
 * nur Dateien innerhalb cache/<slug>/overlays/ ausgeliefert; ein ausdrücklich
 * begrenzter assets/Legacy-Pfad bleibt als Alt-Fallback. MIME, CSP und nosniff
 * werden serverseitig gesetzt.
 */

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';

if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
  http_response_code(404);
  exit;
}

// allow only safe filenames (no directories)
if ($file === '' || preg_match('#[\\/]|\\.{2}#', $file)) {
  http_response_code(404);
  exit;
}

$allowedExt = ['png', 'jpg', 'jpeg'];
$ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
  http_response_code(415);
  exit;
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/bootstrap.php';
$primaryPath = $app->paths()->cachePath($slug . '/overlays/' . $file);
$legacyPath = __DIR__ . '/assets/overlays/' . $slug . '/' . $file;
$path = is_file($primaryPath) ? $primaryPath : $legacyPath;

if (!is_file($path)) {
  http_response_code(404);
  exit;
}

$realPath = realpath($path);
$allowedBases = array_filter([
  realpath($app->paths()->cachePath($slug . '/overlays')),
  realpath(__DIR__ . '/assets/overlays/' . $slug),
]);
$allowed = false;
if ($realPath !== false) {
  foreach ($allowedBases as $base) {
    if ($base !== false && str_starts_with($realPath, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
      $allowed = true;
      break;
    }
  }
}
if (!$allowed) {
  http_response_code(404);
  exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $path) : '';
if ($finfo) {
  finfo_close($finfo);
}

// Only serve bitmap mime types we expect
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
  http_response_code(415);
  exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'none'; script-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: public, max-age=31536000, immutable');
header('Cross-Origin-Resource-Policy: same-origin');

$fp = fopen($path, 'rb');
if ($fp === false) {
  http_response_code(500);
  exit;
}
fpassthru($fp);
fclose($fp);
