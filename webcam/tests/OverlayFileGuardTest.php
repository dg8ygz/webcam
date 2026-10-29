<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Overlay\OverlayFileGuard;

final class OverlayFileGuardTest extends TestCase
{
  public function testSecondGuardCannotAcquireHeldOperationLock(): void
  {
    $app = $this->makeApp();
    $first = OverlayFileGuard::acquire($app, 1);

    $this->expectException(RuntimeException::class);
    try {
      OverlayFileGuard::acquire($app, 1);
    } finally {
      $first->release();
    }
  }

  public function testRecentlyPublishedProtectsOnlyFilesInsideGraceWindow(): void
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'overlay-grace-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $path = $root . '/logo.png';
    file_put_contents($path, 'png');
    touch($path, 1000);

    self::assertTrue(OverlayFileGuard::recentlyPublished($path, 1050, 60));
    self::assertFalse(OverlayFileGuard::recentlyPublished($path, 1060, 60));
    self::assertFalse(OverlayFileGuard::recentlyPublished($path, 1050, 0));
  }

  private function makeApp(): App
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'overlay-lock-' . bin2hex(random_bytes(4));
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db'] as $dir) {
      mkdir($root . DIRECTORY_SEPARATOR . $dir, 0775, true);
    }
    $cameraConfig = $root . DIRECTORY_SEPARATOR . 'cameras.php';
    file_put_contents($cameraConfig, "<?php\nreturn [];\n");

    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $cameraConfig,
      'cache_path' => $root . DIRECTORY_SEPARATOR . 'cache',
      'storage_path' => $root . DIRECTORY_SEPARATOR . 'storage',
      'archive_path' => $root . DIRECTORY_SEPARATOR . 'archive',
      'state_path' => $root . DIRECTORY_SEPARATOR . 'state',
      'log_path' => $root . DIRECTORY_SEPARATOR . 'log',
      'info_path' => $root . DIRECTORY_SEPARATOR . 'info',
      'camera_db_path' => $root . DIRECTORY_SEPARATOR . 'camera-db',
    ]);
  }
}
