<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Storage\CacheRetentionManager;
use Webcam\Service\Storage\CaptureBackupScheduler;
use Webcam\Service\Storage\ImageStorage;
use Webcam\Service\Storage\ImageVariantGenerator;

require_once __DIR__ . '/../bootstrap.php';

final class ImageStorageTest extends TestCase
{
    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wcam_img_store_' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            $this->fail('Unable to create test root: ' . $this->root);
        }

        $cameraConfig = [
            [
                'id' => 'testcam',
                'slug' => 'testcam',
                'name' => 'testcam',
                'title' => 'Test Cam',
                'short_title' => 'Test Cam',
                'description' => '',
                'location' => [
                    'latitude' => 0,
                    'longitude' => 0,
                    'map_center_offset' => 0,
                    'map_center_offset_x' => 0,
                ],
                'tags' => [],
                'mode' => 'active-capture',
                'capture' => [
                    'interval_seconds' => 600,
                ],
                'storage' => [
                    'base_path' => $this->root . '/storage/testcam',
                    'current_dir' => 'current',
                    'archive_dir' => 'archive',
                    'best_of_dir' => 'bestof',
                ],
                'status' => [],
                'stream' => ['mode' => 'none'],
                'resolutions' => [400],
                'weather_commands' => [],
            ],
            [
                'id' => 'nightcam',
                'slug' => 'nightcam',
                'name' => 'nightcam',
                'title' => 'Night Cam',
                'short_title' => 'Night Cam',
                'description' => '',
                'location' => [
                    'latitude' => 0,
                    'longitude' => 0,
                    'map_center_offset' => 0,
                    'map_center_offset_x' => 0,
                ],
                'tags' => ['__night_denoise'],
                'mode' => 'active-capture',
                'capture' => [
                    'interval_seconds' => 600,
                ],
                'storage' => [
                    'base_path' => $this->root . '/storage/nightcam',
                    'current_dir' => 'current',
                    'archive_dir' => 'archive',
                    'best_of_dir' => 'bestof',
                ],
                'status' => [],
                'stream' => ['mode' => 'none'],
                'resolutions' => [400],
                'weather_commands' => [],
            ],
        ];

        $this->configFile = $this->root . '/cameras.php';
        file_put_contents($this->configFile, '<?php return ' . var_export($cameraConfig, true) . ';');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testLegacyCurrentRawPreservesOriginalAndRefreshesWithVariants(): void
    {
        $app = $this->makeApp();
        $config = (require $this->configFile)[0];
        $slug = 'current-compat-' . bin2hex(random_bytes(6));
        $config['id'] = $config['slug'] = $slug;
        $config['tags'] = ['__night_denoise'];
        $camera = new \Webcam\Domain\Camera($config);
        $current = dirname(__DIR__) . '/cache/' . $slug . '/current';
        $storage = new ImageStorage($app);
        $previous = null;
        try {
            foreach ([0, 600] as $offset) {
                $image = $this->createDarkNoisyImage(640, 480);
                ob_start();
                imagejpeg($image, null, 95);
                $binary = ob_get_clean();
                imagedestroy($image);
                $stored = $storage->storeSnapshot($camera, $binary, time() + $offset);
                self::assertFileExists($current . '/raw.jpg');
                self::assertSame(hash('sha256', $binary), hash_file('sha256', $current . '/raw.jpg'));
                self::assertNotSame($previous, hash_file('sha256', $current . '/raw.jpg'));
                $previous = hash_file('sha256', $current . '/raw.jpg');
                foreach (['full', 'hu'] as $name) {
                    self::assertSame([640, 480], array_slice(getimagesize($current . '/' . $name . '.jpg'), 0, 2));
                }
                self::assertNotSame(hash_file('sha256', $current . '/raw.jpg'), hash_file('sha256', $current . '/full.jpg'));
                self::assertSame(hash_file('sha256', $stored['variants'][400]), hash_file('sha256', $current . '/400.jpg'));
                self::assertSame(hash_file('sha256', $current . '/400.jpg'), hash_file('sha256', $current . '/current.jpg'));
            }
        } finally {
            $this->rrmdir(dirname($current));
        }
    }

    public function testProcessingPublishesOnlyProcessedJpegsAndRetainsPrivatePreview(): void
    {
        $app = $this->makeApp();
        $config = (require $this->configFile)[0];
        $slug = 'processing-' . bin2hex(random_bytes(4));
        $config['id'] = $config['slug'] = $slug;
        $config['capture']['image_processing'] = ['enabled' => true, 'crop' => ['bottom' => 30], 'regions' => [
            ['type' => 'mask', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'color' => '#000000'],
        ]];
        $camera = new \Webcam\Domain\Camera($config);
        $current = dirname(__DIR__) . '/cache/' . $slug . '/current';
        $source = $this->root . '/source.jpg';
        $this->writeSampleJpeg($source);
        try {
            $stored = (new ImageStorage($app))->storeSnapshotFile($camera, $source, time());
            self::assertSame([640, 450], array_slice(getimagesize($stored['original']), 0, 2));
            self::assertNotSame(hash_file('sha256', $source), hash_file('sha256', $current . '/raw.jpg'));
            $image = imagecreatefromjpeg($current . '/raw.jpg');
            self::assertLessThan(10, imagecolorat($image, 40, 40) & 255);
            imagedestroy($image);
            $db = (new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera))->pdo();
            self::assertSame(hash_file('sha256', $stored['original']), $db->query('SELECT hash_sha256 FROM captures')->fetchColumn());
            $private = $app->paths()->statePath('image-processing/' . $slug . '/source.jpg');
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $private));
            self::assertSame(0600, fileperms($private) & 0777);
            $preview = (new \Webcam\Service\Storage\ImageProcessingPreview($app))->render($camera, $config['capture']['image_processing']);
            self::assertSame(480, $preview['height']);
            self::assertSame(450, $preview['result_height']);
            self::assertStringStartsWith('data:image/jpeg;base64,', $preview['preview']);
        } finally { $this->rrmdir(dirname($current)); }
    }

    public function testRejectsInvalidSnapshotPayloadBeforeWritingFiles(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $storage = new ImageStorage($app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('supported image');
        $storage->storeSnapshot($camera, 'definitely-not-an-image');
    }

    public function testRejectsOversizedImageHeadersBeforeDecodingOrWriting(): void
    {
        $app = $this->makeApp();
        $storage = new ImageStorage($app);
        $camera = $app->cameraOrFail('testcam');
        $source = $this->root . '/dimension-fixture.jpg';
        $this->writeSampleJpeg($source);
        $binary = file_get_contents($source);
        $sof = strpos($binary, "\xff\xc0");
        self::assertNotFalse($sof);
        foreach ([[8193, 1], [1, 8193], [8000, 8000]] as [$width, $height]) {
            // Nur den kleinen Bildkopf ändern, niemals ein Riesenbild anlegen.
            $oversized = substr_replace($binary, pack('nn', $height, $width), $sof + 5, 4);
            self::assertSame($width, getimagesizefromstring($oversized)[0]);
            file_put_contents($source, $oversized);
            foreach (['string', 'file'] as $mode) {
                try {
                    $mode === 'string'
                        ? $storage->storeSnapshot($camera, $oversized)
                        : $storage->storeSnapshotFile($camera, $source);
                    self::fail('Oversized dimensions accepted');
                } catch (\Webcam\Service\Storage\SnapshotResourceLimitException $exception) {
                    self::assertStringContainsString('dimension or pixel', $exception->getMessage());
                }
            }
        }
        \Webcam\Service\Storage\SnapshotResourceLimits::checkDimensions([6000, 4000]);
        \Webcam\Service\Storage\SnapshotResourceLimits::checkDimensions([8000, 5000]);
        self::assertFileDoesNotExist($app->paths()->cameraDbPath('testcam/db.sqlite'));
    }

    public function testExplicitSlotKeepsScheduledAndCapturedTimestampsSeparate(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $storage = new ImageStorage($app);
        $cameraDbDir = $app->paths()->cameraDbPath('testcam');
        if (!mkdir($cameraDbDir, 0777, true) && !is_dir($cameraDbDir)) {
            $this->fail('Unable to create camera DB test directory');
        }
        $samplePath = $this->root . '/scheduled.jpg';
        $this->writeSampleJpeg($samplePath);
        $binary = file_get_contents($samplePath);
        $this->assertIsString($binary);

        $capturedAt = strtotime(date('Y') . '-08-12 00:10:13 UTC');
        $slotTimestamp = strtotime(date('Y') . '-08-12 00:05:00 UTC');
        $this->assertIsInt($capturedAt);
        $this->assertIsInt($slotTimestamp);

        $stored = $storage->storeSnapshot(
            $camera,
            $binary,
            $capturedAt,
            $slotTimestamp
        );

        $this->assertSame($slotTimestamp, $stored['slot_timestamp']);
        $this->assertSame($capturedAt, $stored['captured_timestamp']);
        $this->assertStringContainsString(date('Ymd_His', $slotTimestamp), $stored['original']);

        $pdo = new PDO('sqlite:' . $app->paths()->cameraDbPath('testcam/db.sqlite'));
        $row = $pdo->query(
            'SELECT slot_ts, captured_ts FROM captures ORDER BY slot_ts DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame((string) $slotTimestamp, (string) $row['slot_ts']);
        $this->assertSame((string) $capturedAt, (string) $row['captured_ts']);
    }

    public function testFallBackHourStoresDistinctImagesAndVariants(): void
    {
        $app = $this->makeApp(true, 'Europe/Berlin');
        $camera = $app->cameraOrFail('testcam');
        $storage = new ImageStorage($app);
        $stored = [];
        foreach ([1792887000, 1792890600] as $slot) {
            $im = $this->createDarkNoisyImage(640, 480);
            ob_start(); imagejpeg($im, null, 92); $binary = ob_get_clean(); imagedestroy($im);
            $stored[] = [$storage->storeSnapshot($camera, $binary, $slot + 20, $slot), hash('sha256', $binary)];
        }
        self::assertNotSame($stored[0][0]['original'], $stored[1][0]['original']);
        foreach ($stored as [$result, $hash]) {
            self::assertSame($hash, hash_file('sha256', $result['original']));
            foreach ($result['named_variants'] as $path) self::assertFileExists($path);
        }
        self::assertStringEndsWith('_utc1792890600.jpg', $stored[1][0]['original']);
    }

    public function testIdenticalFollowingSnapshotIsFlaggedAndImageIsDiscarded(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $storage = new ImageStorage($app);
        $samplePath = $this->root . '/duplicate.jpg';
        $this->writeSampleJpeg($samplePath);
        $binary = file_get_contents($samplePath);
        $this->assertIsString($binary);

        $firstSlot = strtotime(date('Y') . '-08-13 06:00:00 UTC');
        $secondSlot = $firstSlot + 600;
        $first = $storage->storeSnapshot($camera, $binary, $firstSlot, $firstSlot);
        $second = $storage->storeSnapshot($camera, $binary, $secondSlot, $secondSlot);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertFileExists($first['original']);
        $this->assertFileDoesNotExist($second['original']);

        $storage->storeWeatherLines($camera, $secondSlot, ['Temperature: 20 C']);
        $pdo = new PDO('sqlite:' . $app->paths()->cameraDbPath('testcam/db.sqlite'));
        $rows = $pdo->query(
            'SELECT c.slot_ts, c.is_duplicate, COUNT(w.id) AS weather_count
               FROM captures c
               LEFT JOIN weather_entries w ON w.capture_id = c.id
              GROUP BY c.id
              ORDER BY c.slot_ts ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('0', (string) $rows[0]['is_duplicate']);
        $this->assertSame('1', (string) $rows[1]['is_duplicate']);
        $this->assertSame('1', (string) $rows[1]['weather_count']);

        $store = new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera, null, true);
        $visible = $store->capturesForRange($firstSlot, $secondSlot);
        $this->assertCount(1, $visible);
        $this->assertSame($firstSlot, (int) $visible[0]['slot_ts']);
    }

    public function testNightDenoiseActivatesOnlyForTaggedDarkCameras(): void
    {
        $app = $this->makeApp();
        $plainCamera = $app->cameraOrFail('testcam');
        $nightCamera = $app->cameraOrFail('nightcam');
        $generator = new ImageVariantGenerator($app);

        $this->assertFalse($generator->denoiseEnabled($plainCamera, 12.0));
        $this->assertFalse($generator->denoiseEnabled($nightCamera, 35.0));
        $this->assertTrue($generator->denoiseEnabled($nightCamera, 12.0));
    }

    public function testNightDenoiseProducesDifferentDerivedVariant(): void
    {
        $app = $this->makeApp();
        $generator = new ImageVariantGenerator($app);
        $image = $this->createDarkNoisyImage(640, 480);

        $plainPath = $this->root . '/plain.jpg';
        $denoisedPath = $this->root . '/denoised.jpg';

        $method = new ReflectionMethod(ImageVariantGenerator::class, 'writeResized');
        $method->setAccessible(true);
        $method->invoke($generator, $image, 640, 480, 400, $plainPath, false);
        $method->invoke($generator, $image, 640, 480, 400, $denoisedPath, true);

        imagedestroy($image);

        $this->assertFileExists($plainPath);
        $this->assertFileExists($denoisedPath);
        $this->assertNotSame(md5_file($plainPath), md5_file($denoisedPath));
    }

    public function testCacheRetentionRunsOnlyOncePerCameraAndDay(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $retention = new CacheRetentionManager($app);

        $method = new ReflectionMethod(CacheRetentionManager::class, 'claimDailyRun');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($retention, $camera));
        $this->assertFalse($method->invoke($retention, $camera));
        $this->assertFileExists($this->root . '/state/cache-retention/testcam.json');
    }

    public function testNewCaptureDefersOldCacheDeletionToMaintenance(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $cacheRoot = $app->paths()->cachePath('testcam');
        $oldDir = $cacheRoot . '/2025/01/01';
        mkdir($oldDir, 0777, true);
        $oldFile = $oldDir . '/20250101_000000.jpg';
        $this->writeSampleJpeg($oldFile);
        $this->writeCompleteArchiveSet($app, 'testcam', '2025/01/01', '0000', $oldFile);
        touch($oldFile, time() - 400 * 86400);

        $result = (new ImageStorage($app))->storeSnapshot($camera, file_get_contents($oldFile));
        $this->assertFileExists($result['original']);
        $this->assertFileExists($oldFile, 'Capture must not perform retention.');
        $this->assertFileDoesNotExist($this->root . '/state/cache-retention/testcam.json');

        (new CacheRetentionManager($app))->run($camera, $cacheRoot, time() - 180 * 86400);
        $this->assertFileDoesNotExist($oldFile, 'Separate maintenance retains archive-verified deletion.');
        $this->assertFileExists($result['original']);
    }

    public function testCacheRetentionKeepsOverlayAssets(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $retention = new CacheRetentionManager($app);
        $cacheRoot = $app->paths()->cachePath('testcam');

        $overlayDir = $cacheRoot . '/overlays';
        $oldCacheDir = $cacheRoot . '/2025/01/01';
        if (!mkdir($overlayDir, 0777, true) && !is_dir($overlayDir)) {
            $this->fail('Unable to create overlay dir: ' . $overlayDir);
        }
        if (!mkdir($oldCacheDir, 0777, true) && !is_dir($oldCacheDir)) {
            $this->fail('Unable to create cache dir: ' . $oldCacheDir);
        }

        $overlayFile = $overlayDir . '/logo.png';
        $oldCacheFile = $oldCacheDir . '/20250101_000000.jpg';
        file_put_contents($overlayFile, 'overlay');
        $this->writeSampleJpeg($oldCacheFile);
        $this->writeCompleteArchiveSet($app, 'testcam', '2025/01/01', '0000', $oldCacheFile);

        $oldTimestamp = time() - 400 * 86400;
        touch($overlayFile, $oldTimestamp);
        touch($oldCacheFile, $oldTimestamp);

        $method = new ReflectionMethod(CacheRetentionManager::class, 'purge');
        $method->setAccessible(true);
        $method->invoke($retention, $camera, $cacheRoot, time() - 180 * 86400);

        $this->assertFileExists($overlayFile);
        $this->assertDirectoryExists($overlayDir);
        $this->assertFileDoesNotExist($oldCacheFile);
    }

    public function testRetentionSelectsOnlyCompletedExpiredLocalDays(): void
    {
        $app = $this->makeApp(true, 'Europe/Berlin');
        $root = $app->paths()->cachePath('testcam');
        foreach (['2025/12/31', '2026/02/30', '2026/04/16', '2026/04/17', '2026/05/01', '2027/01/01'] as $day) {
            mkdir($root . '/' . $day, 0777, true);
        }
        symlink($root . '/2025', $root . '/2024');
        symlink($root . '/2026/04', $root . '/2026/03');
        symlink($root . '/2026/04/16', $root . '/2026/04/15');
        // UTC is still April 16, but the local cutoff day is already April 17.
        $cutoff = strtotime('2026-04-16T22:30:00Z');
        $method = new ReflectionMethod(CacheRetentionManager::class, 'dayDirectories');
        $days = $method->invoke(new CacheRetentionManager($app), $root, $cutoff);
        self::assertSame(['2025/12/31', '2026/04/16'], array_keys($days));
    }

    public function testRetentionKeepsRecentlyModifiedOldDaysAndOldMtimeRecentDays(): void
    {
        $app = $this->makeApp();
        $root = $app->paths()->cachePath('testcam');
        $cutoff = strtotime('2026-04-17T00:15:00Z');
        foreach (['2026/04/15', '2026/04/16', '2026/04/17', '2026/10/14'] as $day) {
            mkdir($root . '/' . $day, 0777, true);
            mkdir($app->paths()->archivePath('testcam/' . $day), 0777, true);
            file_put_contents($root . '/' . $day . '/0000_sm.jpg', 'derived artifact');
            copy($root . '/' . $day . '/0000_sm.jpg', $app->paths()->archivePath('testcam/' . $day . '/0000_sm.jpg'));
            touch($root . '/' . $day . '/0000_sm.jpg', $cutoff - 86400);
        }
        // A newly regenerated variant prevents deletion of the entire old day.
        file_put_contents($root . '/2026/04/16/fresh.jpg', 'new');
        touch($root . '/2026/04/16/fresh.jpg', $cutoff);
        $purge = new ReflectionMethod(CacheRetentionManager::class, 'purge');
        $purge->invoke(new CacheRetentionManager($app), $app->cameraOrFail('testcam'), $root, $cutoff);
        self::assertDirectoryDoesNotExist($root . '/2026/04/15');
        foreach (['2026/04/16', '2026/04/17', '2026/10/14'] as $day) {
            self::assertFileExists($root . '/' . $day . '/0000_sm.jpg');
        }
    }

    public function testLooseCleanupPrunesProtectedTreesBeforeDescending(): void
    {
        $app = $this->makeApp();
        $retention = new CacheRetentionManager($app);
        $root = $app->paths()->cachePath('testcam');
        foreach (['2026/09/09', '2025/01/01', 'overlays/deep', 'current', 'legacy/nested'] as $directory) {
            mkdir($root . '/' . $directory, 0777, true);
            file_put_contents($root . '/' . $directory . '/keep.txt', 'fixture');
        }
        touch($root . '/current/keep.txt', 1);
        file_put_contents($root . '/loose.txt', 'old');
        touch($root . '/loose.txt', 1);
        file_put_contents($root . '/legacy/nested/stale.txt', 'old');
        touch($root . '/legacy/nested/stale.txt', 1);
        symlink($root . '/2026', $root . '/year-link');
        symlink($root . '/loose.txt', $root . '/file-link');

        // Prueft die Traversierung selbst, nicht bloss den Erhalt der Dateien:
        // der alte CHILD_FIRST-Filter liess sie stehen, las aber alle vorher.
        $iterator = new ReflectionMethod(CacheRetentionManager::class, 'looseFiles');
        $visited = [];
        foreach ($iterator->invoke($retention, $root) as $entry) {
            $visited[] = substr($entry->getPathname(), strlen($root) + 1);
        }
        sort($visited);
        self::assertSame([
            'legacy', 'legacy/nested', 'legacy/nested/keep.txt',
            'legacy/nested/stale.txt', 'loose.txt',
        ], $visited);

        $purge = new ReflectionMethod(CacheRetentionManager::class, 'purgeLooseFiles');
        $purge->invoke($retention, $app->cameraOrFail('testcam'), $root, time() - 86400);
        self::assertFileDoesNotExist($root . '/loose.txt');
        self::assertFileDoesNotExist($root . '/legacy/nested/stale.txt');
        self::assertFileExists($root . '/legacy/nested/keep.txt');
        self::assertFileExists($root . '/2026/09/09/keep.txt');
        self::assertFileExists($root . '/2025/01/01/keep.txt');
        self::assertFileExists($root . '/overlays/deep/keep.txt');
        self::assertFileExists($root . '/current/keep.txt');
        self::assertTrue(is_link($root . '/year-link'));
        self::assertTrue(is_link($root . '/file-link'));
    }

    public function testCacheRetentionKeepsWholeDayAndLogsFailedArchiveVerification(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $retention = new CacheRetentionManager($app);
        $cacheRoot = $app->paths()->cachePath('testcam');
        $oldCacheDir = $cacheRoot . '/2025/01/02';
        if (!mkdir($oldCacheDir, 0777, true) && !is_dir($oldCacheDir)) {
            $this->fail('Unable to create cache dir: ' . $oldCacheDir);
        }

        $original = $oldCacheDir . '/20250102_000000.jpg';
        $variant = $oldCacheDir . '/20250102_000000_400.jpg';
        $this->writeSampleJpeg($original);
        copy($original, $variant);
        $this->writeCompleteArchiveSet($app, 'testcam', '2025/01/02', '0000', $original);
        unlink($app->paths()->archivePath('testcam/2025/01/02/0000_lm.jpg'));
        $oldTimestamp = time() - 400 * 86400;
        touch($original, $oldTimestamp);
        touch($variant, $oldTimestamp);

        $method = new ReflectionMethod(CacheRetentionManager::class, 'purge');
        $method->setAccessible(true);
        $method->invoke($retention, $camera, $cacheRoot, time() - 180 * 86400);

        $this->assertFileExists($original);
        $this->assertFileExists($variant);
        $log = file_get_contents($this->root . '/log/app.log');
        $this->assertIsString($log);
        $this->assertStringContainsString('cache retention archive verification failed', $log);
        $this->assertStringContainsString('2025-01-02', $log);
    $this->assertStringContainsString('0000_lm.jpg', $log);
  }

  public function testCacheRetentionRemovesOldDerivedOnlyDayWithoutWarning(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail('testcam');
    $retention = new CacheRetentionManager($app);
    $cacheRoot = $app->paths()->cachePath('testcam');
    $oldCacheDir = $cacheRoot . '/2025/01/04';
    $archiveDir = $app->paths()->archivePath('testcam/2025/01/04');
    if (!mkdir($oldCacheDir, 0777, true) && !is_dir($oldCacheDir)) {
      $this->fail('Unable to create cache dir: ' . $oldCacheDir);
    }
    if (!mkdir($archiveDir, 0777, true) && !is_dir($archiveDir)) {
      $this->fail('Unable to create archive dir: ' . $archiveDir);
    }

    // Nur eine abgeleitete Variante: kein Original-YYYYMMDD_HHMMSS.jpg.
    $derived = $oldCacheDir . '/0000_sm.jpg';
    $this->writeSampleJpeg($derived);
    copy($derived, $archiveDir . '/0000_sm.jpg');
    touch($derived, time() - 400 * 86400);

    $method = new ReflectionMethod(CacheRetentionManager::class, 'purge');
    $method->setAccessible(true);
    $method->invoke($retention, $camera, $cacheRoot, time() - 180 * 86400);

    $this->assertFileDoesNotExist($derived);
    $this->assertDirectoryDoesNotExist($oldCacheDir);
    $log = file_get_contents($this->root . '/log/app.log');
    $this->assertIsString($log);
    $this->assertStringNotContainsString('cache retention archive verification failed', $log);
    $this->assertStringContainsString('cache retention removed verified archive day', $log);
  }

  public function testRetentionPreservesOrphanMetadataUntilIdenticalArchiveCopyExists(): void
  {
    $app = $this->makeApp();
    $cache = $app->paths()->cachePath('testcam/2025/01/05');
    $archive = $app->paths()->archivePath('testcam/2025/01/05');
    mkdir($cache, 0777, true); mkdir($archive, 0777, true);
    $source = $cache . '/20250105_120000_WX.txt';
    file_put_contents($source, "HistoricalExtra|unique\n");
    touch($source, 1);
    $purge = new ReflectionMethod(CacheRetentionManager::class, 'purge');
    $manager = new CacheRetentionManager($app);
    foreach ([null, "HistoricalExtra|wrong!\n"] as $target) {
      if ($target !== null) { file_put_contents($archive . '/1200_wx.txt', $target); }
      $purge->invoke($manager, $app->cameraOrFail('testcam'), $app->paths()->cachePath('testcam'), time()-86400);
      self::assertFileExists($source);
    }
    copy($source, $archive . '/1200_wx.txt');
    $purge->invoke($manager, $app->cameraOrFail('testcam'), $app->paths()->cachePath('testcam'), time()-86400);
    self::assertFileDoesNotExist($source);
    self::assertSame("HistoricalExtra|unique\n", file_get_contents($archive . '/1200_wx.txt'));
  }

  public function testCacheRetentionKeepsWholeDayWithoutMountSentinel(): void
    {
        $app = $this->makeApp(false);
        $camera = $app->cameraOrFail('testcam');
        $retention = new CacheRetentionManager($app);
        $cacheRoot = $app->paths()->cachePath('testcam');
        $oldCacheDir = $cacheRoot . '/2025/01/03';
        if (!mkdir($oldCacheDir, 0777, true) && !is_dir($oldCacheDir)) {
            $this->fail('Unable to create cache dir: ' . $oldCacheDir);
        }

        $original = $oldCacheDir . '/20250103_000000.jpg';
        $this->writeSampleJpeg($original);
        touch($original, time() - 400 * 86400);

        $method = new ReflectionMethod(CacheRetentionManager::class, 'purge');
        $method->setAccessible(true);
        $method->invoke($retention, $camera, $cacheRoot, time() - 180 * 86400);

        $this->assertFileExists($original);
        $log = file_get_contents($this->root . '/log/app.log');
        $this->assertIsString($log);
        $this->assertStringContainsString('cache retention archive mount guard failed', $log);
        $this->assertStringContainsString('Archive mount sentinel missing or unreadable', $log);
    }

    public function testTargetedBackupStatusCoversOnlySuccessfulCamera(): void
    {
        $app = $this->makeApp();
        $scheduler = new CaptureBackupScheduler($app);
        $stateDir = $this->root . '/state';
        if (!is_dir($stateDir) && !mkdir($stateDir, 0777, true) && !is_dir($stateDir)) {
            $this->fail('Unable to create backup state dir');
        }
        $stateFile = $stateDir . '/backup_partial.json';
        file_put_contents($stateFile, json_encode([
            'status' => 'ok',
            'scope' => 'partial',
            'ts' => time(),
            'ok_slugs' => ['testcam'],
        ], JSON_THROW_ON_ERROR));

        $method = new ReflectionMethod(CaptureBackupScheduler::class, 'statusCoversCamera');
        $method->setAccessible(true);
        $timezone = new DateTimeZone('UTC');
        $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');

        $this->assertTrue($method->invoke(
            $scheduler,
            $stateFile,
            $app->cameraOrFail('testcam'),
            $today,
            $timezone
        ));
        $this->assertFalse($method->invoke(
            $scheduler,
            $stateFile,
            $app->cameraOrFail('nightcam'),
            $today,
            $timezone
        ));
    }

    private function makeApp(bool $withMountSentinel = true, string $timezone = 'UTC'): App
    {
        $cfg = [
            'timezone' => $timezone,
            'camera_config_path' => $this->configFile,
            'storage_path' => $this->root . '/storage',
            'archive_path' => $this->root . '/storage/archive',
            'state_path' => $this->root . '/state',
            'log_path' => $this->root . '/log',
            'info_path' => $this->root . '/info',
            'cache_path' => $this->root . '/cache',
            'camera_db_path' => $this->root . '/camera-db',
            'archive_min_jpeg_bytes' => 1024,
            'cache_retention_days' => 180,
            'archive_mount_sentinel_required' => true,
            'base_url' => '',
        ];
        $app = new App($cfg);
        // Bild-/DB-Vertrag isoliert pruefen, keinen abgekoppelten Backup-Prozess
        // mit der globalen HTTP-Testkonfiguration starten. Der Scheduler hat
        // eigene echte Lock-/Parallelitaets-Tests in CaptureBackupSchedulerTest.
        file_put_contents($app->paths()->statePath('backup_db.json'), json_encode([
            'ts' => time(), 'status' => 'ok', 'scope' => 'full',
        ], JSON_THROW_ON_ERROR));
        if ($withMountSentinel) {
            file_put_contents(
                $app->paths()->archivePath(\Webcam\Service\Storage\ArchiveMountGuard::SENTINEL_FILENAME),
                ''
            );
        }
        return $app;
    }

    private function createDarkNoisyImage(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image) {
            $this->fail('Unable to create test image');
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $base = 18;
                $noise = random_int(-18, 18);
                $channel = max(0, min(255, $base + $noise));
                $rgb = ($channel << 16) | ($channel << 8) | $channel;
                imagesetpixel($image, $x, $y, $rgb);
            }
        }

        return $image;
    }

    private function writeSampleJpeg(string $path): void
    {
        $image = imagecreatetruecolor(640, 480);
        if (!$image) {
            $this->fail('Unable to create sample image');
        }
        for ($y = 0; $y < 480; $y++) {
            $color = imagecolorallocate($image, $y % 255, ($y * 2) % 255, ($y * 3) % 255);
            imageline($image, 0, $y, 639, $y, $color);
        }
        imagejpeg($image, $path, 92);
        imagedestroy($image);
    }

    private function writeCompleteArchiveSet(
        App $app,
        string $slug,
        string $relativeDay,
        string $time,
        string $source
    ): void {
        $targetDir = $app->paths()->archivePath($slug . '/' . $relativeDay);
        if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            $this->fail('Unable to create archive dir: ' . $targetDir);
        }
        $image = imagecreatefromjpeg($source);
        if (!$image) {
            $this->fail('Unable to read sample image');
        }
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        foreach (['sm' => 114, 'la' => 816, 'lm' => 1200] as $suffix => $width) {
            $targetWidth = min($width, $sourceWidth);
            $targetHeight = (int) max(1, round($sourceHeight * ($targetWidth / $sourceWidth)));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            imagejpeg($canvas, $targetDir . '/' . $time . '_' . $suffix . '.jpg', 92);
            imagedestroy($canvas);
        }
        imagedestroy($image);
        copy($source, $targetDir . '/' . $time . '_hu.jpg');
        copy($source, $targetDir . '/' . $time . '_full.jpg');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
