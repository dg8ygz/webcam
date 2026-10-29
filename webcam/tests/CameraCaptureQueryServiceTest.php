<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Admin\CameraCaptureEditor;
use Webcam\Service\Admin\CameraCaptureQueryService;

/**
 * Integrationstests für die ausgelagerte Capture-Suche des Admin-Editors.
 *
 * Die Tests verwenden eine echte, isolierte Kamera-SQLite-DB. Damit prüfen sie
 * nicht nur Parameterarithmetik, sondern auch Reihenfolge, Metadatenhydrierung
 * und die genaue Form der Antworten, welche admin/api.php an den Browser sendet.
 */
final class CameraCaptureQueryServiceTest extends TestCase
{
  private string $root;
  private App $app;
  private CameraDataStore $store;
  private CameraCaptureQueryService $queryService;
  private int $middleSlot;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/capture-query-' . bin2hex(random_bytes(5));
    $this->app = $this->createApp($this->root);
    $camera = $this->app->cameraOrFail('testcam');
    $this->store = new CameraDataStore($this->app, $camera);
    $this->store->ensureWritableSchema();

    $timezone = new DateTimeZone('UTC');
    $editor = new CameraCaptureEditor($this->app);
    $this->queryService = new CameraCaptureQueryService($editor, $timezone);
    $this->middleSlot = (new DateTimeImmutable('2026-08-30 12:10:00', $timezone))
      ->getTimestamp();

    $this->recordCapture($this->middleSlot - 600, 'keep-old.jpg', '100');
    $this->recordCapture($this->middleSlot, 'skip-middle.jpg', '200');
    $this->recordCapture($this->middleSlot + 600, 'keep-new.jpg', '300');
  }

  public function testExactSlotReturnsCompleteDetailAndMissingSlotReturnsNull(): void
  {
    $result = $this->queryService->query($this->store, [
      'slot_ts' => (string) $this->middleSlot,
    ]);

    self::assertIsArray($result);
    self::assertSame($this->middleSlot, $result['capture']['slot_ts'] ?? null);
    self::assertSame('skip-middle.jpg', $result['capture']['relative_path'] ?? null);
    self::assertSame('la', $result['variants'][0]['variant'] ?? null);
    self::assertSame('ISO', $result['exif'][0]['tag'] ?? null);
    self::assertSame('200', $result['exif'][0]['value'] ?? null);
    self::assertSame('Temperatur: 18 °C', $result['weather'][0]['value'] ?? null);
    self::assertArrayHasKey('logo', $result);

    self::assertNull($this->queryService->query($this->store, [
      'slot_ts' => $this->middleSlot + 99,
    ]));
  }

  public function testCalendarRangeFiltersBeforeApplyingOffsetAndLimit(): void
  {
    $result = $this->queryService->query($this->store, [
      'year' => '2026',
      'month' => '8',
      'day' => '30',
      'q' => 'keep-',
      'offset' => '1',
      'limit' => '1',
    ]);

    self::assertIsArray($result);
    self::assertSame(1, $result['count'] ?? null);
    self::assertSame(
      $this->middleSlot + 600,
      $result['captures'][0]['capture']['slot_ts'] ?? null
    );
    self::assertArrayNotHasKey('logo', $result['captures'][0] ?? []);
  }

  public function testLatestReturnsNewestDetailedCapture(): void
  {
    $result = $this->queryService->query(
      $this->store,
      ['latest' => '1'],
      $this->middleSlot + 3600
    );

    self::assertIsArray($result);
    self::assertSame($this->middleSlot + 600, $result['capture']['slot_ts'] ?? null);
    self::assertSame('300', $result['exif'][0]['value'] ?? null);
    self::assertArrayHasKey('logo', $result);
  }

  public function testLatestWithoutMatchingRowsReturnsEmptyCollection(): void
  {
    $result = $this->queryService->query(
      $this->store,
      ['latest' => '1', 'q' => 'does-not-exist'],
      $this->middleSlot + 3600
    );

    self::assertSame(['captures' => [], 'count' => 0], $result);
  }

  public function testTimeSelectorChoosesOlderCaptureWhenDistanceIsEqual(): void
  {
    $result = $this->queryService->query($this->store, [
      'year' => 2026,
      'month' => 8,
      'day' => 30,
      'time' => '12:15',
    ]);

    self::assertIsArray($result);
    self::assertSame($this->middleSlot, $result['capture']['slot_ts'] ?? null);
    self::assertSame('skip-middle.jpg', $result['capture']['relative_path'] ?? null);
  }

  public function testMissingRangeIsRejectedBeforeDatabaseQuery(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage(
      'Provide slot_ts or a valid date range (from_ts/to_ts or year/month[/day])'
    );

    $this->queryService->query($this->store, ['limit' => 10]);
  }

  private function recordCapture(int $slotTimestamp, string $filename, string $iso): void
  {
    $this->store->recordCapture([
      'slot_timestamp' => $slotTimestamp,
      'captured_timestamp' => $slotTimestamp + 2,
      'relative_path' => $filename,
      'luminance' => 18.5,
      'hash' => hash('sha256', $filename),
      'variants' => [[
        'variant' => 'la',
        'relative_path' => preg_replace('/\.jpg$/', '_la.jpg', $filename),
        'width' => 1600,
        'height' => 900,
        'size' => 100,
      ]],
      'exif' => ['ISO' => $iso],
      'weather_lines' => ['Temperatur: 18 °C'],
    ]);
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
