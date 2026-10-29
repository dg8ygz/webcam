<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Stream\StreamState;

final class StreamStateTest extends TestCase
{
  public function testUntrustedRemoteAddrCannotSpoofForwardedIp(): void
  {
    $state = new StreamState($this->makeApp(['127.0.0.1']));

    $identity = $state->clientIdentity([
      'REMOTE_ADDR' => '203.0.113.10',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
      'HTTP_X_REAL_IP' => '198.51.100.21',
      'HTTP_CF_CONNECTING_IP' => '198.51.100.22',
      'HTTP_USER_AGENT' => 'test-agent',
    ]);

    self::assertSame('203.0.113.10', $identity['ip']);
    self::assertSame('remote_addr', $identity['ip_source']);
  }

  public function testTrustedProxyMayProvideForwardedIp(): void
  {
    $state = new StreamState($this->makeApp(['127.0.0.1']));

    $identity = $state->clientIdentity([
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 203.0.113.30',
      'HTTP_USER_AGENT' => 'test-agent',
    ]);

    self::assertSame('203.0.113.30', $identity['ip']);
    self::assertSame('x_forwarded_for', $identity['ip_source']);
  }

  public function testTrustedProxyChainStopsAtFirstUntrustedHop(): void
  {
    $state = new StreamState($this->makeApp(['10.0.0.0/8', '192.0.2.0/24']));

    $identity = $state->clientIdentity([
      'REMOTE_ADDR' => '10.12.3.4',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 203.0.113.23, 192.0.2.8',
      'HTTP_USER_AGENT' => 'test-agent',
    ]);

    self::assertSame('203.0.113.23', $identity['ip']);
    self::assertSame('x_forwarded_for', $identity['ip_source']);
  }

  public function testMalformedForwardedChainFailsClosed(): void
  {
    $state = new StreamState($this->makeApp(['127.0.0.1']));
    $identity = $state->clientIdentity([
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20, invalid-hop',
    ]);

    self::assertSame('127.0.0.1', $identity['ip']);
    self::assertSame('remote_addr', $identity['ip_source']);
  }

  public function testIpv6ClientIsCanonicalized(): void
  {
    $state = new StreamState($this->makeApp(['127.0.0.1']));
    $identity = $state->clientIdentity([
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_FOR' => '2001:0db8:0:0:0:0:0:1',
    ]);

    self::assertSame('2001:db8::1', $identity['ip']);
  }

  /**
   * @param list<string> $trustedProxies
   */
  private function makeApp(array $trustedProxies): App
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'stream-state-' . bin2hex(random_bytes(4));
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db'] as $dir) {
      mkdir($root . DIRECTORY_SEPARATOR . $dir, 0775, true);
    }
    $cameraConfig = $root . DIRECTORY_SEPARATOR . 'cameras.php';
    file_put_contents($cameraConfig, "<?php\nreturn [];\n");

    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $cameraConfig,
      'cache_path' => $root . DIRECTORY_SEPARATOR . 'cache',
      'storage_path' => $root . DIRECTORY_SEPARATOR . 'storage',
      'archive_path' => $root . DIRECTORY_SEPARATOR . 'archive',
      'state_path' => $root . DIRECTORY_SEPARATOR . 'state',
      'log_path' => $root . DIRECTORY_SEPARATOR . 'log',
      'info_path' => $root . DIRECTORY_SEPARATOR . 'info',
      'camera_db_path' => $root . DIRECTORY_SEPARATOR . 'camera-db',
      'trusted_proxies' => $trustedProxies,
    ]);
  }
}
