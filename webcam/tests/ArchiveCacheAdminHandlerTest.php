<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Http\Admin\AdminRouteResult;
use Webcam\Http\Admin\ArchiveCacheAdminHandler;
use Webcam\Infrastructure\Config\ConfigStore;

require_once __DIR__ . '/Support/TempSqlite.php';

/**
 * Vertragstests für den getrennten Legacy-Archivcache-Handler.
 *
 * Ein injizierter Prozessadapter zeichnet Kommandos auf, startet aber kein
 * Rebuild. Dadurch lassen sich Freigabe, Lock, Timeout und Fehlerabbildung
 * vollständig prüfen, ohne Archive oder produktive State-Dateien anzufassen.
 */
final class ArchiveCacheAdminHandlerTest extends TestCase
{
  private string $root;
  private TempSqlite $configDatabase;
  private App $app;
  private ConfigStore $configStore;
  /** @var array<string,mixed>|null */
  private ?array $previousConfigOptions = null;
  /** @var mixed */
  private mixed $previousCameraMeta = null;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/archive-cache-handler-' . bin2hex(random_bytes(5));
    if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
      self::fail('Unable to create archive cache handler test root');
    }

    $schema = (string) file_get_contents(__DIR__ . '/../database/schema.config.sql');
    $this->configDatabase = new TempSqlite($schema);
    $this->configDatabase->pdo = null;
    $this->previousConfigOptions = isset($GLOBALS['webcam_camera_config_options'])
      && is_array($GLOBALS['webcam_camera_config_options'])
      ? $GLOBALS['webcam_camera_config_options']
      : null;
    $this->previousCameraMeta = $GLOBALS['webcam_cameras_meta'] ?? null;
    $GLOBALS['webcam_camera_config_options'] = [
      'db_path' => $this->configDatabase->path,
      'driver' => 'sqlite',
      'json_path' => null,
    ];
    require_once __DIR__ . '/../config/camera_store.php';
    webcam_camera_store_reset_connections();
    webcam_camera_store_save([
      'version' => 1,
      'defaults' => [],
      'cameras' => ['testcam' => $this->cameraConfig()],
      'archive_cache' => ['enabled' => true, 'last_built_at' => null],
    ], false);

    $this->app = $this->createApp();
    $this->configStore = new ConfigStore($this->app);
  }

  protected function tearDown(): void
  {
    webcam_camera_store_reset_connections();
    if ($this->previousConfigOptions === null) {
      unset($GLOBALS['webcam_camera_config_options']);
    } else {
      $GLOBALS['webcam_camera_config_options'] = $this->previousConfigOptions;
    }
    if ($this->previousCameraMeta === null) {
      unset($GLOBALS['webcam_cameras_meta']);
    } else {
      $GLOBALS['webcam_cameras_meta'] = $this->previousCameraMeta;
    }
    webcam_camera_store_reset_connections();
    parent::tearDown();
  }

  public function testReadsStatusAndPersistsEnabledSetting(): void
  {
    $handler = $this->handler(true, true, $this->successfulRunner());
    $status = $handler->handle('GET', ['archive-cache'], [], static fn(): array => []);
    self::assertSame(200, $status->status);
    self::assertTrue($status->payload['admin_allowed'] ?? false);
    self::assertArrayHasKey('size_bytes', $status->payload ?? []);

    $updated = $handler->handle(
      'PUT',
      ['archive-cache'],
      [],
      static fn(): array => ['enabled' => false]
    );
    self::assertSame(200, $updated->status);
    self::assertFalse($updated->payload['enabled'] ?? true);

    $missing = $handler->handle(
      'GET',
      ['archive-cache'],
      ['slug' => 'missing'],
      static fn(): array => []
    );
    self::assertSame(404, $missing->status);
  }

  public function testDisabledAdminRejectsChangesBeforeReadingBody(): void
  {
    $handler = $this->handler(false, true, $this->successfulRunner());
    $bodyWasRead = false;
    $result = $handler->handle(
      'POST',
      ['archive-cache', 'rebuild'],
      [],
      static function () use (&$bodyWasRead): array {
        $bodyWasRead = true;
        return [];
      }
    );

    self::assertSame(403, $result->status);
    self::assertFalse($bodyWasRead);
    self::assertFalse($handler->supports(['config']));
  }

  public function testUnlockedStaleLockAllowsBoundedArgumentList(): void
  {
    $lockDirectory = $this->root . '/state/archive-cache';
    mkdir($lockDirectory, 0775, true);
    file_put_contents($lockDirectory . '/rebuild.lock', "stale\n");

    $capturedCommand = [];
    $capturedWorkingDirectory = '';
    $runner = static function (
      array $command,
      string $workingDirectory,
      int $timeoutSeconds
    ) use (&$capturedCommand, &$capturedWorkingDirectory): array {
      $capturedCommand = $command;
      $capturedWorkingDirectory = $workingDirectory;
      return [
        'exit_code' => 0,
        'stdout' => 'rebuild ok',
        'stderr' => '',
        'timed_out' => false,
        'timeout_seconds' => $timeoutSeconds,
      ];
    };
    $handler = $this->handler(true, true, $runner);

    $result = $handler->handle(
      'POST',
      ['archive-cache', 'rebuild'],
      [],
      static fn(): array => [
        'slug' => 'testcam',
        'year' => 2026,
        'dry_run' => true,
      ]
    );

    self::assertSame(200, $result->status, json_encode($result->payload));
    self::assertSame('/project-root', $capturedWorkingDirectory);
    self::assertSame([
      'php',
      '/project-root/bin/archive_cache_rebuild.php',
      '--slug=testcam',
      '--year=2026',
      '--dry-run',
      '--verbose',
    ], $capturedCommand);
  }

  public function testHeldLockAndProcessFailuresMapToStableStatuses(): void
  {
    $lockDirectory = $this->root . '/state/archive-cache';
    mkdir($lockDirectory, 0775, true);
    $lockHandle = fopen($lockDirectory . '/rebuild.lock', 'c');
    self::assertIsResource($lockHandle);
    self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

    $runnerCalled = false;
    $handler = $this->handler(
      true,
      true,
      static function () use (&$runnerCalled): array {
        $runnerCalled = true;
        return [];
      }
    );
    $busy = $handler->handle(
      'POST',
      ['archive-cache', 'rebuild'],
      [],
      static fn(): array => []
    );
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    self::assertSame(409, $busy->status);
    self::assertFalse($runnerCalled);

    $timedOut = $this->handler(true, true, static fn(): array => [
      'exit_code' => 124,
      'stdout' => '',
      'stderr' => '',
      'timed_out' => true,
      'timeout_seconds' => 30,
    ])->handle('POST', ['archive-cache', 'rebuild'], [], static fn(): array => []);
    self::assertSame(504, $timedOut->status);

    $failed = $this->handler(true, true, static fn(): array => [
      'exit_code' => 1,
      'stdout' => '',
      'stderr' => 'failure',
      'timed_out' => false,
      'timeout_seconds' => 30,
    ])->handle('POST', ['archive-cache', 'rebuild'], [], static fn(): array => []);
    self::assertSame(500, $failed->status);
  }

  /** @return callable(list<string>,string,int):array<string,mixed> */
  private function successfulRunner(): callable
  {
    return static fn(array $command, string $cwd, int $timeout): array => [
      'exit_code' => 0,
      'stdout' => '',
      'stderr' => '',
      'timed_out' => false,
      'timeout_seconds' => $timeout,
    ];
  }

  private function handler(
    bool $allowed,
    bool $testRequest,
    callable $runner
  ): ArchiveCacheAdminHandler {
    return new ArchiveCacheAdminHandler(
      $this->app,
      $this->configStore,
      '/project-root',
      $allowed,
      $testRequest,
      $runner
    );
  }

  /** @return array<string,mixed> */
  private function cameraConfig(): array
  {
    return [
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'Test Camera',
      'title' => 'Test Camera',
      'short_title' => 'Test',
      'description' => '',
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => $this->root . '/storage/testcam',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
      ],
      'location' => [],
      'tags' => [],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [],
      'weather_commands' => [],
    ];
  }

  private function createApp(): App
  {
    $configPath = $this->root . '/cameras.php';
    $php = '<?php $GLOBALS["webcam_cameras_meta"] = '
      . var_export(['archive_cache' => ['enabled' => true, 'last_built_at' => []]], true)
      . '; return '
      . var_export([$this->cameraConfig()], true)
      . ';';
    file_put_contents($configPath, $php);

    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $configPath,
      'camera_config_db' => $this->configDatabase->path,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'camera_db_path' => $this->root . '/camera-db',
      'base_url' => '',
    ]);
  }
}
