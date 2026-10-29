<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Storage\DailyArchiver;
use Webcam\Service\Storage\ArchiveCompletenessVerifier;

final class DailyArchiverTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/webcam-daily-archiver-' . bin2hex(random_bytes(6));
    if (!mkdir($concurrentDirectory = $this->root, 0775, true) && !is_dir($concurrentDirectory)) {
      self::fail('Unable to create temp test root: ' . $this->root);
    }
  }

  protected function tearDown(): void
  {
    $this->deleteTree($this->root);
    parent::tearDown();
  }

  public function testArchiveDayUsesRollingCacheAsSource(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $baseKey = '20260409_153000';

    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, $baseKey);

    $result = (new DailyArchiver($app))->archiveDay($camera, $day);

    self::assertSame([
      'processed' => 1,
      'skipped' => 0,
      'missing' => false,
    ], $result);

    $targetDir = $app->paths()->archivePath('demo/' . $day->format('Y/m/d'));
    foreach (['sm', 'la', 'lm', 'hu', 'full'] as $suffix) {
      self::assertFileExists($targetDir . '/1530_' . $suffix . '.jpg');
    }
    self::assertFileExists($targetDir . '/1530_ex.txt');
    self::assertFileExists($targetDir . '/1530_wx.txt');
  }

  public function testFallbackHourArchivesBothOriginalsAndSidecarsSeparately(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-10-25', new DateTimeZone('Europe/Berlin'));
    $source = $app->paths()->cachePath('demo/2026/10/25');
    $target = $app->paths()->archivePath('demo/2026/10/25');
    foreach (['20261025_021000', '20261025_021000_utc1792890600'] as $base) {
      $this->writeCaptureFiles($source, $base);
      file_put_contents($source . '/' . $base . '_WX.txt', $base);
    }
    $secondFile = $source . '/20261025_021000_utc1792890600.jpg';
    $image = imagecreatefromjpeg($secondFile);
    imagefilter($image, IMG_FILTER_NEGATE);
    imagejpeg($image, $secondFile, 92);
    imagedestroy($image);
    $archiver = new DailyArchiver($app);
    self::assertSame(2, $archiver->archiveDay($camera, $day)['processed']);
    foreach (['20261025_021000' => '0210', '20261025_021000_utc1792890600' => '0210_utc1792890600'] as $src => $dst) {
      self::assertSame(hash_file('sha256', $source . '/' . $src . '.jpg'), hash_file('sha256', $target . '/' . $dst . '_hu.jpg'));
      self::assertSame($src, file_get_contents($target . '/' . $dst . '_wx.txt'));
    }
    $verifier = new ArchiveCompletenessVerifier($app);
    self::assertTrue($verifier->verifyDay($source, $target)['complete']);
    self::assertSame(2, $archiver->archiveDay($camera, $day)['skipped']);

    $mapper = new \Webcam\Service\Storage\TimelineMapper();
    $dbReader = new \Webcam\Service\Storage\ArchiveDatabaseReader($app, $mapper);
    $reader = new \Webcam\Service\Storage\ArchiveFilesystemReader($app, $mapper, $dbReader);
    foreach ([[$app->paths()->cachePath('demo'), ''], ['', $app->paths()->archivePath('demo')]] as [$cache, $archive]) {
      $entries = $reader->entriesForDay($camera, 2026, 10, 25, $cache, '', $archive);
      self::assertSame([1792887000, 1792890600], array_column($entries, 'timestamp'));
    }
    self::assertSame('archive/demo/2026/10/25/0210_utc1792890600_lm.jpg',
      $dbReader->mapLegacyCacheVariantToArchiveRelativePath($camera, 'cache/demo/2026/10/25/20261025_021000_utc1792890600_lm.jpg'));
    $parser = new \Webcam\Service\Weather\WeatherSidecarParser();
    self::assertSame(1792890600, $parser->pathMetadata($target . '/0210_utc1792890600_wx.txt', new DateTimeZone('Europe/Berlin'))['slot_ts']);
    unlink($target . '/0210_utc1792890600_hu.jpg');
    self::assertFalse($verifier->verifyDay($source, $target)['complete']);
  }

  public function testArchiveDaySkipsOnlyACompleteValidTargetSet(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, '20260409_153000');

    (new DailyArchiver($app))->archiveDay($camera, $day);
    $result = (new DailyArchiver($app))->archiveDay($camera, $day);

    self::assertSame(0, $result['processed']);
    self::assertSame(1, $result['skipped']);
  }

  /** RTSP snapshots may have a historical zero-byte EXIF placeholder. */
  public function testEmptyExifSourceDoesNotRequireArchiveSidecar(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09', new DateTimeZone('Europe/Berlin'));
    $source = $app->paths()->cachePath('demo/2026/04/09');
    $target = $app->paths()->archivePath('demo/2026/04/09');
    $this->writeCaptureFiles($source, '20260409_153000');
    file_put_contents($source . '/20260409_153000_ex.txt', '');
    clearstatcache();

    $archiver = new DailyArchiver($app);
    self::assertSame(1, $archiver->archiveDay($camera, $day)['processed']);
    $verifier = new ArchiveCompletenessVerifier($app);
    self::assertTrue($verifier->verifyDay($source, $target)['complete']);
    self::assertSame(1, $archiver->archiveDay($camera, $day)['skipped']);

    // A missing archive EXIF file is equally valid; image protection is not.
    if (is_file($target . '/1530_ex.txt')) {
      unlink($target . '/1530_ex.txt');
    }
    clearstatcache();
    self::assertTrue($verifier->verifyDay($source, $target)['complete']);
    unlink($target . '/1530_sm.jpg');
    clearstatcache();
    self::assertFalse($verifier->verifyDay($source, $target)['complete']);
  }

  /** Only exactly empty EXIF is exempt, not populated EXIF or weather. */
  public function testEmptyExifExceptionDoesNotWeakenOtherSidecarChecks(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09', new DateTimeZone('Europe/Berlin'));
    $source = $app->paths()->cachePath('demo/2026/04/09');
    $target = $app->paths()->archivePath('demo/2026/04/09');
    $this->writeCaptureFiles($source, '20260409_153000');
    (new DailyArchiver($app))->archiveDay($camera, $day);
    $verifier = new ArchiveCompletenessVerifier($app);
    unlink($target . '/1530_ex.txt');
    clearstatcache();
    self::assertFalse($verifier->verifyDay($source, $target)['complete']);
    file_put_contents($target . '/1530_ex.txt', '');
    clearstatcache();
    self::assertFalse($verifier->verifyDay($source, $target)['complete']);
    file_put_contents($source . '/20260409_153000_ex.txt', "\n");
    clearstatcache();
    self::assertFalse($verifier->verifyDay($source, $target)['complete']);

    file_put_contents($source . '/20260409_153000_ex.txt', '');
    file_put_contents($source . '/20260409_153000_WX.txt', '');
    unlink($target . '/1530_wx.txt');
    clearstatcache();
    $result = $verifier->verifyDay($source, $target);
    self::assertFalse($result['complete']);
    self::assertStringContainsString('1530_wx.txt', $result['reason']);
  }

  public function testVerifierRejectsSameLengthCorruptionAndTruncatedVariants(): void
  {
    $app = $this->createTempApp();
    $day = new DateTimeImmutable('2026-04-09', new DateTimeZone('Europe/Berlin'));
    $source = $app->paths()->cachePath('demo/2026/04/09');
    $target = $app->paths()->archivePath('demo/2026/04/09');
    $this->writeCaptureFiles($source, '20260409_153000');
    $archiver = new DailyArchiver($app);
    $archiver->archiveDay($this->requireCamera($app), $day);
    $verifier = new ArchiveCompletenessVerifier($app);
    foreach (['hu', 'full', 'sm', 'la', 'lm'] as $suffix) {
      $path = $target . '/1530_' . $suffix . '.jpg';
      $valid = file_get_contents($path);
      // Keep the JPEG dimensions and file length, remove entropy/end marker.
      $sos = strpos($valid, "\xff\xda");
      self::assertNotFalse($sos);
      $start = $sos + 2 + unpack('n', substr($valid, $sos + 2, 2))[1];
      file_put_contents($path, substr($valid, 0, $start) . str_repeat("\0", strlen($valid) - $start));
      clearstatcache();
      self::assertIsArray(getimagesize($path));
      self::assertFalse($verifier->verifyDay($source, $target)['complete'], $suffix);
      file_put_contents($path, $valid);
    }
    self::assertTrue($verifier->verifyDay($source, $target)['complete']);
  }

  public function testArchiveDayRepairsCorruptExistingJpegSet(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, '20260409_153000');
    $archiver = new DailyArchiver($app);
    $archiver->archiveDay($camera, $day);

    $target = $app->paths()->archivePath('demo/' . $day->format('Y/m/d') . '/1530_sm.jpg');
    file_put_contents($target, 'not a jpeg');
    $result = $archiver->archiveDay($camera, $day);

    self::assertSame(1, $result['processed']);
    self::assertSame(0, $result['skipped']);
    $info = getimagesize($target);
    self::assertIsArray($info);
    self::assertSame(114, $info[0]);
    self::assertSame(86, $info[1]);
  }

  public function testArchiveDayRepairsWrongDimensionsAndEmptySidecar(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, '20260409_153000');
    $archiver = new DailyArchiver($app);
    $archiver->archiveDay($camera, $day);

    $targetDir = $app->paths()->archivePath('demo/' . $day->format('Y/m/d'));
    $this->writeSampleJpeg($targetDir . '/1530_sm.jpg');
    file_put_contents($targetDir . '/1530_ex.txt', '');
    $result = $archiver->archiveDay($camera, $day);

    self::assertSame(1, $result['processed']);
    $info = getimagesize($targetDir . '/1530_sm.jpg');
    self::assertIsArray($info);
    self::assertSame([114, 86], [$info[0], $info[1]]);
    self::assertSame("ExposureTime: 1/10\n", file_get_contents($targetDir . '/1530_ex.txt'));
  }

  public function testArchiveDayFallsBackToLegacyCurrentLayout(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $baseKey = '20260409_153000';

    $sourceDir = $this->root . '/storage/current/demo/' . $day->format('Y/m/d');
    $this->writeCaptureFiles($sourceDir, $baseKey);

    $result = (new DailyArchiver($app))->archiveDay($camera, $day);

    self::assertSame(1, $result['processed']);
    self::assertSame(0, $result['skipped']);
    self::assertFalse($result['missing']);
    self::assertFileExists($app->paths()->archivePath('demo/' . $day->format('Y/m/d') . '/1530_full.jpg'));
  }

  public function testArchiveDayNeverPrunesOlderCacheYear(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTimeImmutable('now', $tz);
    $currentYear = $now->format('Y');
    $olderYear = $now->sub(new DateInterval('P1Y'))->format('Y');
    $day = new DateTimeImmutable($currentYear . '-04-09 00:00:00', $tz);
    $baseKey = $day->format('Ymd') . '_153000';

    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, $baseKey);

    $staleCacheDay = $app->paths()->cachePath('demo/' . $olderYear . '/01/02');
    if (!mkdir($concurrentDirectory = $staleCacheDay, 0775, true) && !is_dir($concurrentDirectory)) {
      self::fail('Unable to create stale cache day dir: ' . $staleCacheDay);
    }
    $staleBase = $olderYear . '0102_153000';
    $this->writeCaptureFiles($staleCacheDay, $staleBase);

    (new DailyArchiver($app))->archiveDay($camera, $day);

    self::assertDirectoryExists($app->paths()->cachePath('demo/' . $olderYear));
    self::assertFileExists($staleCacheDay . '/' . $staleBase . '.jpg');
    self::assertDirectoryExists($app->paths()->cachePath('demo/' . $currentYear));
  }

  public function testArchiveDayKeepsOlderCacheYearWhileArchiveMirrorIsIncomplete(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $tz = new DateTimeZone('Europe/Berlin');
    $now = new DateTimeImmutable('now', $tz);
    $currentYear = $now->format('Y');
    $olderYear = $now->sub(new DateInterval('P1Y'))->format('Y');
    $day = new DateTimeImmutable($currentYear . '-04-09 00:00:00', $tz);

    $staleCacheDay = $app->paths()->cachePath('demo/' . $olderYear . '/01/02');
    if (!mkdir($concurrentDirectory = $staleCacheDay, 0775, true) && !is_dir($concurrentDirectory)) {
      self::fail('Unable to create stale cache day dir: ' . $staleCacheDay);
    }
    file_put_contents($staleCacheDay . '/placeholder.txt', "stale\n");

    $result = (new DailyArchiver($app))->archiveDay($camera, $day);

    self::assertTrue($result['missing']);
    self::assertDirectoryExists($app->paths()->cachePath('demo/' . $olderYear));
  }

  public function testArchiveDayReportsProgressEvents(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $baseKey = '20260409_153000';

    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, $baseKey);

    $events = [];
    (new DailyArchiver($app))->archiveDay(
      $camera,
      $day,
      false,
      static function (array $event) use (&$events): void {
        $events[] = $event;
      }
    );

    self::assertNotEmpty($events);
    self::assertSame('checking', $events[0]['phase']);
    self::assertSame('start', $events[1]['phase']);
    self::assertSame('done', $events[array_key_last($events)]['phase']);

    $progressEvents = array_values(array_filter(
      $events,
      static fn (array $event): bool => $event['phase'] === 'progress'
    ));
    self::assertCount(1, $progressEvents);
    self::assertSame('1530', $progressEvents[0]['current']);
    self::assertSame(1, $progressEvents[0]['processed']);
    self::assertSame(0, $progressEvents[0]['skipped']);
  }

  public function testArchiveDayReportsCheckingAndMissingBeforeNoSourceReturn(): void
  {
    $app = $this->createTempApp();
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $events = [];

    $result = (new DailyArchiver($app))->archiveDay(
      $camera,
      $day,
      false,
      static function (array $event) use (&$events): void {
        $events[] = $event['phase'];
      }
    );

    self::assertTrue($result['missing']);
    self::assertSame(['checking', 'missing'], $events);
  }

  public function testArchiveDayFailsClosedWithoutMountSentinel(): void
  {
    $app = $this->createTempApp(false);
    $camera = $this->requireCamera($app);
    $day = new DateTimeImmutable('2026-04-09 00:00:00', new DateTimeZone('Europe/Berlin'));
    $sourceDir = $app->paths()->cachePath('demo/' . $day->format('Y/m/d'));
    $this->writeCaptureFiles($sourceDir, '20260409_153000');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Archive mount sentinel missing or unreadable for daily archive rollover');

    (new DailyArchiver($app))->archiveDay($camera, $day);
  }

  private function createTempApp(bool $withMountSentinel = true): App
  {
    $cameraConfigPath = $this->root . '/cameras.php';
    $config = [[
      'id' => 'demo-camera',
      'slug' => 'demo',
      'name' => 'Demo',
      'title' => 'Demo Camera',
      'description' => '',
      'location' => [
        'lat' => 0.0,
        'lng' => 0.0,
        'map_center_offset' => 0,
        'map_center_offset_x' => 0,
      ],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => 'storage/demo',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [150, 816, 1200],
      'weather_commands' => [],
    ]];
    file_put_contents($cameraConfigPath, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");

    $app = new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $cameraConfigPath,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/var/state',
      'log_path' => $this->root . '/var/log',
      'info_path' => $this->root . '/var/info',
      'cache_path' => $this->root . '/cache',
      'default_resolutions' => [150, 816, 1200],
      'storage_owner_user' => '',
      'storage_owner_group' => '',
      'archive_min_jpeg_bytes' => 1024,
      'archive_mount_sentinel_required' => true,
    ]);
    if ($withMountSentinel) {
      file_put_contents(
        $app->paths()->archivePath(\Webcam\Service\Storage\ArchiveMountGuard::SENTINEL_FILENAME),
        ''
      );
    }
    return $app;
  }

  private function requireCamera(App $app): Camera
  {
    $camera = $app->cameras()->find('demo');
    self::assertInstanceOf(Camera::class, $camera);
    return $camera;
  }

  private function writeCaptureFiles(string $dir, string $baseKey): void
  {
    if (!is_dir($dir) && !mkdir($concurrentDirectory = $dir, 0775, true) && !is_dir($concurrentDirectory)) {
      self::fail('Unable to create source dir: ' . $dir);
    }

    $imagePath = $dir . '/' . $baseKey . '.jpg';
    $this->writeSampleJpeg($imagePath);
    file_put_contents($dir . '/' . $baseKey . '_ex.txt', "ExposureTime: 1/10\n");
    file_put_contents($dir . '/' . $baseKey . '_WX.txt', "Weather: clear\n");
    file_put_contents($dir . '/' . $baseKey . '_150.jpg', 'ignored');
  }

  private function writeSampleJpeg(string $path): void
  {
    $image = imagecreatetruecolor(640, 480);
    self::assertNotFalse($image, 'Unable to create GD image resource.');

    for ($y = 0; $y < 480; $y++) {
      $color = imagecolorallocate($image, $y % 255, ($y * 2) % 255, ($y * 3) % 255);
      imageline($image, 0, $y, 639, $y, $color);
    }

    self::assertTrue(imagejpeg($image, $path, 92), 'Unable to write sample JPEG.');
    imagedestroy($image);
    clearstatcache(true, $path);
    self::assertGreaterThanOrEqual(1024, (int) filesize($path));
  }

  private function deleteTree(string $path): void
  {
    if ($path === '' || !file_exists($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }

    $entries = scandir($path);
    if ($entries === false) {
      return;
    }
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->deleteTree($path . '/' . $entry);
    }
    @rmdir($path);
  }
}
