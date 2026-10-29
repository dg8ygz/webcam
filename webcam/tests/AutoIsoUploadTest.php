<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Capture\AutoIsoController;
use Webcam\Service\Storage\CaptureExifMetadata;
use Webcam\Service\Upload\UploadIngestor;

final class AutoIsoUploadTest extends TestCase
{
  public static function processingModes(): array { return [[false], [true]]; }
  #[\PHPUnit\Framework\Attributes\DataProvider('processingModes')]
  public function testRealJpegExifFlowsIntoQueueAndDuplicateDoesNotReplay(bool $processing): void
  {
    $root = TestTempDir::ensure() . '/iso-upload-' . bin2hex(random_bytes(4)); mkdir($root);
    $config = ['id' => 'iso-upload', 'slug' => 'iso-upload', 'name' => 'ISO', 'title' => 'ISO',
      'mode' => 'active-capture', 'location' => [], 'resolutions' => [],
      'capture' => ['interval_seconds' => 600, 'auto_iso' => ['mode' => 'auto', 'min_iso' => 100, 'max_iso' => 400]],
      'storage' => ['base_path' => $root . '/storage', 'current_dir' => $root . '/current', 'archive_dir' => 'archive', 'best_of_dir' => 'bestof']];
    if ($processing) $config['capture']['image_processing'] = ['enabled' => true, 'gamma' => 2, 'regions' => [
      ['type' => 'mask', 'x' => 0, 'y' => 0, 'width' => 32, 'height' => 48, 'color' => '#ffffff'],
    ]];
    file_put_contents($root . '/cameras.php', '<?php return ' . var_export([$config], true) . ';');
    $appConfig = ['timezone' => 'UTC', 'camera_config_path' => $root . '/cameras.php', 'base_url' => ''];
    foreach (['storage', 'archive', 'cache', 'state', 'log', 'info', 'camera_db'] as $dir) $appConfig[$dir . '_path'] = $root . '/' . $dir;
    $app = new App($appConfig); $camera = $app->cameraOrFail('iso-upload');
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 10, 10));
    ob_start(); imagejpeg($image, null, 90); $jpeg = ob_get_clean(); imagedestroy($image);
    // Minimal valid TIFF: model in IFD0, exposure rational and ISO in Exif IFD.
    $model = "Canon EOS 1100D\0";
    $sub = 38 + strlen($model); $rational = $sub + 42;
    $tiff = "II" . pack('vV', 42, 8) . pack('v', 2)
      . pack('vvVV', 0x0110, 2, strlen($model), 38)
      . pack('vvVV', 0x8769, 4, 1, $sub) . pack('V', 0) . $model
      . pack('v', 3) . pack('vvVV', 0x829a, 5, 1, $rational)
      . pack('vvVV', 0x8827, 3, 1, 100) . pack('vvVV', 0x9204, 10, 1, $rational + 8)
      . pack('V', 0) . pack('VV', 301, 10) . pack('VV', 5, 3);
    $exif = "Exif\0\0" . $tiff;
    $jpeg = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    $path = $root . '/test.jpg'; file_put_contents($path, $jpeg);
    $summary = (new CaptureExifMetadata($app))->read($camera, $path, 3.9, $raw);
    self::assertSame(30.1, $raw['seconds']);
    self::assertSame(100.0, $raw['iso']);
    self::assertEqualsWithDelta(5 / 3, $raw['bias'], 0.00001);
    self::assertSame('1.7 eV', $summary['Exposure Bias']);
    self::assertSame('30 sec.', $summary['Exposure Time']); // regression: previously trailing zero was lost
    $q = new AutoIsoController($app->paths()->statePath('auto-iso'));
    $now = time(); $q->poll($camera, $now);
    $uploader = new UploadIngestor($app);
    $result = $uploader->ingestFile($camera, $path, $now);
    self::assertSame(200, $result['status'], json_encode($result));
    self::assertFalse($result['payload']['duplicate']);
    self::assertSame('queued', $q->status($camera)['state']);
    self::assertSame(400, $q->status($camera)['decision']['target_iso']);
    // Ingestion can cross a second boundary; polling must not travel back
    // before the pending command's creation time.
    $dispatch = time();
    self::assertSame("command\n{$dispatch}\nwebcam_isoexp 400\n", $q->poll($camera, $dispatch));
    $retry = $uploader->ingestFile($camera, $path, $now);
    self::assertSame(200, $retry['status']);
    self::assertTrue($retry['payload']['duplicate']);
    self::assertNull($q->poll($camera, max(time(), $dispatch + 1)));
  }
}
