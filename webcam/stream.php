<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Domain\Enums\CameraStreamMode;
use Webcam\Domain\Enums\CaptureTransport;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Security\SecretRedactor;
use Webcam\Service\Stream\RtspInput;
use Webcam\Service\Stream\StreamState;

require_once __DIR__ . '/src/Service/Stream/hls_functions.php';
require_once __DIR__ . '/src/Service/Stream/mjpeg_functions.php';
require_once __DIR__ . '/src/Service/Stream/usb_functions.php';

// Ablauf dieses Endpunkts:
// 1. Kamera und RTSP-/ffmpeg-Konfiguration prüfen.
// 2. Clientidentität, Sperren und eine mögliche Admin-Trennung prüfen.
// 3. Je nach format MJPEG direkt ausgeben oder den geteilten HLS-Runner nutzen.
// 4. Viewer-State und Prozesse auch bei Abbruch zuverlässig freigeben.
// Ein probe=1-Aufruf prüft nur Verfügbarkeit/Belegung und hält keinen MJPEG-Slot.

/** @var App $app */
$app = require __DIR__ . '/bootstrap.php';
$logger = $app->logger();
$streamState = new StreamState($app);

$slug = '';
if (isset($_GET['slug'])) {
  $slug = trim((string) $_GET['slug']);
}
if ($slug === '' && isset($_SERVER['PATH_INFO'])) {
  $slug = trim((string) $_SERVER['PATH_INFO'], '/');
}

if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
  http_response_code(404);
  exit;
}

$camera = $app->cameras()->find($slug);
if (!$camera) {
  http_response_code(404);
  exit;
}

// Unlike xx:x9 new-start admission, a privacy closing deadline applies to
// every media request, including cached segments and already admitted viewers.
$usb = \Webcam\Service\Stream\UsbStream::forPaths($app->paths());
$diagnostic = usb_stream_diagnostic($usb, $logger, $slug);
try {
  if (\Webcam\Service\Capture\CameraPrivacyState::forPaths($app->paths())->videoBlocked($slug)) {
    if ($diagnostic) { $diagnostic('privacy_blocked', ['status' => 503]); }
    http_response_code(503); header('Cache-Control: no-store'); header('Retry-After: 30');
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'ready'=>false,'message'=>'Aufnahmefenster geschlossen: Livestream gesperrt.']);
    exit;
  }
} catch (\Throwable) {
  if ($diagnostic) { $diagnostic('privacy_unavailable', ['status' => 503]); }
  fail($logger,503,'camera privacy unavailable',['camera'=>$slug]);
}

try { $usbEnabled = $usb->registration($slug) !== null; }
catch (\Throwable $error) { fail($logger, 503, 'USB registration invalid', ['camera' => $slug]); }
$rtspUrl = resolve_rtsp_url($camera);
if (!$usbEnabled && $rtspUrl === null) {
  fail($logger, 404, 'stream rtsp not configured', ['camera' => $slug]);
}

