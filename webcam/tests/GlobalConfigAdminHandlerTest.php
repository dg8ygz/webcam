<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Http\Admin\AdminRouteResult;
use Webcam\Http\Admin\GlobalConfigAdminHandler;
use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Service\Maintenance\MaintenanceSchedule;

require_once __DIR__ . '/Support/TempSqlite.php';

/**
 * Vertragstests für den globalen Config-Handler.
 *
 * Die Tests rufen den Handler ohne HTTP-Server auf und verwenden eine eigene
 * Config-SQLite. Sie sichern damit sowohl Routing und Statuscodes als auch die
 * tatsächliche Persistenz, ohne DEV- oder PROD-Konfiguration zu verändern.
 */
final class GlobalConfigAdminHandlerTest extends TestCase
{
  private string $root;
  private TempSqlite $configDatabase;
  private GlobalConfigAdminHandler $handler;
  /** @var array<string,mixed>|null */
  private ?array $previousConfigOptions = null;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/global-config-handler-' . bin2hex(random_bytes(5));
    if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
      self::fail('Unable to create global config handler test root');
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

    webcam_camera_store_save([
      'version' => 1,
      'defaults' => [],
      'cameras' => [],
      'timeline' => ['max_entries' => 500],
      'runtime' => ['weather_command_timeout_seconds' => 20],
      'overview_hero' => 'Alte Überschrift',
      'overview_map' => ['lat' => 52.0, 'lng' => 8.5, 'zoom' => 9, 'height_px' => 320],
      'public_rate_limit' => ['limit' => 60, 'window_seconds' => 60, 'ttl_days' => 7],
      'maintenance_scripts' => MaintenanceSchedule::defaults(),
    ], false);

    $cameraConfigPath = $this->root . '/cameras.php';
    file_put_contents($cameraConfigPath, '<?php return [];');
    $app = new App([
      'timezone' => 'Europe/Berlin',
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
    $this->handler = new GlobalConfigAdminHandler(
      $app,
      new ConfigStore($app),
      realpath(__DIR__ . '/..') ?: dirname(__DIR__)
    );
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

  public function testReadsDocumentAndPersistsGlobalSettings(): void
  {
    $document = $this->request('GET', ['config']);
    self::assertSame(200, $document->status);
    self::assertSame(500, $document->payload['timeline']['max_entries'] ?? null);

    $timeline = $this->request(
      'PUT',
      ['config', 'timeline'],
      ['max_entries' => 750]
    );
    self::assertSame(200, $timeline->status);
    self::assertSame(750, $timeline->payload['max_entries'] ?? null);

    $runtime = $this->request(
      'PUT',
      ['config', 'runtime'],
      ['weather_command_timeout_seconds' => 42]
    );
    self::assertSame(200, $runtime->status);
    self::assertSame(42, $runtime->payload['weather_command_timeout_seconds'] ?? null);

    $hero = $this->request(
      'PUT',
      ['config', 'overview-hero'],
      ['overview_hero' => 'Neue Überschrift']
    );
    self::assertSame('Neue Überschrift', $hero->payload['overview_hero'] ?? null);

    $map = $this->request('PUT', ['config', 'overview-map'], [
      'lat' => 52.03,
      'lng' => 8.53,
      'zoom' => 11,
      'height_px' => 420,
    ]);
    self::assertSame(200, $map->status);
    self::assertSame(420, $map->payload['height_px'] ?? null);

    $rateLimit = $this->request('PUT', ['config', 'public-rate-limit'], [
      'limit' => 80,
      'window_seconds' => 120,
      'ttl_days' => 14,
    ]);
    self::assertSame(200, $rateLimit->status);
    self::assertSame(80, $rateLimit->payload['limit'] ?? null);

    // Der abschließende GET beweist, dass nicht nur das Rückgabemodell, sondern
    // das gemeinsame temporäre SQLite-Dokument geändert wurde.
    $saved = $this->request('GET', ['config']);
    self::assertSame(750, $saved->payload['timeline']['max_entries'] ?? null);
    self::assertSame(42, $saved->payload['runtime']['weather_command_timeout_seconds'] ?? null);
  }

  public function testRejectsInvalidSettingsWithoutReadingUnrelatedBodies(): void
  {
    $invalidRuntime = $this->request(
      'PUT',
      ['config', 'runtime'],
      ['weather_command_timeout_seconds' => 301]
    );
    self::assertSame(400, $invalidRuntime->status);

    $invalidMap = $this->request('PUT', ['config', 'overview-map'], [
      'lat' => 91,
      'lng' => 8.5,
      'zoom' => 9,
      'height_px' => 320,
    ]);
    self::assertSame(400, $invalidMap->status);
    self::assertSame('lat must be between -90 and 90', $invalidMap->payload['detail'] ?? null);

    $bodyWasRead = false;
    $unsupported = $this->handler->handle(
      'POST',
      ['config', 'timeline'],
      static function () use (&$bodyWasRead): array {
        $bodyWasRead = true;
        return [];
      }
    );
    self::assertSame(404, $unsupported->status);
    self::assertFalse($bodyWasRead);
    self::assertFalse($this->handler->supports(['status']));
  }

  public function testValidatesMaintenanceScriptsAndBuildsWorkspaceStatus(): void
  {
    $settings = MaintenanceSchedule::defaults();
    $settings['backup_daily']['time'] = '04:15';
    $settings['weather_quality']['time'] = '04:35';
    $saved = $this->request('PUT', ['config', 'maintenance-scripts'], $settings);
    self::assertSame(200, $saved->status, json_encode($saved->payload));
    self::assertSame('04:15', $saved->payload['backup_daily']['time'] ?? null);

    $stateDirectory = $this->root . '/state/maintenance-scheduler';
    mkdir($stateDirectory, 0775, true);
    file_put_contents($stateDirectory . '/backup_daily.json', json_encode([
      'task' => 'backup_daily',
      'status' => 'done',
      'finished_at' => '2026-08-30T04:16:00+02:00',
      'exit_code' => 0,
      'log_entries' => [],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($stateDirectory . '/tick.lock', '1788055200');

    $workspace = $this->request('GET', ['scripts']);
    self::assertSame(200, $workspace->status);
    self::assertTrue($workspace->payload['definitions']['backup_daily']['builtin'] ?? false);
    self::assertSame('done', $workspace->payload['status']['backup_daily']['status'] ?? null);
    self::assertSame('camera_poll', $workspace->payload['trigger']['mode'] ?? null);
    self::assertNotNull($workspace->payload['trigger']['last_tick_at'] ?? null);
  }

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $body
   */
  private function request(string $method, array $segments, array $body = []): AdminRouteResult
  {
    return $this->handler->handle(
      $method,
      $segments,
      static fn(): array => $body
    );
  }
}
