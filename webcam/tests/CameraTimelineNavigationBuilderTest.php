<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\View\CameraTimelineNavigationBuilder;

/**
 * Prüft das aus camera.php ausgelagerte Navigations-View-Model ohne Browser.
 * Die Test-Timeline enthält bereits Tagesdaten; deshalb muss der Builder weder
 * Bilder anlegen noch einen vollständigen Archivjahrgang scannen.
 */
final class CameraTimelineNavigationBuilderTest extends TestCase
{
  private string $root;
  private App $app;
  private Camera $camera;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-timeline-navigation-' . bin2hex(random_bytes(6));
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

  public function testEmptySelectionReturnsEmptyNavigationWithoutChangingTimeline(): void
  {
    $timeline = ['entries' => [], 'months' => [], 'days' => []];
    $result = (new CameraTimelineNavigationBuilder($this->app))->build(
      $this->camera,
      null,
      $timeline,
      null,
      [],
      ['year' => null, 'month' => null, 'day' => null, 'time' => null, 'slug' => 'testcam'],
      static fn(array $entry): string => '/unused',
      static fn(int $year, ?int $month, ?int $day, mixed $time, string $slug): string => '/unused'
    );

    self::assertSame($timeline, $result['timeline']);
    self::assertSame(['has_data' => false], $result['navigation']);
    self::assertSame([], $result['time_picker_options']);
    self::assertSame([], $result['hour_picker_options']);
    self::assertSame([], $result['year_picker_options']);
  }

  public function testBuildCreatesTimeYearAndMonthNavigationFromLoadedTimeline(): void
  {
    $entries = [
      $this->entry('2026-08-15 09:50:00'),
      $this->entry('2026-08-15 10:00:00'),
      $this->entry('2026-08-15 10:10:00'),
    ];
    $selected = $entries[1];
    $timeline = [
      'entries' => $entries,
      'months' => [2026 => [7, 8, 9]],
      'days' => [2026 => [7 => [31], 8 => [15], 9 => [14]]],
    ];
    $profileMarks = [];
    $timelineLink = static fn(array $entry): string => '/at/' . (string) $entry['time'];
    $yearLink = static function (
      int $year,
      ?int $month,
      ?int $day,
      mixed $time,
      string $slug
    ): string {
      return sprintf(
        '/%s/%04d/%s/%s/%s',
        $slug,
        $year,
        $month === null ? '-' : (string) $month,
        $day === null ? '-' : (string) $day,
        $time === null ? '-' : (string) $time
      );
    };

    $result = (new CameraTimelineNavigationBuilder($this->app))->build(
      $this->camera,
      $selected,
      $timeline,
      null,
      [2025, 2026, 2027],
      ['year' => null, 'month' => null, 'day' => null, 'time' => null, 'slug' => 'testcam'],
      $timelineLink,
      $yearLink,
      static function (string $mark) use (&$profileMarks): void {
        $profileMarks[] = $mark;
      }
    );

    self::assertTrue($result['navigation']['has_data']);
    self::assertSame('/at/095000', $result['navigation']['time']['prev_url']);
    self::assertSame('/at/101000', $result['navigation']['time']['next_url']);
    self::assertSame($entries[0]['timestamp'], $result['navigation']['time']['prev_timestamp']);
    self::assertSame($entries[2]['timestamp'], $result['navigation']['time']['next_timestamp']);
    self::assertSame('/testcam/2025/8/15/100000', $result['navigation']['year']['prev_url']);
    self::assertSame('/testcam/2027/8/15/100000', $result['navigation']['year']['next_url']);
    self::assertSame('/testcam/2026/7/31/100000', $result['navigation']['month']['prev_url']);
    self::assertSame('/testcam/2026/9/14/100000', $result['navigation']['month']['next_url']);

    self::assertSame(['09:50', '10:00', '10:10'], array_column($result['time_picker_options'], 'label'));
    self::assertSame(['past', 'current', 'future'], array_column($result['time_picker_options'], 'kind'));
    self::assertSame([false, true, false], array_column($result['time_picker_options'], 'selected'));
    self::assertSame(['09 Uhr', '10 Uhr'], array_column($result['hour_picker_options'], 'label'));
    self::assertSame([false, true], array_column($result['hour_picker_options'], 'selected'));
    self::assertSame(['2025', '2026', '2027'], array_column($result['year_picker_options'], 'label'));
    self::assertSame([false, true, false], array_column($result['year_picker_options'], 'selected'));
    self::assertSame(['entriesForDay reused', 'nav prep done'], $profileMarks);
  }

