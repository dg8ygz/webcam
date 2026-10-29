<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\View\CameraSnapshotPresentationBuilder;

/**
 * Sichert das vollständige Präsentationsmodell eines ausgewählten Bildes.
 *
 * Die Tests arbeiten ohne HTTP und Template. Dadurch zeigen Fehler direkt, ob
 * EXIF/Wetter, Overlay-Kontrast, Like-/Logo-Abgleich oder Best-Of-Zustand beim
 * Aufbereiten falsch wurden, statt erst als schwer lesbarer HTML-Unterschied
 * in der großen Kameraseite sichtbar zu werden.
 */
final class CameraSnapshotPresentationBuilderTest extends TestCase
{
  private string $root;
  private App $app;
  private Camera $camera;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-snapshot-presentation-' . bin2hex(random_bytes(6));
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
      'labels' => [[
        'text' => 'Testmarke',
        'x' => 25,
        'y' => 30,
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-12-31',
      ]],
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

  public function testLocalSensorCaptionIsCompactWithoutChangingOtherWeather(): void
  {
    $metadata = new \Webcam\Service\View\CameraWeatherMetadata($this->app);
    $raw = "Temp: 23.6 C\nHumidity: 59.6 %\nSource: local DHT22\nMeasured at: 2026-09-11T20:49:56+00:00";
    self::assertSame('Temp: 23,6 °C · Hum: 59,6 %', $metadata->normalizeText($raw));
    self::assertSame("Temp: 23.6 C\nHumidity: 59.6 %", $metadata->normalizeText("Temp: 23.6 C\nHumidity: 59.6 %"));
  }

  public function testBuildsExifWeatherOverlayLabelsAndBestOfState(): void
  {
    $timestamp = (new DateTimeImmutable('2026-08-15 12:00:00', new DateTimeZone('Europe/Berlin')))
      ->getTimestamp();
    $filename = '20260815_120000_full.jpg';
    $snapshot = [
      'timestamp' => $timestamp,
      'slot_ts' => $timestamp,
      'year' => 2026,
      'month' => 8,
      'day' => 15,
      'relative_path' => 'cache/testcam/2026/08/15/' . $filename,
      'likes_count' => 2,
      'exif_text' => implode("\n", [
        'Exposure Time|1/100 sec',
        'FNumber|2.8',
        'ISO|200',
        'Luminance|60',
        'Likes: 2',
      ]),
      'weather_text' => " Temperatur:   12 °C \n Wind:  3 km/h ",
    ];

    $result = (new CameraSnapshotPresentationBuilder($this->app))->build(
      $this->camera,
      $snapshot,
      [],
      ['last_capture' => new DateTimeImmutable('@' . $timestamp)],
      [['filename' => $filename]],
      false,
      'history'
    );

    self::assertStringEndsWith('1/100 sec · f/2.8 · ISO 200 · 2 Likes', (string) $result['exif_data']);
    self::assertSame("Temperatur: 12 °C\nWind: 3 km/h", $result['weather_data']);
    self::assertSame([
      "15.08.2026 12:00 Uhr Temperatur: 12 °C\nWind: 3 km/h",
      '1/100 sec · 2.8 · ISO 200',
      '❤ 2 Likes',
    ], $result['overlay']['parts']);
    self::assertSame('dark', $result['overlay']['tone']);
    self::assertSame(60.0, $result['overlay']['luminance']);
    self::assertSame('dark', $result['logo_tone']);
    self::assertFalse($result['frontend_overlay_suppressed']);
    self::assertSame('Testmarke', $result['camera_labels'][0]['text']);
    self::assertSame([
      'relative_path' => $snapshot['relative_path'],
      'is_best_of' => true,
    ], $result['best_of_state']);
    self::assertTrue($result['weather_day_available']);
    self::assertSame($result['weather_data'], $result['modal_weather_text']);
    self::assertSame($result['exif_data'], $result['modal_exif_text']);
  }

