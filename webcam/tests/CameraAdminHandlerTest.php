<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Http\Admin\CameraAdminHandler;
use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Admin\CameraRuntimeAccessInspector;

require_once __DIR__ . '/Support/TempSqlite.php';

/**
 * Vertragstests für den ausgelagerten Kamera-HTTP-Handler.
 *
 * Anders als die Service-Einzeltests prüfen diese Fälle die komplette
 * Zuordnung von Methode und URL: Kamera-CRUD, Abschnittsspeicherung,
 * Jahresliste, DB-Vorschau sowie Capture-Lesen und -Ändern. Config- und
 * Kamera-SQLite liegen ausschließlich in einem temporären Testverzeichnis.
 */
final class CameraAdminHandlerTest extends TestCase
{
  private string $root;
  private TempSqlite $configDatabase;
  private App $app;
  private CameraAdminHandler $handler;
  /** @var array<string,mixed>|null */
  private ?array $previousConfigOptions = null;
  private int $slotTimestamp;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/camera-admin-handler-' . bin2hex(random_bytes(5));
    if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
      self::fail('Unable to create handler test root');
    }

    $schema = (string) file_get_contents(__DIR__ . '/../database/schema.config.sql');
    $this->configDatabase = new TempSqlite($schema);
    $this->configDatabase->pdo = null;
    $this->previousConfigOptions = isset($GLOBALS['webcam_camera_config_options'])
      && is_array($GLOBALS['webcam_camera_config_options'])
      ? $GLOBALS['webcam_camera_config_options']
      : null;
    $GLOBALS['webcam_camera_config_options'] = [
      'db_path' => $this->configDatabase->path,
      'driver' => 'sqlite',
      'json_path' => null,
    ];
    require_once __DIR__ . '/../config/camera_store.php';
    webcam_camera_store_reset_connections();

    $camera = $this->cameraConfig('testcam');
    webcam_camera_store_save([
      'version' => 1,
      'defaults' => ['resolutions' => [400]],
      'cameras' => ['testcam' => $camera],
    ], false);

    mkdir($this->root . '/testcam/current', 0775, true);
    $this->app = $this->createApp($camera);
    $this->handler = new CameraAdminHandler(
      $this->app,
      new ConfigStore($this->app),
      static fn(string $path): bool => is_file($path),
      new CameraRuntimeAccessInspector(
        $this->root,
        null,
        null,
        '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply'
      )
    );

    // Eine reale, aber vollständig isolierte Kamera-SQLite versorgt Vorschau
    // und Capture-Routen. So berührt der Test weder DEV- noch PROD-Datenbanken.
    $cameraStore = new CameraDataStore($this->app, $this->app->cameraOrFail('testcam'));
    $cameraStore->ensureWritableSchema();
    $this->slotTimestamp = (new DateTimeImmutable(
      '2026-08-30 12:10:00',
      new DateTimeZone('UTC')
    ))->getTimestamp();
    $cameraStore->recordCapture([
      'slot_timestamp' => $this->slotTimestamp,
      'captured_timestamp' => $this->slotTimestamp + 2,
      'relative_path' => 'cache/testcam/2026/08/30/original.jpg',
      'luminance' => 12.5,
      'hash' => str_repeat('a', 64),
      'variants' => [],
      'exif' => ['ISO' => '100'],
      'weather_lines' => ['Temperatur: 18 °C'],
    ]);
    $cameraStore->close();
  }

  protected function tearDown(): void
  {
    webcam_camera_store_reset_connections();
    if ($this->previousConfigOptions === null) {
      unset($GLOBALS['webcam_camera_config_options']);
    } else {
      $GLOBALS['webcam_camera_config_options'] = $this->previousConfigOptions;
    }
    webcam_camera_store_reset_connections();
    parent::tearDown();
  }

  public function testManualCaptureRouteIsScopedAndDoesNotRunNetworkWork(): void
  {
    self::assertSame(404, $this->request('POST', ['cameras', 'missing', 'capture'])->status);
    self::assertSame(404, $this->request('GET', ['cameras', 'testcam', 'capture'])->status);
    self::assertSame(409, $this->request('POST', ['cameras', 'testcam', 'capture'])->status);
    $camera = $this->cameraConfig('testcam');
    $camera['capture']['upload_key'] = 'unit-test-only-key';
    $this->app = $this->createApp($camera);
    $this->handler = new CameraAdminHandler($this->app, new ConfigStore($this->app));
    $result = $this->request('POST', ['cameras', 'testcam', 'capture']);
    // Endpoint is unreachable: this route must only enqueue, never fetch it.
    self::assertSame(202, $result->status, json_encode($result->payload));
    self::assertSame('testcam', $result->payload['camera']);
    self::assertSame(409, $this->request('POST', ['cameras', 'testcam', 'capture'])->status);
    $queue = new \Webcam\Service\Capture\ManualCaptureQueue($this->app->paths()->statePath('manual-capture'));
    self::assertTrue($queue->pending('testcam'));
    self::assertFalse($queue->pending('other'));
  }

  public function testFocusRouteRequiresCapabilityAndStrictBody(): void
  {
    $camera = $this->cameraConfig('testcam');
    $camera['capture']['upload_key'] = 'unit-test-only-key';
    $this->app = $this->createApp($camera);
    $this->handler = new CameraAdminHandler($this->app, new ConfigStore($this->app));
    $route = ['cameras', 'testcam', 'focus'];
    self::assertFalse($this->request('GET', $route)->payload['available']);
    self::assertSame(404, $this->request('POST', ['cameras', 'missing', 'focus'], [], ['mode' => 'auto'])->status);
    self::assertSame(409, $this->request('POST', $route, [], ['mode' => 'auto'])->status);
    $queue = new \Webcam\Service\Capture\FocusQueue($this->app->paths()->statePath('focus'));
    $queue->poll('testcam', $queue::PROTOCOL);
    self::assertTrue($this->request('GET', $route)->payload['available']);
    foreach ([[], ['mode' => 'exec'], ['mode' => ['auto']], ['mode' => 'auto', 'command' => 'id']] as $body) {
      self::assertSame(400, $this->request('POST', $route, [], $body)->status);
    }
    self::assertSame(202, $this->request('POST', $route, [], ['mode' => '++'])->status);
    self::assertSame(409, $this->request('POST', $route, [], ['mode' => '-'])->status);
    self::assertNull($queue->poll('other', $queue::PROTOCOL));
    self::assertStringEndsWith("\n++\n", $queue->poll('testcam', $queue::PROTOCOL));
  }

  public function testFailedDirectoryProvisioningDoesNotSaveCamera(): void
  {
    if (!is_dir($this->root . '/cache')) mkdir($this->root . '/cache');
    file_put_contents($this->root . '/cache/blocked-camera', 'not a directory');
    $result = $this->request('POST', ['cameras'], [], $this->cameraConfig('blocked-camera'));
    self::assertSame(503, $result->status);
    self::assertSame(404, $this->request('GET', ['cameras', 'blocked-camera'])->status);
  }

  public function testRoutesCameraCrudAndInvalidatesPageCache(): void
  {
    $list = $this->request('GET', ['cameras']);
    self::assertSame(200, $list->status);
    self::assertArrayHasKey('testcam', $list->payload['cameras'] ?? []);
    self::assertFalse(
      $list->payload['runtime']['current_paths']['testcam']['provision_required'] ?? true
    );

    $created = $this->request('POST', ['cameras'], [], $this->cameraConfig('handler-test'));
    self::assertSame(201, $created->status, json_encode($created->payload));
    self::assertSame('handler-test', $created->payload['slug'] ?? null);
    self::assertFalse($created->payload['_runtime']['provision_required'] ?? true);
    self::assertDirectoryExists($this->root . '/cache/handler-test/current');
    self::assertDirectoryDoesNotExist($this->root . '/handler-test');
    self::assertSame(
      '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply',
      $created->payload['_runtime']['provision_command'] ?? null
    );

    $cacheDirectory = $this->root . '/state/page-cache/handler-test';
    mkdir($cacheDirectory, 0775, true);
    $cachedPage = $cacheDirectory . '/live_test.html';
    file_put_contents($cachedPage, '<html>stale</html>');

    $updated = $this->request(
      'PUT',
      ['cameras', 'handler-test', 'sections', 'general'],
      [],
      ['name' => 'Neuer Name', 'title' => 'Neuer Titel']
    );
    self::assertSame(200, $updated->status);
    self::assertSame('Neuer Name', $updated->payload['camera']['name'] ?? null);
    self::assertFileDoesNotExist($cachedPage);

    $read = $this->request('GET', ['cameras', 'handler-test']);
    self::assertSame(200, $read->status);
    self::assertSame('Neuer Titel', $read->payload['title'] ?? null);

    $deleted = $this->request('DELETE', ['cameras', 'handler-test']);
    self::assertSame(204, $deleted->status);
    self::assertNull($deleted->payload);
    self::assertSame(404, $this->request('GET', ['cameras', 'handler-test'])->status);
  }

  public function testInterleavedCameraSavePreservesTheOtherAdminChange(): void
  {
    $store = new ConfigStore($this->app);
    try {
      $this->handler->handle('PUT', ['cameras', 'testcam', 'sections', 'general'], [], [],
        function () use ($store): array {
          $other = $store->load();
          $other['overview_hero'] = 'Saved by another administrator';
          $store->save($other);
          return ['name' => 'Conflicting edit'];
        });
      self::fail('A stale document must not overwrite the concurrent save');
    } catch (\Webcam\Infrastructure\Config\ConfigConflictException) {
      $current = $store->load();
      self::assertSame('Saved by another administrator', $current['overview_hero']);
      self::assertSame('Test Camera', $current['cameras']['testcam']['name']);
    }
  }

  public function testRoutesYearsDatabasePreviewAndCaptureRead(): void
  {
    $years = $this->request('GET', ['cameras', 'testcam', 'years']);
    self::assertSame(200, $years->status);
    self::assertIsArray($years->payload['years'] ?? null);

    $preview = $this->request('GET', ['cameras', 'testcam', 'db'], ['limit' => '1']);
    self::assertSame(200, $preview->status);
    self::assertCount(1, $preview->payload['entries'] ?? []);
    self::assertSame($this->slotTimestamp, $preview->payload['entries'][0]['slot_ts'] ?? null);

    $capture = $this->request(
      'GET',
      ['cameras', 'testcam', 'captures'],
      ['slot_ts' => (string) $this->slotTimestamp]
    );
    self::assertSame(200, $capture->status);
    self::assertSame($this->slotTimestamp, $capture->payload['capture']['slot_ts'] ?? null);
    self::assertSame('ISO', $capture->payload['exif'][0]['tag'] ?? null);
  }

  public function testRoutesCaptureUpdateAndPreservesValidationOrder(): void
  {
    $updated = $this->request(
      'PUT',
      ['cameras', 'testcam', 'captures', (string) $this->slotTimestamp],
      [],
      ['luminance' => 24.5]
    );
    self::assertSame(200, $updated->status);
    self::assertSame(24.5, $updated->payload['capture']['luminance'] ?? null);

    $bodyWasRead = false;
    $invalidSlot = $this->handler->handle(
      'PUT',
      ['cameras', 'testcam', 'captures', 'invalid'],
      [],
      [],
      static function () use (&$bodyWasRead): array {
        $bodyWasRead = true;
        return [];
      }
    );
    self::assertSame(400, $invalidSlot->status);
    self::assertFalse($bodyWasRead, 'Invalid slot must be rejected before reading JSON');
  }

  public function testRejectsUnknownOrUnsupportedRoutesWithoutReadingBody(): void
  {
    $bodyWasRead = false;
    $result = $this->handler->handle(
      'PUT',
      ['cameras', 'missing'],
      [],
      [],
      static function () use (&$bodyWasRead): array {
        $bodyWasRead = true;
        return [];
      }
    );

    self::assertSame(404, $result->status);
    self::assertSame('Camera not found', $result->payload['detail'] ?? null);
    self::assertFalse($bodyWasRead, 'Unknown camera must be rejected before reading JSON');
    self::assertFalse($this->handler->supports(['status']));
  }

  public function testWatchdogSaveRejectsInvalidDataWithoutPersisting(): void
  {
    $valid = ['enabled' => false, 'reset_sequence' => [
      ['type' => 'http-get', 'url' => 'https://camera.example.test/reset', 'timeout' => 60],
      ['type' => 'delay', 'seconds' => 300],
    ]];
    $saved = $this->request('PUT', ['cameras', 'testcam', 'sections', 'watchdog'], [], ['status' => ['watchdog' => $valid]]);
    self::assertSame(200, $saved->status, json_encode($saved->payload));
    $before = $this->request('GET', ['cameras', 'testcam'])->payload['status']['watchdog'];
    self::assertSame($valid['reset_sequence'], $before['reset_sequence']);
    $invalid = $valid;
    $invalid['reset_sequence'][1]['seconds'] = 301;
    foreach ([
      ['POST', ['cameras'], array_replace($this->cameraConfig('invalid-reset'), ['status' => ['watchdog' => $invalid]])],
      ['PUT', ['cameras', 'testcam'], ['status' => ['watchdog' => $invalid]]],
      ['PUT', ['cameras', 'testcam', 'sections', 'watchdog'], ['status' => ['watchdog' => $invalid]]],
    ] as [$method, $segments, $body]) {
      $response = $this->request($method, $segments, [], $body);
      self::assertSame(400, $response->status, json_encode($response->payload));
      self::assertStringContainsString('reset_sequence[1].seconds', $response->payload['detail']);
      self::assertSame($before, $this->request('GET', ['cameras', 'testcam'])->payload['status']['watchdog']);
    }
    self::assertSame(404, $this->request('GET', ['cameras', 'invalid-reset'])->status);
  }

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $query
   * @param array<string,mixed> $body
   */
  private function request(
    string $method,
    array $segments,
    array $query = [],
    array $body = []
  ): \Webcam\Http\Admin\AdminRouteResult {
    return $this->handler->handle(
      $method,
      $segments,
      $query,
      [],
      static fn(): array => $body
    );
  }

  /** @return array<string,mixed> */
  private function cameraConfig(string $slug): array
  {
    return [
      'id' => $slug,
      'slug' => $slug,
      'name' => 'Test Camera',
      'title' => 'Test Camera',
      'short_title' => 'Test',
      'description' => 'Handler test camera',
      'mode' => 'active-capture',
      'tags' => [],
      'capture' => [
        'interval_seconds' => 600,
        'transport' => 'http-get',
        'endpoint' => 'http://192.0.2.10/current.jpg',
        'timeout_seconds' => 10,
        'expected_response' => 200,
        'extra_times' => [],
      ],
      'storage' => [
        'base_path' => 'storage/' . $slug,
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'location' => [],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [400],
      'weather_commands' => [],
    ];
  }

  /** @param array<string,mixed> $camera */
  private function createApp(array $camera): App
  {
    $cameraConfigPath = $this->root . '/cameras.php';
    file_put_contents($cameraConfigPath, '<?php return ' . var_export([$camera], true) . ';');

    return new App([
      'timezone' => 'UTC',
      'camera_config_path' => $cameraConfigPath,
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
