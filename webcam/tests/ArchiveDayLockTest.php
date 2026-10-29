<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Storage\ArchiveDayLock;
use Webcam\Service\Storage\ArchiveMountGuard;

final class ArchiveDayLockTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/webcam-archive-lock-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($this->root . '/storage/archive', 0775, true));
    file_put_contents($this->root . '/cameras.php', "<?php\ndeclare(strict_types=1);\nreturn [];\n");
    file_put_contents($this->root . '/storage/archive/' . ArchiveMountGuard::SENTINEL_FILENAME, '');
  }

  protected function tearDown(): void
  {
    $this->deleteTree($this->root);
    parent::tearDown();
  }

  public function testOnlyOneProcessCanHoldSharedDayLock(): void
  {
    $app = $this->createApp();
    $day = new DateTimeImmutable('2026-08-01');
    $first = ArchiveDayLock::acquire($app, $day);

    self::assertStringEndsWith('/.webcam-archive-locks/2026-08-01.lock', $first->path());
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Shared archive day already locked');
    try {
      ArchiveDayLock::acquire($app, $day);
    } finally {
      $first->release();
    }
  }

  public function testLockCanBeAcquiredAgainAfterRelease(): void
  {
    $app = $this->createApp();
    $day = new DateTimeImmutable('2026-08-01');
    $first = ArchiveDayLock::acquire($app, $day);
    $first->release();

    $second = ArchiveDayLock::acquire($app, $day);
    self::assertFileExists($second->path());
    $second->release();
  }

  private function createApp(): App
  {
    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $this->root . '/cameras.php',
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/var/state',
      'log_path' => $this->root . '/var/log',
      'info_path' => $this->root . '/var/info',
      'cache_path' => $this->root . '/cache',
      'archive_mount_sentinel_required' => true,
      'archive_day_lock_enabled' => true,
      'archive_day_lock_timeout_seconds' => 0,
    ]);
  }

  private function deleteTree(string $path): void
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
      $this->deleteTree($path . '/' . $entry);
    }
    @rmdir($path);
  }
}
