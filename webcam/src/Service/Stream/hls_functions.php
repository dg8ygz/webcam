<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Service\Stream\RtspInput;
use Webcam\Service\Stream\StreamState;

// HLS wird von mehreren Browsern gemeinsam genutzt. Dieser Block verwaltet
// Playlist, Segmente und genau einen entkoppelten ffmpeg-Runner je Kamera.

function handle_hls_request(
  App $app,
  \Webcam\Infrastructure\Logging\Logger $logger,
  StreamState $streamState,
  string $slug,
  string $rtspUrl,
  string $maskedUrl,
  string $ffmpeg,
  string $transport,
  int $fps,
  int $quality,
  int $maxRuntimeSeconds,
  int $hlsIdleTimeoutSeconds,
  bool $isProbeRequest
): void {
  $dir = $app->paths()->statePath('stream-hls/' . $slug);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail($logger, 500, 'hls state dir create failed', ['camera' => $slug, 'dir' => $dir]);
  }

  $file = trim((string) ($_GET['file'] ?? 'index.m3u8'));
  if (!is_valid_hls_file($file)) {
    fail($logger, 404, 'hls file rejected', ['camera' => $slug, 'file' => $file]);
  }

  if (!$isProbeRequest) {
    $client = $streamState->registerHlsRequest($slug, $_SERVER);
    $token = (string) ($client['token'] ?? '');
    if ($token !== '' && $streamState->disconnectRequested($token)) {
      deny_stream_request(false, $slug, 'Livestream-Verbindung wurde getrennt.');
    }
  }

  if ($file !== 'index.m3u8') {
    serve_hls_file($logger, $slug, $dir, $file);
    return;
  }

  $ready = ensure_hls_runner($app, $logger, $slug, $dir, $rtspUrl, $maskedUrl, $ffmpeg, $transport, $fps, $quality, $maxRuntimeSeconds, $hlsIdleTimeoutSeconds);
  if (!$ready) {
    if ($isProbeRequest) {
      http_response_code(503);
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      echo json_encode([
        'ok' => false,
        'camera' => $slug,
        'message' => 'HLS-Fallback ist noch nicht bereit.',
      ], JSON_UNESCAPED_SLASHES);
      return;
    }
    fail($logger, 503, 'hls playlist not ready', ['camera' => $slug, 'rtsp' => $maskedUrl]);
  }

  if ($isProbeRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
      'ok' => true,
      'camera' => $slug,
      'message' => 'HLS-Fallback verfügbar.',
    ], JSON_UNESCAPED_SLASHES);
    return;
  }

  serve_hls_file($logger, $slug, $dir, 'index.m3u8');
}

/** Erlaubt nur index.m3u8 und die erwarteten seg_<n>.ts-Dateien. */
function is_valid_hls_file(string $file): bool
{
  return $file === 'index.m3u8' || preg_match('/^seg_[0-9]{5,}\.ts$/', $file) === 1;
}

/** Liefert genau eine validierte HLS-Datei mit passendem MIME- und Cache-Header. */
function serve_hls_file(\Webcam\Infrastructure\Logging\Logger $logger, string $slug, string $dir, string $file): void
{
  $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  // Hold the open file across rotation/cleanup. A valid segment outside the
  // short live window is expired media, not a missing application route.
  $handle = @fopen($path, 'rb');
  if (!is_resource($handle)) {
    clearstatcache(true, $path);
    if ($file !== 'index.m3u8' && !file_exists($path)) {
      http_response_code(410);
    } else {
      http_response_code(503);
      $logger->warning('hls file temporarily unavailable', ['camera' => $slug, 'file' => $file]);
    }
    return;
  }

  try {
    if ($file === 'index.m3u8') {
      $playlist = stream_get_contents($handle);
      if ($playlist === false) {
        fail($logger, 500, 'hls playlist read failed', ['camera' => $slug, 'path' => $path]);
      }
      header('Content-Type: application/vnd.apple.mpegurl; charset=UTF-8');
      echo rewrite_hls_playlist($playlist, $slug);
      return;
    }

    header('Content-Type: video/mp2t');
    $stat = fstat($handle);
    if ($stat !== false) header('Content-Length: ' . $stat['size']);
    fpassthru($handle);
  } finally {
    fclose($handle);
  }
}

/** Hängt einen Cache-Buster an Segment-URLs, ohne fremde Playlist-Zeilen umzubauen. */
function rewrite_hls_playlist(string $playlist, string $slug): string
{
  $lines = preg_split('/\r\n|\r|\n/', $playlist);
  if (!is_array($lines)) {
    return $playlist;
  }
  $rewritten = [];
  foreach ($lines as $line) {
    $trimmed = trim($line);
    if (is_valid_hls_file($trimmed) && $trimmed !== 'index.m3u8') {
      $rewritten[] = '?slug=' . rawurlencode($slug) . '&format=hls&file=' . rawurlencode($trimmed);
    } else {
      $rewritten[] = $line;
    }
  }
  return implode("\n", $rewritten) . "\n";
}

