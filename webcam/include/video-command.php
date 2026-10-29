<?php
declare(strict_types=1);

// Video-only poll: no capture scheduling, no upload, no maintenance SSH lease.
// Reuses the camera's existing authentication without writing configuration DBs.
$app = require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$slug = $_POST['wc'] ?? '';
$key = $_SERVER['HTTP_X_COMMAND_KEY'] ?? '';
$camera = is_string($slug) && preg_match('/^[a-z0-9-]{1,64}$/D', $slug) ? $app->cameras()->find($slug) : null;
$expected = $camera ? (string) ($camera->capture()['upload_key'] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($key) || $expected === '' || !hash_equals($expected, $key)) {
  http_response_code(403);
  echo '{"error":"forbidden"}';
  exit;
}
try {
  $privacy=\Webcam\Service\Capture\CameraPrivacyState::forPaths($app->paths());
  $privacy->receive($slug,'video',(string)($_SERVER['HTTP_X_CAMERA_PRIVACY'] ?? ''));
  $command = \Webcam\Service\Stream\UsbStream::forPaths($app->paths())->command($slug);
  if ($privacy->videoBlocked($slug)) { $command['active']=false; $command['ttl']=0; }
  // Demand comes only from admitted viewers. The xx:x9 guard is for NEW
  // admissions, not a timed shutdown; actual photo priority is enforced on Pi.
  echo json_encode($command, JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
  http_response_code(503);
  echo '{"error":"video control unavailable"}';
  $app->logger()->warning('USB video control unavailable', ['camera' => $slug]);
}
