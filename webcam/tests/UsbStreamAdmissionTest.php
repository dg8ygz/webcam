<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Stream\{StreamState, UsbStream, UsbStreamWindow};

/** Actual admission handler + RAM-like isolated state, no relay or live DB. */
final class UsbStreamAdmissionTest extends TestCase
{
  #[RunInSeparateProcess]
  #[PreserveGlobalState(false)]
  public function testNewViewerWaitsButExistingViewerContinuesAtMinuteNine(): void
  {
    require_once __DIR__ . '/../src/Service/Stream/usb_functions.php';
    $root = TestTempDir::ensure() . '/usb-admit-' . bin2hex(random_bytes(4));
    mkdir($root, 0700, true);
    file_put_contents($root . '/cameras.php', '<?php return [];');
    $app = new App(['state_path' => $root, 'log_path' => $root, 'trusted_proxies' => [],
      'camera_config_path' => $root . '/cameras.php', 'timezone' => 'Europe/Berlin',
      'storage_path' => $root, 'archive_path' => $root, 'cache_path' => $root]);
    $state = new StreamState($app);
    $usb = new UsbStream($app->paths());
    mkdir($usb->directory('test'), 0700, true);
    file_put_contents($usb->directory('test') . '/registration.json', json_encode(['port' => 23099, 'token' => str_repeat('a', 64)]));
    $window = static fn(bool $known): array => UsbStreamWindow::decision(1789318800 + 540, true, $known);
    $_SERVER = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'viewer-test'];
    $request = static function (bool $probe, array $query) use ($usb, $state, $window): array {
      $_GET = $query;
      http_response_code(200);
      ob_start();
      handle_usb_hls_request($usb, $state, 'test', $probe, 1, $window);
      return [http_response_code(), ob_get_clean()];
    };
    // A resume flag does not create an existing client or start the camera.
    [$code, $body] = $request(true, ['resume' => '1']);
    self::assertFalse(json_decode($body, true)['ready']);
    self::assertFileDoesNotExist($usb->directory('test') . '/demand');
    self::assertSame(503, $request(false, [])[0]);
    self::assertSame(0, $state->viewerCount('test'));

    // Simulate the same viewer admitted BEFORE the boundary, then fetch a
    // cached valid playlist. This exercises the real handler, not only policy.
    $client = $state->registerHlsRequest('test', $_SERVER);
    $playlist = "#EXTM3U\n#EXTINF:2.0,\nseg_1234567890abcdef_000001.ts\n";
    file_put_contents($usb->directory('test') . '/index.m3u8', $playlist);
    self::assertFalse(json_decode($request(true, [])[1], true)['ready'], 'New Start still waits');
    $resume = json_decode($request(true, ['resume' => '1'])[1], true);
    self::assertTrue($resume['ok'], 'Existing client does not hit its own client limit');
    self::assertTrue($resume['ready']);
    self::assertSame(200, $request(false, [])[0]);
    self::assertTrue($usb->command('test')['active'], 'Pi demand survives the boundary');

    $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
    self::assertSame(503, $request(false, [])[0], 'Another viewer must wait');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    $client['last_seen_at'] = time() - 1000;
    file_put_contents($root . '/stream-hls/test/clients/' . $client['token'] . '.json', json_encode($client));
    self::assertFalse(json_decode($request(true, ['resume' => '1'])[1], true)['ready'], 'Expired viewers cannot resume through the guard');
  }
}