$ffmpeg = $usbEnabled ? '' : resolve_ffmpeg_path($logger);
$maskedUrl = $usbEnabled ? '' : mask_rtsp_url($rtspUrl);
$streamConfig = $camera->stream();
$transport = trim((string) getenv('WEBCAM_RTSP_TRANSPORT'));
if ($transport === '') {
  $transport = 'tcp';
}
$fps = isset($streamConfig['fps']) ? (int) $streamConfig['fps'] : 0;
if ($fps <= 0 || $fps > 30) {
  $fps = (int) getenv('WEBCAM_STREAM_FPS');
  if ($fps <= 0 || $fps > 30) {
    $fps = 3;
  }
}
$quality = isset($streamConfig['quality']) ? (int) $streamConfig['quality'] : 0;
if ($quality < 2 || $quality > 31) {
  $quality = (int) getenv('WEBCAM_STREAM_QUALITY');
  if ($quality < 2 || $quality > 31) {
    $quality = 5;
  }
}
$maxClients = resolve_stream_limit($streamConfig, 'max_clients', 'WEBCAM_STREAM_MAX_CLIENTS', 2, 1, 20);
$maxRuntimeSeconds = resolve_stream_limit($streamConfig, 'max_runtime_seconds', 'WEBCAM_STREAM_MAX_SECONDS', 1800, 30, 86400);
$hlsIdleTimeoutSeconds = resolve_stream_limit($streamConfig, 'hls_idle_timeout_seconds', 'WEBCAM_HLS_IDLE_TIMEOUT_SECONDS', 45, 30, 300);
$isProbeRequest = isset($_GET['probe']) && (string) $_GET['probe'] === '1';
$clientIdentity = $streamState->clientIdentity($_SERVER);
if ($clientIdentity['ip'] !== '' && $streamState->isBlockedIp($clientIdentity['ip'])) {
  deny_stream_request($isProbeRequest, $slug, 'Livestream ist für diese IP gesperrt.');
}
if ($streamState->identityDisconnectRequested($slug, $_SERVER)) {
  deny_stream_request($isProbeRequest, $slug, 'Livestream-Verbindung wurde getrennt.');
}
$format = strtolower(trim((string) ($_GET['format'] ?? 'mjpeg')));
if ($usbEnabled) {
  if ($format !== 'hls') { http_response_code(400); exit; }
  $windowPolicy = new \Webcam\Service\Stream\UsbStreamWindow($app->paths());
  $windowForViewer = static fn(bool $continuing): array => $windowPolicy->status($slug, null, $continuing);
  try {
    handle_usb_hls_request($usb, $streamState, $slug, $isProbeRequest, $maxClients, $windowForViewer, $diagnostic);
  } catch (\Throwable $error) {
    if ($diagnostic) { $diagnostic('readiness_unavailable', ['status' => 503]); }
    fail($logger, 503, 'USB capture readiness unavailable', ['camera' => $slug]);
  }
  exit;
}
if ($format === 'hls') {
  handle_hls_request($app, $logger, $streamState, $slug, $rtspUrl, $maskedUrl, $ffmpeg, $transport, $fps, $quality, $maxRuntimeSeconds, $hlsIdleTimeoutSeconds, $isProbeRequest);
  exit;
}
if ($format !== '' && $format !== 'mjpeg') {
  fail($logger, 400, 'stream format not supported', ['camera' => $slug, 'format' => $format]);
}
if ($isProbeRequest) {
  $activeClients = count_active_stream_slots($app, $slug, $maxClients);
  if ($activeClients >= $maxClients) {
    http_response_code(503);
    header('Retry-After: 10');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
      'ok' => false,
      'camera' => $slug,
      'active_clients' => $activeClients,
      'max_clients' => $maxClients,
      'message' => sprintf('Livestream ist derzeit von %d Clients belegt.', $maxClients),
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode([
    'ok' => true,
    'camera' => $slug,
    'active_clients' => $activeClients,
    'max_clients' => $maxClients,
    'message' => 'Livestream verfügbar.',
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
$streamSlot = acquire_stream_slot($app, $streamState, $slug, $logger, $maxClients, $_SERVER);
if ($streamSlot === null) {
  header('Retry-After: 10');
  fail($logger, 503, 'stream client limit reached', ['camera' => $slug, 'max_clients' => $maxClients]);
}
set_time_limit($maxRuntimeSeconds + 30);
ignore_user_abort(true);
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
  ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: multipart/x-mixed-replace; boundary=frame');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Connection: close');
header('X-Accel-Buffering: no');

try {
  $rtspManifest = RtspInput::manifest($rtspUrl, $transport);
  $command = [
    $ffmpeg,
    '-hide_banner',
    '-loglevel', 'error',
    ...RtspInput::ffmpegArguments(),
    '-vf', 'fps=' . $fps,
    '-q:v', (string) $quality,
    '-f', 'image2pipe',
    '-vcodec', 'mjpeg',
    'pipe:1',
  ];
} catch (\Throwable $exception) {
  fail($logger, 500, 'stream input configuration invalid', ['camera' => $slug]);
}

$pipes = [];
$process = proc_open(
  $command,
  [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ],
  $pipes,
  null,
  null,
  ['bypass_shell' => true]
);

if (!is_resource($process)) {
  fail($logger, 500, 'stream failed to start ffmpeg', ['camera' => $slug, 'rtsp' => $maskedUrl]);
}
if (!RtspInput::writeManifest($pipes[0], $rtspManifest)) {
  fclose($pipes[0]);
  proc_terminate($process);
  proc_close($process);
  fail($logger, 500, 'stream failed to pass protected input', ['camera' => $slug]);
}

$streamProcess = $process;
$streamPipes = $pipes;
$streamExitCode = null;
register_shutdown_function(static function () use (&$streamProcess, &$streamPipes, &$streamSlot, &$streamExitCode): void {
  if (is_resource($streamProcess)) {
    $streamExitCode = close_stream_process($streamProcess, $streamPipes, true);
  }
  release_stream_slot($streamSlot);
});

fclose($pipes[0]);
$streamPipes[0] = null;
$stdout = $pipes[1];
$stderr = $pipes[2];
stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

$buffer = '';
$boundary = 'frame';
$maxBuffer = 2 * 1024 * 1024;
$stderrBuffer = '';
$maxErrBuffer = 4096;
$hadFrame = false;
$clientAborted = false;
$maxRuntimeReached = false;
$deadline = time() + $maxRuntimeSeconds;
$stateTickAt = 0;
$streamToken = (string) ($streamSlot['metadata']['token'] ?? '');
$streamIp = (string) ($streamSlot['metadata']['ip'] ?? '');

while (true) {
  $nowLoop = time();
  if ($nowLoop >= $stateTickAt) {
    touch_stream_slot($streamSlot);
    if (($streamToken !== '' && $streamState->disconnectRequested($streamToken))
      || ($streamIp !== '' && $streamState->isBlockedIp($streamIp))
    ) {
      $clientAborted = true;
      break;
    }
    $stateTickAt = $nowLoop + 1;
  }
  if (time() >= $deadline) {
    $maxRuntimeReached = true;
    break;
  }
  if (connection_aborted()) {
    $clientAborted = true;
    break;
  }
  $chunk = stream_get_contents($stdout);
  $errChunk = stream_get_contents($stderr);
  if ($errChunk !== false && $errChunk !== '') {
    $stderrBuffer .= $errChunk;
    if (strlen($stderrBuffer) > $maxErrBuffer) {
      $stderrBuffer = substr($stderrBuffer, -$maxErrBuffer);
    }
  }
  if ($chunk !== false && $chunk !== '') {
    $buffer .= $chunk;
    if (strlen($buffer) > $maxBuffer) {
      $buffer = substr($buffer, -$maxBuffer);
    }
    while (true) {
      $start = strpos($buffer, "\xFF\xD8");
      if ($start === false) {
        if (strlen($buffer) > 4) {
          $buffer = substr($buffer, -4);
        }
        break;
      }
      $end = strpos($buffer, "\xFF\xD9", $start + 2);
      if ($end === false) {
        if ($start > 0) {
          $buffer = substr($buffer, $start);
        }
        break;
      }
      $jpeg = substr($buffer, $start, $end - $start + 2);
      $buffer = substr($buffer, $end + 2);
      $hadFrame = true;
      echo "--{$boundary}\r\n";
      echo "Content-Type: image/jpeg\r\n";
      echo 'Content-Length: ' . strlen($jpeg) . "\r\n\r\n";
      echo $jpeg . "\r\n";
      flush();
    }
  } else {
    $status = proc_get_status($process);
    if (!$status['running']) {
      break;
    }
    usleep(100000);
  }
}

$streamExitCode = close_stream_process($streamProcess, $streamPipes, true);
$exitCode = $streamExitCode ?? -1;
if (!$clientAborted && !$hadFrame) {
  $logger->warning('stream ended without frames', ['camera' => $slug, 'rtsp' => $maskedUrl]);
}
if ($maxRuntimeReached) {
  $logger->warning('stream max runtime reached', [
    'camera' => $slug,
    'rtsp' => $maskedUrl,
    'max_runtime_seconds' => $maxRuntimeSeconds,
  ]);
}
if (!$clientAborted && $exitCode !== 0) {
  $logger->error('stream ffmpeg exited with error', [
    'camera' => $slug,
    'rtsp' => $maskedUrl,
    'exit_code' => $exitCode,
    'stderr' => SecretRedactor::redact(trim($stderrBuffer)),
  ]);
}

exit;

/** Liest nur für einen konfigurierten RTSP-Stream die normalisierte Quell-URL. */
function resolve_rtsp_url(\Webcam\Domain\Camera $camera): ?string
{
  $stream = $camera->stream();
  if ($camera->streamMode() === CameraStreamMode::Rtsp) {
    $candidate = (string) ($stream['playlist_url'] ?? $stream['snapshot_url'] ?? '');
    if ($candidate !== '') {
      return normalize_rtsp_url($candidate);
    }
  }
  if ($camera->captureTransport() === CaptureTransport::Rtsp) {
    $capture = $camera->capture();
    $candidate = (string) ($capture['endpoint'] ?? '');
    if ($candidate !== '') {
      return normalize_rtsp_url($candidate);
    }
  }
  return null;
}

/** Akzeptiert ausschließlich rtsp:// bzw. rtsps:// und lehnt Steuerzeichen ab. */
function normalize_rtsp_url(string $url): ?string
{
  $url = trim($url);
  if ($url === '') {
    return null;
  }
  if (!preg_match('#^rtsps?://#i', $url) || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
    return null;
  }
  return $url;
}

/** Findet ein ausführbares ffmpeg aus Env oder bekannten Hostpfaden, sonst 503. */
function resolve_ffmpeg_path(\Webcam\Infrastructure\Logging\Logger $logger): string
{
  $configured = trim((string) getenv('WEBCAM_FFMPEG_PATH'));
  if ($configured !== '') {
    if (!is_executable($configured)) {
      fail($logger, 500, 'ffmpeg not executable', ['path' => $configured]);
    }
    return $configured;
  }
  $candidates = [];
  $pathEnv = getenv('PATH');
  if (is_string($pathEnv) && $pathEnv !== '') {
    foreach (explode(':', $pathEnv) as $dir) {
      $dir = trim($dir);
      if ($dir !== '') {
        $candidates[] = rtrim($dir, '/') . '/ffmpeg';
      }
    }
  }
  $candidates[] = '/usr/bin/ffmpeg';
  $candidates[] = '/usr/local/bin/ffmpeg';
  foreach (array_values(array_unique($candidates)) as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) {
      return $candidate;
    }
  }
  fail($logger, 500, 'ffmpeg not found on PATH', ['candidates' => $candidates]);
}

/** Entfernt Benutzername/Passwort aus URLs, bevor sie ins Log gelangen. */
function mask_rtsp_url(string $url): string
{
  return SecretRedactor::redact($url);
}

/** Liest einen Stream-Grenzwert aus Kamera, Env oder Default und klemmt ihn ein. */
function resolve_stream_limit(array $streamConfig, string $configKey, string $envKey, int $default, int $min, int $max): int
{
  $value = isset($streamConfig[$configKey]) ? (int) $streamConfig[$configKey] : 0;
  if ($value <= 0) {
    $value = (int) getenv($envKey);
  }
  if ($value <= 0) {
    $value = $default;
  }
  return max($min, min($max, $value));
}

/** Loggt technische Streamdetails und beendet die öffentliche Antwort knapp. */
function fail(\Webcam\Infrastructure\Logging\Logger $logger, int $status, string $message, array $context = []): void
{
  if ($status >= 500) {
    $logger->error($message, $context);
  } else {
    $logger->warning($message, $context);
  }
  http_response_code($status);
  exit;
}

/** Gibt eine kontrollierte Sperre als JSON-Probe oder sichtbaren Stream-Fehler aus. */
function deny_stream_request(bool $isProbeRequest, string $slug, string $message): void
{
  http_response_code(403);
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  if ($isProbeRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'ok' => false,
      'camera' => $slug,
      'message' => $message,
    ], JSON_UNESCAPED_SLASHES);
  }
  exit;
}
