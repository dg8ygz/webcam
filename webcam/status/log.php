<?php
declare(strict_types=1);

use Webcam\Infrastructure\Security\SecretRedactor;

$app = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/admin_auth.php';

// Authentifizierte Rohlog-Ausgabe genau einer Kamera. Slug, Kameraexistenz und
// daraus abgeleiteter Logpfad werden geprüft; beliebige Pfade sind nicht möglich.
// Ein Test-Bypass gilt nur in WEBCAM_ENV=test vom Loopback-Host.
if (!statusLogTestBypassAllowed() && !adminIsAuthorized($app)) {
  adminSendAuthChallenge();
  http_response_code(401);
  echo "Unauthorized.";
  exit;
}

$slug = isset($_GET['cam']) ? strtolower(trim((string) $_GET['cam'])) : '';
if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
  http_response_code(400);
  echo "Ungültiger Kameraschlüssel.";
  exit;
}

$camera = $app->cameras()->find($slug);
if ($camera === null) {
  http_response_code(404);
  echo "Kamera nicht gefunden.";
  exit;
}

$logDir = $app->paths()->logPath('capture');
$logFile = $logDir . DIRECTORY_SEPARATOR . $slug . '.log';
if (!is_file($logFile)) {
  http_response_code(404);
  echo "Noch keine Logdaten verfügbar.";
  exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Disposition: attachment; filename="' . $slug . '-capture.log"');
$handle = fopen($logFile, 'rb');
if (!is_resource($handle)) {
  http_response_code(500);
  echo "Logdatei konnte nicht gelesen werden.";
  exit;
}
while (($line = fgets($handle)) !== false) {
  echo SecretRedactor::redact($line);
}
fclose($handle);

function statusLogTestBypassAllowed(): bool
{
  $isTagged = (($_SERVER['HTTP_X_WC_TEST'] ?? '') === '1')
    || ((isset($_GET['test']) && $_GET['test'] === '1'));
  if (!$isTagged) {
    return false;
  }

  $env = strtolower(trim((string) (getenv('WEBCAM_ENV') ?: '')));
  if ($env !== 'test') {
    return false;
  }

  $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
  return $remote === '' || in_array($remote, ['127.0.0.1', '::1'], true);
}
