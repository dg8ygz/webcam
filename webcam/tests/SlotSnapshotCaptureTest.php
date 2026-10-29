<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Capture\CaptureManager;

/** End-to-End des Pull-Pfads auf lokalen Fixtures, ohne Live-Capture oder Backupjob. */
final class SlotSnapshotCaptureTest extends TestCase
{
  private string $root;
  private mixed $server = null;
  private string $base;
  private App $app;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/slot-snapshot-' . bin2hex(random_bytes(6));
    foreach (['source', 'cache', 'archive', 'storage', 'state', 'log', 'info', 'camera-db'] as $dir) {
      mkdir($this->root . '/' . $dir, 0775, true);
    }
    file_put_contents($this->root . '/cameras.php', '<?php return [];');
    $this->app = new App([
      'timezone' => 'UTC', 'base_url' => '',
      'camera_config_path' => $this->root . '/cameras.php',
      'cache_path' => $this->root . '/cache', 'archive_path' => $this->root . '/archive',
      'storage_path' => $this->root . '/storage', 'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log', 'info_path' => $this->root . '/info',
      'camera_db_path' => $this->root . '/camera-db',
      'archive_mount_sentinel_required' => false,
    ]);
    // Nur lokaler Fixture-State: ImageStorage darf keinen echten Backup starten.
    file_put_contents($this->root . '/state/backup_db.json', json_encode([
      'ts' => time(), 'status' => 'ok', 'scope' => 'full',
    ], JSON_THROW_ON_ERROR));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    self::assertNotFalse($socket, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $this->base = 'http://' . $address;
    $this->server = proc_open([PHP_BINARY, '-S', $address, '-t', $this->root . '/source'], [
      0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    self::assertIsResource($this->server);
    for ($attempt = 0; $attempt < 50; $attempt++) {
      $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
      if ($ready !== false) {
        fclose($ready);
        return;
      }
      usleep(20000);
    }
    self::fail('Slot source fixture did not start');
  }

  protected function tearDown(): void
  {
    if (is_resource($this->server)) {
      proc_terminate($this->server);
      proc_close($this->server);
    }
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
  }

  public function testMissingSlotWaitsWithoutWritingOldImageThenStoresExactPublishedOriginal(): void
  {
    $slot = time() - 30;
    $camera = $this->camera($this->base . '/{slot_unix}.jpg');
    $manager = new CaptureManager($this->app);
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150));
    imagejpeg($image, $this->root . '/source/current.jpg');
    $waiting = $manager->capture($camera, slotTimestamp: $slot);
    self::assertTrue($waiting->pending);
    self::assertFalse($waiting->successful);
    self::assertFileDoesNotExist($this->root . '/camera-db/demo/db.sqlite');
    self::assertSame(0, $this->app->statusService()->state($camera)['consecutive_failures']);

    copy($this->root . '/source/current.jpg', $this->root . '/source/' . $slot . '.jpg');
    $success = $manager->capture($camera, slotTimestamp: $slot);
    self::assertTrue($success->successful, $success->message);
    self::assertFalse($success->pending);
    self::assertSame($slot, $success->timestamp);
    self::assertFileExists($success->imagePath);
    $db = new PDO('sqlite:' . $this->root . '/camera-db/demo/db.sqlite');
    $row = $db->query('SELECT slot_ts, captured_ts, hash_sha256 FROM captures')->fetch(PDO::FETCH_ASSOC);
    self::assertSame($slot, (int) $row['slot_ts']);
    self::assertGreaterThanOrEqual($slot, (int) $row['captured_ts']);
    self::assertSame(hash_file('sha256', $this->root . '/source/' . $slot . '.jpg'), $row['hash_sha256']);
  }

  public function testPlain404AndExpiredMissingSlotRemainFailures(): void
  {
    $manager = new CaptureManager($this->app);
    foreach ([$this->base . '/missing.jpg', $this->base . '/{slot_unix}.jpg'] as $url) {
      $result = $manager->capture($this->camera($url), slotTimestamp: time() - 601);
      self::assertFalse($result->pending);
      self::assertFalse($result->successful);
      self::assertSame('Snapshot request failed with HTTP 404', $result->message);
    }
  }

  /** Fixture-Kamera hat weder Wetterkommandos noch Reset-/Hardwareaktionen. */
  private function camera(string $endpoint): Camera
  {
    return new Camera([
      'id' => 'demo', 'slug' => 'demo', 'name' => 'Demo', 'title' => 'Demo', 'location' => [],
      'mode' => 'pull-snapshot', 'capture' => ['endpoint' => $endpoint, 'interval_seconds' => 600, 'timeout_seconds' => 2],
      'storage' => ['base_path' => $this->root . '/storage/demo', 'current_dir' => 'current', 'archive_dir' => 'archive', 'best_of_dir' => 'bestof'],
      'resolutions' => [64], 'status' => [], 'weather_commands' => [],
    ]);
  }
}
