<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Storage\ArchiveDatabaseReader;
use Webcam\Service\Storage\ArchiveFilesystemReader;
use Webcam\Service\Storage\ArchiveStoragePaths;
use Webcam\Service\Storage\LegacyArchiveCache;
use Webcam\Service\Storage\LegacyArchiveCacheCodec;
use Webcam\Service\Storage\TimelineMapper;

/**
 * Prüft die beim Entzerren neu entstandenen Komponentengrenzen.
 *
 * Die Tests verwenden einen vollständig lokalen Temp-Baum. Produktive Archive,
 * Kamera-DBs und der optionale reale Legacy-Cache werden dabei nicht berührt.
 */
final class LegacyArchiveCacheTest extends TestCase
{
  private string $root;
  private App $app;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam_legacy_cache_' . bin2hex(random_bytes(4));
    if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
      self::fail('Temporäres Testverzeichnis konnte nicht angelegt werden.');
    }
    $this->app = $this->makeApp($this->root);
  }

  protected function tearDown(): void
  {
    $this->removeTree($this->root);
  }

  public function testStoragePathsNormalizeHistoricalCameraSuffix(): void
  {
    $paths = new ArchiveStoragePaths($this->app);
    $camera = $this->app->cameraOrFail('testcam');

    self::assertSame($this->root . '/storage', $paths->baseRoot($camera));
    self::assertSame(
      $this->root . '/storage/archive/testcam',
      $paths->join($paths->baseRoot($camera), '/archive/testcam')
    );
    self::assertSame('/archive/testcam', $paths->join('/', 'archive/testcam'));
    self::assertSame('./archive/testcam', $paths->join('.', 'archive/testcam'));
  }

  public function testCodecRoundTripRestoresUrlsLabelsAndSidecars(): void
  {
    $camera = $this->app->cameraOrFail('testcam');
    $codec = new LegacyArchiveCacheCodec($this->app, new ArchiveStoragePaths($this->app));
    $timestamp = (new DateTimeImmutable('2026-08-14 06:10:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $entry = $this->expandedEntry($timestamp);

    $compact = $codec->compress([$entry]);
    self::assertSame($timestamp, $compact[0]['ts']);
    self::assertSame('cache/testcam/2026/08/14/20260814_061000_sm.jpg', $compact[0]['variants']['sm']);

    // Leere Anzeigenamen simulieren einen alten Cache. hydrate muss diese
    // Werte in der konfigurierten Zeitzone wiederherstellen.
    $compact[0]['tl'] = '';
    $compact[0]['dl'] = '';
    $compact[0]['iso'] = '';
    $hydrated = $codec->hydrate($camera, $compact);

    self::assertSame('06:10', $hydrated[0]['time_label']);
    self::assertSame('14.08.2026 06:10', $hydrated[0]['datetime_label']);
    self::assertSame('cache/testcam/2026/08/14/20260814_061000_sm.jpg', $hydrated[0]['url']);
    self::assertSame('sm', $hydrated[0]['primary_variant']);
    self::assertSame(
      $this->root . '/storage/archive/testcam/2026/08/14/20260814_061000_exif.txt',
      $hydrated[0]['exif_path']
    );
  }

  public function testCacheWriteLoadNearestAndStatusStayBehindServiceBoundary(): void
  {
    $camera = $this->app->cameraOrFail('testcam');
    $cache = $this->makeCache();
    $first = (new DateTimeImmutable('2026-08-14 06:10:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $second = $first + 600;

    self::assertTrue($cache->isEnabled());
    $cache->write($camera, 2026, [
      $this->expandedEntry($first),
      $this->expandedEntry($second, '062000'),
    ]);

    $cacheFile = $this->root . '/state/archive-cache/testcam/2026.json';
    self::assertFileExists($cacheFile);
    self::assertTrue($cache->isFresh($camera, 2026));

    $loaded = $cache->load($camera, 2026);
    self::assertIsArray($loaded);
    self::assertCount(2, $loaded);
    self::assertSame($second, $loaded[1]['timestamp']);

    $nearest = $cache->nearest($camera, 2026, $first + 590);
    self::assertIsArray($nearest);
    self::assertSame($second, $nearest['timestamp']);

    $status = $cache->status($camera);
    self::assertTrue($status['enabled']);
    self::assertSame('testcam', $status['scope']);
    self::assertGreaterThan(0, $status['size_bytes']);
  }

  private function makeCache(): LegacyArchiveCache
  {
    $mapper = new TimelineMapper();
    $databaseReader = new ArchiveDatabaseReader($this->app, $mapper);
    $filesystemReader = new ArchiveFilesystemReader($this->app, $mapper, $databaseReader);
    $paths = new ArchiveStoragePaths($this->app);
    return new LegacyArchiveCache($this->app, $filesystemReader, $paths);
  }

  /** @return array<string,mixed> */
  private function expandedEntry(int $timestamp, string $time = '061000'): array
  {
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $file = '20260814_' . $time;
    return [
      'timestamp' => $timestamp,
      'year' => 2026,
      'month' => 8,
      'day' => 14,
      'time' => $time,
      'time_label' => $date->format('H:i'),
      'datetime_label' => $date->format('d.m.Y H:i'),
      'datetime_iso' => $date->format(DATE_ATOM),
      'primary_variant' => 'sm',
      'variants' => [
        'sm' => [
          'relative_path' => 'cache/testcam/2026/08/14/' . $file . '_sm.jpg',
          'url' => 'unused-during-compression',
          'filename' => $file . '_sm.jpg',
        ],
      ],
      'exif_relative' => 'archive/testcam/2026/08/14/' . $file . '_exif.txt',
      'weather_relative' => 'archive/testcam/2026/08/14/' . $file . '_wx.txt',
    ];
  }

  private function makeApp(string $root): App
  {
    $cameraConfig = [[
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'testcam',
      'title' => 'Testkamera',
      'short_title' => 'Testkamera',
      'description' => '',
      'location' => ['latitude' => 0, 'longitude' => 0],
      'tags' => [],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        // Das Altformat enthält den Kamera-Slug bereits. Genau diese Form soll
        // ArchiveStoragePaths auf den gemeinsamen Root zurückführen.
        'base_path' => $root . '/storage/testcam',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
      ],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [],
      'weather_commands' => [],
    ]];
    $meta = [
      'archive_cache' => [
        'enabled' => true,
        'last_built_at' => [],
      ],
    ];
    $configFile = $root . '/cameras.php';
    $php = '<?php $GLOBALS["webcam_cameras_meta"] = '
      . var_export($meta, true)
      . '; return '
      . var_export($cameraConfig, true)
      . ';';
    file_put_contents($configFile, $php);

    return new App([
      'timezone' => 'Europe/Berlin',
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

  private function removeTree(string $dir): void
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
        $this->removeTree($path);
      } else {
        unlink($path);
      }
    }
    rmdir($dir);
  }
}
