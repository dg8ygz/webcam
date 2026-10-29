<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\View\CameraTimelineNavigationBuilder;
use Webcam\Service\View\CameraTimelineResolver;

/**
 * Isolierte Monatsauswahl: nur den gewählten Tag hydrieren; Kalender und
 * explizite Bildauswahl bleiben vollständig. Alle Dateien/DBs sind temporär.
 */
final class CameraTimelineResolverTest extends TestCase
{
  private string $root;
  private App $app;
  private Camera $camera;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-timeline-resolver-' . bin2hex(random_bytes(6));
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

  private function capture(string $date, bool $exists = true, bool $duplicate = false): int
  {
    $dt = new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin'));
    $relative = 'cache/testcam/' . $dt->format('Y/m/d/Ymd_His') . '_la.jpg';
    $absolute = $this->root . '/' . $relative;
    if ($exists) {
      if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0775, true);
      file_put_contents($absolute, 'test image');
    }
    $store = new CameraDataStore($this->app, $this->camera);
    $store->ensureWritableSchema();
    $store->recordCapture([
      'slot_timestamp' => $dt->getTimestamp(), 'captured_timestamp' => $dt->getTimestamp(),
      'relative_path' => $relative,
      'variants' => [['variant' => 'la', 'relative_path' => $relative, 'size' => 10]],
    ]);
    if ($duplicate) {
      $statement = $store->pdo()->prepare('UPDATE captures SET is_duplicate = 1 WHERE slot_ts = ?');
      $statement->execute([$dt->getTimestamp()]);
    }
    $store->close();
    return $dt->getTimestamp();
  }

  private function resolve(array $criteria = []): array
  {
    return (new CameraTimelineResolver($this->app))->resolve(
      $this->camera, $criteria + ['year' => 2026, 'month' => 9],
      2026, 9, $criteria['day'] ?? null, false, null,
      ['current' => ['2026'], 'archive' => []]
    );
  }

  public function testMonthLoadsOnlyLatestDayAndKeepsCalendarNavigation(): void
  {
    $this->capture('2026-09-01 12:00:00');
    $previous = $this->capture('2026-09-29 23:50:00');
    $first = $this->capture('2026-09-30 10:00:00');
    $latest = $this->capture('2026-09-30 12:00:00');
    $this->capture('2026-09-30 13:00:00', true, true);
    $this->capture('2026-10-01 00:10:00');
    $result = $this->resolve();
    self::assertSame($latest, $result['selected_snapshot']['timestamp']);
    self::assertSame([$first, $latest], array_column($result['timeline']['entries'], 'timestamp'));
    $navigation = (new CameraTimelineNavigationBuilder($this->app))->build(
      $this->camera, $result['selected_snapshot'], $result['timeline'], null, [2026],
      ['year' => 2026, 'month' => 9, 'day' => null, 'time' => null, 'slug' => 'testcam'],
      static fn(array $entry): string => '/at/' . $entry['timestamp'],
      static fn(int $year, ?int $month, ?int $day, mixed $time, string $slug): string => '/unused'
    );
    self::assertSame([1, 29, 30], $navigation['timeline']['days'][2026][9]);
    self::assertSame('/at/' . $previous, $navigation['navigation']['day']['prev_url']);
  }

  public function testExplicitTimestampDoesNotJumpToLatestDay(): void
  {
    $first = $this->capture('2026-09-01 12:00:00');
    $this->capture('2026-09-30 12:00:00');
    self::assertSame($first, $this->resolve(['timestamp' => $first])['selected_snapshot']['timestamp']);
    self::assertSame($first, $this->resolve(['day' => 1, 'time' => '120000'])['selected_snapshot']['timestamp']);
  }

  public function testTimelapseSamplesEntireRangeBeyondTimelineCap(): void
  {
    $timestamps = [];
    for ($day = 1; $day <= 10; $day++) {
      $timestamps[] = $this->capture(sprintf('2026-09-%02d 12:00:00', $day));
    }
    $previous = getenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES');
    putenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES=5');
    try {
      $entries = $this->app->archiveBrowser()->timelapseEntries($this->camera, $timestamps[0], $timestamps[9], 5);
      self::assertCount(5, $entries);
      self::assertSame($timestamps[0], $entries[0]['timestamp']);
      self::assertSame($timestamps[9], $entries[4]['timestamp']);
      self::assertCount(5, array_unique(array_column($entries, 'timestamp')));
      foreach ($entries as $entry) { self::assertNotEmpty($entry['variants']); }
    } finally {
      putenv($previous === false ? 'WEBCAM_TIMELINE_RANGE_MAX_ENTRIES' : 'WEBCAM_TIMELINE_RANGE_MAX_ENTRIES=' . $previous);
    }
  }

  public function testTimelapseDatabaseSamplingBoundsLargeRangesAndSkipsDuplicates(): void
  {
    $first = $this->capture('2026-01-01 00:00:00');
    $store = new CameraDataStore($this->app, $this->camera);
    $store->pdo()->exec('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<21000)
      INSERT INTO captures(slot_ts,captured_ts,relative_path,created_at,is_duplicate)
      SELECT ' . $first . '+x*600,' . $first . '+x*600,\'fixture/\'||x||\'.jpg\',' . $first . ',0 FROM n');
    $last = $first + 21000 * 600;
    $rows = $store->sampledCapturesForRange($first, $last, 600);
    self::assertCount(600, $rows);
    self::assertSame($first, (int) $rows[0]['slot_ts']);
    self::assertSame($last, (int) $rows[599]['slot_ts']);
    self::assertArrayNotHasKey('exif_rows', $rows[0]);
    self::assertArrayNotHasKey('weather_rows', $rows[0]);
    self::assertNotEmpty($rows[0]['variants_rows']);
    $store->pdo()->exec('UPDATE captures SET is_duplicate=1 WHERE slot_ts=' . $last);
    $rows = $store->sampledCapturesForRange($first, $last, 5);
    self::assertCount(5, $rows);
    self::assertSame($last - 600, (int) $rows[4]['slot_ts']);
    self::assertCount(1, $store->sampledCapturesForRange($first, $first, 5));
    self::assertSame([], $store->sampledCapturesForRange($last, $last, 5));
    $store->close();
  }

  public function testMissingNewestFileRetainsExistingFallbackToUsableImage(): void
  {
    $valid = $this->capture('2026-09-29 12:00:00');
    $this->capture('2026-09-30 12:00:00', false);
    self::assertSame($valid, $this->resolve()['selected_snapshot']['timestamp']);
  }
}
