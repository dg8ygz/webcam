<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Real independent PHP workers and threaded HTTP; only isolated synthetic data. */
final class UsbStreamConcurrencyTest extends TestCase
{
  private string $root;
  private array $children = [];
  private const SEGMENT = 'seg_1234567890abcdef_000001.ts';
  private const PLAYLIST = "#EXTM3U\n#EXTINF:2.0,\n" . self::SEGMENT . "\n";

  protected function setUp(): void
  {
    $this->root = TestTempDir::ensure() . '/usb-concurrent-' . bin2hex(random_bytes(4));
    mkdir($this->root . '/usb-stream/test', 0700, true);
    mkdir($this->root . '/origin');
    file_put_contents($this->root . '/cameras.php', '<?php return [];');
    file_put_contents($this->root . '/origin/index.m3u8', self::PLAYLIST);
    file_put_contents($this->root . '/origin/' . self::SEGMENT, str_repeat("synthetic\0", 200));
    file_put_contents($this->root . '/usb-stream/test/index.m3u8', self::PLAYLIST);
    touch($this->root . '/usb-stream/test/index.m3u8', time() - 10);
    $this->spawn(['python3', __DIR__ . '/fixtures/usb-hls-concurrent-origin.py', $this->root]);
    $this->await('port');
    file_put_contents($this->root . '/usb-stream/test/registration.json', json_encode([
      'port' => (int) file_get_contents($this->root . '/port'), 'token' => str_repeat('a', 64)]));
  }

  protected function tearDown(): void
  {
    foreach ($this->children as $child) { if (proc_get_status($child)['running']) proc_terminate($child); }
    $until = microtime(true) + 2;
    foreach ($this->children as $child) {
      while (proc_get_status($child)['running'] && microtime(true) < $until) usleep(10000);
      if (proc_get_status($child)['running']) proc_terminate($child, 9);
      proc_close($child);
    }
    $this->children = [];
  }

  private function spawn(array $command): void
  {
    $child = proc_open($command, [0 => ['file', '/dev/null', 'r'],
      1 => ['file', $this->root . '/child.log', 'a'], 2 => ['file', $this->root . '/child.log', 'a']], $pipes);
    self::assertIsResource($child);
    $this->children[] = $child;
  }

  private function worker(string $id, string $file, string $mode = 'fetch', string $ip = '192.0.2.1', string $hold = ''): void
  {
    $this->spawn([PHP_BINARY, __DIR__ . '/fixtures/usb-hls-worker.php', $this->root,
      $mode, $file, $this->root . '/' . $id . '.json', $ip, $hold]);
  }

  private function await(string $file, float $seconds = 3): void
  {
    $until = microtime(true) + $seconds;
    do {
      clearstatcache(true, $this->root . '/' . $file);
      if (is_file($this->root . '/' . $file) && ($file !== 'port' || filesize($this->root . '/' . $file) > 0)) return;
      usleep(10000);
    } while (microtime(true) < $until);
    self::fail('Timed out waiting for fixture: ' . $file);
  }

