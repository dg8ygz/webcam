<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Storage\ArchiveDatabaseReader;
use Webcam\Service\Storage\TimelineMapper;

final class ArchiveBrowserTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = require __DIR__ . '/../bootstrap.php';
    }

    public function testTimelineReturnsEntriesOrEmptyArray(): void
    {
        $cameras = array_values($this->app->cameras()->all());
        $this->assertNotEmpty($cameras, 'No cameras configured for archive browser test.');

        // Keep the test fast by limiting to one camera and a single year.
        $camera = $cameras[0];
        $year = (int) date('Y');
        $timeline = $this->app->archiveBrowser()->timeline($camera, $year);
        $this->assertIsArray($timeline);
        $this->assertArrayHasKey('entries', $timeline);
        $this->assertIsArray($timeline['entries']);
        // allow empty if no archive present, but structure must exist
        if (!empty($timeline['entries'])) {
            $entry = $timeline['entries'][array_key_last($timeline['entries'])];
            $this->assertArrayHasKey('timestamp', $entry);
            $this->assertArrayHasKey('variants', $entry);
        }
    }

    public function testAvailableCalendarReturnsStructuredMaps(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $years = $this->app->archiveBrowser()->listYears($camera);
        $yearsFlat = array_values(array_unique(array_map('intval', array_merge(
            $years['current'] ?? [],
            $years['archive'] ?? []
        ))));
        if (!$yearsFlat) {
            $yearsFlat = [(int) date('Y')];
        }

        $calendar = $this->app->archiveBrowser()->availableCalendar($camera, [min($yearsFlat), max($yearsFlat)]);
        $this->assertIsArray($calendar);
        $this->assertArrayHasKey('months', $calendar);
        $this->assertArrayHasKey('days', $calendar);
        $this->assertIsArray($calendar['months']);
        $this->assertIsArray($calendar['days']);
    }

    public function testLatestEntryReturnsNullOrEntryShape(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $entry = $this->app->archiveBrowser()->latestEntry($camera);
        if ($entry === null) {
            $this->assertNull($entry);
            return;
        }

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('variants', $entry);
    }

    public function testDayEdgeEntryReturnsNavigationShape(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $latest = $this->app->archiveBrowser()->latestEntry($camera);
        if ($latest === null) {
            $this->markTestSkipped('No archive data available for day-edge lookup.');
        }

        $year = (int) ($latest['year'] ?? date('Y'));
        $month = (int) ($latest['month'] ?? date('n'));
        $day = (int) ($latest['day'] ?? date('j'));

        $first = $this->app->archiveBrowser()->dayEdgeEntry($camera, $year, $month, $day, 'first');
        $last = $this->app->archiveBrowser()->dayEdgeEntry($camera, $year, $month, $day, 'last');

        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertArrayHasKey('timestamp', $first);
        $this->assertArrayHasKey('time', $first);
        $this->assertArrayHasKey('timestamp', $last);
        $this->assertArrayHasKey('time', $last);
        $this->assertLessThanOrEqual((int) $last['timestamp'], (int) $first['timestamp']);
        $this->assertSame($year, (int) $last['year']);
        $this->assertSame($month, (int) $last['month']);
        $this->assertSame($day, (int) $last['day']);
    }

    public function testHourlyAdjacentEntriesPreserveMinuteAcrossMidnight(): void
    {
        $root = sys_get_temp_dir() . '/wcam_hour_nav_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $tz = new DateTimeZone('UTC');

            foreach ([
                '2026-08-04 23:50:00',
                '2026-08-05 00:00:00',
                '2026-08-05 00:50:00',
                '2026-08-05 01:50:00',
                '2026-08-06 00:00:00',
                '2026-08-06 04:50:00',
            ] as $date) {
                $slot = new DateTimeImmutable($date, $tz);
                $relative = sprintf(
                    'cache/testcam/%s/%s_la.jpg',
                    $slot->format('Y/m/d'),
                    $slot->format('Ymd_His')
                );
                $this->writeSampleJpeg($app->paths()->cachePath(sprintf(
                    'testcam/%s/%s_la.jpg',
                    $slot->format('Y/m/d'),
                    $slot->format('Ymd_His')
                )));
                $store->recordCapture([
                    'slot_timestamp' => $slot->getTimestamp(),
                    'captured_timestamp' => $slot->getTimestamp(),
                    'relative_path' => $relative,
                    'variants' => [[
                        'variant' => 'la',
                        'relative_path' => $relative,
                    ]],
                ]);
            }
            $store->close();

            $selected = (new DateTimeImmutable('2026-08-05 00:50:00', $tz))->getTimestamp();
            $adjacent = $app->archiveBrowser()->hourlyAdjacentEntries($camera, $selected);

            $this->assertNotNull($adjacent['prev']);
            $this->assertSame(4, (int) $adjacent['prev']['day']);
            $this->assertSame('235000', (string) $adjacent['prev']['time']);
            $this->assertNotNull($adjacent['next']);
            $this->assertSame(5, (int) $adjacent['next']['day']);
            $this->assertSame('015000', (string) $adjacent['next']['time']);

            $sparseSelected = (new DateTimeImmutable('2026-08-06 04:50:00', $tz))->getTimestamp();
            $sparseAdjacent = $app->archiveBrowser()->hourlyAdjacentEntries($camera, $sparseSelected);
            $this->assertNotNull($sparseAdjacent['prev']);
            $this->assertSame('000000', (string) $sparseAdjacent['prev']['time']);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testSelectFromTimelinePrefersNearestEntryWithinRequestedDay(): void
    {
        $timeline = [
            'entries' => [
                [
                    'timestamp' => strtotime('2025-03-15 17:30:00 UTC'),
                    'year' => 2025,
                    'month' => 3,
                    'day' => 15,
                    'time' => '173000',
                    'variants' => ['sm' => ['url' => 'a']],
                    'preview_url' => 'a',
                ],
                [
                    'timestamp' => strtotime('2025-03-15 23:50:00 UTC'),
                    'year' => 2025,
                    'month' => 3,
                    'day' => 15,
                    'time' => '235000',
                    'variants' => ['sm' => ['url' => 'b']],
                    'preview_url' => 'b',
                ],
            ],
        ];

        $selected = $this->app->archiveBrowser()->selectFromTimeline($timeline, [
            'year' => 2025,
            'month' => 3,
            'day' => 15,
            'time' => '120000',
        ]);

        $this->assertNotNull($selected);
        $this->assertSame('173000', (string) $selected['time']);
    }

    public function testMapsLegacyCacheVariantPathToArchiveLayout(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $reader = new ArchiveDatabaseReader($this->app, new TimelineMapper());

        $mapped = $reader->mapLegacyCacheVariantToArchiveRelativePath(
            $camera,
            sprintf('cache/%s/2025/12/19/20251219_120000_lm.jpg', $camera->slug())
        );

        $this->assertSame(
            sprintf('%s/%s/2025/12/19/1200_lm.jpg', trim($camera->storageArchiveDir(), '/'), $camera->slug()),
            $mapped
        );
    }

    public function testCanonicalArchiveVariantPathMissingFileIsRejected(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $reader = new ArchiveDatabaseReader($this->app, new TimelineMapper());

        $relative = sprintf('%s/%s/2025/12/19/1200_la.jpg', trim($camera->storageArchiveDir(), '/'), $camera->slug());
        $resolved = $reader->resolveVariantRelativePath($camera, $relative);

        $this->assertNull($resolved);
    }

    public function testCanonicalCacheVariantPathMissingFileIsRejected(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $reader = new ArchiveDatabaseReader($this->app, new TimelineMapper());

        $relative = sprintf('cache/%s/2026/04/11/20260411_140000_la.jpg', $camera->slug());
        $resolved = $reader->resolveVariantRelativePath($camera, $relative);

        $this->assertNull($resolved);
    }

    public function testExistingCanonicalCacheVariantPathIsAccepted(): void
    {
        $camera = array_values($this->app->cameras()->all())[0];
        $reader = new ArchiveDatabaseReader($this->app, new TimelineMapper());

        $relative = sprintf('cache/%s/2099/12/31/20991231_235000_400.jpg', $camera->slug());
        $absolute = $this->app->resolveCameraStoragePath($camera, $relative);
        $this->assertNotNull($absolute);

        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($absolute, 'test');

        try {
            $resolved = $reader->resolveVariantRelativePath($camera, $relative);
            $this->assertSame($relative, $resolved);
        } finally {
            @unlink($absolute);
            @rmdir($dir);
            @rmdir(dirname($dir));
            @rmdir(dirname(dirname($dir)));
            @rmdir(dirname(dirname(dirname($dir))));
        }
    }

    public function testStoredCacheVariantFallsBackToExistingArchiveFile(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_variant_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $reader = new ArchiveDatabaseReader($app, new TimelineMapper());
            $cacheRelative = 'cache/testcam/2026/04/11/20260411_140000_sm.jpg';
            $archiveRelative = $reader->mapLegacyCacheVariantToArchiveRelativePath($camera, $cacheRelative);
            $this->assertNotNull($archiveRelative);
            $archiveAbsolute = $app->resolveCameraStoragePath($camera, $archiveRelative);
            $this->assertNotNull($archiveAbsolute);
            $this->writeSampleJpeg($archiveAbsolute);

            // size_bytes bildet den realen Altbestand nach: Der Datensatz weiß,
            // dass die Datei einmal vorhanden war, obwohl sie inzwischen aus
            // dem Rolling Cache in das Langzeitarchiv verschoben wurde.
            $entry = $reader->entryFromRow($camera, [
                'slot_ts' => (new DateTimeImmutable('2026-04-11 14:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                'variants_rows' => [[
                    'variant' => 'sm',
                    'relative_path' => $cacheRelative,
                    'size_bytes' => 12345,
                    'width' => 114,
                    'height' => 64,
                ]],
            ], new DateTimeZone('UTC'), '', '');

            $this->assertNotNull($entry);
            $this->assertSame($archiveRelative, $entry['variants']['sm']['relative_path'] ?? null);
            $this->assertSame($archiveRelative, $entry['variants']['sm']['url'] ?? null);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testMergeTimelineEntriesAddsMissingCurrentDaySlot(): void
    {
        $browser = $this->app->archiveBrowser();
        $year = 2025;
        $tz = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));

        $baseTimeline = [
            'entries' => [
                [
                    'timestamp' => (new DateTimeImmutable('2025-04-11 13:00:00', $tz))->getTimestamp(),
                    'year' => $year,
                    'month' => 4,
                    'day' => 11,
                    'time' => '130000',
                    'time_label' => '13:00',
                    'preview_url' => 'a',
                    'variants' => ['sm' => ['url' => 'a']],
                ],
            ],
            'years' => [$year],
            'months' => [$year => [4]],
            'days' => [$year => [4 => [11]]],
        ];
        $fsEntries = [
            [
                'timestamp' => (new DateTimeImmutable('2025-04-11 14:00:00', $tz))->getTimestamp(),
                'year' => $year,
                'month' => 4,
                'day' => 11,
                'time' => '140000',
                'time_label' => '14:00',
                'preview_url' => 'b',
                'variants' => ['sm' => ['url' => 'b']],
            ],
        ];

        $merged = (new TimelineMapper())->merge($baseTimeline, $fsEntries, $year);
        $selected = $browser->selectFromTimeline($merged, [
            'year' => $year,
            'month' => 4,
            'day' => 11,
            'time' => '1400',
        ]);

        $this->assertIsArray($merged);
        $this->assertCount(2, $merged['entries']);
        $this->assertNotNull($selected);
        $this->assertSame('140000', (string) $selected['time']);
    }

    public function testEntriesForDayMergesCurrentYearFilesystemSlotWhenDbLags(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();

            $day = new DateTimeImmutable(date('Y') . '-04-11 12:00:00', new DateTimeZone('UTC'));
            $older = $day->modify('-1 hour');
            $dayDir = $app->paths()->cachePath(sprintf('testcam/%s', $day->format('Y/m/d')));
            if (!is_dir($dayDir) && !mkdir($dayDir, 0775, true) && !is_dir($dayDir)) {
                $this->fail('Unable to create day dir: ' . $dayDir);
            }

            $olderRelative = sprintf('cache/testcam/%s/%s_la.jpg', $day->format('Y/m/d'), $older->format('Ymd_His'));
            $newerRelative = sprintf('cache/testcam/%s/%s_la.jpg', $day->format('Y/m/d'), $day->format('Ymd_His'));
            $this->writeSampleJpeg($app->paths()->cachePath(sprintf('testcam/%s_la.jpg', $older->format('Y/m/d/Ymd_His'))));
            $this->writeSampleJpeg($app->paths()->cachePath(sprintf('testcam/%s_la.jpg', $day->format('Y/m/d/Ymd_His'))));

            $store->recordCapture([
                'slot_timestamp' => $older->getTimestamp(),
                'captured_timestamp' => $older->getTimestamp(),
                'relative_path' => $olderRelative,
                'variants' => [
                    [
                        'variant' => 'la',
                        'relative_path' => $olderRelative,
                    ],
                ],
            ]);
            $store->close();

            $result = $app->archiveBrowser()->entriesForDay($camera, (int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'));
            $times = array_values(array_map(
                static fn(array $entry): string => (string) ($entry['time'] ?? ''),
                $result['entries'] ?? []
            ));

            $this->assertContains($older->format('His'), $times);
            $this->assertContains($day->format('His'), $times);
            $this->assertSame($day->format('His'), (string) (($result['latest']['time'] ?? null) ?? ''));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testTimelineSingleDayFallsBackToFilesystemEntriesWhenDatabaseUnavailable(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $day = new DateTimeImmutable(date('Y') . '-04-11 14:00:00', new DateTimeZone('UTC'));
            $dayDir = $app->paths()->cachePath(sprintf('testcam/%s', $day->format('Y/m/d')));
            $path = $dayDir . DIRECTORY_SEPARATOR . $day->format('Ymd_His') . '_la.jpg';
            $this->writeSampleJpeg($path);

            $start = $day->setTime(0, 0, 0)->getTimestamp();
            $end = $day->setTime(23, 59, 59)->getTimestamp();
            $timeline = $app->archiveBrowser()->timeline($camera, (int) $day->format('Y'), $start, $end);

            $this->assertNotEmpty($timeline['entries'] ?? []);
            $this->assertSame($day->format('His'), (string) (($timeline['latest']['time'] ?? null) ?? ''));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testListYearsPrefersFilesystemBackedYearsOverStaleDatabaseYears(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();

            foreach (['2012-03-02 17:05:00', '2025-12-19 12:00:00', '2026-04-11 14:00:00'] as $date) {
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
            $store->close();

            $cacheYearDir = $app->paths()->cachePath('testcam/2026');
            $archiveYearDir = $app->paths()->storagePath('archive/testcam/2025');
            mkdir($cacheYearDir, 0775, true);
            mkdir($archiveYearDir, 0775, true);

            $years = $app->archiveBrowser()->listYears($camera);

            $this->assertSame(['2026'], $years['current']);
            $this->assertSame(['2025'], $years['archive']);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testAvailableCalendarPrefersDatabaseMetaOverFilesystemFallback(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();

            foreach (['2023-01-05 12:00:00', '2023-02-07 12:00:00', '2023-03-09 12:00:00'] as $date) {
                $ts = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
                $store->recordCapture([
                    'slot_timestamp' => $ts,
                    'captured_timestamp' => $ts,
                    'relative_path' => 'archive/testcam/' . gmdate('Y/m/d/Hi', $ts) . '_la.jpg',
                    'variants' => [
                        [
                            'variant' => 'la',
                            'relative_path' => 'archive/testcam/' . gmdate('Y/m/d/Hi', $ts) . '_la.jpg',
                        ],
                    ],
                ]);
            }
            $store->close();

            mkdir($app->paths()->storagePath('archive/testcam/2023/02/17'), 0775, true);
            mkdir($app->paths()->storagePath('archive/testcam/2023/11/04'), 0775, true);

            $calendar = $app->archiveBrowser()->availableCalendar($camera, [2023]);

            $this->assertSame([1, 2, 3], array_map('intval', $calendar['months'][2023] ?? []));
            $this->assertSame([5], array_map('intval', $calendar['days'][2023][1] ?? []));
            $this->assertSame([7], array_map('intval', $calendar['days'][2023][2] ?? []));
            $this->assertSame([9], array_map('intval', $calendar['days'][2023][3] ?? []));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testAvailableCalendarUsesConfiguredTimezoneAtYearBoundary(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_timezone_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root, 'Europe/Berlin');
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $timezone = new DateTimeZone('Europe/Berlin');

            // 00:00 Uhr am Neujahrstag ist in UTC noch der 31. Dezember. Der
            // Monatskatalog muss trotzdem Januar und nicht Dezember melden.
            foreach (['2026-01-01 00:00:00', '2026-05-31 16:00:00'] as $localDate) {
                $timestamp = (new DateTimeImmutable($localDate, $timezone))->getTimestamp();
                $store->recordCapture([
                    'slot_timestamp' => $timestamp,
                    'captured_timestamp' => $timestamp,
                    'relative_path' => 'cache/testcam/' . str_replace(['-', ' ', ':'], '', $localDate) . '.jpg',
                    'variants' => [],
                ]);
            }
            $store->close();

            $calendar = $app->archiveBrowser()->availableCalendar($camera, [2026]);

            $this->assertSame([1, 5], array_map('intval', $calendar['months'][2026] ?? []));
            $this->assertSame([1], array_map('intval', $calendar['days'][2026][1] ?? []));
            $this->assertSame([31], array_map('intval', $calendar['days'][2026][5] ?? []));
            $this->assertArrayNotHasKey(12, $calendar['days'][2026] ?? []);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testTimelineSkipsCorruptZeroByteDbVariants(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();

            $validTs = (new DateTimeImmutable('2026-01-21 00:10:00', new DateTimeZone('UTC')))->getTimestamp();
            $corruptTs = (new DateTimeImmutable('2026-01-26 00:10:00', new DateTimeZone('UTC')))->getTimestamp();
            $this->writeSampleJpeg($app->paths()->cachePath('testcam/2026/01/21/20260121_001000_la.jpg'));

            $store->recordCapture([
                'slot_timestamp' => $validTs,
                'captured_timestamp' => $validTs,
                'relative_path' => 'cache/testcam/2026/01/21/20260121_001000.jpg',
                'variants' => [
                    [
                        'variant' => 'la',
                        'relative_path' => 'cache/testcam/2026/01/21/20260121_001000_la.jpg',
                        'size' => 123,
                    ],
                ],
            ]);
            $store->recordCapture([
                'slot_timestamp' => $corruptTs,
                'captured_timestamp' => $corruptTs,
                'relative_path' => 'cache/testcam/2026/01/26/20260126_001000.jpg',
                'variants' => [
                    [
                        'variant' => 'la',
                        'relative_path' => 'cache/testcam/2026/01/26/20260126_001000_la.jpg',
                        'size' => 0,
                    ],
                ],
            ]);
            $store->close();

            $tz = new DateTimeZone('UTC');
            $from = (new DateTimeImmutable('2026-01-01 00:00:00', $tz))->getTimestamp();
            $to = (new DateTimeImmutable('2026-01-31 23:59:59', $tz))->getTimestamp();
            $timeline = $app->archiveBrowser()->timeline($camera, 2026, $from, $to);
            $selected = $app->archiveBrowser()->selectFromTimeline($timeline, [
                'year' => 2026,
                'month' => 1,
            ]);

            $this->assertCount(1, $timeline['entries'] ?? []);
            $this->assertNotNull($selected);
            $this->assertSame('20260121_001000_la.jpg', basename((string) ($selected['relative_path'] ?? '')));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testTimelineDoesNotRestoreDuplicateOnlyDayFromFilesystem(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $tz = new DateTimeZone('UTC');
            $slot = (new DateTimeImmutable('2026-01-26 00:10:00', $tz))->getTimestamp();
            $relative = 'cache/testcam/2026/01/26/20260126_001000_la.jpg';
            $this->writeSampleJpeg($app->paths()->cachePath('testcam/2026/01/26/20260126_001000_la.jpg'));
            $store->recordCapture([
                'slot_timestamp' => $slot,
                'captured_timestamp' => $slot,
                'relative_path' => $relative,
                'is_duplicate' => true,
                'variants' => [[
                    'variant' => 'la',
                    'relative_path' => $relative,
                    'size' => filesize($app->paths()->cachePath('testcam/2026/01/26/20260126_001000_la.jpg')),
                ]],
            ]);
            $store->close();

            $from = (new DateTimeImmutable('2026-01-26 00:00:00', $tz))->getTimestamp();
            $to = (new DateTimeImmutable('2026-01-26 23:59:59', $tz))->getTimestamp();
            $timeline = $app->archiveBrowser()->timeline($camera, 2026, $from, $to);

            $this->assertSame([], $timeline['entries'] ?? null);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testTimelineFilesystemMergeExcludesDuplicateSlotsFromMixedDay(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $tz = new DateTimeZone('UTC');

            foreach ([
                ['2026-01-26 00:10:00', false],
                ['2026-01-26 00:20:00', true],
            ] as [$date, $duplicate]) {
                $slot = (new DateTimeImmutable($date, $tz))->getTimestamp();
                $stamp = (new DateTimeImmutable('@' . $slot))->setTimezone($tz)->format('Ymd_His');
                $cacheRelative = 'testcam/2026/01/26/' . $stamp . '_la.jpg';
                $relative = 'cache/' . $cacheRelative;
                $absolute = $app->paths()->cachePath($cacheRelative);
                $this->writeSampleJpeg($absolute);
                $store->recordCapture([
                    'slot_timestamp' => $slot,
                    'captured_timestamp' => $slot,
                    'relative_path' => $relative,
                    'is_duplicate' => $duplicate,
                    'variants' => [[
                        'variant' => 'la',
                        'relative_path' => $relative,
                        'size' => filesize($absolute),
                    ]],
                ]);
            }
            $store->close();

            $from = (new DateTimeImmutable('2026-01-26 00:00:00', $tz))->getTimestamp();
            $to = (new DateTimeImmutable('2026-01-26 23:59:59', $tz))->getTimestamp();
            $timeline = $app->archiveBrowser()->timeline($camera, 2026, $from, $to);
            $entries = $timeline['entries'] ?? [];

            $this->assertCount(1, $entries);
            $this->assertSame(
                (new DateTimeImmutable('2026-01-26 00:10:00', $tz))->getTimestamp(),
                (int) ($entries[0]['timestamp'] ?? 0)
            );
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testTimelineResolverUsesNearestDatabaseDayWithoutLoadingWholeYear(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $tz = new DateTimeZone('UTC');

            foreach (['2026-01-01 00:10:00', '2026-01-25 00:10:00'] as $date) {
                $slot = (new DateTimeImmutable($date, $tz))->getTimestamp();
                $stamp = (new DateTimeImmutable('@' . $slot))->setTimezone($tz)->format('Ymd_His');
                $cacheRelative = 'testcam/2026/01/' . substr($stamp, 6, 2) . '/' . $stamp . '_la.jpg';
                $relative = 'cache/' . $cacheRelative;
                $absolute = $app->paths()->cachePath($cacheRelative);
                $this->writeSampleJpeg($absolute);
                $store->recordCapture([
                    'slot_timestamp' => $slot,
                    'captured_timestamp' => $slot,
                    'relative_path' => $relative,
                    'variants' => [[
                        'variant' => 'la',
                        'relative_path' => $relative,
                        'size' => filesize($absolute),
                    ]],
                ]);
            }

            $duplicateSlot = (new DateTimeImmutable('2026-01-26 00:10:00', $tz))->getTimestamp();
            $store->recordCapture([
                'slot_timestamp' => $duplicateSlot,
                'captured_timestamp' => $duplicateSlot,
                'relative_path' => 'cache/testcam/2026/01/26/20260126_001000_la.jpg',
                'is_duplicate' => true,
                'variants' => [],
            ]);
            $store->close();

            $resolution = $app->cameraTimelineResolver()->resolve(
                $camera,
                [
                    'year' => 2026,
                    'month' => 1,
                    'day' => 26,
                    'time' => '001000',
                ],
                2026,
                1,
                26,
                false,
                null,
                ['current' => [2026], 'archive' => []]
            );

            $selected = $resolution['selected_snapshot'] ?? null;
            $entries = $resolution['timeline']['entries'] ?? [];
            $expected = (new DateTimeImmutable('2026-01-25 00:10:00', $tz))->getTimestamp();
            $this->assertIsArray($selected);
            $this->assertSame($expected, (int) ($selected['timestamp'] ?? 0));
            $this->assertCount(1, $entries);
            $this->assertSame($expected, (int) ($entries[0]['timestamp'] ?? 0));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testUnboundedTimelineAppliesSafeDatabaseLimitBeforeHydration(): void
    {
        $root = sys_get_temp_dir() . '/wcam_archive_browser_' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('Unable to create test root: ' . $root);
        }

        try {
            $app = $this->makeTempApp($root);
            $camera = $app->cameraOrFail('testcam');
            $store = new CameraDataStore($app, $camera);
            $store->ensureWritableSchema();
            $tz = new DateTimeZone('UTC');
            $first = (new DateTimeImmutable('2026-01-01 00:00:00', $tz))->getTimestamp();

            for ($index = 0; $index < 305; $index++) {
                $slot = $first + ($index * 600);
                $stamp = (new DateTimeImmutable('@' . $slot))->setTimezone($tz)->format('Y/m/d/Ymd_His');
                $relative = 'cache/testcam/' . $stamp . '_la.jpg';
                // Die Abfrage soll echte, öffentlich auslieferbare DB-Treffer
                // begrenzen. Ein positiver DB-Umfang allein darf seit dem
                // Archivpfad-Fallback keine nicht vorhandene Datei vortäuschen.
                $absolute = $app->resolveCameraStoragePath($camera, $relative);
                if ($absolute === null) {
                    $this->fail('Unable to resolve generated cache path: ' . $relative);
                }
                $this->writeSampleJpeg($absolute);
                $store->recordCapture([
                    'slot_timestamp' => $slot,
                    'captured_timestamp' => $slot,
                    'relative_path' => $relative,
                    'variants' => [[
                        'variant' => 'la',
                        'relative_path' => $relative,
                        'size' => 123,
                    ]],
                ]);
            }
            $store->close();

            $timeline = $app->archiveBrowser()->timeline($camera, 2026);
            $entries = $timeline['entries'] ?? [];

            $this->assertCount(100, $entries);
            $this->assertSame($first + (205 * 600), (int) ($entries[0]['timestamp'] ?? 0));
            $this->assertSame($first + (304 * 600), (int) ($entries[99]['timestamp'] ?? 0));

            $previousRangeLimit = getenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES');
            putenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES=100');
            try {
                $bounded = $app->archiveBrowser()->timeline(
                    $camera,
                    2026,
                    $first,
                    $first + (304 * 600)
                );
                $boundedEntries = $bounded['entries'] ?? [];
                $this->assertCount(100, $boundedEntries);
                $this->assertSame($first + (205 * 600), (int) ($boundedEntries[0]['timestamp'] ?? 0));
                $this->assertSame($first + (304 * 600), (int) ($boundedEntries[99]['timestamp'] ?? 0));
            } finally {
                if ($previousRangeLimit === false) {
                    putenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES');
                } else {
                    putenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES=' . $previousRangeLimit);
                }
            }
        } finally {
            $this->rrmdir($root);
        }
    }

    private function makeTempApp(string $root, string $timezone = 'UTC'): App
    {
        $configFile = $root . '/cameras.php';
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
                    'base_path' => $root . '/storage/testcam',
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
        file_put_contents($configFile, '<?php return ' . var_export($cameraConfig, true) . ';');

        return new App([
            'timezone' => $timezone,
            'camera_config_path' => $configFile,
            'storage_path' => $root . '/storage',
            'archive_path' => $root . '/storage/archive',
            'state_path' => $root . '/state',
            'log_path' => $root . '/log',
            'info_path' => $root . '/info',
            'cache_path' => $root . '/cache',
            'base_url' => '',
        ]);
    }

    private function writeSampleJpeg(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Unable to create fixture dir: ' . $dir);
        }
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwC4A//Z', true);
        if ($bytes === false) {
            $this->fail('Unable to decode sample JPEG fixture.');
        }
        file_put_contents($path, $bytes);
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
