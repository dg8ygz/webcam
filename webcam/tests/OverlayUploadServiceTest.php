<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Overlay\OverlayFileGuard;
use Webcam\Service\Overlay\OverlayImageLimits;
use Webcam\Service\Overlay\OverlayUploadException;
use Webcam\Service\Overlay\OverlayUploadService;

/**
 * Prüft die ausgelagerte Sicherheitsgrenze des Admin-Overlay-Uploads.
 *
 * Die Tests umgehen bewusst HTTP und is_uploaded_file(): Sie beginnen genau an
 * der Stelle, an der der Multipart-Adapter eine echte PHP-Uploaddatei bereits
 * bestätigt hat. So lassen sich Inhalt, Limit, Zielname, Lock und atomare
 * Publikation mit isolierten temporären Verzeichnissen reproduzierbar prüfen.
 */
final class OverlayUploadServiceTest extends TestCase
{
  private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
  private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==';

  private string $root;
  private App $app;
  private OverlayUploadService $service;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/overlay-upload-' . bin2hex(random_bytes(5));
    $this->app = $this->createApp($this->root);
    $this->service = new OverlayUploadService($this->app, 1024 * 1024, 1);
  }

  public function testStoresPngAtomicallyWithSafeNameAndProtectedPublicUrl(): void
  {
    $source = $this->writeFixture('incoming.png', self::PNG_1X1);
    $result = $this->service->store(
      'testcam',
      $source,
      'Mein Logo.PNG',
      filesize($source) ?: 0,
      1788100000
    );

    self::assertSame('Mein-Logo-1788100000.png', $result['filename']);
    self::assertSame(1, $result['width']);
    self::assertSame(1, $result['height']);
    self::assertSame(
      'overlay.php?slug=testcam&file=Mein-Logo-1788100000.png',
      $result['url']
    );
    self::assertSame($result['url'], $result['relative_path']);
    self::assertSame(
      'cache/testcam/overlays/Mein-Logo-1788100000.png',
      $result['storage_path']
    );

    $target = $this->root . '/cache/testcam/overlays/' . $result['filename'];
    self::assertFileExists($target);
    self::assertSame(0664, fileperms($target) & 0777);
    self::assertFileDoesNotExist($source);
  }

  public function testStoresJpegUsingContentDerivedExtension(): void
  {
    $source = $this->writeFixture('incoming.data', self::JPEG_1X1);
    $result = $this->service->store(
      'testcam',
      $source,
      'Kamera.PNG',
      filesize($source) ?: 0,
      1788100001
    );

    // Der Clientname behauptet PNG; maßgeblich ist der erkannte JPEG-Inhalt.
    self::assertSame('Kamera-1788100001.jpg', $result['filename']);
    self::assertSame(1, $result['width']);
    self::assertSame(1, $result['height']);
    self::assertFileExists(
      $this->root . '/cache/testcam/overlays/Kamera-1788100001.jpg'
    );
  }

  public function testRejectsUnsupportedContentAndRemovesTemporaryFile(): void
  {
    $source = $this->writeRawFile('not-an-image.txt', "not an image\n");

    try {
      $this->service->store('testcam', $source, 'fake.png', filesize($source) ?: 0);
      self::fail('Unsupported content was accepted');
    } catch (OverlayUploadException $exception) {
      self::assertSame(OverlayUploadException::UNSUPPORTED_MEDIA, $exception->reason);
      self::assertSame('Unsupported media type', $exception->getMessage());
    }

    self::assertFileDoesNotExist($source);
  }

  public function testActualFileSizeCannotBeHiddenBySmallerReportedSize(): void
  {
    $source = $this->writeFixture('oversized.png', self::PNG_1X1);
    $smallLimitService = new OverlayUploadService($this->app, 16, 1);

    try {
      $smallLimitService->store('testcam', $source, 'small.png', 1);
      self::fail('Oversized upload was accepted');
    } catch (OverlayUploadException $exception) {
      self::assertSame(OverlayUploadException::TOO_LARGE, $exception->reason);
      self::assertSame('Overlay exceeds size limit', $exception->getMessage());
    }

    self::assertFileExists($source);
    self::assertDirectoryDoesNotExist($this->root . '/cache/testcam/overlays');
  }

  public function testBusyGuardRejectsPublicationWithoutConsumingSource(): void
  {
    $source = $this->writeFixture('waiting.png', self::PNG_1X1);
    $guard = OverlayFileGuard::acquire($this->app, 1);
    try {
      $this->service->store('testcam', $source, 'waiting.png', filesize($source) ?: 0);
      self::fail('Upload unexpectedly acquired an already held overlay lock');
    } catch (OverlayUploadException $exception) {
      self::assertSame(OverlayUploadException::BUSY, $exception->reason);
      self::assertSame('Overlay-Verarbeitung ist gerade belegt', $exception->getMessage());
    } finally {
      $guard->release();
    }

    self::assertFileExists($source);
    self::assertDirectoryDoesNotExist($this->root . '/cache/testcam/overlays');
  }

  public function testUnknownCameraIsRejectedBeforeFilesystemWrite(): void
  {
    $source = $this->writeFixture('unknown.png', self::PNG_1X1);

    try {
      $this->service->store('missing', $source, 'unknown.png', filesize($source) ?: 0);
      self::fail('Unknown camera was accepted');
    } catch (OverlayUploadException $exception) {
      self::assertSame(OverlayUploadException::CAMERA_NOT_FOUND, $exception->reason);
      self::assertSame('Camera not found', $exception->getMessage());
    }

    self::assertFileExists($source);
  }

  private function writeFixture(string $filename, string $base64): string
  {
    $contents = base64_decode($base64, true);
    self::assertIsString($contents);
    return $this->writeRawFile($filename, $contents);
  }

  public function testRejectsExtremeDimensionsBeforePublicationWithoutDecodingPixels(): void
  {
    foreach ([[4097, 1], [1, 4097], [4096, 2049], [65535, 65535]] as [$width, $height]) {
      $source = $this->writeFixture('header.png', self::PNG_1X1);
      $png = (string) file_get_contents($source);
      $png = substr_replace($png, pack('NN', $width, $height), 16, 8);
      $png = substr_replace($png, pack('N', crc32(substr($png, 12, 17))), 29, 4);
      file_put_contents($source, $png);
      self::assertLessThan(100, filesize($source));
      try {
        $this->service->store('testcam', $source, 'huge.png', 1);
        self::fail('Extreme image dimensions accepted');
      } catch (OverlayUploadException $exception) {
        self::assertSame(OverlayUploadException::TOO_LARGE, $exception->reason);
        self::assertStringContainsString('dimension limit', $exception->getMessage());
      }
      self::assertFileDoesNotExist($source);
      self::assertDirectoryDoesNotExist($this->root . '/cache/testcam/overlays');
    }
  }

  public function testDimensionBoundariesAndExistingLogoSizes(): void
  {
    foreach ([[4096, 2048], [2048, 4096], [3867, 1620], [2941, 2839], [494, 495]] as [$w, $h]) {
      self::assertTrue(OverlayImageLimits::allows($w, $h));
    }
    foreach ([[0, 1], [1, -1], [PHP_INT_MAX, PHP_INT_MAX], [4096, 4096]] as [$w, $h]) {
      self::assertFalse(OverlayImageLimits::allows($w, $h));
    }
    self::assertTrue(OverlayImageLimits::allows(512, 512, true));
    self::assertFalse(OverlayImageLimits::allows(513, 1, true));
    self::assertFalse(OverlayImageLimits::allows(1, 513, true));
    self::assertFalse(OverlayImageLimits::allows(2941, 2839, true));
  }

  public function testRejectsExcessiveJpegHeightEvenWithTinyFileSize(): void
  {
    $image = imagecreatetruecolor(1, 4097);
    $source = $this->writeRawFile('tall.jpg', '');
    imagejpeg($image, $source);
    imagedestroy($image);
    self::assertLessThan(1048576, filesize($source));
    $this->expectException(OverlayUploadException::class);
    $this->expectExceptionMessage('dimension limit');
    $this->service->store('testcam', $source, 'tall.jpg', 1);
  }

  public function testLogoAnalysisRejectsLargeLegacyFileBeforeGdDecode(): void
  {
    $source = $this->writeFixture('legacy.png', self::PNG_1X1);
    OverlayImageLimits::assertAnalyzable($source);
    $png = (string) file_get_contents($source);
    $png = substr_replace($png, pack('NN', 4096, 4096), 16, 8);
    file_put_contents($source, $png);
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Logo analysis limit');
    OverlayImageLimits::assertAnalyzable($source);
  }

  private function writeRawFile(string $filename, string $contents): string
  {
    $directory = $this->root . '/incoming';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      self::fail('Unable to create upload fixture directory');
    }
    $path = $directory . '/' . $filename;
    file_put_contents($path, $contents);
    return $path;
  }

  private function createApp(string $root): App
  {
    $configPath = $root . '/cameras.php';
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
      self::fail('Unable to create test root');
    }
    $cameras = [[
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'Testcam',
      'title' => 'Testcam',
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => $root . '/storage/testcam',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'location' => [],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [],
      'weather_commands' => [],
    ]];
    file_put_contents($configPath, '<?php return ' . var_export($cameras, true) . ';');

    return new App([
      'timezone' => 'UTC',
      'camera_config_path' => $configPath,
      'storage_path' => $root . '/storage',
      'archive_path' => $root . '/storage/archive',
      'state_path' => $root . '/state',
      'log_path' => $root . '/log',
      'info_path' => $root . '/info',
      'cache_path' => $root . '/cache',
      'camera_db_path' => $root . '/camera-db',
      'base_url' => '',
    ]);
  }
}
