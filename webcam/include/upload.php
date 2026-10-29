<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Service\Upload\UploadIngestor;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';
$ingestor = new UploadIngestor($app);

header('Content-Type: application/json');

$respond = static function (int $status, array $payload): never {
  http_response_code($status);
  echo json_encode($payload);
  exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  $respond(405, ['ok' => false, 'error' => 'POST required']);
}

$slugValue = $_POST['wc'] ?? $_GET['wc'] ?? '';
$slug = is_string($slugValue) ? $slugValue : '';
$camera = $slug !== '' ? $app->cameras()->find($slug) : null;
if (!$camera) {
  $respond(404, ['ok' => false, 'error' => 'camera not found']);
}

$authError = $ingestor->authenticationError($camera, $_SERVER, $_POST, $_GET, true);
if ($authError !== null) {
  $respond($authError['status'], $authError['payload']);
}

// Früher Schutz, falls PHP wegen vollem tmp gar keine brauchbare $_FILES-Datei
// erzeugen könnte. Der Chunk-Endpunkt verwendet denselben Mindestpuffer.
$uploadTmp = ini_get('upload_tmp_dir');
$uploadTmp = $uploadTmp !== false && $uploadTmp !== '' ? (string) $uploadTmp : sys_get_temp_dir();
$freeTmp = disk_free_space($uploadTmp);
if ($freeTmp !== false && $freeTmp < 50 * 1024 * 1024) {
  $app->logger()->warning('upload tmp low space', [
    'camera' => $slug,
    'tmp_dir' => $uploadTmp,
    'free_bytes' => $freeTmp,
  ]);
  $ingestor->log($slug, 'upload_tmp_full', ['tmp_dir' => $uploadTmp, 'free_bytes' => $freeTmp]);
  $respond(507, ['ok' => false, 'error' => 'upload_tmp_full', 'message' => 'Upload-Zwischenspeicher voll']);
}

if (!isset($_FILES['upload']) || !is_array($_FILES['upload'])) {
  $ingestor->log($slug, 'missing upload');
  $respond(400, ['ok' => false, 'error' => 'missing upload']);
}

$uploadError = (int) ($_FILES['upload']['error'] ?? UPLOAD_ERR_OK);
if ($uploadError !== UPLOAD_ERR_OK) {
  $message = UploadIngestor::uploadErrorMessage($uploadError);
  $app->logger()->warning('upload error', ['camera' => $slug, 'code' => $uploadError, 'message' => $message]);
  $ingestor->log($slug, $message, ['code' => $uploadError]);
  $respond(400, ['ok' => false, 'error' => $message]);
}

$uploadSize = isset($_FILES['upload']['size']) && is_numeric($_FILES['upload']['size'])
  ? (int) $_FILES['upload']['size']
  : null;
if ($uploadSize !== null && $uploadSize > $ingestor->maxUploadBytes()) {
  $app->logger()->warning('upload too large', [
    'camera' => $slug,
    'bytes' => $uploadSize,
    'max_bytes' => $ingestor->maxUploadBytes(),
  ]);
  $ingestor->log($slug, 'file too large', ['bytes' => $uploadSize, 'max_bytes' => $ingestor->maxUploadBytes()]);
  $respond(413, ['ok' => false, 'error' => 'file too large']);
}

$temporaryName = $_FILES['upload']['tmp_name'] ?? null;
if (!is_string($temporaryName) || !is_uploaded_file($temporaryName)) {
  $ingestor->log($slug, 'missing upload');
  $respond(400, ['ok' => false, 'error' => 'missing upload']);
}
$timestampValue = $_SERVER['HTTP_X_CAPTURE_TIMESTAMP']
  ?? $_SERVER['HTTP_X_UPLOAD_TIMESTAMP']
  ?? $_POST['now']
  ?? null;
if (isset($_GET['now'])) {
  $app->logger()->warning('upload query timestamp ignored', ['camera' => $slug]);
  $ingestor->log($slug, 'query_timestamp_ignored');
}

try {
  $timestamp = $ingestor->parseTimestamp($timestampValue);
  $capturedAt = $ingestor->parseCapturedAt($_POST['captured_at'] ?? null);
} catch (InvalidArgumentException) {
  $respond(400, ['ok' => false, 'error' => 'invalid capture timestamp']);
}
$result = $ingestor->ingestFile($camera, $temporaryName, $timestamp, $_POST['local_weather'] ?? null, $capturedAt);
$respond($result['status'], $result['payload']);
