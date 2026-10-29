<?php
declare(strict_types=1);

use Webcam\Service\Interaction\BestOfAssetManager;
use Webcam\Service\Interaction\BestOfCaptureRegistrar;
use Webcam\Service\Interaction\BestOfLikeCoordinator;
use Webcam\Service\Interaction\BestOfSourceResolver;
use Webcam\Service\Interaction\LikeService;

$app = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/include/api_helpers.php';
require_once __DIR__ . '/include/like_request_guard.php';

// Kombinierter Best-Of-/Like-Endpunkt: CSRF, Captcha, Kamera und den streng
// erlaubten Bildpfad prüfen. Danach hält BestOfLikeCoordinator Dateikopie und
// DB-Like auch bei Parallelzugriffen möglichst konsistent.
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Nur POST-Anfragen erlaubt.']);
  return;
}

if (!require_public_csrf($app)) {
  return;
}

$isTestMode = public_test_bypass_allowed();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
  $data = $_POST;
}

$slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
$relativePath = isset($data['path']) ? trim((string) $data['path']) : '';

if ($slug === '' || $relativePath === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter.']);
  return;
}

try {
  $camera = $app->cameraOrFail($slug);
} catch (RuntimeException $exception) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Kamera nicht gefunden.']);
  return;
}

$normalizedPath = normalizeRelativePath($relativePath);
if ($normalizedPath === null) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Ungültiger Dateipfad.']);
  return;
}

$sourceResolver = new BestOfSourceResolver($app);
$normalizedPath = $sourceResolver->mapBestOfToCapture($camera, $normalizedPath);
if (!$sourceResolver->belongsToCamera($normalizedPath, $camera->slug())) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Pfad stimmt nicht mit der Kamera überein.']);
  return;
}

$resolvedSource = $sourceResolver->resolveSource($camera, $normalizedPath);
if ($resolvedSource === null) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Quelldatei nicht gefunden.']);
  return;
}
$sourcePath = $resolvedSource['source_path'];
$normalizedPath = $resolvedSource['relative_path'];

if (!preg_match('/\.(jpe?g)$/i', $sourcePath)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Nur JPEG-Dateien können markiert werden.']);
  return;
}

if (!require_public_like_protection($app, $data)) {
  return;
}

// Testmodus: Schutzadapter durchlaufen, aber keine FS-/DB-Schreibzugriffe.
// Ohne X-Session-ID ueberspringt die Testumgebung das Budget wie bisher; der
// gezielte Security-Test kann es mit diesem Header ausdruecklich aktivieren.
if ($isTestMode) {
  echo json_encode([
    'ok' => true,
    'active' => true,
    'path' => $normalizedPath,
    'likes_count' => 1,
    'session_id' => 'test-session',
    'test' => true,
  ]);
  return;
}

// Das Bild wird unter einem bildbezogenen Lock atomar bereitgestellt, bevor der
// Like festgeschrieben wird. Bei einer abgelehnten DB-Operation räumt der
// Coordinator nur eine von diesem Request neu angelegte Datei wieder auf.
$likeService = new LikeService($app);
$sessionId = $likeService->ensureSessionId();
$coordinator = new BestOfLikeCoordinator($app);
$captureRegistrar = new BestOfCaptureRegistrar($app);
try {
  $publication = $coordinator->execute(
    $camera,
    $sourcePath,
    static function () use ($camera, $likeService, $captureRegistrar, $normalizedPath, $sessionId, $sourcePath): array {
      $likeResult = $likeService->like($camera, $sessionId, ['relative_path' => $normalizedPath]);
      if (!($likeResult['ok'] ?? false) && ($likeResult['code'] ?? null) === 'not_found') {
        // Capture fehlt in DB -> aus Pfad ableiten und erneut versuchen
        $captureId = $captureRegistrar->ensure($camera, $normalizedPath, $sourcePath);
        if ($captureId !== null) {
          $likeResult = $likeService->like($camera, $sessionId, ['relative_path' => $normalizedPath]);
        }
      }
      return $likeResult;
    }
  );
} catch (\Throwable $exception) {
  $app->logger()->warning('bestof publication failed', [
    'camera' => $camera->slug(),
    'source' => $sourcePath,
    'error' => $exception->getMessage(),
  ]);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Best-Of-Datei konnte nicht sicher angelegt werden.']);
  return;
}

$likeResult = $publication['result'];
$targetDir = $publication['target_dir'];
$targetPath = $publication['target_path'];
$baseName = $publication['base_name'];
$active = true;

if ($publication['created']) {
  (new BestOfAssetManager($app))->finish(
    $camera,
    $sourcePath,
    $targetDir,
    $targetPath,
    $baseName
  );
}

if (!($likeResult['ok'] ?? false)) {
  $code = $likeResult['code'] ?? null;
  if ($code === 'duplicate_image') {
    // Bereits geliked: als 200 antworten, damit kein 429 im UI-Log erscheint
    echo json_encode(['ok' => false, 'error' => $likeResult['message'] ?? 'Bereits geliked', 'code' => $code]);
    return;
  }
  http_response_code($code === 'rate_limited' ? 429 : (($code === 'not_found') ? 404 : 429));
  echo json_encode(['ok' => false, 'error' => $likeResult['message'] ?? 'Like fehlgeschlagen', 'code' => $code]);
  return;
}

echo json_encode([
  'ok' => true,
  'active' => $active,
  'path' => $normalizedPath,
  'likes_count' => $likeResult['likes_count'] ?? null,
  'session_id' => $sessionId,
]);
