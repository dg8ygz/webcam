<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/include/api_helpers.php';
require_once __DIR__ . '/include/like_request_guard.php';

use Webcam\Service\Interaction\LikeService;

// Legacy-Like-Endpunkt: Request und Bildreferenz prüfen, danach denselben
// Captcha-/IP-Schutz wie beim aktuellen Best-Of-Endpunkt erzwingen. Erst dann
// darf LikeService Sessionduplikat, fachliches Fenster und DB-Write ausführen.
// Best-Of-Dateien werden weiterhin ausschließlich in bestof-toggle.php gebaut.
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt']);
  return;
}

if (!require_public_csrf($app)) {
  return;
}

$isTestMode = public_test_bypass_allowed();

$input = file_get_contents('php://input');
$data = json_decode($input ?: '', true);
if (!is_array($data)) {
  $data = $_POST;
}

$slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Slug fehlt']);
  return;
}

try {
  $camera = $app->cameraOrFail($slug);
} catch (RuntimeException) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Kamera nicht gefunden']);
  return;
}

$relativePath = isset($data['path']) ? (string) $data['path'] : null;
if ($relativePath !== null) {
  $normalized = normalize_relative_path($relativePath);
  if ($normalized === null || !pathIncludesSegment($normalized, $slug)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Pfad']);
    return;
  }
  $relativePath = $normalized;
}
$slotTs = isset($data['slot_ts']) && is_numeric($data['slot_ts']) ? (int) $data['slot_ts'] : null;
if ($relativePath === null && $slotTs === null) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Parameter path oder slot_ts erforderlich']);
  return;
}

if (!require_public_like_protection($app, $data)) {
  return;
}

// Der lokale HTTP-Vertragstest muss das IP-Budget ausloesen koennen, darf aber
// weder eine Live-Kamera-DB oeffnen noch einen Like schreiben. Der Bypass ist
// in api_helpers.php strikt auf WEBCAM_ENV=test und Loopback begrenzt.
if ($isTestMode) {
  echo json_encode([
    'ok' => true,
    'likes_count' => 1,
    'session_id' => 'test-session',
    'capture_relative_path' => $relativePath,
    'test' => true,
  ]);
  return;
}

$likeService = new LikeService($app);
$sessionId = $likeService->ensureSessionId();

$result = $likeService->like($camera, $sessionId, [
  'relative_path' => $relativePath,
  'slot_ts' => $slotTs,
]);

if (!$result['ok']) {
  http_response_code($result['code'] === 'not_found' ? 404 : 429);
  echo json_encode(['ok' => false, 'error' => $result['message'] ?? 'Fehler', 'code' => $result['code'] ?? null]);
  return;
}

echo json_encode([
  'ok' => true,
  'likes_count' => $result['likes_count'] ?? null,
  'session_id' => $sessionId,
  'capture_relative_path' => $result['relative_path'] ?? null,
]);
