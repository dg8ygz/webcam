<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Storage\PresentationFrames;
use Webcam\Service\Dayvideo\DayvideoRequest;

final class PresentationFramesTest extends TestCase
{
  public function testLocalCalendarHandlesDstAndLeapYears(): void
  {
    $zone = new DateTimeZone('Europe/Berlin');
    foreach (['2024-03-31' => 23, '2024-10-27' => 25] as $date => $hours) {
      [$from, $to] = PresentationFrames::range(PresentationFrames::date($date, $zone), 'day', []);
      self::assertSame($hours * 3600, $to - $from + 1);
    }
    [$from, $to] = PresentationFrames::range(PresentationFrames::date('2024-02-10', $zone), 'month', []);
    self::assertSame(29 * 86400, $to - $from + 1);
    $request = DayvideoRequest::fromQuery(['date' => '2024-10-27', 'period' => 'year', 'clock' => '09:15'], $zone);
    self::assertSame('2024-01-01', $request->date->format('Y-m-d'));
    self::assertSame('09:15', $request->clock);
  }

  public function testRandomArchiveIgnoresFutureDuplicatesAndMissingFiles(): void
  {
    [$app, $store] = $this->fixture();
    $frames = new PresentationFrames($app);
    self::assertNull($frames->randomFrame());
    $past = 1600000000;
    foreach ([$past - 600, $past, time() + 86400] as $stamp) {
      $store->recordCapture(['slot_timestamp' => $stamp, 'relative_path' => 'cache/presentation/fixture.jpg',
        'variants' => [['variant' => '1200', 'width' => 1200, 'relative_path' => 'cache/presentation/fixture.jpg']]]);
    }
    $store->pdo()->exec('UPDATE captures SET is_duplicate = 1 WHERE slot_ts = ' . ($past - 600));
    self::assertSame([$past, $past], $store->captureTimeBounds(time()));
    $slot = $frames->randomFrame();
    self::assertSame('presentation', $slot['slug']);
    self::assertSame($past, $slot['frame']['timestamp']);
    unlink($app->paths()->cachePath('presentation/fixture.jpg'));
    self::assertNull($frames->randomFrame());
  }

  public function testRandomArchiveNeverSelectsHiddenCamera(): void
  {
    [$app, $store] = $this->fixture(false, true);
    $store->recordCapture(['slot_timestamp' => 1600000000, 'relative_path' => 'cache/presentation/fixture.jpg',
      'variants' => [['variant' => '1200', 'width' => 1200, 'relative_path' => 'cache/presentation/fixture.jpg']]]);
    self::assertNotEmpty($app->cameras()->all());
    self::assertNull((new PresentationFrames($app))->randomFrame());
  }

  public function testSolarWindowIncludesFollowingMorning(): void
  {
    $day = PresentationFrames::date('2024-03-30', new DateTimeZone('Europe/Berlin'));
    [$from, $to] = PresentationFrames::range($day, 'night', ['latitude' => 52, 'longitude' => 8.5]);
    self::assertSame('2024-03-30', $day->setTimestamp($from)->format('Y-m-d'));
    self::assertSame('2024-03-31', $day->setTimestamp($to)->format('Y-m-d'));
    self::assertGreaterThan(8 * 3600, $to - $from);
    self::assertLessThan(14 * 3600, $to - $from);
  }

  public function testYearSelectionIsBoundedAndKeepsBothEnds(): void
  {
    [$app, $store] = $this->fixture();
    $day = new DateTimeImmutable('2024-01-01 12:00', new DateTimeZone('Europe/Berlin'));
    $pdo = $store->pdo(); $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO captures(slot_ts,relative_path,created_at) VALUES(?,?,1)');
    for ($i = 0; $i < 366; $i++) {
      $insert->execute([$day->modify('+' . $i . ' days')->getTimestamp(), 'cache/presentation/fixture.jpg']);
    }
    $pdo->exec("INSERT INTO variants(capture_id,variant,width,height,relative_path) SELECT id,'1200',1200,800,'cache/presentation/fixture.jpg' FROM captures");
    $pdo->commit();
    $selection = (new PresentationFrames($app))->select($app->cameraOrFail('presentation'), $day->setTime(0, 0), 'year');
    self::assertCount(300, $selection['entries']);
    self::assertSame(300, $selection['sampled_days']);
    self::assertSame(0, $selection['missing_days']);
    self::assertSame($day->getTimestamp(), $selection['entries'][0]['timestamp']);
    self::assertSame($day->modify('+365 days')->getTimestamp(), $selection['entries'][299]['timestamp']);
    $march = (new PresentationFrames($app))->select($app->cameraOrFail('presentation'), $day->setDate(2024, 3, 1)->setTime(0, 0), 'month');
    self::assertCount(31, $march['entries']);
    foreach ($march['entries'] as $entry) self::assertSame('12:00', $day->setTimestamp($entry['timestamp'])->format('H:i'));
  }

  public function testLegacyVariantsWithoutDimensionsRespectRequestedWidth(): void
  {
    [$app, $store] = $this->fixture();
    $day = new DateTimeImmutable('2024-01-01 12:00', new DateTimeZone('Europe/Berlin'));
    $variants = [];
    foreach (['sm', 'la', 'lm', 'hu'] as $variant) {
      $variants[] = ['variant' => $variant, 'relative_path' => 'cache/presentation/fixture.jpg'];
    }
    $store->recordCapture(['slot_timestamp' => $day->getTimestamp(),
      'relative_path' => 'cache/presentation/fixture.jpg', 'variants' => $variants]);
    foreach ([1200 => 'lm', 1000 => 'la', 100 => 'sm'] as $width => $expected) {
      $selection = (new PresentationFrames($app))->select($app->cameraOrFail('presentation'),
        $day->setTime(0, 0), 'year', '12:00', 300, $width);
      self::assertCount(1, $selection['entries']);
      self::assertSame([$expected], array_keys($selection['entries'][0]['variants']));
    }
  }

