<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Http\Admin\AdminRouteResult;
use Webcam\Http\Admin\StreamAdminHandler;
use Webcam\Service\Stream\StreamState;

/**
 * Vertragstests für die ausgelagerten Stream-Admin-Routen.
 *
 * Alle Runtime-Dateien liegen unter einem eigenen temporären App-Verzeichnis.
 * Dadurch prüfen die Tests echte StreamState-Lese- und Schreibvorgänge, ohne
 * aktive DEV-/PROD-Viewer oder deren Sperrliste zu berühren.
 */
final class StreamAdminHandlerTest extends TestCase
{
  private StreamState $streamState;
  private StreamAdminHandler $handler;

  protected function setUp(): void
  {
    parent::setUp();
    $root = TestTempDir::ensure() . '/stream-admin-handler-' . bin2hex(random_bytes(5));
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db'] as $directory) {
      mkdir($root . '/' . $directory, 0775, true);
    }
    $cameraConfig = $root . '/cameras.php';
    file_put_contents($cameraConfig, "<?php\nreturn [];\n");

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
      'trusted_proxies' => ['127.0.0.1'],
    ]);
    $this->streamState = new StreamState($app);
    $this->handler = new StreamAdminHandler($app, $this->streamState);
  }

  public function testBuildsOverviewFromActiveClientsAndBlockedIps(): void
  {
    // Zwei unterschiedliche User-Agents erzeugen zwei HLS-Identitäten für
    // dieselbe Kamera und sichern damit auch die Gruppierung `by_camera` ab.
    $this->streamState->registerHlsRequest('demo', [
      'REMOTE_ADDR' => '198.51.100.10',
      'HTTP_USER_AGENT' => 'viewer-a',
    ]);
    $this->streamState->registerHlsRequest('demo', [
      'REMOTE_ADDR' => '198.51.100.10',
      'HTTP_USER_AGENT' => 'viewer-b',
    ]);
    $this->streamState->blockIp('203.0.113.7', 'Vertragstest');

    $result = $this->request('GET', ['streams']);

    self::assertSame(200, $result->status);
    self::assertCount(2, $result->payload['items'] ?? []);
    self::assertSame(2, $result->payload['summary']['total_active'] ?? null);
    self::assertSame(2, $result->payload['summary']['by_camera']['demo'] ?? null);
    self::assertSame(1, $result->payload['summary']['blocked_total'] ?? null);
    self::assertSame('203.0.113.7', $result->payload['blocked_ips'][0]['ip'] ?? null);
  }

  public function testDisconnectBlockAndUnblockKeepExistingHttpContract(): void
  {
    $blocked = $this->request('POST', ['streams', 'block'], [
      'ip' => '198.51.100.25',
      'note' => 'Admin-Test',
    ]);
    self::assertSame(200, $blocked->status);
    self::assertTrue($blocked->payload['ok'] ?? false);
    self::assertSame('198.51.100.25', $blocked->payload['blocked_ips'][0]['ip'] ?? null);

    $unblocked = $this->request('POST', ['streams', 'unblock'], [
      'ip' => '198.51.100.25',
    ]);
    self::assertSame(200, $unblocked->status);
    self::assertSame([], $unblocked->payload['blocked_ips'] ?? null);

    $disconnected = $this->request('POST', ['streams', 'disconnect'], [
      'token' => 'hls-valid-test-token',
    ]);
    self::assertSame(200, $disconnected->status);
    self::assertTrue($disconnected->payload['ok'] ?? false);
    self::assertTrue($this->streamState->disconnectRequested('hls-valid-test-token'));
  }

  public function testRejectsInvalidValuesAndDoesNotReadUnsupportedBodies(): void
  {
    $invalidIp = $this->request('POST', ['streams', 'block'], ['ip' => 'not-an-ip']);
    self::assertSame(400, $invalidIp->status);
    self::assertSame('Invalid IP address', $invalidIp->payload['detail'] ?? null);

    $invalidToken = $this->request('POST', ['streams', 'disconnect'], ['token' => '/']);
    self::assertSame(400, $invalidToken->status);
    self::assertSame('Invalid stream token', $invalidToken->payload['detail'] ?? null);

    $bodyWasRead = false;
    $unsupported = $this->handler->handle(
      'PUT',
      ['streams', 'block'],
      static function () use (&$bodyWasRead): array {
        $bodyWasRead = true;
        return [];
      }
    );
    self::assertSame(404, $unsupported->status);
    self::assertFalse($bodyWasRead);
    self::assertFalse($this->handler->supports(['status']));
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