  private function workerResult(string $id): array
  {
    $this->await($id . '.json', 6);
    return json_decode(file_get_contents($this->root . '/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
  }

  public function testPlaylistAndSegmentsDoNotRejectEachOther(): void
  {
    touch($this->root . '/hold-index.m3u8');
    $this->worker('playlist', 'index.m3u8');
    $this->await('entered-index.m3u8');
    $this->worker('segment', self::SEGMENT);
    $segment = $this->workerResult('segment');
    self::assertSame(200, $segment['status'] ?? null, json_encode($segment));
    self::assertFileDoesNotExist($this->root . '/playlist.json', 'Segment must finish while playlist is waiting');
    unlink($this->root . '/hold-index.m3u8');
    self::assertSame(200, $this->workerResult('playlist')['status']);
  }

  public function testConcurrentMissesShareOneFetchAndCachedSegmentsRemainAvailable(): void
  {
    $cached = 'seg_1234567890abcdef_000002.ts';
    file_put_contents($this->root . '/usb-stream/test/' . $cached, 'cached media');
    touch($this->root . '/hold-' . self::SEGMENT);
    $this->worker('first', self::SEGMENT);
    $this->await('entered-' . self::SEGMENT);
    $this->worker('second', self::SEGMENT);
    $this->worker('cached', $cached);
    self::assertSame(200, $this->workerResult('cached')['status']);
    usleep(100000);
    self::assertFileDoesNotExist($this->root . '/second.json', 'Follower should wait for the shared fetch');
    unlink($this->root . '/hold-' . self::SEGMENT);
    $first = $this->workerResult('first');
    $second = $this->workerResult('second');
    self::assertSame(200, $second['status'] ?? null, json_encode($second));
    self::assertSame($first['hash'], $second['hash']);
    self::assertSame([self::SEGMENT], file($this->root . '/requests', FILE_IGNORE_NEW_LINES));
  }

  public function testAdmissionWaitPreservesViewerLimitAndExistingViewer(): void
  {
    file_put_contents($this->root . '/usb-stream/test/' . self::SEGMENT, 'cached media');
    $this->worker('first', self::SEGMENT, 'admit', '192.0.2.1', 'hold');
    $this->await('admitted');
    $this->worker('second', self::SEGMENT, 'admit', '192.0.2.1');
    $this->worker('other', self::SEGMENT, 'admit', '192.0.2.2');
    self::assertSame(200, $this->workerResult('first')['status']);
    $second = $this->workerResult('second');
    self::assertSame(200, $second['status'], json_encode($second));
    $other = $this->workerResult('other');
    self::assertSame(503, $other['status']);
    self::assertSame(['viewer_limit'], $other['events']);
    self::assertSame(1, $other['viewers']);
  }

  public function testPreparationWaitsWithoutMediaErrorsAndPreservesRealFailures(): void
  {
    $this->worker('probe', 'index.m3u8', 'probe');
    self::assertSame(200, $this->workerResult('probe')['status']);
    self::assertFileDoesNotExist($this->root . '/usb-stream/test/demand');
    $this->worker('wrong-method', 'index.m3u8', 'prepare-get');
    self::assertSame(405, $this->workerResult('wrong-method')['status']);
    self::assertFileDoesNotExist($this->root . '/usb-stream/test/demand');

    file_put_contents($this->root . '/status', '503');
    $this->worker('pending', 'index.m3u8', 'prepare');
    $pending = $this->workerResult('pending');
    self::assertSame(202, $pending['status']);
    self::assertFalse(json_decode($pending['body'], true)['ready']);
    self::assertSame(['upstream', 'preparation_pending'], $pending['events']);
    self::assertFileExists($this->root . '/usb-stream/test/demand');
    $this->worker('media-pending', 'index.m3u8');
    self::assertSame('USB stream not ready', $this->workerResult('media-pending')['error']);

    unlink($this->root . '/status');
    $this->worker('ready', 'index.m3u8', 'prepare');
    $ready = $this->workerResult('ready');
    self::assertSame(200, $ready['status']);
    self::assertTrue(json_decode($ready['body'], true)['ready']);
    self::assertSame(self::PLAYLIST, file_get_contents($this->root . '/usb-stream/test/index.m3u8'));

    unlink($this->root . '/usb-stream/test/index.m3u8');
    file_put_contents($this->root . '/status', '403');
    $this->worker('auth-failure', 'index.m3u8', 'prepare');
    self::assertSame(503, $this->workerResult('auth-failure')['status'], 'Upstream auth failure is not normal startup');
    unlink($this->root . '/status');
    file_put_contents($this->root . '/origin/index.m3u8', 'invalid playlist');
    $this->worker('invalid-playlist', 'index.m3u8', 'prepare');
    self::assertSame(503, $this->workerResult('invalid-playlist')['status'], 'Invalid media must not become ready');
  }

  public function testStuckFetchHasBoundedWait(): void
  {
    $lock = fopen($this->root . '/usb-stream/test/fetch.lock', 'c');
    self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
    try {
      $this->worker('blocked', self::SEGMENT);
      $result = $this->workerResult('blocked');
      self::assertSame('USB relay busy', $result['error']);
      self::assertGreaterThanOrEqual(2.0, $result['elapsed']);
      self::assertLessThan(3.5, $result['elapsed']);
      self::assertFileDoesNotExist($this->root . '/requests');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }
}
