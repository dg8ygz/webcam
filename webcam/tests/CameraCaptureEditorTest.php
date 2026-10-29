<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Admin\CameraCaptureEditor;

/**
 * Integrationstests für die ausgelagerte SQLite-Arbeit des Capture-Editors.
 *
 * Eine isolierte Kamera-DB beweist, dass Hauptzeile, Varianten, EXIF und Wetter
 * gemeinsam geändert und anschließend im API-Format wieder gelesen werden.
 */
final class CameraCaptureEditorTest extends TestCase
{
  private string $root;
  private App $app;
  private CameraDataStore $store;
  private CameraCaptureEditor $editor;
  private int $slotTs;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/capture-editor-' . bin2hex(random_bytes(5));
    $this->app = $this->createApp($this->root);
    $camera = $this->app->cameraOrFail('testcam');
    $this->store = new CameraDataStore($this->app, $camera);
    $this->store->ensureWritableSchema();
    $this->editor = new CameraCaptureEditor($this->app);
    $this->slotTs = (new DateTimeImmutable('2026-08-30 12:10:00', new DateTimeZone('UTC')))
      ->getTimestamp();

    $this->store->recordCapture([
      'slot_timestamp' => $this->slotTs,
      'captured_timestamp' => $this->slotTs + 3,
      'relative_path' => 'cache/testcam/2026/08/30/original.jpg',
      'luminance' => 20.5,
      'hash' => str_repeat('a', 64),
      'variants' => [[
        'variant' => 'la',
        'relative_path' => 'cache/testcam/2026/08/30/original_la.jpg',
        'width' => 1600,
        'height' => 900,
        'size' => 100,
      ]],
      'exif' => ['ISO' => '100'],
      'weather_lines' => ['Temperatur: 18 °C'],
    ]);
  }

  public function testLoadsDetailAndSerializesCaptureDates(): void
  {
    $detail = $this->editor->detail($this->store, $this->slotTs);
    self::assertIsArray($detail);
    self::assertSame('la', $detail['variants'][0]['variant'] ?? null);
    self::assertSame('ISO', $detail['exif'][0]['tag'] ?? null);
    self::assertSame('Temperatur: 18 °C', $detail['weather'][0]['value'] ?? null);

    $serialized = $this->editor->serializeCapture(
      $detail['capture'],
      new DateTimeZone('Europe/Berlin')
    );
    self::assertSame($this->slotTs, $serialized['slot_ts']);
    self::assertSame('2026-08-30T14:10:00+02:00', $serialized['datetime']);
    self::assertSame('2026-08-30T14:10:03+02:00', $serialized['captured_at']);
  }

  public function testUpdateReplacesOnlyExplicitlySentListsInOneTransaction(): void
  {
    $updated = $this->editor->update($this->store, $this->slotTs, [
      'likes_count' => 7,
      'relative_path' => 'cache/testcam/2026/08/30/updated.jpg',
      'variants' => [[
        'variant' => 'sm',
        'relative_path' => 'cache/testcam/2026/08/30/updated_sm.jpg',
        'width' => 400,
        'height' => 225,
        'size_bytes' => 50,
        'mtime' => $this->slotTs + 5,
      ]],
      'exif' => [[
        'tag' => 'ISO',
        'value' => '200',
        'sort_order' => 0,
      ]],
      // weather fehlt absichtlich und muss deshalb unverändert bleiben.
    ]);

    self::assertSame(7, (int) ($updated['capture']['likes_count'] ?? 0));
    self::assertSame(
      'cache/testcam/2026/08/30/updated.jpg',
      $updated['capture']['relative_path'] ?? null
    );
    self::assertCount(1, $updated['variants']);
    self::assertSame('sm', $updated['variants'][0]['variant'] ?? null);
    self::assertSame('200', $updated['exif'][0]['value'] ?? null);
    self::assertSame('Temperatur: 18 °C', $updated['weather'][0]['value'] ?? null);
  }

  public function testPreviewReturnsNewestFirstAndCompactMetadata(): void
  {
    $newer = $this->slotTs + 600;
    $this->store->recordCapture([
      'slot_timestamp' => $newer,
      'captured_timestamp' => $newer,
      'relative_path' => 'cache/testcam/2026/08/30/newer.jpg',
      'variants' => [],
      'exif' => ['Exposure Time' => '1/100'],
      'weather_lines' => ['Luftdruck: 1013 hPa'],
    ]);
    $this->store->close();

    $preview = $this->editor->preview('testcam', 2);
    self::assertCount(2, $preview);
    self::assertSame($newer, $preview[0]['slot_ts']);
    self::assertSame('30.08.2026 12:20', $preview[0]['datetime']);
    self::assertSame('Exposure Time', $preview[0]['exif'][0]['tag'] ?? null);
    self::assertSame('Luftdruck: 1013 hPa', $preview[0]['weather']);
  }

  public function testUpdateRejectsUnknownCaptureWithoutLeavingTransactionOpen(): void
  {
    try {
      $this->editor->update($this->store, $this->slotTs + 9999, ['likes_count' => 1]);
      self::fail('Unknown capture should fail');
    } catch (RuntimeException $exception) {
      self::assertSame('Capture not found', $exception->getMessage());
    }

    // Ein anschließender gültiger Schreibvorgang beweist, dass der Fehlerpfad
    // Transaktion und Schreiblock zuverlässig freigegeben hat.
    $updated = $this->editor->update($this->store, $this->slotTs, ['likes_count' => 1]);
    self::assertSame(1, (int) ($updated['capture']['likes_count'] ?? 0));
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
