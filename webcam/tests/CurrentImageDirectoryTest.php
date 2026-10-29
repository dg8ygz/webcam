<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\CurrentImageDirectory;

final class CurrentImageDirectoryTest extends TestCase
{
  public function testNewCameraAndLegacyCameraKeepStableLocations(): void
  {
    $root = TestTempDir::ensure() . '/current-path-' . bin2hex(random_bytes(6));
    mkdir($root . '/old-camera/current', 0775, true);
    $paths = new CurrentImageDirectory($root);
    self::assertSame($root . '/old-camera/current', $paths->prepare('old-camera'));
    self::assertSame($root . '/cache/new-camera/current', $paths->prepare('new-camera'));
    self::assertDirectoryDoesNotExist($root . '/new-camera');
    // A later execution of the old root helper must not change the writer.
    mkdir($root . '/new-camera/current', 0775, true);
    self::assertSame($root . '/cache/new-camera/current', $paths->prepare('new-camera'));
  }

  public function testInvalidSlugCannotEscapeRuntimeDirectory(): void
  {
    $this->expectException(RuntimeException::class);
    (new CurrentImageDirectory('/tmp'))->prepare('../outside');
  }

  public function testCreationFailureIsNotReportedAsReady(): void
  {
    $root = TestTempDir::ensure() . '/current-failure-' . bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root . '/cache', 'blocking file');
    $this->expectException(RuntimeException::class);
    (new CurrentImageDirectory($root))->prepare('camera');
  }
}
