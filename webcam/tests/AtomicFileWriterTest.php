<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

require_once __DIR__ . '/../bootstrap.php';

final class AtomicFileWriterTest extends TestCase
{
  private string $directory;

  protected function setUp(): void
  {
    $this->directory = TestTempDir::ensure() . '/atomic-file-' . bin2hex(random_bytes(5));
    self::assertTrue(mkdir($this->directory, 0775, true));
  }

  protected function tearDown(): void
  {
    foreach (glob($this->directory . '/*') ?: [] as $path) {
      @unlink($path);
    }
    foreach (glob($this->directory . '/.*.tmp-*') ?: [] as $path) {
      @unlink($path);
    }
    @rmdir($this->directory);
  }

  public function testWriteReplacesCompleteContentsAndKeepsExistingMode(): void
  {
    $destination = $this->directory . '/capture.jpg';
    file_put_contents($destination, 'old');
    chmod($destination, 0640);

    self::assertTrue(AtomicFileWriter::write($destination, str_repeat('new-data', 1024)));
    self::assertSame(str_repeat('new-data', 1024), file_get_contents($destination));
    self::assertSame(0640, fileperms($destination) & 0777);
    self::assertSame([], glob($this->directory . '/.*.tmp-*') ?: []);
  }

  public function testFailedCallbackKeepsExistingFileUntouched(): void
  {
    $destination = $this->directory . '/current.jpg';
    file_put_contents($destination, 'complete-old-image');

    self::assertFalse(AtomicFileWriter::writeWithCallback(
      $destination,
      static function ($handle): bool {
        fwrite($handle, 'partial-new-image');
        return false;
      }
    ));

    self::assertSame('complete-old-image', file_get_contents($destination));
    self::assertSame([], glob($this->directory . '/.*.tmp-*') ?: []);
  }

  public function testCopyPublishesExactBytesAndSourceTimestamp(): void
  {
    $source = $this->directory . '/source.jpg';
    $destination = $this->directory . '/destination.jpg';
    $payload = random_bytes(128 * 1024);
    file_put_contents($source, $payload);
    chmod($source, 0600);
    $mtime = time() - 3600;
    touch($source, $mtime);

    self::assertTrue(AtomicFileWriter::copy($source, $destination, 0664));
    self::assertSame(hash_file('sha256', $source), hash_file('sha256', $destination));
    self::assertSame($mtime, filemtime($destination));
    self::assertSame(0664, fileperms($destination) & 0777);
    self::assertSame([], glob($this->directory . '/.*.tmp-*') ?: []);
  }

  public function testValidatorRejectsStagedFileWithoutReplacingDestination(): void
  {
    $destination = $this->directory . '/variant.jpg';
    file_put_contents($destination, 'valid-old');

    self::assertFalse(AtomicFileWriter::writeWithCallback(
      $destination,
      static fn($handle): bool => fwrite($handle, 'invalid-new') !== false,
      static fn(string $path): bool => filesize($path) > 100
    ));

    self::assertSame('valid-old', file_get_contents($destination));
    self::assertSame([], glob($this->directory . '/.*.tmp-*') ?: []);
  }
}
