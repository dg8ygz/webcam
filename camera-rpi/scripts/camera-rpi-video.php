#!/usr/bin/php
<?php
declare(strict_types=1);

// One USB device, one video lease, one encoder. Camera workers retain priority.
// All children are owned proc_open handles and are reaped before releasing USB.
require_once is_file(__DIR__ . '/video-stream.php') ? __DIR__ . '/video-stream.php' : __DIR__ . '/lib/video-stream.php';
// Missing optional config preserves current behavior. If configured, a missing
// helper, invalid site/time or closing window must stop existing video too.
$privacyPath=getenv('CAMERA_RPI_PRIVACY_CONFIG') ?: '/etc/camera-rpi/privacy.json';
$privacyHelper=__DIR__.'/camera-rpi-privacy.php';
if (is_file($privacyHelper)) { require_once $privacyHelper; }
if (($argv[1] ?? '') === '--check') {
  CameraVideo::config($argv[2] ?? '');
  echo "Video-Konfiguration gueltig; kein Kamerazugriff\n";
  exit;
}
$configPath = $argv[1] ?? '';
$c = CameraVideo::config($configPath);
$run = '/run/camera-rpi-video';
$dir = $run . '/hls';
$operationPath = '/run/lock/camera-rpi.operation.lock.video';
umask(0077);
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('RAM-Verzeichnis fehlt'); }
$single = fopen($run . '/daemon.lock', 'c');
if (!$single || !flock($single, LOCK_EX | LOCK_NB)) { exit(75); }
$operation = fopen($operationPath, 'c');
$priority = fopen($operationPath . '.priority', 'c');
if (!$operation || !$priority) { throw new RuntimeException('Kamerasperre fehlt'); }
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
$children = [];
$locked = false;
$nextPoll = 0.0;
$expires = 0.0;
$started = 0.0;
$retryAt = 0.0;
$exhausted = false;
$lastState = '';
$lastPollError = '';
$pollFailures = 0;

