<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';

// Kleine eigenständige Einbettungsansicht: Kamera prüfen, aktuelles Presenter-
// Bild und Aufnahmezeit laden, relative URLs für die aktuelle Mount-Tiefe bauen
// und minimales HTML ohne die vollständige Kamera-Timeline ausgeben.
$slug = $_GET['cam'] ?? $_GET['slug'] ?? null;
if (!is_string($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
  http_response_code(404);
  echo 'Camera not found';
  exit;
}

$camera = $app->cameras()->find($slug);
if ($camera === null) {
  http_response_code(404);
  echo 'Camera not found';
  exit;
}

$data = $app->cameraPresenter()->overviewData($camera);
$title = (string) ($data['title'] ?? $camera->title());
$imageUrlRaw = (string) ($data['current_image_url'] ?? '');
$fullUrlRaw = (string) ($data['full_image_url'] ?? $imageUrlRaw);
$lastCapture = $data['last_capture'] ?? null;
$timezone = (string) $app->config('timezone', 'Europe/Berlin');
$timestamp = $lastCapture instanceof DateTimeImmutable
  ? $lastCapture->setTimezone(new DateTimeZone($timezone))->format('d.m.Y H:i')
  : 'unbekannt';

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);
$basePrefix = $basePathValue === '' ? '' : '/' . $basePathValue;
$normalizeAsset = static function (string $path) use ($relativePrefix, $basePrefix): string {
  if ($path === '') {
    return '';
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  if ($path[0] === '/') {
    if ($basePrefix !== '' && str_starts_with($path, $basePrefix . '/')) {
      return $relativePrefix . ltrim(substr($path, strlen($basePrefix)), '/');
    }
    return $path;
  }
  return $relativePrefix . ltrim($path, '/');
};
$imageUrl = $normalizeAsset($imageUrlRaw);
$fullUrl = $normalizeAsset($fullUrlRaw);
$detailUrl = $relativePrefix . 'camera/' . rawurlencode($slug);

if (!headers_sent()) {
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: public, max-age=60, stale-while-revalidate=60');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <meta http-equiv="refresh" content="120">
  <title><?= htmlspecialchars($title) ?> Webcam</title>
  <style>
    :root {
      color-scheme: dark;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #0b1118;
      color: #fff;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; background: #0b1118; }
    .embed {
      display: block;
      position: relative;
      width: 100%;
      height: 100vh;
      min-height: 180px;
      color: inherit;
      text-decoration: none;
      overflow: hidden;
      background: #0b1118;
    }
    .embed__image {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
      background: #0b1118;
    }
    .embed__meta {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 12px;
      background: linear-gradient(180deg, rgba(11, 17, 24, 0), rgba(11, 17, 24, 0.88));
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.8);
    }
    .embed__title {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: 0.95rem;
      font-weight: 700;
    }
    .embed__time {
      flex: 0 0 auto;
      font-size: 0.82rem;
      opacity: 0.9;
    }
    .embed__empty {
      display: grid;
      min-height: 220px;
      place-items: center;
      padding: 24px;
      text-align: center;
      color: rgba(255, 255, 255, 0.78);
    }
    @media (max-width: 420px) {
      .embed__meta {
        align-items: flex-start;
        flex-direction: column;
        gap: 2px;
      }
      .embed__title {
        white-space: normal;
      }
    }
  </style>
</head>
<body>
  <a class="embed" href="<?= htmlspecialchars($detailUrl) ?>" target="_blank" rel="noopener">
    <?php if ($imageUrl !== ''): ?>
      <img
        class="embed__image"
        src="<?= htmlspecialchars($imageUrl) ?>"
        <?php if ($fullUrl !== '' && $fullUrl !== $imageUrl): ?>
          srcset="<?= htmlspecialchars($imageUrl) ?> 800w, <?= htmlspecialchars($fullUrl) ?> 1600w"
          sizes="100vw"
        <?php endif; ?>
        alt="Webcam <?= htmlspecialchars($title) ?>"
        loading="eager">
    <?php else: ?>
      <div class="embed__empty">Aktuell ist kein Bild verfügbar.</div>
    <?php endif; ?>
    <div class="embed__meta">
      <div class="embed__title"><?= htmlspecialchars($title) ?></div>
      <div class="embed__time"><?= htmlspecialchars($timestamp) ?></div>
    </div>
  </a>
</body>
</html>
