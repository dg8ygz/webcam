<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Service\Stream\UsbStream;

/** Real FFmpeg -> loopback HTTP -> shared relay cache, never a live camera. */
final class UsbStreamTransportTest extends TestCase
{
  public function testSyntheticHlsIsFetchedOnceAndUnsafeNamesNeverReachOrigin(): void
  {
    self::assertTrue(is_executable('/usr/bin/ffmpeg'));
    $root = TestTempDir::ensure() . '/usb-media-' . bin2hex(random_bytes(4));
    mkdir($root . '/usb-stream/test', 0700, true);
    $children = [];
    try {
      // Random free port within the production allowlist; bind fixture itself
      // before starting ffmpeg so collisions fail visibly, not against live state.
      $port = random_int(23500, 23999);
      $children[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/usb-hls-origin.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $root . '/http.log', 'w']], $pipes,
        null, array_merge(getenv(), ['USB_HLS_FIXTURE' => $root]));
      $children[] = proc_open(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-re',
        '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=3', '-an', '-c:v', 'libx264', '-threads', '1',
        '-g', '6', '-sc_threshold', '0', '-f', 'hls', '-hls_time', '2', '-hls_list_size', '6',
        '-hls_flags', 'delete_segments+temp_file+independent_segments',
        '-hls_segment_filename', $root . '/seg_1234567890abcdef_%06d.ts', $root . '/index.m3u8'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $root . '/ffmpeg.log', 'w']], $pipes);
      $end = microtime(true) + 8;
      do { clearstatcache(); if (is_file($root . '/index.m3u8')) break; usleep(100000); } while (microtime(true) < $end);
      self::assertFileExists($root . '/index.m3u8');
      self::assertTrue(proc_get_status($children[0])['running']);
      $s = new UsbStream(new PathResolver(null, null, $root, null));
      file_put_contents($root . '/usb-stream/test/registration.json', json_encode(['port' => $port, 'token' => str_repeat('a', 64)]));
      $diagnostics = [];
      $diagnostic = static function (string $stage, array $data) use (&$diagnostics): void { $diagnostics[] = [$stage, $data]; };
      $playlist = $s->fetch('test', 'index.m3u8', $diagnostic);
      self::assertSame('upstream', $diagnostics[0][0]);
      self::assertSame(200, $diagnostics[0][1]['http_status']);
      self::assertSame(0, $diagnostics[0][1]['curl_errno']);
      self::assertStringNotContainsString(str_repeat('a', 64), json_encode($diagnostics));
      self::assertStringStartsWith('#EXTM3U', $playlist);
      preg_match('/^seg_[a-f0-9]{16}_[0-9]+\.ts$/m', $playlist, $m);
      self::assertNotEmpty($m);
      $body = $s->fetch('test', $m[0]);
      self::assertGreaterThan(188, strlen($body));
      self::assertSame($body, $s->fetch('test', $m[0]));
      $requests = file($root . '/requests', FILE_IGNORE_NEW_LINES);
      self::assertSame(1, count(array_filter($requests, static fn($v) => $v === $m[0])));
      try { $s->fetch('test', 'seg_aaaaaaaaaaaaaaaa_999999.ts'); self::fail('Unadvertised segment fetched'); }
      catch (RuntimeException $e) { self::assertSame('Segment not advertised', $e->getMessage()); }
      unlink($root . '/usb-stream/test/index.m3u8');
      file_put_contents($root . '/usb-stream/test/registration.json', json_encode(['port' => $port, 'token' => str_repeat('b', 64)]));
      try { $s->fetch('test', 'index.m3u8', $diagnostic); self::fail('Bad authentication accepted'); }
      catch (RuntimeException $e) { self::assertSame('USB stream not ready', $e->getMessage()); }
      self::assertSame(403, $diagnostics[1][1]['http_status']);
      self::assertSame(0, $diagnostics[1][1]['curl_errno']);
      self::assertStringNotContainsString(str_repeat('b', 64), json_encode($diagnostics));
    } finally {
      foreach ($children as $p) { if (is_resource($p)) proc_terminate($p); }
      $until = microtime(true) + 2;
      foreach ($children as $p) {
        if (!is_resource($p)) continue;
        while (proc_get_status($p)['running'] && microtime(true) < $until) usleep(50000);
        if (proc_get_status($p)['running']) proc_terminate($p, 9);
        proc_close($p);
      }
    }
  }
}
