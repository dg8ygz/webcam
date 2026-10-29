<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Infrastructure\Security\SecretRedactor;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';

// Schreibender Helper-Endpunkt: Kamera/Key prüfen, Eingabe auf Länge und
// Steuerzeichen begrenzen und genau eine Zeile an das rotierende Kamera-Log
// anhängen. Freie Dateipfade oder fremde Logziele sind nicht erlaubt.
header('Content-Type: text/plain; charset=UTF-8');

const CAMERA_LOG_MAX_BYTES = 262144;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo "POST required\n";
  exit;
}

$slug = isset($_POST['wc'])
  ? trim((string) $_POST['wc'])
  : trim((string) ($_GET['wc'] ?? ''));
if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
  http_response_code(400);
  echo "invalid camera\n";
  exit;
}

$camera = $app->cameras()->find($slug);
if (!$camera) {
  http_response_code(404);
  echo "camera not found\n";
  exit;
}

$expected = (string) ($camera->capture()['upload_key'] ?? '');
$key = '';
if (isset($_SERVER['HTTP_X_UPLOAD_KEY']) && is_string($_SERVER['HTTP_X_UPLOAD_KEY'])) {
  $key = trim((string) $_SERVER['HTTP_X_UPLOAD_KEY']);
} elseif (isset($_POST['key']) && is_string($_POST['key'])) {
  $key = trim((string) $_POST['key']);
} elseif (isset($_GET['key'])) {
  http_response_code(400);
  appendCameraLog($app, $slug, '-', 'query_key_rejected');
  echo "query key not allowed\n";
  exit;
}

if ($expected === '' || !hash_equals($expected, $key)) {
  http_response_code(403);
  appendCameraLog($app, $slug, '-', 'forbidden');
  echo "forbidden\n";
  exit;
}

$serial = sanitizeCameraLogToken((string) ($_POST['serial'] ?? ''));
$content = readUploadedCameraLog();
if ($content === false) {
  http_response_code(413);
  appendCameraLog($app, $slug, $serial, 'log too large');
  echo "log too large\n";
  exit;
}

if (!appendCameraLog($app, $slug, $serial, normalizeCameraLogContent(str_replace($expected, '[REDACTED]', $content)))) {
  http_response_code(500);
  echo "log write failed\n";
  exit;
}
echo "ok\n";

/**
 * @return string|false false means the uploaded log exceeded the accepted size.
 */
function readUploadedCameraLog(): string|false
{
  if (!isset($_FILES['log']) || !is_array($_FILES['log'])) {
    return '';
  }

  $error = (int) ($_FILES['log']['error'] ?? UPLOAD_ERR_OK);
  if ($error === UPLOAD_ERR_NO_FILE) {
    return '';
  }
  if ($error !== UPLOAD_ERR_OK) {
    return 'upload_error_' . $error;
  }

  $size = isset($_FILES['log']['size']) && is_numeric($_FILES['log']['size'])
    ? (int) $_FILES['log']['size']
    : null;
  if ($size !== null && $size > CAMERA_LOG_MAX_BYTES) {
    return false;
  }

  $tmp = isset($_FILES['log']['tmp_name']) ? (string) $_FILES['log']['tmp_name'] : '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return '';
  }

  $content = file_get_contents($tmp, false, null, 0, CAMERA_LOG_MAX_BYTES + 1);
  if ($content === false) {
    return '';
  }
  if (strlen($content) > CAMERA_LOG_MAX_BYTES) {
    return false;
  }

  return $content;
}

function sanitizeCameraLogToken(string $value): string
{
  $value = preg_replace('/[^A-Za-z0-9_.:-]/', '', trim($value)) ?? '';
  return $value !== '' ? $value : '-';
}

function normalizeCameraLogContent(string $content): string
{
  $content = SecretRedactor::redact($content);
  $content = str_replace(["\r", "\n", "\t"], ' ', $content);
  $content = preg_replace('/[[:cntrl:]]+/', ' ', $content) ?? $content;
  $content = preg_replace('/ {2,}/', ' ', $content) ?? $content;
  $content = trim($content);

  return $content !== '' ? $content : '-';
}

function appendCameraLog(App $app, string $slug, string $serial, string $content): bool
{
  $line = sprintf(
    "[%s] wc=%s serial=%s %s\n",
    date('c'),
    $slug,
    $serial !== '' ? $serial : '-',
    $content !== '' ? $content : '-'
  );

  $dest = $app->paths()->logPath('upload.log');
  if (file_put_contents($dest, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
    $app->logger()->warning('camera log write failed', ['path' => $dest, 'camera' => $slug]);
    return false;
  }
  return true;
}
