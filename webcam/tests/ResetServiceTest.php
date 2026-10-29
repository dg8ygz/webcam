<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;

/** Only private temporary state/harmless commands, never a real camera reset. */
final class ResetServiceTest extends TestCase
{
  public function testPrivacyPauseSuppressesResetOnlyWhileFresh(): void
  {
    [$app, $root] = $this->fixture();
    $privacy = new \Webcam\Service\Capture\CameraPrivacyState(
      new \Webcam\Infrastructure\Filesystem\PathResolver(null, null, $root . '/private-ram', null)
    );
    $service = new \Webcam\Service\Status\ResetService($app, $privacy);
    $marker = $root . '/executed';
    $camera = $this->camera([['type' => 'shell', 'command' => 'touch ' . escapeshellarg($marker)]]);
    $report = base64_encode(json_encode(['enabled' => true, 'allowed' => false,
      'until' => time() + 3600, 'suppressed' => true]));
    $privacy->receive('demo', 'command', $report, time());
    $service->execute($camera, 'intentional night pause');
    self::assertFileDoesNotExist($marker);
    self::assertFileDoesNotExist($root . '/state/cameras/demo.json');
    $privacy->receive('demo', 'command', $report, time() - 901);
    $service->execute($camera, 'heartbeat expired');
    self::assertFileExists($marker);
    self::assertSame(1, $app->statusService()->state($camera)['resets_total']);
  }

  public function testInvalidLastStepPreventsAllEarlierSideEffects(): void
  {
    [$app, $root] = $this->fixture();
    $marker = $root . '/executed';
    $camera = $this->camera([
      ['type' => 'shell', 'command' => 'touch ' . escapeshellarg($marker)],
      ['type' => 'delay', 'seconds' => 301],
    ]);
    $app->resetService()->execute($camera, 'test');
    self::assertFileDoesNotExist($marker);
    self::assertFileDoesNotExist($root . '/state/cameras/demo.json');
    self::assertStringContainsString('camera reset configuration invalid', file_get_contents($root . '/log/app.log'));
  }

  public function testTooManyLegacyStepsDoNotExecuteOrRecordReset(): void
  {
    [$app, $root] = $this->fixture();
    $marker = $root . '/executed';
    $app->resetService()->execute($this->camera(array_fill(0, 21, [
      'type' => 'shell', 'command' => 'touch ' . escapeshellarg($marker),
    ])), 'test');
    self::assertFileDoesNotExist($marker);
    self::assertFileDoesNotExist($root . '/state/cameras/demo.json');
  }

  public function testValidShellSequenceKeepsResetAccounting(): void
  {
    [$app, $root] = $this->fixture();
    $marker = $root . '/executed';
    $camera = $this->camera([['type' => 'shell', 'command' => 'touch ' . escapeshellarg($marker), 'timeout_seconds' => 1]]);
    $app->resetService()->execute($camera, 'unit reset');
    self::assertFileExists($marker);
    self::assertSame(1, $app->statusService()->state($camera)['resets_total']);
  }

  public function testExistingShellTimeoutStillTerminatesTheProcessGroup(): void
  {
    [$app, $root] = $this->fixture();
    $started = microtime(true);
    $app->resetService()->execute($this->camera([
      ['type' => 'shell', 'command' => 'sleep 5', 'timeout' => 1],
    ]), 'unit timeout');
    self::assertLessThan(3, microtime(true) - $started);
    self::assertStringContainsString('camera reset shell timed out', file_get_contents($root . '/log/app.log'));
  }

  private function camera(array $sequence): Camera
  {
    return new Camera([
      'id' => 'demo', 'slug' => 'demo', 'name' => 'Demo', 'title' => 'Demo',
      'location' => [], 'mode' => 'active-capture', 'capture' => [], 'storage' => [],
      'status' => ['watchdog' => ['enabled' => true, 'reset_sequence' => $sequence]],
    ]);
  }

  private function fixture(): array
  {
    $root = TestTempDir::ensure() . '/reset-' . bin2hex(random_bytes(5));
    mkdir($root, 0700);
    file_put_contents($root . '/cameras.php', '<?php return [];');
    $config = ['camera_config_path' => $root . '/cameras.php'];
    foreach (['storage', 'archive', 'state', 'log', 'info', 'cache', 'camera_db'] as $path) {
      $config[$path . '_path'] = $root . '/' . $path;
    }
    return [new App($config), $root];
  }
}
