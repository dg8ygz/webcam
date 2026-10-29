<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Service\Upload\ChunkUploadStore;
use Webcam\Service\Upload\UploadIngestor;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';
$ingestor = new UploadIngestor($app);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload): never {
  http_response_code($status);
  echo json_encode($payload);
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  $respond(405, ['ok' => false, 'error' => 'POST required']);
}

$slug = isset($_POST['wc']) && is_string($_POST['wc']) ? $_POST['wc'] : '';
$camera = $slug !== '' ? $app->cameras()->find($slug) : null;
if (!$camera) {
  $respond(404, ['ok' => false, 'error' => 'camera not found']);
}

// Chunk-v1 akzeptiert Keys ausschliesslich im privaten X-Upload-Key-Header.
$authError = $ingestor->authenticationError($camera, $_SERVER, $_POST, $_GET, false);
if ($authError !== null) {
  $respond($authError['status'], $authError['payload']);
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$store = new ChunkUploadStore($app, $ingestor);

try {
  $result = match ($action) {
    'capabilities' => $store->capabilities(),
    'chunk' => $store->acceptChunk(
      $camera,
      $_POST,
      isset($_FILES['upload']) && is_array($_FILES['upload']) ? $_FILES['upload'] : null
    ),
    'finalize' => $store->finalize($camera, $_POST),
    default => ['status' => 400, 'payload' => ['ok' => false, 'error' => 'invalid chunk action']],
  };
} catch (Throwable $error) {
  $app->logger()->error('chunk upload failed', ['camera' => $slug, 'error' => $error->getMessage()]);
  $ingestor->log($slug, 'chunk_exception', ['error' => $error->getMessage()]);
  $result = ['status' => 500, 'payload' => ['ok' => false, 'error' => 'chunk upload failed']];
}

$respond($result['status'], $result['payload']);
