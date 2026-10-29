<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Backup\SidecarBackup;

final class SidecarBackupTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-sidecar-backup-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($this->root, 0775, true) || is_dir($this->root));
  }

  protected function tearDown(): void
  {
    $this->removeTree($this->root);
  }

  public function testBacksUpCacheAndArchiveWeatherAndExifSidecars(): void
  {
    $app = $this->createApp();
    $dateKey = '2026/07/25';
    $cacheDay = $app->paths()->cachePath('demo/' . $dateKey);
    $archiveDay = $app->paths()->archivePath('demo/' . $dateKey);
    self::assertTrue(mkdir($cacheDay, 0775, true) || is_dir($cacheDay));
    self::assertTrue(mkdir($archiveDay, 0775, true) || is_dir($archiveDay));

    file_put_contents($cacheDay . '/20260725_000000_WX.txt', "Temp: 20 C\n");
    file_put_contents($cacheDay . '/20260725_000000_ex.txt', "ExposureTime: 1/10\n");
    file_put_contents($archiveDay . '/0000_wx.txt', "Temp: 20 C\n");
    file_put_contents($archiveDay . '/0000_ex.txt', "ExposureTime: 1/10\n");

    $backupBase = $this->root . '/backups/sidecars';
    $day = new DateTimeImmutable('2026-07-25', new DateTimeZone('UTC'));
    $service = new SidecarBackup($app, $backupBase);
    $result = $service->backupDays([$day]);

    self::assertSame(4, $result['total']);
    self::assertSame(4, $result['copied']);
    self::assertSame(0, $result['failed']);
    self::assertSame("Temp: 20 C\n", file_get_contents($backupBase . '/demo/' . $dateKey . '/cache/20260725_000000_WX.txt'));
    self::assertSame("ExposureTime: 1/10\n", file_get_contents($backupBase . '/demo/' . $dateKey . '/cache/20260725_000000_ex.txt'));
    self::assertSame("Temp: 20 C\n", file_get_contents($backupBase . '/demo/' . $dateKey . '/archive/0000_wx.txt'));
    self::assertSame("ExposureTime: 1/10\n", file_get_contents($backupBase . '/demo/' . $dateKey . '/archive/0000_ex.txt'));

    $second = $service->backupDays([$day]);
    self::assertSame(0, $second['copied']);
    self::assertSame(4, $second['unchanged']);
    self::assertSame(0, $second['failed']);
  }

  public function testRefusesToOverwriteDifferentExistingBackupWithoutExplicitFlag(): void
  {
    $app = $this->createApp();
    $dateKey = '2026/07/25';
    $cacheDay = $app->paths()->cachePath('demo/' . $dateKey);
    self::assertTrue(mkdir($cacheDay, 0775, true) || is_dir($cacheDay));
    $source = $cacheDay . '/20260725_000000_WX.txt';
    file_put_contents($source, "Temp: 20 C\n");

    $backupBase = $this->root . '/backups/sidecars';
    $day = new DateTimeImmutable('2026-07-25', new DateTimeZone('UTC'));
    $service = new SidecarBackup($app, $backupBase);
    self::assertSame(1, $service->backupDays([$day])['copied']);

    file_put_contents($source, "Temp: 21 C\n");
    $conflict = $service->backupDays([$day]);
    self::assertSame(1, $conflict['failed']);
    $target = $backupBase . '/demo/' . $dateKey . '/cache/20260725_000000_WX.txt';
    self::assertSame("Temp: 20 C\n", file_get_contents($target));

    $overwritten = $service->backupDays([$day], [], true);
    self::assertSame(1, $overwritten['copied']);
    self::assertSame(0, $overwritten['failed']);
    self::assertSame("Temp: 21 C\n", file_get_contents($target));
  }

  public function testReportsEmptySidecarAsFailureAndKeepsNoBackup(): void
  {
    $app = $this->createApp();
    $dateKey = '2026/07/25';
    $archiveDay = $app->paths()->archivePath('demo/' . $dateKey);
    self::assertTrue(mkdir($archiveDay, 0775, true) || is_dir($archiveDay));
    file_put_contents($archiveDay . '/0000_wx.txt', '');

    $backupBase = $this->root . '/backups/sidecars';
    $day = new DateTimeImmutable('2026-07-25', new DateTimeZone('UTC'));
    $result = (new SidecarBackup($app, $backupBase))->backupDays([$day]);

    self::assertSame(1, $result['failed']);
    self::assertContains('demo', $result['failed_slugs']);
    self::assertFileDoesNotExist($backupBase . '/demo/' . $dateKey . '/archive/0000_wx.txt');
    $log = file_get_contents($this->root . '/log/app.log');
    self::assertIsString($log);
    self::assertStringContainsString('sidecar backup failed', $log);
  }

  public function testReportsCaptureDayWithoutAnyTextSidecars(): void
  {
    $app = $this->createApp();
    $dateKey = '2026/07/25';
    $cacheDay = $app->paths()->cachePath('demo/' . $dateKey);
    self::assertTrue(mkdir($cacheDay, 0775, true) || is_dir($cacheDay));
    file_put_contents($cacheDay . '/20260725_000000.jpg', 'jpeg-placeholder');

    $day = new DateTimeImmutable('2026-07-25', new DateTimeZone('UTC'));
    $result = (new SidecarBackup($app, $this->root . '/backups/sidecars'))->backupDays([$day]);

    self::assertSame(1, $result['failed']);
    self::assertContains('demo', $result['failed_slugs']);
    $log = file_get_contents($this->root . '/log/app.log');
    self::assertIsString($log);
    self::assertStringContainsString('sidecar backup found captures without text sidecars', $log);
  }

  private function createApp(): App
  {
    $configPath = $this->root . '/cameras.php';
    $cameras = [[
      'id' => 'demo',
      'slug' => 'demo',
      'name' => 'Demo',
      'title' => 'Demo',
      'description' => '',
      'location' => ['lat' => 0.0, 'lng' => 0.0],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => 'storage/demo',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [400],
      'weather_commands' => [],
    ]];
    file_put_contents($configPath, '<?php return ' . var_export($cameras, true) . ';');

    return new App([
      'timezone' => 'UTC',
      'camera_config_path' => $configPath,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'camera_db_path' => $this->root . '/camera-db',
    ]);
  }

  private function removeTree(string $path): void
  {
    if (!file_exists($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->removeTree($path . '/' . $entry);
    }
    @rmdir($path);
  }
}