  public function testMidnightNeverReusesPreviousDayAndMissingDaysAreCounted(): void
  {
    [$app, $store] = $this->fixture();
    $zone = new DateTimeZone('Europe/Berlin');
    $stamp = (new DateTimeImmutable('2024-01-31 23:55', $zone))->getTimestamp();
    $store->pdo()->prepare('INSERT INTO captures(slot_ts,relative_path,created_at) VALUES(?,?,1)')->execute([$stamp, 'cache/presentation/fixture.jpg']);
    $store->pdo()->exec("INSERT INTO variants(capture_id,variant,width,relative_path) SELECT id,'1200',1200,'cache/presentation/fixture.jpg' FROM captures");
    $selection = (new PresentationFrames($app))->select($app->cameraOrFail('presentation'), new DateTimeImmutable('2024-02-01', $zone), 'month', '00:00');
    self::assertSame(29, $selection['missing_days']);
    self::assertSame([], $selection['entries']);
    $store->recordCapture(['slot_timestamp' => (new DateTimeImmutable('2024-03-31 03:30', $zone))->getTimestamp(),
      'relative_path' => 'cache/presentation/fixture.jpg',
      'variants' => [['variant' => '1200', 'width' => 1200, 'relative_path' => 'cache/presentation/fixture.jpg']]]);
    $spring = (new PresentationFrames($app))->select($app->cameraOrFail('presentation'), new DateTimeImmutable('2024-03-01', $zone), 'month', '02:30');
    self::assertSame(31, $spring['missing_days']);
    self::assertSame([], $spring['entries'], 'Nonexistent 02:30 must not silently become 03:30');
  }

  public function testMonthlyMp4BuildCacheAndEmptyAsyncResponse(): void
  {
    [$app, $store] = $this->fixture(true);
    $camera = $app->cameraOrFail('presentation');
    $zone = new DateTimeZone('Europe/Berlin');
    for ($day = 1; $day <= 3; $day++) {
      $relative = 'cache/presentation/frame' . $day . '.jpg';
      $image = imagecreatetruecolor(64, 48);
      imagefill($image, 0, 0, imagecolorallocate($image, 50 * $day, 70, 120));
      imagejpeg($image, $app->paths()->cachePath('presentation/frame' . $day . '.jpg'));
      $store->recordCapture(['slot_timestamp' => (new DateTimeImmutable('2024-01-0' . $day . ' 12:00', $zone))->getTimestamp(),
        'relative_path' => $relative, 'variants' => [['variant' => '64', 'width' => 64, 'height' => 48, 'relative_path' => $relative]]]);
    }
    $service = new \Webcam\Service\Dayvideo\DayvideoService($app,
      new \Webcam\Service\Security\PublicRateLimiter($app, [$app->paths()->statePath('rate.sqlite')]));
    $request = DayvideoRequest::fromQuery(['date' => '2024-01-15', 'period' => 'month'], $zone);
    $called = 0;
    $callback = static function () use (&$called): void { $called++; };
    $result = $service->handle($camera, $request, '192.0.2.1', $callback);
    self::assertSame(200, $result->status, json_encode($result->payload));
    self::assertSame(1, $called);
    self::assertSame(3, $result->payload['frame_count']);
    self::assertStringContainsString('/2024/01/01/month_1200_', $result->payload['video_url']);
    $video = $app->paths()->cachePath(substr($result->payload['video_url'], 6));
    self::assertGreaterThan(1024, filesize($video));
    self::assertSame('ftyp', substr(file_get_contents($video), 4, 4));
    self::assertTrue($service->handle($camera, $request, '192.0.2.1', $callback)->payload['cached']);
    $empty = DayvideoRequest::fromQuery(['date' => '2023-01-01', 'period' => 'month', 'async' => '1'], $zone);
    self::assertSame(404, $service->handle($camera, $empty, '192.0.2.1', $callback)->status);
    self::assertSame(1, $called, 'No spurious HTTP 202 for cache hits or missing frames');
  }

  private function fixture(bool $build = false, bool $hidden = false): array
  {
    $root = TestTempDir::ensure() . '/presentation-' . bin2hex(random_bytes(4)); mkdir($root);
    $camera = ['id' => 'presentation', 'slug' => 'presentation', 'name' => 'Presentation', 'title' => 'Presentation',
      'tags' => $hidden ? [\Webcam\Domain\Camera::TEST_CAMERA_TAG] : [],
      'location' => ['latitude' => 52, 'longitude' => 8.5], 'mode' => 'active-capture', 'capture' => [],
      'storage' => ['base_path' => $root . '/storage/presentation']];
    file_put_contents($root . '/cameras.php', '<?php return ' . var_export([$camera], true) . ';');
    $app = new App(['timezone' => 'Europe/Berlin', 'camera_config_path' => $root . '/cameras.php',
      'storage_path' => $root . '/storage', 'archive_path' => $root . '/archive', 'cache_path' => $root . '/cache',
      'state_path' => $root . '/state', 'log_path' => $root . '/log', 'info_path' => $root . '/info', 'camera_db_path' => $root . '/db', 'base_url' => '',
      'dayvideo_public_build' => $build, 'dayvideo_global_lock_dir' => $root . '/slots']);
    mkdir($root . '/cache/presentation', 0775, true);
    file_put_contents($root . '/cache/presentation/fixture.jpg', 'fixture');
    $store = new CameraDataStore($app, $app->cameraOrFail('presentation'), null, false); $store->ensureWritableSchema();
    return [$app, $store];
  }
}
