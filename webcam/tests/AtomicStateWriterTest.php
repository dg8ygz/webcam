<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AtomicStateWriterTest extends TestCase
{
  public function testAtomicJsonWriterReplacesCompletePayloadAndKeepsMode(): void
  {
    $dir = TestTempDir::ensure() . '/atomic-state-' . bin2hex(random_bytes(5));
    self::assertTrue(mkdir($dir, 0775, true));
    $path = $dir . '/state.json';
    file_put_contents($path, "old\n");
    chmod($path, 0640);

    self::assertTrue(webcam_atomic_write_json($path, [
      'status' => 'ready',
      'count' => 3,
    ], JSON_PRETTY_PRINT));

    self::assertSame([
      'status' => 'ready',
      'count' => 3,
    ], json_decode((string) file_get_contents($path), true));
    self::assertSame(0640, fileperms($path) & 0777);
    self::assertSame([], glob($path . '.tmp-*') ?: []);
  }

  public function testEncodingFailureKeepsExistingStateUntouched(): void
  {
    $dir = TestTempDir::ensure() . '/atomic-state-' . bin2hex(random_bytes(5));
    self::assertTrue(mkdir($dir, 0775, true));
    $path = $dir . '/state.json';
    file_put_contents($path, "old\n");
    $resource = fopen('php://memory', 'rb');
    self::assertIsResource($resource);

    try {
      self::assertFalse(webcam_atomic_write_json($path, ['invalid' => $resource]));
    } finally {
      fclose($resource);
    }

    self::assertSame("old\n", file_get_contents($path));
    self::assertSame([], glob($path . '.tmp-*') ?: []);
  }
}
