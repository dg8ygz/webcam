<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Capture\CaptureResult;
use Webcam\Service\Capture\CaptureSourcePriority;
use Webcam\Service\Upload\UploadIngestor;

/**
 * Vertragstests für die Priorität zwischen Pull und externem Upload.
 *
 * Echte Netzwerk- und Bildoperationen bleiben außen vor. Die Callbacks zeigen
 * eindeutig, ob die jeweilige Quelle ausgeführt, ignoriert oder freigegeben
 * wurde; der persistente Fehlerzähler läuft dabei wie im Produktivbetrieb.
 */
final class CaptureSourcePriorityTest extends TestCase
{
  private string $root;
  private App $app;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-source-priority-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0775, true);
    foreach (['storage', 'archive', 'state', 'log', 'info', 'cache', 'camera-db'] as $directory) {
      mkdir($this->root . '/' . $directory, 0775, true);
    }
    $configPath = $this->root . '/cameras.php';
    file_put_contents($configPath, '<?php return [];');
    $this->app = new App([
      'timezone' => 'UTC',
      'camera_config_path' => $configPath,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'camera_db_path' => $this->root . '/camera-db',
    ]);
  }

  protected function tearDown(): void
  {
    $this->removePath($this->root);
  }

  public function testExternalUploadStartsOnlyAfterConfiguredPullFailures(): void
  {
    $camera = $this->camera('pull-snapshot', 2);
    $priority = new CaptureSourcePriority($this->app);
    $uploads = 0;
    $upload = static function () use (&$uploads): array {
      $uploads++;
      return ['status' => 200, 'payload' => ['ok' => true]];
    };

    self::assertFalse($priority->shouldRequestExternalCapture($camera));
    $initial = $priority->runExternalUpload($camera, $upload);
    self::assertTrue($initial['payload']['ignored']);
    self::assertSame(0, $uploads);

    $priority->runPull(
      $camera,
      static fn(): CaptureResult => CaptureResult::failure($camera, 'first pull error')
    );
    $afterOneFailure = $priority->runExternalUpload($camera, $upload);
    self::assertTrue($afterOneFailure['payload']['ignored']);
    self::assertSame(1, $afterOneFailure['payload']['pull_failures']);

    $priority->runPull(
      $camera,
      static fn(): CaptureResult => CaptureResult::failure($camera, 'second pull error')
    );
    self::assertTrue($priority->shouldRequestExternalCapture($camera));
    $fallback = $priority->runExternalUpload($camera, $upload);
    self::assertTrue($fallback['payload']['fallback']);
    self::assertSame(2, $fallback['payload']['pull_failures']);
    self::assertSame(1, $uploads);

    // Sobald Pull wieder liefert, verliert Active-Upload unmittelbar seine
    // Fallback-Freigabe und wird wieder als bestätigter No-op behandelt.
    $priority->runPull(
      $camera,
      static fn(): CaptureResult => CaptureResult::success($camera, 'pull recovered')
    );
    self::assertFalse($priority->shouldRequestExternalCapture($camera));
    $afterRecovery = $priority->runExternalUpload($camera, $upload);
    self::assertTrue($afterRecovery['payload']['ignored']);
    self::assertSame(0, $afterRecovery['payload']['pull_failures']);
    self::assertSame(1, $uploads);
  }

  public function testAwaitingSourceDoesNotCountAsFailureOrRecovery(): void
  {
    $camera = $this->camera('pull-snapshot', 2);
    $priority = new CaptureSourcePriority($this->app);
    $priority->runPull($camera, static fn(): CaptureResult => CaptureResult::failure($camera, 'first error'));
    $result = $priority->runPull($camera, static fn(): CaptureResult => CaptureResult::awaitingSource($camera, 600));
    self::assertTrue($result->pending);
    self::assertFalse($result->successful);
    self::assertNull($result->imagePath);
    self::assertSame(600, $result->timestamp);
    $upload = $priority->runExternalUpload($camera, static fn(): array => ['status' => 200, 'payload' => ['ok' => true]]);
    self::assertTrue($upload['payload']['ignored']);
    self::assertSame(1, $upload['payload']['pull_failures']);
  }

  public function testZeroDisablesFallbackEvenAfterPullFailures(): void
  {
    $camera = $this->camera('pull-snapshot', 0);
    $priority = new CaptureSourcePriority($this->app);
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $priority->runPull(
        $camera,
        static fn(): CaptureResult => CaptureResult::failure($camera, 'pull error')
      );
    }

    $result = $priority->runExternalUpload(
      $camera,
      static fn(): array => ['status' => 200, 'payload' => ['ok' => true]]
    );
    self::assertTrue($result['payload']['ignored']);
    self::assertSame(3, $result['payload']['pull_failures']);
    self::assertSame(0, $result['payload']['fallback_after_failures']);
  }

  public function testRuntimeExceptionCountsAsPullFailure(): void
  {
    $camera = $this->camera('pull-snapshot', 1);
    $priority = new CaptureSourcePriority($this->app);
    try {
      $priority->runPull(
        $camera,
        static function (): CaptureResult {
          throw new RuntimeException('network unavailable');
        }
      );
      self::fail('Expected RuntimeException');
    } catch (RuntimeException $exception) {
      self::assertSame('network unavailable', $exception->getMessage());
    }

    $result = $priority->runExternalUpload(
      $camera,
      static fn(): array => ['status' => 200, 'payload' => ['ok' => true]]
    );
    self::assertTrue($result['payload']['fallback']);
  }

  public function testNonPullModeKeepsExistingUploadBehaviour(): void
  {
    $camera = $this->camera('active-capture', 0);
    $priority = new CaptureSourcePriority($this->app);
    self::assertTrue($priority->shouldRequestExternalCapture($camera));
    $result = $priority->runExternalUpload(
      $camera,
      static fn(): array => ['status' => 201, 'payload' => ['ok' => true, 'stored' => true]]
    );

    self::assertSame(201, $result['status']);
    self::assertTrue($result['payload']['stored']);
    self::assertArrayNotHasKey('fallback', $result['payload']);
  }

  public function testUploadIngestorSkipsImageWorkWhilePullIsPrimary(): void
  {
    $pullCamera = $this->camera('pull-snapshot', 0);
    $activeCamera = $this->camera('active-capture', 0);
    $ingestor = new UploadIngestor($this->app);

    // Leere Bytes wären im normalen Uploadpfad ein 400-Fehler. Bei einer
    // Pull-Kamera beweist HTTP 200/ignored, dass die Quellenprüfung vorher
    // stattfindet und das Bild weder dekodiert noch gespeichert wird.
    $ignored = $ingestor->ingest($pullCamera, '', time());
    self::assertSame(200, $ignored['status']);
    self::assertTrue($ignored['payload']['ignored']);

    $normal = $ingestor->ingest($activeCamera, '', time());
    self::assertSame(400, $normal['status']);
    self::assertSame('empty upload', $normal['payload']['error']);
  }

  public function testCommandDecisionFailsClosedWithoutLongPollDelay(): void
  {
    $camera = $this->camera('pull-snapshot', 2);
    $directory = $this->root . '/state/pull-fallback';
    mkdir($directory, 0775, true);
    $handle = fopen($directory . '/testcam.lock', 'c');
    self::assertIsResource($handle);
    self::assertTrue(flock($handle, LOCK_EX));

    $startedAt = microtime(true);
    try {
      self::assertFalse((new CaptureSourcePriority($this->app))->shouldRequestExternalCapture($camera));
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }

    self::assertLessThan(1.0, microtime(true) - $startedAt);
  }

  private function camera(string $mode, int $fallbackFailures): Camera
  {
    return new Camera([
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'Testcam',
      'title' => 'Testcam',
      'mode' => $mode,
      'capture' => [
        'interval_seconds' => 600,
        'transport' => 'http-get',
        'endpoint' => 'http://192.0.2.1/current.jpg',
        'active_upload_fallback_failures' => $fallbackFailures,
      ],
      'storage' => [],
      'location' => [],
    ]);
  }

  private function removePath(string $path): void
  {
    if (is_dir($path) && !is_link($path)) {
      foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
          $this->removePath($path . '/' . $entry);
        }
      }
      @rmdir($path);
      return;
    }
    @unlink($path);
  }
}
