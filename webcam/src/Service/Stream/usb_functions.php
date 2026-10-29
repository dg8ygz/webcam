<?php
declare(strict_types=1);

/** USB branch behind stream.php's existing IP block/disconnect checks. */
function handle_usb_hls_request(\Webcam\Service\Stream\UsbStream $usb, \Webcam\Service\Stream\StreamState $state, string $slug, bool $probe, int $maxClients, callable $windowForViewer, ?callable $diagnostic = null): void
{
  header('Cache-Control: no-store');
  $file = (string) ($_GET['file'] ?? 'index.m3u8');
  if (!\Webcam\Service\Stream\UsbStream::validFile($file)) { http_response_code(404); return; }
  $prepare = ($_GET['prepare'] ?? '') === '1';
  if ($prepare && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $probe || $file !== 'index.m3u8')) {
    http_response_code(405); header('Allow: POST'); return;
  }
  $identity = $state->clientIdentity($_SERVER);
  $token = 'hls-' . substr(hash('sha256', $slug . "\0" . $identity['ip'] . "\0" . $identity['user_agent']), 0, 24);
  if ($identity['ip'] === '' || $state->disconnectRequested($token)) { http_response_code(403); return; }
  // Only the server's fresh HLS registry proves an existing viewer. A resume
  // query alone never bypasses admission; expired/unknown viewers wait normally.
  $isKnown = static function () use ($state, $token): bool {
    foreach ($state->activeClients() as $client) {
      if (($client['token'] ?? '') === $token) { return true; }
    }
    return false;
  };
  if ($probe) {
    // Availability is not camera readiness. No demand or viewer slot on probe.
    $known = $isKnown();
    $window = $windowForViewer($known && ($_GET['resume'] ?? '') === '1');
    $available = $known || $state->viewerCount($slug) < $maxClients;
    if ($diagnostic) { $diagnostic('probe', ['status' => $available ? 200 : 503, 'known' => $known, 'ready' => $window['ready'], 'available' => $available]); }
    if (!$available) { http_response_code(503); header('Retry-After: 10'); }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $available, 'camera' => $slug, 'ready' => $window['ready'],
      'duration' => $window['duration'],
      'message' => $available ? $window['message'] : 'Alle Streamplätze sind belegt.']);
    return;
  }
  // One admission lock per camera also serialises simultaneous new viewers.
  $admission = $usb->admissionLock($slug);
  if ($admission === false) {
    if ($diagnostic) { $diagnostic('admission_busy', ['status' => 503]); }
    http_response_code(503); header('Retry-After: 3'); return;
  }
  try {
    $known = $isKnown();
    $window = $windowForViewer($known);
    if (!$window['ready']) {
      if ($prepare) {
        usb_stream_preparation_response(false, $window['message']);
        return;
      }
      if ($diagnostic) { $diagnostic('photo_window', ['status' => 503, 'known' => $known]); }
      http_response_code(503); header('Retry-After: 5');
      header('Content-Type: text/plain; charset=UTF-8');
      echo $window['message'];
      return;
    }
    if ($identity['ip'] === '' || $state->disconnectRequested($token)) { http_response_code(403); return; }
    if (!$known && $state->viewerCount($slug) >= $maxClients) {
      if ($diagnostic) { $diagnostic('viewer_limit', ['status' => 503, 'limit' => $maxClients]); }
      http_response_code(503); header('Retry-After: 10'); return;
    }
    $state->registerHlsRequest($slug, $_SERVER);
    $usb->demand($slug);
  } finally { flock($admission, LOCK_UN); fclose($admission); }
  try {
    $body = $usb->fetch($slug, $file, $diagnostic);
    if ($prepare) {
      if ($diagnostic) { $diagnostic('preparation_ready', ['status' => 200]); }
      usb_stream_preparation_response(true, 'Stream bereit.');
      return;
    }
    if ($file === 'index.m3u8') {
      $body = preg_replace_callback('/^seg_[a-f0-9]{16}_[0-9]{6,12}\.ts$/m',
        static fn(array $m): string => '?slug=' . rawurlencode($slug) . '&format=hls&file=' . rawurlencode($m[0]), $body);
    }
    header('Content-Type: ' . ($file === 'index.m3u8' ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
    header('X-Content-Type-Options: nosniff');
    if ($diagnostic && $file === 'index.m3u8') { $diagnostic('playlist_ready', ['status' => 200, 'bytes' => strlen($body)]); }
    echo $body;
  } catch (\Throwable $error) {
    if ($prepare && $error instanceof \Webcam\Service\Stream\UsbStreamPending) {
      if ($diagnostic) { $diagnostic('preparation_pending', ['status' => 202]); }
      usb_stream_preparation_response(false, 'Stream wird gestartet …');
      return;
    }
    if ($diagnostic) {
      $knownReasons = ['USB relay busy', 'USB stream not ready', 'USB stream disabled', 'Segment not advertised', 'Invalid USB playlist', 'Unsafe USB playlist', 'Empty USB playlist', 'USB cache read failed'];
      $diagnostic('fetch_failed', ['status' => 503, 'kind' => $file === 'index.m3u8' ? 'playlist' : 'segment',
        'reason' => in_array($error->getMessage(), $knownReasons, true) ? $error->getMessage() : 'internal_error']);
    }
    http_response_code(503); header('Retry-After: 3');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'USB-Stream noch nicht bereit oder fuer Fotoaufnahme pausiert.';
  }
}

/** Separate control response: never return JSON/202 as an HLS playlist. */
function usb_stream_preparation_response(bool $ready, string $message): void
{
  http_response_code($ready ? 200 : 202);
  header('Content-Type: application/json; charset=UTF-8');
  if (!$ready) { header('Retry-After: 3'); }
  echo json_encode(['ok' => true, 'ready' => $ready, 'message' => $message]);
}

/** Explicit local lease: no request parameter can enable diagnostic logging. */
function usb_stream_diagnostic(\Webcam\Service\Stream\UsbStream $usb, \Webcam\Infrastructure\Logging\Logger $logger, string $slug): ?callable
{
  if ($slug !== 'test') { return null; }
  $path = $usb->directory($slug) . '/diagnostic-until';
  if (!is_file($path)) { return null; }
  $value = trim((string) file_get_contents($path, false, null, 0, 32));
  $now = time();
  if (!ctype_digit($value) || (int) $value <= $now || (int) $value > $now + 1800) { return null; }
  $request = bin2hex(random_bytes(6));
  return static function (string $stage, array $context = []) use ($logger, $slug, $request): void {
    $logger->info('USB stream diagnostic', ['camera' => $slug, 'request' => $request, 'stage' => $stage] + $context);
  };
}
