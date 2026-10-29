<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Http\Admin\StatusAdminHandler;

/**
 * Vertragstests für die ausgelagerte Kamera-Statusübersicht.
 *
 * Vier kleine Kameras decken unbekannt, aktuell, verspätet und fehlerhaft ab.
 * Der feste Request-Zeitpunkt macht die Intervallgrenzen deterministisch und
 * verhindert Tests, die vom Zeitpunkt ihrer Ausführung abhängen.
 */
final class StatusAdminHandlerTest extends TestCase
{
  private StatusAdminHandler $handler;

  protected function setUp(): void
  {
    parent::setUp();
    $root = TestTempDir::ensure() . '/status-admin-handler-' . bin2hex(random_bytes(5));
    foreach (['cache', 'storage', 'archive', 'state/cameras', 'log', 'info', 'camera-db'] as $directory) {
      mkdir($root . '/' . $directory, 0775, true);
    }

    $cameras = [];
    foreach (['unknown', 'current', 'delayed', 'stale'] as $slug) {
      $cameras[] = $this->cameraConfig($root, $slug);
    }
    $cameraConfig = $root . '/cameras.php';
    file_put_contents($cameraConfig, "<?php\nreturn " . var_export($cameras, true) . ";\n");

    // StatusService ergänzt nicht vorhandene Felder aus seinem Default-State.
    // Für diesen HTTP-Vertrag genügen deshalb die tatsächlich gelesenen Werte.
    $this->writeState($root, 'current', 2400, 2390, 0, 0, null);
    $this->writeState($root, 'delayed', 1100, 1080, 2, 1, 900);
    $this->writeState($root, 'stale', 500, 490, 5, 3, 700);

    $app = new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $cameraConfig,
      'cache_path' => $root . '/cache',
      'storage_path' => $root . '/storage',
      'archive_path' => $root . '/archive',
      'state_path' => $root . '/state',
      'log_path' => $root . '/log',
      'info_path' => $root . '/info',
      'camera_db_path' => $root . '/camera-db',
    ]);
    $this->handler = new StatusAdminHandler($app, 2500);
  }

  public function testBuildsAllSeverityLevelsAndPreservesStatusFields(): void
  {
    $result = $this->handler->handle('GET', ['status']);
    self::assertSame(200, $result->status);

    $items = [];
    foreach ($result->payload['items'] ?? [] as $item) {
      $items[$item['slug']] = $item;
    }

    self::assertSame('unknown', $items['unknown']['severity'] ?? null);
    self::assertNull($items['unknown']['age_seconds'] ?? null);

    self::assertSame('ok', $items['current']['severity'] ?? null);
    self::assertSame(100, $items['current']['age_seconds'] ?? null);

    self::assertSame('warn', $items['delayed']['severity'] ?? null);
    self::assertSame(1400, $items['delayed']['age_seconds'] ?? null);
    self::assertSame(2, $items['delayed']['consecutive_failures'] ?? null);
    self::assertSame(1, $items['delayed']['resets_total'] ?? null);
    self::assertSame(900, $items['delayed']['last_reset_at'] ?? null);

    self::assertSame('error', $items['stale']['severity'] ?? null);
    self::assertSame(2000, $items['stale']['age_seconds'] ?? null);
    self::assertSame(490, $items['stale']['last_capture_at'] ?? null);
    self::assertSame(500, $items['stale']['last_success_at'] ?? null);
  }

  public function testRejectsUnsupportedMethodsAndPaths(): void
  {
    self::assertSame(404, $this->handler->handle('POST', ['status'])->status);
    self::assertSame(404, $this->handler->handle('GET', ['status', 'demo'])->status);
    self::assertFalse($this->handler->supports(['streams']));
  }

  /** @return array<string,mixed> */
  private function cameraConfig(string $root, string $slug): array
  {
    return [
      'id' => $slug,
      'slug' => $slug,
      'name' => ucfirst($slug),
      'title' => ucfirst($slug),
      'location' => ['lat' => 0.0, 'lng' => 0.0],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => $root,
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [400],
      'weather_commands' => [],
    ];
  }

  private function writeState(
    string $root,
    string $slug,
    int $lastSuccess,
    int $lastCapture,
    int $failures,
    int $resets,
    ?int $lastReset
  ): void {
    file_put_contents(
      $root . '/state/cameras/' . $slug . '.json',
      json_encode([
        'camera_id' => $slug,
        'last_success_at' => $lastSuccess,
        'last_capture_at' => $lastCapture,
        'consecutive_failures' => $failures,
        'resets_total' => $resets,
        'last_reset_at' => $lastReset,
      ], JSON_THROW_ON_ERROR)
    );
  }
}
