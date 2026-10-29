<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;

require_once __DIR__ . '/../bootstrap.php';

final class CameraDataStoreTest extends TestCase
{
    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wcam_store_' . bin2hex(random_bytes(4));
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
                'resolutions' => [],
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

    public function testCanInitializeSchemaAtCustomDbPath(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $dbPath = $this->root . '/staging/custom-db.sqlite';

        $store = new CameraDataStore($app, $camera, $dbPath);
        $store->ensureWritableSchema();
        $store->close();

        $this->assertSame($dbPath, $store->dbPath());
        $this->assertFileExists($dbPath);

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $this->assertContains('captures', $tables);
        $this->assertContains('capture_logo', $tables);
        $this->assertContains('meta', $tables);
    }

    public function testWeatherProjectionTracksRealCaptureAndWeatherWriters(): void
    {
        $app = $this->makeApp();
        $store = new CameraDataStore($app, $app->cameraOrFail('testcam'), $this->root . '/projection.sqlite');
        $store->ensureWritableSchema();
        $pdo = $store->pdo();
        $metrics = new \Webcam\Infrastructure\Storage\CameraWeatherMetricsRepository($pdo);
        $metrics->install();
        $tz = new DateTimeZone('UTC');
        $ts = (new DateTimeImmutable('2026-03-29 12:00:00', $tz))->getTimestamp();
        $id = $store->recordCapture(['slot_timestamp' => $ts, 'relative_path' => 'fixture.jpg', 'weather_lines' => ['Temp: 10 C']]);
        self::assertSame(10.0, $metrics->aggregate(['2026-03-29'], $tz)['latest']['temperature']['value']);
        self::assertTrue($store->upsertWeatherForSlot($ts, ['Temp: 20 C']));
        self::assertSame(20.0, $metrics->aggregate(['2026-03-29'], $tz)['latest']['temperature']['value']);
        $store->upsertWeatherForSlot($ts, []);
        self::assertSame([], $metrics->aggregate(['2026-03-29'], $tz)['daily']);
        $store->upsertWeatherForSlot($ts, ['Temp: 30 C']);
        $pdo->prepare('DELETE FROM captures WHERE id=?')->execute([$id]);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM weather_measurements')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM weather_measurements_dirty')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM weather_entries')->fetchColumn());
        self::assertFalse($pdo->query('PRAGMA foreign_key_check')->fetch());
        $store->close();
    }

    public function testSchemaVersionThreeAddsDuplicateFlagWithoutLosingRows(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $dbPath = $this->root . '/legacy/db.sqlite';
        mkdir(dirname($dbPath), 0777, true);
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->exec(
            'CREATE TABLE captures (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              slot_ts INTEGER NOT NULL UNIQUE,
              captured_ts INTEGER,
              relative_path TEXT NOT NULL,
              luminance REAL,
              hash_sha256 TEXT,
              likes_count INTEGER NOT NULL DEFAULT 0,
              created_at INTEGER NOT NULL
            );
            CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
            INSERT INTO meta (key, value) VALUES ("version", "3");
            INSERT INTO captures (slot_ts, relative_path, created_at)
              VALUES (1000, "cache/testcam/legacy.jpg", 1000);'
        );
        $pdo = null;

        $store = new CameraDataStore($app, $camera, $dbPath);
        $store->ensureWritableSchema();
        $columns = $store->pdo()->query('PRAGMA table_info(captures)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        $this->assertContains('is_duplicate', $names);
        $this->assertSame('0', (string) $store->pdo()->query(
            'SELECT is_duplicate FROM captures WHERE slot_ts = 1000'
        )->fetchColumn());
        $this->assertSame('4', (string) $store->pdo()->query(
            "SELECT value FROM meta WHERE key = 'version'"
        )->fetchColumn());
    }

    public function testConfiguredCameraDbPathOverridesCacheRoot(): void
    {
        $cameraDbRoot = $this->root . '/camera-db';
        $app = $this->makeApp([
            'camera_db_path' => $cameraDbRoot,
        ]);
        $camera = $app->cameraOrFail('testcam');

        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();
        $store->close();

        $expected = $cameraDbRoot . '/testcam/db.sqlite';
        $this->assertSame($expected, $store->dbPath());
        $this->assertFileExists($expected);
        $this->assertFileDoesNotExist($this->root . '/cache/testcam/db.sqlite');
    }

    public function testYearsReturnExactSparseYearsWhenTimezoneMatchesSystem(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        foreach (['2012-03-02 17:05:00', '2013-02-01 00:20:00', '2025-12-19 12:00:00', '2026-04-11 14:00:00'] as $date) {
            $ts = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
            $store->recordCapture([
                'slot_timestamp' => $ts,
                'captured_timestamp' => $ts,
                'relative_path' => 'cache/testcam/' . gmdate('Y/m/d/Ymd_His', $ts) . '_la.jpg',
                'variants' => [
                    [
                        'variant' => 'la',
                        'relative_path' => 'cache/testcam/' . gmdate('Y/m/d/Ymd_His', $ts) . '_la.jpg',
                    ],
                ],
            ]);
        }

        $years = $store->years();

        $this->assertSame([2026, 2025, 2013, 2012], $years);
    }

    public function testNearestCaptureSlotReusesStoreAndPrefersEarlierSlotOnTie(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        foreach ([1000, 1200, 1600] as $slotTs) {
            $store->recordCapture([
                'slot_timestamp' => $slotTs,
                'captured_timestamp' => $slotTs,
                'relative_path' => 'cache/testcam/test-' . $slotTs . '.jpg',
                'variants' => [],
            ]);
        }

        $this->assertSame(1200, $store->nearestCaptureSlot(1400, 900, 1700));
        $this->assertSame(1600, $store->nearestCaptureSlot(1550, 900, 1700));
        $this->assertNull($store->nearestCaptureSlot(1400, 1300, 1500));
        $this->assertNull($store->nearestCaptureSlot(1400, 1500, 1300));
    }

    public function testDuplicateCapturesRemainStoredButAreExcludedFromImageQueries(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $goodId = $store->recordCapture([
            'slot_timestamp' => 1000,
            'captured_timestamp' => 1001,
            'relative_path' => 'cache/testcam/good.jpg',
            'hash' => str_repeat('a', 64),
            'variants' => [],
            'weather_lines' => ['Temperature: 10 C'],
        ]);
        $dupeId = $store->recordCapture([
            'slot_timestamp' => 1600,
            'captured_timestamp' => 1601,
            'relative_path' => 'cache/testcam/dupe.jpg',
            'hash' => str_repeat('a', 64),
            'is_duplicate' => true,
            'variants' => [],
            'weather_lines' => ['Temperature: 11 C'],
        ]);

        $this->assertGreaterThan(0, $goodId);
        $this->assertGreaterThan(0, $dupeId);
        $this->assertSame(str_repeat('a', 64), $store->latestUsableHashBeforeSlot(1700));
        $this->assertSame(1000, $store->nearestCaptureSlot(1600, 900, 1700));
        $this->assertCount(1, $store->capturesForRange(900, 1700));
        $this->assertNull($store->findCaptureIdByRelativePath('cache/testcam/dupe.jpg'));

        $pdo = $store->pdo();
        $weatherCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM weather_entries WHERE capture_id = ' . $dupeId
        )->fetchColumn();
        $this->assertSame(1, $weatherCount);
    }

    public function testCapturesForRangeLimitsRowsBeforeLoadingRelations(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        foreach ([1000, 1600, 2200] as $slotTs) {
            $store->recordCapture([
                'slot_timestamp' => $slotTs,
                'captured_timestamp' => $slotTs,
                'relative_path' => 'cache/testcam/test-' . $slotTs . '.jpg',
                'variants' => [],
                'weather_lines' => ['Temperature: ' . $slotTs],
            ]);
        }

        $rows = $store->capturesForRange(900, 2300, 2);

        $this->assertSame([1600, 2200], array_map(
            static fn(array $row): int => (int) $row['slot_ts'],
            $rows
        ));
        $this->assertCount(1, $rows[0]['weather_rows'] ?? []);
        $this->assertCount(1, $rows[1]['weather_rows'] ?? []);
    }

    public function testDetectsRangesContainingOnlyDuplicateCaptures(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $store->recordCapture([
            'slot_timestamp' => 1000,
            'relative_path' => 'cache/testcam/good.jpg',
            'variants' => [],
        ]);
        $store->recordCapture([
            'slot_timestamp' => 1600,
            'relative_path' => 'cache/testcam/dupe.jpg',
            'is_duplicate' => true,
            'variants' => [],
        ]);

        $this->assertTrue($store->rangeContainsOnlyDuplicateCaptures(1500, 1700));
        $this->assertSame([1600], $store->duplicateCaptureSlotsForRange(900, 1700));
        $this->assertFalse($store->rangeContainsOnlyDuplicateCaptures(900, 1700));
        $this->assertFalse($store->rangeContainsOnlyDuplicateCaptures(2000, 2100));
        $this->assertFalse($store->rangeContainsOnlyDuplicateCaptures(1700, 1500));
    }

    public function testDuplicateFlagsCanBeUpdatedAtomicallyAsBatch(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $firstId = $store->recordCapture([
            'slot_timestamp' => 1000,
            'relative_path' => 'cache/testcam/first.jpg',
            'variants' => [],
        ]);
        $secondId = $store->recordCapture([
            'slot_timestamp' => 1600,
            'relative_path' => 'cache/testcam/second.jpg',
            'variants' => [],
        ]);

        $this->assertSame(2, $store->setCaptureDuplicates([
            [$firstId, true],
            [$secondId, true],
        ]));
        $this->assertSame(2, (int) $store->pdo()->query(
            'SELECT COUNT(*) FROM captures WHERE is_duplicate = 1'
        )->fetchColumn());

        try {
            $store->setCaptureDuplicates([
                [$firstId, false],
                [PHP_INT_MAX, false],
            ]);
            $this->fail('Expected an unknown capture id to abort the duplicate batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Capture not found', $exception->getMessage());
        }

        $this->assertSame(2, (int) $store->pdo()->query(
            'SELECT COUNT(*) FROM captures WHERE is_duplicate = 1'
        )->fetchColumn());
    }

    public function testCaptureAndVariantImageStateCanBeReconciledAtomically(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $captureId = $store->recordCapture([
            'slot_timestamp' => 1000,
            'relative_path' => 'cache/testcam/original.jpg',
            'variants' => [[
                'variant' => 'la',
                'relative_path' => 'cache/testcam/large.jpg',
                'size' => 1234,
                'mtime' => 900,
            ]],
            'weather_lines' => ['Temperature: 10 C'],
            'exif' => ['Model' => 'Test Camera'],
        ]);
        $variantId = (int) $store->pdo()->query(
            'SELECT id FROM variants WHERE capture_id = ' . $captureId
        )->fetchColumn();

        $result = $store->reconcileCaptureImageState(
            [[$captureId, true]],
            [[$variantId, 0, 0]]
        );

        $this->assertSame(['captures' => 1, 'variants' => 1], $result);
        $this->assertSame('1', (string) $store->pdo()->query(
            'SELECT is_duplicate FROM captures WHERE id = ' . $captureId
        )->fetchColumn());
        $this->assertSame(
            ['size_bytes' => 0, 'mtime' => 0],
            $store->pdo()->query(
                'SELECT size_bytes, mtime FROM variants WHERE id = ' . $variantId
            )->fetch(PDO::FETCH_ASSOC)
        );
        $this->assertSame(1, (int) $store->pdo()->query(
            'SELECT COUNT(*) FROM weather_entries WHERE capture_id = ' . $captureId
        )->fetchColumn());
        $this->assertSame(1, (int) $store->pdo()->query(
            'SELECT COUNT(*) FROM exif_entries WHERE capture_id = ' . $captureId
        )->fetchColumn());

        try {
            $store->reconcileCaptureImageState(
                [[$captureId, false]],
                [[PHP_INT_MAX, 1, 1]]
            );
            $this->fail('Expected an unknown variant id to abort the image-state batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Variant not found', $exception->getMessage());
        }

        $this->assertSame('1', (string) $store->pdo()->query(
            'SELECT is_duplicate FROM captures WHERE id = ' . $captureId
        )->fetchColumn());
        $this->assertSame('0', (string) $store->pdo()->query(
            'SELECT size_bytes FROM variants WHERE id = ' . $variantId
        )->fetchColumn());
    }

    public function testRecordCaptureFallsBackToStagedWriteWhenCustomDbPathIsLocked(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $dbPath = $this->root . '/cache/testcam/custom-db.sqlite';

        $writer = new CameraDataStore($app, $camera, $dbPath);
        $writer->ensureWritableSchema();
        $writer->recordCapture([
            'slot_timestamp' => 1712833200,
            'captured_timestamp' => 1712833200,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000.jpg',
            'variants' => [],
        ]);
        $writer->close();

        $locker = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $locker->exec('PRAGMA journal_mode = DELETE');
        $locker->exec('BEGIN EXCLUSIVE');
        $locker->query('SELECT COUNT(*) FROM captures')->fetchColumn();

        $store = new CameraDataStore($app, $camera, $dbPath);
        $captureId = $store->recordCapture([
            'slot_timestamp' => 1712833800,
            'captured_timestamp' => 1712833800,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_131000.jpg',
            'variants' => [
                [
                    'variant' => 'la',
                    'relative_path' => 'cache/testcam/2024/04/11/20240411_131000_la.jpg',
                ],
            ],
        ]);
        $store->close();

        $locker->exec('ROLLBACK');
        $locker = null;

        $this->assertGreaterThan(0, $captureId);

        $verify = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $latest = $verify->query('SELECT slot_ts, relative_path FROM captures ORDER BY slot_ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1712833800', (string) ($latest['slot_ts'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_131000.jpg', (string) ($latest['relative_path'] ?? ''));
    }

    public function testLockedLiveDbKeepsExistingDatabaseIntactWhenWriteFails(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');

        $writer = new CameraDataStore($app, $camera);
        $writer->ensureWritableSchema();
        $writer->recordCapture([
            'slot_timestamp' => 1712833200,
            'captured_timestamp' => 1712833200,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000.jpg',
            'variants' => [],
        ]);
        $writer->close();

        $dbPath = $app->paths()->cachePath('testcam/db.sqlite');
        $beforeSize = filesize($dbPath);
        $this->assertIsInt($beforeSize);
        $this->assertGreaterThan(0, $beforeSize);

        $locker = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $locker->exec('PRAGMA journal_mode = DELETE');
        $locker->exec('BEGIN EXCLUSIVE');
        $locker->query('SELECT COUNT(*) FROM captures')->fetchColumn();

        $store = new CameraDataStore($app, $camera);
        try {
            $store->recordCapture([
                'slot_timestamp' => 1712833800,
                'captured_timestamp' => 1712833800,
                'relative_path' => 'cache/testcam/2024/04/11/20240411_131000.jpg',
                'variants' => [],
            ]);
            $this->fail('Expected locked live DB write to fail without staged replacement.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('locked', strtolower($exception->getMessage()));
        } finally {
            $store->close();
            $locker->exec('ROLLBACK');
            $locker = null;
        }

        clearstatcache(true, $dbPath);
        $afterSize = filesize($dbPath);
        $this->assertSame($beforeSize, $afterSize);

        $verify = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $latest = $verify->query('SELECT slot_ts, relative_path FROM captures ORDER BY slot_ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1712833200', (string) ($latest['slot_ts'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_130000.jpg', (string) ($latest['relative_path'] ?? ''));
    }

    public function testRecordCaptureRecreatesUnreadableEmptyWriteLock(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();
        $store->close();

        $lockPath = $app->paths()->cachePath('testcam/db.sqlite.write.lock');
        file_put_contents($lockPath, '');
        chmod($lockPath, 0400);

        $writer = new CameraDataStore($app, $camera);
        $captureId = $writer->recordCapture([
            'slot_timestamp' => 1712833200,
            'captured_timestamp' => 1712833200,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000.jpg',
            'variants' => [],
        ]);
        $writer->close();

        $this->assertGreaterThan(0, $captureId);
        $this->assertFileExists($lockPath);
        $this->assertTrue(is_readable($lockPath));
        $this->assertTrue(is_writable($lockPath));
        $this->assertSame('660', substr(sprintf('%o', fileperms($lockPath)), -3));

        $pdo = new PDO('sqlite:' . $app->paths()->cachePath('testcam/db.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $latest = $pdo->query('SELECT slot_ts, relative_path FROM captures ORDER BY slot_ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1712833200', (string) ($latest['slot_ts'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_130000.jpg', (string) ($latest['relative_path'] ?? ''));
    }

    public function testStagedWriteCanReuseAnAlreadyHeldLiveWriteLock(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $method = new ReflectionMethod(CameraDataStore::class, 'withStagedWrite');
        $method->setAccessible(true);

        $captureId = $store->withWriteLock(function () use ($method, $store): int {
            return $method->invoke(
                $store,
                static function (CameraDataStore $stagingStore): int {
                    return $stagingStore->recordCapture([
                        'slot_timestamp' => 1712834400,
                        'captured_timestamp' => 1712834400,
                        'relative_path' => 'cache/testcam/2024/04/11/20240411_132000.jpg',
                        'variants' => [
                            [
                                'variant' => 'la',
                                'relative_path' => 'cache/testcam/2024/04/11/20240411_132000_la.jpg',
                            ],
                        ],
                    ]);
                },
                'test_nested_staged_write'
            );
        });
        $store->close();

        $this->assertGreaterThan(0, $captureId);

        $pdo = new PDO('sqlite:' . $app->paths()->cachePath('testcam/db.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $latest = $pdo->query('SELECT slot_ts, relative_path FROM captures ORDER BY slot_ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1712834400', (string) ($latest['slot_ts'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_132000.jpg', (string) ($latest['relative_path'] ?? ''));
    }

    public function testReadonlyLiveDbCanFallBackToDirectCopyWhenSwapCreationFails(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');

        $writer = new CameraDataStore($app, $camera);
        $writer->ensureWritableSchema();
        $writer->recordCapture([
            'slot_timestamp' => 1712833200,
            'captured_timestamp' => 1712833200,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000.jpg',
            'variants' => [],
        ]);
        $writer->close();

        $dbPath = $app->paths()->cachePath('testcam/db.sqlite');
        $lockPath = $dbPath . '.write.lock';
        if (!is_file($lockPath)) {
            file_put_contents($lockPath, '');
        }
        chmod($lockPath, 0666);
        chmod($dbPath, 0666);

        $dbDir = dirname($dbPath);
        chmod($dbDir, 0555);

        try {
            $store = new CameraDataStore($app, $camera);
            $captureId = $store->recordCapture([
                'slot_timestamp' => 1712835000,
                'captured_timestamp' => 1712835000,
                'relative_path' => 'cache/testcam/2024/04/11/20240411_133000.jpg',
                'variants' => [],
            ]);
            $store->close();
        } finally {
            chmod($dbDir, 0775);
        }

        $this->assertGreaterThan(0, $captureId);
        $this->assertFileDoesNotExist($dbPath . '.swap');

        $verify = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $latest = $verify->query('SELECT slot_ts, relative_path FROM captures ORDER BY slot_ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1712835000', (string) ($latest['slot_ts'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_133000.jpg', (string) ($latest['relative_path'] ?? ''));
    }

    public function testRecordCaptureOverwriteKeepsExistingCaptureIdRelationsValid(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);
        $store->ensureWritableSchema();

        $slotTs = 1712833200;
        $store->recordCapture([
            'slot_timestamp' => $slotTs,
            'captured_timestamp' => $slotTs,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000.jpg',
            'variants' => [
                [
                    'variant' => 'la',
                    'relative_path' => 'cache/testcam/2024/04/11/20240411_130000_la.jpg',
                ],
            ],
        ]);

        $store->recordCapture([
            'slot_timestamp' => $slotTs,
            'captured_timestamp' => $slotTs + 5,
            'relative_path' => 'cache/testcam/2024/04/11/20240411_130000_new.jpg',
            'variants' => [
                [
                    'variant' => 'la',
                    'relative_path' => 'cache/testcam/2024/04/11/20240411_130000_new_la.jpg',
                ],
            ],
        ]);

        $pdo = new PDO('sqlite:' . $app->paths()->cachePath('testcam/db.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query(
            "SELECT c.relative_path AS capture_path, v.relative_path AS variant_path
             FROM captures c
             JOIN variants v ON v.capture_id = c.id
             WHERE c.slot_ts = {$slotTs}
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('cache/testcam/2024/04/11/20240411_130000_new.jpg', (string) ($row['capture_path'] ?? ''));
        $this->assertSame('cache/testcam/2024/04/11/20240411_130000_new_la.jpg', (string) ($row['variant_path'] ?? ''));
    }

    private function makeApp(array $overrides = []): App
    {
        $cfg = [
            'timezone' => 'UTC',
            'camera_config_path' => $this->configFile,
            'storage_path' => $this->root . '/storage',
            'archive_path' => $this->root . '/storage/archive',
            'state_path' => $this->root . '/state',
            'log_path' => $this->root . '/log',
            'info_path' => $this->root . '/info',
            'cache_path' => $this->root . '/cache',
            'base_url' => '',
        ];
        $cfg = array_replace($cfg, $overrides);
        return new App($cfg);
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