  public function testHourPickerContainsEachAvailableHourExactlyOnce(): void
  {
    $entries = [
      $this->entry('2026-05-31 16:00:00'),
      $this->entry('2026-05-31 16:10:00'),
      $this->entry('2026-05-31 17:00:00'),
      $this->entry('2026-05-31 18:20:00'),
      $this->entry('2026-05-31 19:40:00'),
      $this->entry('2026-05-31 20:00:00'),
    ];
    $selected = $entries[3];
    $result = (new CameraTimelineNavigationBuilder($this->app))->build(
      $this->camera,
      $selected,
      [
        'entries' => $entries,
        'months' => [2026 => [5]],
        'days' => [2026 => [5 => [31]]],
      ],
      null,
      [2026],
      ['year' => 2026, 'month' => 5, 'day' => 31, 'time' => '182000', 'slug' => 'testcam'],
      static fn(array $entry): string => '/at/' . (string) $entry['time'],
      static fn(int $year, ?int $month, ?int $day, mixed $time, string $slug): string => '/unused'
    );

    self::assertSame(
      ['16 Uhr', '17 Uhr', '18 Uhr', '19 Uhr', '20 Uhr'],
      array_column($result['hour_picker_options'], 'label')
    );
    self::assertSame(
      ['/at/160000', '/at/170000', '/at/182000', '/at/194000', '/at/200000'],
      array_column($result['hour_picker_options'], 'url')
    );
    self::assertSame(
      [false, false, true, false, false],
      array_column($result['hour_picker_options'], 'selected')
    );
  }

  public static function dayNavigationTimes(): array
  {
    return [
      'same time' => ['12:00:00', '12:00:00', '120000', '120000'],
      'nearest earlier and later' => ['11:40:00', '12:10:00', '114000', '121000'],
      'nearest later and earlier' => ['12:10:00', '11:40:00', '121000', '114000'],
    ];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('dayNavigationTimes')]
  public function testDayNavigationKeepsTimeWhileImageArrowsUseDayEdges(
    string $previousTime, string $nextTime, string $expectedPrevious, string $expectedNext
  ): void
  {
    // Both neighboring days contain competing edge and near-noon captures.
    // The next day crosses a month boundary.
    $store = new CameraDataStore($this->app, $this->camera);
    $store->ensureWritableSchema();
    foreach ([
      '2026-05-30 ' . $previousTime,
      '2026-05-30 23:50:00',
      '2026-05-31 12:00:00',
      '2026-06-01 00:10:00',
      '2026-06-01 ' . $nextTime,
    ] as $localDate) {
      $entry = $this->entry($localDate);
      $relative = sprintf('cache/testcam/%s_la.jpg', $entry['timestamp']);
      $absolute = $this->app->resolveCameraStoragePath($this->camera, $relative);
      if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0775, true);
      file_put_contents($absolute, 'fixture-image');
      $store->recordCapture([
        'slot_timestamp' => $entry['timestamp'],
        'captured_timestamp' => $entry['timestamp'],
        'relative_path' => $relative,
        'variants' => [['variant' => 'la', 'relative_path' => $relative, 'size' => 13]],
      ]);
    }
    $store->close();

