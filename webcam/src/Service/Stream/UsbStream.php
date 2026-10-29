<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/**
 * Opt-in USB-HLS relay. Registrations are installed by the operator, never
 * accepted from a viewer. Only the assigned loopback port is reachable; neither
 * a request nor a camera playlist can supply an arbitrary upstream URL.
 * State/cache must reside on tmpfs (see ops/usb-stream.md).
 */
final class UsbStream
{
  public function __construct(private readonly PathResolver $paths) {}

  /** Separate tmpfs namespace per installation; DEV and PROD never share state. */
  public static function forPaths(PathResolver $paths): self
  {
    $id = substr(hash('sha256', $paths->statePath()), 0, 16);
    return new self(new PathResolver(null, null, '/run/webcam-usb-stream/' . $id, null));
  }

  public function directory(string $slug): string
  {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) {
      throw new \InvalidArgumentException('Invalid stream camera');
    }
    return $this->paths->statePath('usb-stream/' . $slug);
  }

  /** Absence disables the feature; malformed registrations fail closed. */
  public function registration(string $slug): ?array
  {
    $path = $this->directory($slug) . '/registration.json';
    if (!is_file($path)) {
      return null;
    }
    $raw = file_get_contents($path, false, null, 0, 4097);
    $config = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
    if (!is_array($config) || array_keys($config) !== ['port', 'token']
      || !is_int($config['port']) || $config['port'] < 23000 || $config['port'] > 23999
      || !is_string($config['token']) || !preg_match('/^[a-f0-9]{64}$/D', $config['token'])) {
      throw new \RuntimeException('Invalid USB stream registration');
    }
    return $config;
  }

  /** Probe requests do not start video. Only admitted viewers renew demand. */
  public function demand(string $slug): void
  {
    AtomicFileWriter::write($this->directory($slug) . '/demand', (string) time(), null, 0600);
  }

  /** Authenticated camera control, independent of capture slots and SSH leases. */
  public function command(string $slug, ?int $now = null): array
  {
    $now ??= time();
    $enabled = $this->registration($slug) !== null;
    $path = $this->directory($slug) . '/demand';
    $stamp = is_file($path) ? (int) file_get_contents($path, false, null, 0, 32) : 0;
    $active = $enabled && $stamp <= $now && $stamp >= $now - 45;
    return ['protocol' => 'camera-rpi-video-v1', 'active' => $active, 'ttl' => $active ? 30 : 0];
  }

  public static function validFile(string $file): bool
  {
    return $file === 'index.m3u8' || preg_match('/^seg_[a-f0-9]{16}_[0-9]{6,12}\.ts$/D', $file) === 1;
  }

  /** Reject keys, maps, nested playlists and external URIs, including tag URIs. */
  public static function playlist(string $body): string
  {
    if (strlen($body) > 16384 || !str_starts_with($body, "#EXTM3U\n")) {
      throw new \RuntimeException('Invalid USB playlist');
    }
    $segments = 0;
    foreach (explode("\n", trim($body)) as $line) {
      if ($line === '' || preg_match('/^#(?:EXTM3U|EXT-X-VERSION:[0-9]+|EXT-X-TARGETDURATION:[0-9]+|EXT-X-MEDIA-SEQUENCE:[0-9]+|EXT-X-INDEPENDENT-SEGMENTS|EXT-X-DISCONTINUITY|EXTINF:[0-9.]+,)$/D', $line)) {
        continue;
      }
      if ($line === 'index.m3u8' || !self::validFile($line) || ++$segments > 8) {
        throw new \RuntimeException('Unsafe USB playlist');
      }
    }
    if ($segments === 0) {
      throw new \RuntimeException('Empty USB playlist');
    }
    return $body;
  }

  /**
   * At most one playlist and one segment fetch per camera. Followers wait at
   * most 2.5 s and recheck the shared cache; upstream I/O remains capped at 2 s.
   * Segment publication/cleanup stays serial, with only two stable lock files.
   */
  public function fetch(string $slug, string $file, ?callable $diagnostic = null): string
  {
    if (!self::validFile($file)) {
      throw new \InvalidArgumentException('Invalid HLS file');
    }
    $config = $this->registration($slug);
    if ($config === null) {
      throw new \RuntimeException('USB stream disabled');
    }
    $dir = $this->directory($slug);
    // Published segments are immutable and atomically replaced. Keep the open
    // handle across cleanup; a cache hit must not compete with upstream I/O.
    $isPlaylist = $file === 'index.m3u8';
    $path = $dir . '/' . $file;
    $cached = $this->cachedFile($path, $isPlaylist);
    if ($cached !== null) { return $cached; }
    $lock = self::acquireLock($dir . '/' . ($isPlaylist ? 'playlist.lock' : 'fetch.lock'), 2500);
    if ($lock === false) {
      throw new \RuntimeException('USB relay busy');
    }
    try {
      $cached = $this->cachedFile($path, $isPlaylist);
      if ($cached !== null) { return $cached; }
      // Only names in the current validated playlist may request new segments.
      if ($file !== 'index.m3u8') {
        $playlistPath = $dir . '/index.m3u8';
        $playlist = is_file($playlistPath) ? (string) file_get_contents($playlistPath) : '';
        if (!str_contains($playlist, "\n" . $file . "\n")) {
          throw new \RuntimeException('Segment not advertised');
        }
      }
      $body = '';
      $limit = $file === 'index.m3u8' ? 16384 : 4 * 1024 * 1024;
      $curl = curl_init('http://127.0.0.1:' . $config['port'] . '/' . $file);
      curl_setopt_array($curl, [
        CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT_MS => 500, CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['token']],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
          if (strlen($body) + strlen($chunk) > $limit) { return 0; }
          $body .= $chunk;
          return strlen($chunk);
        },
      ]);
      try {
        $ok = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        if ($diagnostic && ($file === 'index.m3u8' || $ok === false || $code !== 200)) {
          $diagnostic('upstream', ['kind' => $file === 'index.m3u8' ? 'playlist' : 'segment',
            'http_status' => $code, 'curl_errno' => curl_errno($curl),
            'elapsed_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000), 'bytes' => strlen($body)]);
        }
      } finally { curl_close($curl); }
      if (($ok === false && $errno === CURLE_COULDNT_CONNECT)
        || ($ok !== false && $code === 503)) {
        throw new UsbStreamPending();
      }
      if ($ok === false || $code !== 200 || $body === '') {
        throw new \RuntimeException('USB stream not ready');
      }
      if ($file === 'index.m3u8') { self::playlist($body); }
      AtomicFileWriter::write($path, $body, null, 0600);
      if (!$isPlaylist) {
        $files = glob($dir . '/seg_*.ts') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, 10) as $old) { unlink($old); }
      }
      return $body;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  /** @return resource|false Caller must release after admission, before I/O. */
  public function admissionLock(string $slug): mixed
  {
    return self::acquireLock($this->directory($slug) . '/admission.lock', 500);
  }

  /** Stable inodes, monotonic deadline, no unbounded blocking flock. */
  private static function acquireLock(string $path, int $waitMs): mixed
  {
    $handle = fopen($path, 'c');
    if ($handle === false) { return false; }
    $deadline = hrtime(true) + $waitMs * 1000000;
    do {
      if (flock($handle, LOCK_EX | LOCK_NB)) { return $handle; }
      $remaining = $deadline - hrtime(true);
      if ($remaining <= 0) { break; }
      usleep((int) min(10000, max(1, intdiv($remaining, 1000))));
    } while (hrtime(true) < $deadline);
    fclose($handle);
    return false;
  }

  private function cachedFile(string $path, bool $freshOnly): ?string
  {
    $handle = @fopen($path, 'rb');
    if ($handle === false) { return null; }
    try {
      // Inspect the opened inode, not a pathname that may have been replaced.
      if ($freshOnly && (fstat($handle)['mtime'] ?? 0) < time() - 1) { return null; }
      $body = stream_get_contents($handle);
      if ($body === false) { throw new \RuntimeException('USB cache read failed'); }
      return $body;
    } finally { fclose($handle); }
  }
}