/** Launch argv directly. Secrets only in the Chisel child environment/config. */
function videoSpawn(array $command, array $env = [], ?string $input = null): array
{
  $pipes = [];
  $p = proc_open($command, [0 => $input===null ? ['file', '/dev/null', 'r'] : ['pipe','r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
    $pipes, null, array_merge(getenv(), $env), ['bypass_shell' => true]);
  if (!is_resource($p)) { throw new RuntimeException('Video-Unterprozess nicht gestartet'); }
  if ($input!==null) {
    $written=fwrite($pipes[0],$input); fclose($pipes[0]);
    if ($written!==strlen($input)) {
      proc_terminate($p); fclose($pipes[2]); proc_close($p);
      throw new RuntimeException('Video-Eingang nicht uebertragen');
    }
  }
  stream_set_blocking($pipes[2], false);
  return [$p, $pipes[2]];
}

/** TERM, bounded grace, KILL, reap: no detached FFmpeg process survives a lease. */
function videoStop(array &$children): void
{
  foreach ($children as [$p, $err]) { proc_terminate($p); }
  $end = CameraVideo::clock() + 2;
  do {
    $running = false;
    foreach ($children as [$p, $err]) { stream_get_contents($err); $running = proc_get_status($p)['running'] || $running; }
    if (!$running) { break; }
    usleep(50000);
  } while (CameraVideo::clock() < $end);
  foreach ($children as [$p, $err]) {
    if (proc_get_status($p)['running']) { proc_terminate($p, SIGKILL); }
    fclose($err); proc_close($p);
  }
  $children = [];
}

/** Only our fixed-name HLS artifacts; never touch photo queues or sensor data. */
function videoClear(string $dir): void
{
  foreach (glob($dir . '/*') ?: [] as $path) {
    $name = basename($path);
    if (CameraVideo::validFile($name) || str_ends_with($name, '.tmp')) { unlink($path); }
  }
}

try {
  while (!$stop) {
    $now = CameraVideo::clock();
    if ($now >= $nextPoll) {
      try {
        $ttl = CameraVideo::poll($c);
        $pollFailures = 0;
        $lastPollError = '';
        $expires = CameraVideo::clock() + $ttl;
        if ($ttl === 0) { $exhausted = false; $started = 0; }
      } catch (Throwable $error) {
        $pollFailures = min(4, $pollFailures + 1);
        $expires = CameraVideo::expiryAfterFailure($expires, CameraVideo::clock(), $error);
        if ($lastPollError !== $error->getMessage()) {
          echo 'USB-Video: control-error: '.$error->getMessage()."\n";
          $lastPollError = $error->getMessage();
        }
      }
      $pollNow = CameraVideo::clock();
      $nextPoll = $pollNow + CameraVideo::pollDelay($pollFailures, $expires, $pollNow);
    }
    $now = CameraVideo::clock();
    if ($started > 0 && $now - $started >= 1800) { $exhausted = true; }
    // Workers take a SH priority lock BEFORE waiting for the EX camera lock.
    // This EX probe detects even a queued photo; it cannot starve behind video.
    $gate = flock($priority, LOCK_EX | LOCK_NB);
    $privacyAllowed=($c['source_profile']['source']??'')!=='gphoto2';
    if (file_exists($privacyPath) || is_link($privacyPath)) {
      try { $privacyAllowed=$privacyAllowed && class_exists('PrivacyApp') && (new PrivacyApp())->decision()['allowed']; }
      catch (Throwable) { $privacyAllowed=false; }
    }
    $want = !$exhausted && $expires > $now && $gate && $privacyAllowed;
    $dead = false;
    foreach ($children as [$p, $err]) {
      // Drain but never log raw child stderr (Chisel may include sensitive URLs).
      stream_get_contents($err, 8192);
      $dead = !proc_get_status($p)['running'] || $dead;
    }
    $bytes = 0;
    clearstatcache();
    foreach (glob($dir . '/*') ?: [] as $path) {
      // FFmpeg rotates completed segments concurrently. ENOENT after glob is
      // normal; an existing but unreadable file instead closes video safely.
      $size = @filesize($path);
      if ($size === false) {
        clearstatcache(true, $path);
        if (file_exists($path)) { $dead = true; }
      } else { $bytes += $size; }
    }
    if ($bytes > 16 * 1024 * 1024) { $dead = true; }
    if (!$want || $dead) {
      videoStop($children);
      if ($locked) { flock($operation, LOCK_UN); $locked = false; videoClear($dir); }
      if ($dead) { $retryAt = $now + 15; }
    }
    if ($want && !$children && $now >= $retryAt && flock($operation, LOCK_EX | LOCK_NB)) {
      $locked = true;
      videoClear($dir);
      $started = $started > 0 ? $started : $now;
      try {
        $children[] = videoSpawn(['/usr/bin/php', '-S', '127.0.0.1:18087', __DIR__ . '/camera-rpi-video-http.php'], ['CAMERA_VIDEO_CONFIG' => $configPath]);
        $children[] = videoSpawn(['/usr/local/bin/chisel', 'client', '--fingerprint', $c['fingerprint'],
          '--keepalive', '25s', '--max-retry-interval', '10s', $c['tunnel_url'],
          'R:127.0.0.1:' . $c['port'] . ':127.0.0.1:18087'], ['AUTH' => $c['auth']]);
        $input=($c['source_profile']['source']??'')==='rtsp' ? CameraSource::input($c['source_profile']) : null;
        $children[] = videoSpawn(CameraVideo::encoder($c, $dir, bin2hex(random_bytes(8))), [], $input);
      } catch (Throwable $error) {
        videoStop($children); flock($operation, LOCK_UN); $locked = false; $retryAt = $now + 15;
      }
    }
    if ($gate) { flock($priority, LOCK_UN); }
    $state = $exhausted ? 'lease-limit' : ($children ? 'streaming-processes' : ($expires > $now ? 'paused-or-retrying' : 'closed'));
    if ($state !== $lastState) {
      echo 'USB-Video: ' . $state . "\n";
      file_put_contents($run . '/status', 'state=' . $state . "\nupdated_epoch=" . time() . "\n");
      $lastState = $state;
    }
    usleep(100000);
  }
} finally {
  videoStop($children);
  if ($locked) { flock($operation, LOCK_UN); }
  videoClear($dir);
  fclose($priority); fclose($operation); fclose($single);
}
