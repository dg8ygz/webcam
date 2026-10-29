<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Service\Stream\UsbStream;

final class UsbStreamTest extends TestCase
{
  public function testDiagnosticsRequireShortLocalLeaseAndTestSlug(): void
  {
    require_once __DIR__ . '/../src/Service/Stream/usb_functions.php';
    [$s, $dir] = $this->fixture();
    $logger = new \Webcam\Infrastructure\Logging\Logger($dir . '/diagnostic.log');
    self::assertNull(usb_stream_diagnostic($s, $logger, 'test'));
    foreach ([(string) (time() - 1), (string) (time() + 3600), 'invalid'] as $stamp) {
      file_put_contents($dir . '/diagnostic-until', $stamp);
      self::assertNull(usb_stream_diagnostic($s, $logger, 'test'));
    }
    file_put_contents($dir . '/diagnostic-until', (string) (time() + 120));
    self::assertNull(usb_stream_diagnostic($s, $logger, 'bielefeld'));
    $diagnostic = usb_stream_diagnostic($s, $logger, 'test');
    self::assertIsCallable($diagnostic);
    $diagnostic('probe', ['status' => 200, 'ready' => true]);
    $entry = json_decode(trim(file_get_contents($dir . '/diagnostic.log')), true);
    self::assertSame('USB stream diagnostic', $entry['message']);
    self::assertSame('probe', $entry['context']['stage']);
    self::assertSame(200, $entry['context']['status']);
    self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/D', $entry['context']['request']);
  }

  private function fixture(): array
  {
    $root = TestTempDir::ensure() . '/usb-' . bin2hex(random_bytes(5));
    mkdir($root . '/usb-stream/test', 0700, true);
    $service = new UsbStream(new PathResolver(null, null, $root, null));
    return [$service, $root . '/usb-stream/test'];
  }

  public function testDefaultOffAndDemandExpiry(): void
  {
    [$s, $dir] = $this->fixture();
    self::assertNull($s->registration('test'));
    self::assertFalse($s->command('test')['active']);
    file_put_contents($dir . '/registration.json', json_encode(['port' => 23001, 'token' => str_repeat('a', 64)]));
    self::assertFalse($s->command('test')['active']);
    $s->demand('test');
    self::assertSame(30, $s->command('test')['ttl']);
    self::assertFalse($s->command('test', time() + 46)['active']);
    self::assertFalse($s->command('test', time() - 10)['active']);
  }

  public function testRegistrationCannotChooseExternalUrl(): void
  {
    [$s, $dir] = $this->fixture();
    file_put_contents($dir . '/registration.json', '{"port":22,"token":"x"}');
    $this->expectException(RuntimeException::class);
    $s->registration('test');
  }

  public function testCachedSegmentSurvivesConcurrentUpstreamFetch(): void
  {
    [$service, $dir] = $this->fixture();
    file_put_contents($dir . '/registration.json', json_encode(['port' => 23001, 'token' => str_repeat('a', 64)]));
    $file = 'seg_1234567890abcdef_000001.ts';
    file_put_contents($dir . '/' . $file, "cached\0segment");
    $lock = fopen($dir . '/fetch.lock', 'c');
    self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
    try {
      self::assertSame("cached\0segment", $service->fetch('test', $file));
      // Cache misses still cannot initiate another concurrent segment fetch;
      // the bounded contention timeout is covered by the multi-process test.
      $this->expectExceptionMessage('USB relay busy');
      $service->fetch('test', 'seg_1234567890abcdef_000002.ts');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  public function testPlaylistAllowsOnlyBoundedMediaNames(): void
  {
    $p = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-INDEPENDENT-SEGMENTS\n#EXTINF:2.000000,\nseg_1234567890abcdef_000001.ts\n";
    self::assertSame($p, UsbStream::playlist($p));
    foreach (['../key', 'https://example.org/x', 'index.m3u8', '#EXT-X-KEY:METHOD=AES-128,URI="http://evil"', '#EXT-X-MAP:URI="key"', 'seg_1234567890abcdef_000001.ts?secret=x'] as $bad) {
      try { UsbStream::playlist($p . $bad . "\n"); self::fail('Accepted unsafe playlist'); }
      catch (RuntimeException $error) { self::assertSame('Unsafe USB playlist', $error->getMessage()); }
    }
    self::assertFalse(UsbStream::validFile('../registration.json'));
    self::assertFalse(UsbStream::validFile('seg_1234567890abcdef_000001.ts.tmp'));
  }
}