  public function testUsesLegacyExifSidecarAndLiveWeatherFallback(): void
  {
    $timestamp = (new DateTimeImmutable('2026-08-15 12:10:00', new DateTimeZone('Europe/Berlin')))
      ->getTimestamp();
    $exifPath = $this->root . '/cache/legacy-exif.txt';
    file_put_contents($exifPath, "Exposure Time: 1/50 sec\nISO: 100\n");

    $result = (new CameraSnapshotPresentationBuilder($this->app))->build(
      $this->camera,
      [
        'timestamp' => $timestamp,
        'year' => 2026,
        'month' => 8,
        'day' => 15,
        'relative_path' => 'cache/testcam/2026/08/15/20260815_121000_full.jpg',
        'exif_path' => $exifPath,
      ],
      ['last_weather_data' => " Temperatur:  18 °C "],
      ['last_capture' => new DateTimeImmutable('@' . $timestamp)],
      [],
      true,
      'live'
    );

    self::assertStringContainsString('Exposure Time: 1/50 sec', (string) $result['exif_data']);
    self::assertStringEndsWith('1/50 sec · ISO 100', (string) $result['exif_data']);
    self::assertSame('Temperatur: 18 °C', $result['weather_data']);
    self::assertSame('Temperatur: 18 °C', $result['modal_weather_text']);
    self::assertFalse($result['frontend_overlay_suppressed']);
  }

  public function testRefreshesLikesAndLogoStatusFromReadOnlyCameraDatabase(): void
  {
    $timestamp = (new DateTimeImmutable('2026-08-15 12:20:00', new DateTimeZone('Europe/Berlin')))
      ->getTimestamp();
    $cameraDbDirectory = $this->root . '/camera-db/testcam';
    mkdir($cameraDbDirectory, 0775, true);
    $pdo = new PDO('sqlite:' . $cameraDbDirectory . '/db.sqlite');
    $pdo->exec(
      'CREATE TABLE captures (
        id INTEGER PRIMARY KEY,
        slot_ts INTEGER NOT NULL,
        relative_path TEXT NOT NULL,
        likes_count INTEGER NOT NULL DEFAULT 0,
        is_duplicate INTEGER NOT NULL DEFAULT 0
      );
      CREATE TABLE capture_logo (
        capture_id INTEGER PRIMARY KEY,
        detected INTEGER NOT NULL,
        score REAL
      );'
    );
    $insert = $pdo->prepare(
      'INSERT INTO captures (id, slot_ts, relative_path, likes_count, is_duplicate)
       VALUES (1, :slot, :path, 4, 0)'
    );
    $relativePath = 'cache/testcam/2026/08/15/20260815_122000_full.jpg';
    $insert->execute([':slot' => $timestamp, ':path' => $relativePath]);
    $pdo->exec('INSERT INTO capture_logo (capture_id, detected, score) VALUES (1, 1, 0.98)');
    $pdo = null;

    $result = (new CameraSnapshotPresentationBuilder($this->app))->build(
      $this->camera,
      [
        'timestamp' => $timestamp,
        'year' => 2026,
        'month' => 8,
        'day' => 15,
        'relative_path' => $relativePath,
        'likes_count' => 0,
      ],
      [],
      ['last_capture' => new DateTimeImmutable('@' . $timestamp)],
      [],
      false,
      'history'
    );

    self::assertContains('❤ 4 Likes', $result['overlay']['parts']);
    self::assertTrue($result['frontend_overlay_suppressed']);
    self::assertSame([], $result['camera_labels']);
  }

  public function testArchivePathSuppressesOverlayAndEmptySnapshotHasStableDefaults(): void
  {
    $timestamp = (new DateTimeImmutable('2026-08-15 12:30:00', new DateTimeZone('Europe/Berlin')))
      ->getTimestamp();
    $builder = new CameraSnapshotPresentationBuilder($this->app);
    $archive = $builder->build(
      $this->camera,
      [
        'timestamp' => $timestamp,
        'year' => 2026,
        'month' => 8,
        'day' => 15,
        'relative_path' => 'storage/archive/testcam/2026/08/15/20260815_123000_full.jpg',
      ],
      [],
      ['last_capture' => new DateTimeImmutable('@' . $timestamp)],
      [],
      false,
      'history'
    );
    self::assertTrue($archive['frontend_overlay_suppressed']);
    self::assertSame([], $archive['camera_labels']);

    $empty = $builder->build($this->camera, null, [], [], [], true, 'live');
    self::assertNull($empty['exif_data']);
    self::assertNull($empty['weather_data']);
    self::assertNull($empty['overlay']);
    self::assertFalse($empty['frontend_overlay_suppressed']);
    self::assertFalse($empty['weather_day_available']);
    self::assertSame('', $empty['modal_exif_text']);
    self::assertSame(['relative_path' => null, 'is_best_of' => false], $empty['best_of_state']);
  }
}