/**
 * Prüft Prozess-Lock und Playlist-Frische. Nur der Besitzer des Start-Locks darf
 * alte Segmente räumen und einen neuen ffmpeg-Runner erzeugen.
 */
function ensure_hls_runner(
  App $app,
  \Webcam\Infrastructure\Logging\Logger $logger,
  string $slug,
  string $dir,
  string $rtspUrl,
  string $maskedUrl,
  string $ffmpeg,
  string $transport,
  int $fps,
  int $quality,
  int $maxRuntimeSeconds,
  int $hlsIdleTimeoutSeconds
): bool {
  $playlist = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.m3u8';
  $pidFile = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ffmpeg.pid';
  $lockPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runner.lock';
  $processLockPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'process.lock';
  $logPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ffmpeg.log';

  if (hls_process_lock_held($processLockPath) && hls_playlist_is_fresh($playlist)) {
    return true;
  }

  $lock = fopen($lockPath, 'c');
  if (!is_resource($lock)) {
    $logger->warning('hls lock open failed', ['camera' => $slug, 'path' => $lockPath]);
    return wait_for_hls_playlist($playlist, 4);
  }
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    return wait_for_hls_playlist($playlist, 5);
  }

  try {
    if (hls_process_lock_held($processLockPath) && hls_playlist_is_fresh($playlist)) {
      return true;
    }
    if (hls_process_lock_held($processLockPath)) {
      $logger->warning('hls process lock held but playlist stale', ['camera' => $slug, 'playlist' => $playlist]);
      return wait_for_hls_playlist($playlist, 4);
    }

    cleanup_hls_segments($dir);
    $started = start_hls_process($logger, $slug, $dir, $rtspUrl, $maskedUrl, $ffmpeg, $transport, $fps, $quality, $maxRuntimeSeconds, $hlsIdleTimeoutSeconds, $pidFile, $logPath, $processLockPath);
    if (!$started) {
      return false;
    }
    return wait_for_hls_playlist($playlist, 8);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

/** Erkennt über flock, ob ein HLS-Runner seinen Prozess-Lock wirklich hält. */
function hls_process_lock_held(string $lockPath): bool
{
  $handle = fopen($lockPath, 'c');
  if (!is_resource($handle)) {
    return false;
  }
  if (!flock($handle, LOCK_EX | LOCK_NB)) {
    fclose($handle);
    return true;
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return false;
}

/** Entfernt ausschließlich alte Playlist-/Segmentdateien im bereits gelösten HLS-Verzeichnis. */
function cleanup_hls_segments(string $dir): void
{
  foreach (glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'seg_*.ts') ?: [] as $file) {
    if (is_file($file)) {
      @unlink($file);
    }
  }
  $playlist = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.m3u8';
  if (is_file($playlist)) {
    @unlink($playlist);
  }
  // Ein nicht konsumiertes Runner-Config gehoert zu einem fehlgeschlagenen
  // Start. Da diese Funktion nur ohne gehaltenen Prozess-Lock laeuft, koennen
  // solche transienten Altdateien gefahrlos entfernt werden.
  foreach (glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runner-config-*.json') ?: [] as $file) {
    if (is_file($file)) {
      @unlink($file);
    }
  }
}

/**
 * Startet den entkoppelten HLS-Runner mit ausschließlich escapten Argumenten.
 * PID und Log landen im Kamera-State; RTSP-Zugangsdaten werden nicht protokolliert.
 */
function start_hls_process(
  \Webcam\Infrastructure\Logging\Logger $logger,
  string $slug,
  string $dir,
  string $rtspUrl,
  string $maskedUrl,
  string $ffmpeg,
  string $transport,
  int $fps,
  int $quality,
  int $maxRuntimeSeconds,
  int $hlsIdleTimeoutSeconds,
  string $pidFile,
  string $logPath,
  string $processLockPath
): bool {
  $segmentPattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'seg_%05d.ts';
  $playlist = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.m3u8';
  $gop = max(2, $fps * 2);
  try {
    $inputManifest = RtspInput::manifest($rtspUrl, $transport);
  } catch (\Throwable $exception) {
    $logger->error('hls protected input build failed', ['camera' => $slug]);
    return false;
  }
  $cmd = [
    $ffmpeg,
    '-hide_banner',
    '-loglevel', 'warning',
    ...RtspInput::ffmpegArguments(),
    '-an',
    '-t', (string) $maxRuntimeSeconds,
    '-vf', 'fps=' . $fps,
    '-c:v', 'libx264',
    // 1080p/3fps must remain responsive on the webcam server; veryfast
    // saturated a CPU core and caused multi-second HLS stalls.
    '-preset', 'ultrafast',
    '-tune', 'zerolatency',
    '-pix_fmt', 'yuv420p',
    '-g', (string) $gop,
    '-sc_threshold', '0',
    '-f', 'hls',
    '-hls_time', '2',
    '-hls_list_size', '6',
    // Never reuse a previous runner's segment names/media sequence. Epoch
    // microseconds also distinguish two starts within the same second.
    '-hls_start_number_source', 'epoch_us',
    '-hls_flags', 'delete_segments+omit_endlist+program_date_time',
    '-hls_segment_filename', $segmentPattern,
    $playlist,
  ];
  try {
    $configPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
      . 'runner-config-' . bin2hex(random_bytes(8)) . '.json';
    $configJson = json_encode([
      'command' => $cmd,
      'client_dir' => rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'clients',
      'process_lock_path' => $processLockPath,
      'pid_file' => $pidFile,
      'log_path' => $logPath,
      'slug' => $slug,
      'stdin_manifest_required' => true,
      'idle_timeout_seconds' => $hlsIdleTimeoutSeconds,
      'max_runtime_seconds' => $maxRuntimeSeconds,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  } catch (\Throwable $exception) {
    $logger->error('hls runner config build failed', ['camera' => $slug, 'error' => $exception->getMessage()]);
    return false;
  }
  if (!AtomicFileWriter::write($configPath, $configJson . PHP_EOL, null, 0600)) {
    $logger->error('hls runner config write failed', ['camera' => $slug, 'path' => $configPath]);
    return false;
  }

  // The CLI runner is installed in the application-level bin/ directory,
  // not below src/Service/Stream/.  The old relative path silently made
  // every RTSP HLS start fail while the MJPEG runner continued to work.
  $runner = dirname(__DIR__, 3) . '/bin/hls_runner.php';
  $phpCli = resolve_php_cli_path();
  if ($phpCli === null) {
    @unlink($configPath);
    $logger->error('hls runner php cli unavailable', ['camera' => $slug]);
    return false;
  }
  $runnerPid = launch_hls_runner(
    $phpCli,
    $runner,
    $configPath,
    $inputManifest,
    $logPath,
    $processLockPath
  );
  if ($runnerPid === null) {
    @unlink($configPath);
    $logger->error('hls runner start failed', ['camera' => $slug, 'rtsp' => $maskedUrl]);
    return false;
  }
  $logger->info('hls runner started', [
    'camera' => $slug,
    'rtsp' => $maskedUrl,
    'runner_pid' => $runnerPid,
    'idle_timeout_seconds' => $hlsIdleTimeoutSeconds,
  ]);
  return true;
}

/**
 * Startet den Runner entkoppelt und reicht das geheime ffconcat-Manifest nur
 * ueber einen anonymen Pipe-Dateideskriptor weiter.
 */
function launch_hls_runner(
  string $phpCli,
  string $runner,
  string $configPath,
  string $inputManifest,
  string $logPath,
  string $processLockPath
): ?int
{
  $shell = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($runner)
    . ' ' . escapeshellarg('--config=' . $configPath)
    . ' <&3 >/dev/null 2>>' . escapeshellarg($logPath) . ' & echo $!';
  $process = @proc_open(
    ['/bin/sh', '-c', $shell],
    [
      0 => ['file', '/dev/null', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
      3 => ['pipe', 'r'],
    ],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
  );
  if (!is_resource($process)) {
    return null;
  }

  $inputWritten = RtspInput::writeManifest($pipes[3], $inputManifest);
  fclose($pipes[3]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($process);
  $pidRaw = trim(is_string($stdout) ? $stdout : '');
  if (
    !$inputWritten
    || $exitCode !== 0
    || (is_string($stderr) && trim($stderr) !== '')
    || $pidRaw === ''
    || !ctype_digit($pidRaw)
  ) {
    return null;
  }
  $deadline = microtime(true) + 5.0;
  while (!hls_process_lock_held($processLockPath) && microtime(true) < $deadline) {
    usleep(50000);
  }
  if (!hls_process_lock_held($processLockPath)) {
    return null;
  }
  return (int) $pidRaw;
}

/** Findet fuer den entkoppelten Runner explizit eine PHP-CLI-Binary. */
function resolve_php_cli_path(): ?string
{
  $candidates = [];
  $configured = trim((string) getenv('WEBCAM_PHP_CLI_PATH'));
  if ($configured !== '') {
    $candidates[] = $configured;
  }
  if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
    $candidates[] = PHP_BINARY;
  }
  $candidates[] = '/usr/bin/php';
  foreach (glob('/usr/bin/php[0-9]*') ?: [] as $candidate) {
    $candidates[] = $candidate;
  }
  foreach (array_values(array_unique($candidates)) as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) {
      return $candidate;
    }
  }
  return null;
}

/** A stalled runner must not make an old, nonempty playlist look ready. */
function hls_playlist_is_fresh(string $playlist): bool
{
  clearstatcache(true, $playlist);
  $stat = @stat($playlist);
  return $stat !== false && is_file($playlist) && $stat['size'] > 0
    && (time() - $stat['mtime']) < 20;
}

/** Wartet begrenzt auf eine frische, nichtleere Playlist. */
function wait_for_hls_playlist(string $playlist, int $seconds): bool
{
  $deadline = microtime(true) + max(1, $seconds);
  while (microtime(true) < $deadline) {
    if (hls_playlist_is_fresh($playlist)) {
      return true;
    }
    usleep(200000);
  }
  return hls_playlist_is_fresh($playlist);
}