    $selected = $this->entry('2026-05-31 12:00:00');
    $result = (new CameraTimelineNavigationBuilder($this->app))->build(
      $this->camera,
      $selected,
      [
        'entries' => [$selected],
        'months' => [2026 => [5, 6]],
        'days' => [2026 => [5 => [30, 31], 6 => [1]]],
      ],
      null,
      [2026],
      ['year' => 2026, 'month' => 5, 'day' => 31, 'time' => '120000', 'slug' => 'testcam'],
      static fn(array $entry): string => '/at/' . (string) $entry['time'],
      static fn(int $year, ?int $month, ?int $day, mixed $time, string $slug): string => '/unused'
    );

    self::assertSame('/at/' . $expectedPrevious, $result['navigation']['day']['prev_url']);
    self::assertSame('/at/' . $expectedNext, $result['navigation']['day']['next_url']);
    self::assertSame('/at/235000', $result['navigation']['time']['prev_url']);
    self::assertSame('/at/001000', $result['navigation']['time']['next_url']);
  }

  public static function midnightBoundaries(): array
  {
    return [
      'Stromberg day change' => ['2026-10-04 23:50:00', '2026-10-05 00:00:00'],
      'month change' => ['2026-09-30 23:50:00', '2026-10-01 00:00:00'],
      'year change' => ['2026-12-31 23:50:00', '2027-01-01 00:00:00'],
      'missing day and midnight frame' => ['2026-10-04 23:50:00', '2026-10-06 00:10:00'],
    ];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('midnightBoundaries')]
  public function testTimeArrowsAndPickerCrossDayBoundariesBothWays(string $before, string $after): void
  {
    $entries = [$this->entry($before), $this->entry($after)];
    $store = new CameraDataStore($this->app, $this->camera);
    $store->ensureWritableSchema();
    foreach ($entries as $entry) {
      $store->recordCapture([
        'slot_timestamp' => $entry['timestamp'], 'captured_timestamp' => $entry['timestamp'],
        'relative_path' => 'cache/testcam/' . $entry['timestamp'] . '.jpg', 'variants' => [],
      ]);
    }
    $store->close();
    foreach ($entries as $i => $selected) {
      $result = (new CameraTimelineNavigationBuilder($this->app))->build(
        $this->camera, $selected, ['entries' => [$selected]], null,
        array_values(array_unique(array_column($entries, 'year'))),
        ['year' => $selected['year'], 'month' => $selected['month'], 'day' => $selected['day'],
          'time' => $selected['time'], 'slug' => 'testcam'],
        static fn(array $entry): string => '/at/' . $entry['timestamp'],
        static fn(int $year, ?int $month, ?int $day, mixed $time, string $slug): string => '/unused'
      );
      $direction = $i === 0 ? 'next' : 'prev';
      $other = $entries[1 - $i];
      self::assertSame('/at/' . $other['timestamp'], $result['navigation']['time'][$direction . '_url']);
      self::assertSame($other['timestamp'], $result['navigation']['time'][$direction . '_timestamp']);
      self::assertNull($result['navigation']['time'][($i === 0 ? 'prev' : 'next') . '_url']);
      self::assertSame(array_map(static fn(array $entry): string => '/at/' . $entry['timestamp'], $entries),
        array_column($result['time_picker_options'], 'url'));
      self::assertSame($i === 0 ? [true, false] : [false, true], array_column($result['time_picker_options'], 'selected'));
      self::assertSame($other['time_label'] . sprintf(' (%02d.%02d.)', $other['day'], $other['month']),
        $result['time_picker_options'][1 - $i]['label']);
    }
  }

  /** @return array<string,int|string> */
  private function entry(string $localDate): array
  {
    $date = new DateTimeImmutable($localDate, new DateTimeZone('Europe/Berlin'));
    return [
      'timestamp' => $date->getTimestamp(),
      'year' => (int) $date->format('Y'),
      'month' => (int) $date->format('n'),
      'day' => (int) $date->format('j'),
      'time' => $date->format('His'),
      'time_label' => $date->format('H:i'),
    ];
  }
}
