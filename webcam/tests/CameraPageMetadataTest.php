<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\View\CameraOverlayMetadata;
use Webcam\Service\View\CameraTimelineCalendar;
use Webcam\Service\View\CameraWeatherMetadata;

/**
 * Sichert die aus camera.php ausgelagerten, vom HTML unabhängigen Regeln ab.
 * Damit können spätere Umbauten an der Seite diese Regeln nicht versehentlich
 * verändern, obwohl das gerenderte Ergebnis zunächst ähnlich aussieht.
 */
final class CameraPageMetadataTest extends TestCase
{
  private string $root;
  private App $app;
  private Camera $camera;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-page-metadata-' . bin2hex(random_bytes(6));
    foreach (['storage', 'archive', 'state', 'log', 'info', 'cache', 'camera-db'] as $directory) {
      mkdir($this->root . '/' . $directory, 0775, true);
    }

    $cameraConfig = $this->root . '/cameras.php';
    file_put_contents($cameraConfig, "<?php\nreturn [];\n");
    $this->app = new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $cameraConfig,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'camera_db_path' => $this->root . '/camera-db',
    ]);
    $this->camera = new Camera([
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'Testkamera',
      'title' => 'Testkamera',
      'location' => ['latitude' => 52.02, 'longitude' => 8.53],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => $this->root . '/storage',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'stream' => ['mode' => 'none'],
    ]);
  }

  protected function tearDown(): void
  {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->root);
  }

  public function testWeatherTextIsNormalizedAndCommandErrorsAreHidden(): void
  {
    $metadata = new CameraWeatherMetadata($this->app);

    self::assertSame("Temperatur: 12 °C\nWind: 3 km/h", $metadata->normalizeText(
      "  Temperatur:   12 °C  \n\n Wind:  3 km/h "
    ));
    self::assertNull($metadata->normalizeText('curl: (28) Operation timed out'));
    self::assertNull($metadata->normalizeText(" \n \t"));
  }

  public function testRtspCaptureIsRecognizableWithoutPublishingCaptureCredentials(): void
  {
    $camera = new Camera([
      'id' => 'rtsp-test', 'slug' => 'rtsp-test',
      'name' => 'RTSP', 'title' => 'RTSP', 'location' => [],
      'mode' => 'pull-snapshot',
      'capture' => [
        'transport' => 'rtsp',
        'endpoint' => 'rtsp://private-user:private-password@camera.invalid/live',
        'key' => 'private-upload-key',
      ],
      'storage' => ['base_path' => $this->root . '/storage'],
      'stream' => ['mode' => 'none'],
    ]);
    $data = $this->app->cameraPresenter()->overviewData($camera);

    self::assertSame('rtsp', $data['capture_transport']);
    self::assertSame('none', $data['stream']['mode']);
    self::assertArrayNotHasKey('capture', $data);
    $publicJson = json_encode($data, JSON_THROW_ON_ERROR);
    foreach (['private-user', 'private-password', 'camera.invalid', 'private-upload-key'] as $secret) {
      self::assertStringNotContainsString($secret, $publicJson);
    }
    self::assertNotSame('rtsp', $this->app->cameraPresenter()->overviewData($this->camera)['capture_transport']);

    $builder = new \Webcam\Service\View\CameraPagePayloadBuilder();
    $renderer = new \Webcam\Infrastructure\View\Renderer(__DIR__ . '/../templates', null);
    $base = $builder->base(
      $this->app, $camera, $data, [], ['grouped' => [], 'years' => []],
      ['year' => null, 'month' => null, 'day' => null, 'time' => null],
      ['html' => '', 'available' => false], ['log' => static function (): void {}, 'enabled' => false], ''
    );
    $html = $renderer->render('camera', $builder->build($base, ['viewMode' => 'live']));
    self::assertStringContainsString('data-stream-enabled="1"', $html);
    self::assertStringContainsString('stream/rtsp-test?format=hls', $html);
    self::assertStringContainsString('data-stream-video-link', $html);
    foreach (['private-user', 'private-password', 'camera.invalid', 'private-upload-key'] as $secret) {
      self::assertStringNotContainsString($secret, $html);
    }
    $archiveHtml = $renderer->render('camera', $builder->build($base, ['viewMode' => 'archive']));
    self::assertStringContainsString('data-stream-enabled="0"', $archiveHtml);
    $base['camera'] = $this->app->cameraPresenter()->overviewData($this->camera);
    $photoHtml = $renderer->render('camera', $builder->build($base, ['viewMode' => 'live']));
    self::assertStringContainsString('data-stream-enabled="0"', $photoHtml);
  }

  public function testWeatherQualityFlagCoversDirectAndActiveFailureSlots(): void
  {
    $cameraDirectory = $this->root . '/camera-db/testcam';
    mkdir($cameraDirectory, 0775, true);
    $pdo = new PDO('sqlite:' . $cameraDirectory . '/db.sqlite');
    $pdo->exec('CREATE TABLE captures (id INTEGER PRIMARY KEY, slot_ts INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE weather_quality_flags (capture_id INTEGER NOT NULL, flag TEXT NOT NULL)');
    $activeFrom = time() - 120;
    $insertCapture = $pdo->prepare('INSERT INTO captures (id, slot_ts) VALUES (1, :slot_ts)');
    $insertCapture->execute([':slot_ts' => $activeFrom]);
    $pdo->exec("INSERT INTO weather_quality_flags (capture_id, flag) VALUES (1, 'frozen_value')");

    $metadata = new CameraWeatherMetadata($this->app);
    self::assertTrue($metadata->slotHasQualityFlag($this->camera, $activeFrom));
    self::assertTrue($metadata->slotHasQualityFlag($this->camera, $activeFrom + 60));
    self::assertFalse($metadata->slotHasQualityFlag($this->camera, $activeFrom - 60));
  }

  public function testLabelsRespectCoordinatesAndInclusiveDateRange(): void
  {
    $metadata = new CameraOverlayMetadata();
    $timezone = new DateTimeZone('Europe/Berlin');
    $reference = (new DateTimeImmutable('2026-08-15 12:00:00', $timezone))->getTimestamp();
    $valid = ['text' => 'Mitte', 'x' => 50, 'y' => 40, 'valid_to' => '2026-08-15'];

    self::assertSame([$valid], $metadata->labelsForTimestamp([
      $valid,
      ['text' => '', 'x' => 10, 'y' => 20],
      ['text' => 'Ohne Position'],
      ['text' => 'Später', 'x' => 10, 'y' => 20, 'valid_from' => '2026-08-16'],
    ], $reference, $timezone));
  }

  public function testNightClassificationUsesLocalSunTimes(): void
  {
    $metadata = new CameraOverlayMetadata();
    $timezone = new DateTimeZone('Europe/Berlin');

    self::assertFalse($metadata->isNight(
      $this->camera,
      (new DateTimeImmutable('2026-06-21 13:00:00', $timezone))->getTimestamp(),
      'Europe/Berlin'
    ));
    self::assertTrue($metadata->isNight(
      $this->camera,
      (new DateTimeImmutable('2026-06-21 01:00:00', $timezone))->getTimestamp(),
      'Europe/Berlin'
    ));
  }

  public function testCalendarFindsAdjacentDaysAcrossYearBoundary(): void
  {
    $calendar = new CameraTimelineCalendar();
    $years = [2026, 2027];
    $months = [2026 => [12], 2027 => [1]];
    $days = [2026 => [12 => [30, 31]], 2027 => [1 => [1, 2]]];

    self::assertSame(
      ['year' => 2027, 'month' => 1, 'day' => 1],
      $calendar->adjacentAvailableDay(2026, 12, 31, $years, $months, $days, true)
    );
    self::assertSame(
      ['year' => 2026, 'month' => 12, 'day' => 31],
      $calendar->adjacentAvailableDay(2027, 1, 1, $years, $months, $days, false)
    );
    self::assertSame('061000', $calendar->normalizeTime('06:10'));
    self::assertNull($calendar->normalizeTime('keine Zeit'));
  }
}
