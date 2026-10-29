<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Storage\ArchiveMountGuard;

final class ArchiveMountGuardTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/webcam-archive-guard-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($this->root . '/archive', 0775, true));
    file_put_contents($this->root . '/cameras.php', "<?php\ndeclare(strict_types=1);\nreturn [];\n");
    file_put_contents($this->root . '/archive/' . ArchiveMountGuard::SENTINEL_FILENAME, '');
  }

  protected function tearDown(): void
  {
    $this->deleteTree($this->root);
    parent::tearDown();
  }

  public function testWritableArchivePassesAndProbeIsRemoved(): void
  {
    $guard = new ArchiveMountGuard($this->createApp($this->root . '/archive', true));

    $guard->assertWritable('test rollover');

    self::assertSame([], glob($this->root . '/archive/.webcam-write-probe-*') ?: []);
  }

  public function testReadOnlyFilesystemFailsWithClearMessage(): void
  {
    if (!is_dir('/sys')) {
      self::markTestSkipped('/sys is unavailable');
    }
    $guard = new ArchiveMountGuard($this->createApp('/sys', false));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage(
      'Archive is not writable for archive rollover (read-only mount or missing write permission): /sys'
    );
    $guard->assertWritable('archive rollover');
  }

  private function createApp(string $archivePath, bool $sentinelRequired): App
  {
    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $this->root . '/cameras.php',
      'storage_path' => $this->root,
      'archive_path' => $archivePath,
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'archive_mount_sentinel_required' => $sentinelRequired,
    ]);
  }

  private function deleteTree(string $path): void
  {
    if ($path === '' || !file_exists($path)) {
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
