<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Service/Stream/hls_functions.php';

final class HlsFilesTest extends TestCase
{
  private function root(): string
  {
    $root = TestTempDir::ensure() . '/hls-files-' . bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    return $root;
  }

  /** Run the real response function in a fresh PHP process (headers/status). */
  private function response(string $root, string $file): array
  {
    $code = 'require $argv[1]; require $argv[2];'
      . '$logger = new Webcam\\Infrastructure\\Logging\\Logger($argv[3]."/test.log");'
      . 'http_response_code(200); ob_start();'
      . 'serve_hls_file($logger,"fixture",$argv[3],$argv[4]);'
      . '$body=ob_get_clean(); echo json_encode([http_response_code(),$body]);';
    $process = proc_open([
      PHP_BINARY, '-r', $code, __DIR__ . '/../autoload.php',
      __DIR__ . '/../src/Service/Stream/hls_functions.php', $root, $file,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    self::assertIsResource($process);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    self::assertSame(0, proc_close($process), $error);
    self::assertSame('', $error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
  }

  public function testExpiredSegmentIsGoneInsteadOfAProxy404Violation(): void
  {
    self::assertSame([410, ''], $this->response($this->root(), 'seg_00450.ts'));
  }

  public function testMissingPlaylistIsTemporarilyUnavailable(): void
  {
    self::assertSame([503, ''], $this->response($this->root(), 'index.m3u8'));
  }

  public function testExistingSegmentIsDeliveredUnchanged(): void
  {
    $root = $this->root();
    file_put_contents($root . '/seg_1790280000000000.ts', "fixture\0segment");
    self::assertSame([200, "fixture\0segment"], $this->response($root, 'seg_1790280000000000.ts'));
  }

  public function testPlaylistPreservesLargeSequenceAndRoutesSegments(): void
  {
    $root = $this->root();
    file_put_contents($root . '/index.m3u8', "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:1790280000000000\nseg_1790280000000000.ts\n");
    [$status, $body] = $this->response($root, 'index.m3u8');
    self::assertSame(200, $status);
    self::assertStringContainsString('#EXT-X-MEDIA-SEQUENCE:1790280000000000', $body);
    self::assertStringContainsString('?slug=fixture&format=hls&file=seg_1790280000000000.ts', $body);
    $segment = array_values(array_filter(explode("\n", $body), static fn($line) => str_starts_with($line, '?')))[0];
    parse_str(substr($segment, 1), $query);
    self::assertSame('fixture', $query['slug']);
    self::assertSame('hls', $query['format']);
    self::assertTrue(is_valid_hls_file($query['file']));
  }

  public function testStalledPlaylistIsNotReadyAndAnExternalRefreshIsRecognized(): void
  {
    $root = $this->root();
    $path = $root . '/index.m3u8';
    self::assertFalse(hls_playlist_is_fresh($path));
    file_put_contents($path, '');
    self::assertFalse(hls_playlist_is_fresh($path));
    file_put_contents($path, "#EXTM3U\n");
    touch($path, time() - 60);
    self::assertFalse(wait_for_hls_playlist($path, 1));
    // A separate process mimics ffmpeg replacing the file behind PHP's stat cache.
    $process = proc_open([PHP_BINARY, '-r', 'touch($argv[1]);', $path], [], $pipes);
    self::assertIsResource($process);
    self::assertSame(0, proc_close($process));
    self::assertTrue(wait_for_hls_playlist($path, 1));
  }
}
