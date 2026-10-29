<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Status\StatusService;
use Webcam\Service\Status\StatusWeatherReader;

final class StatusServiceTest extends TestCase
{
  public function testRejectedWeatherClearsPreviousValuesWithoutSidecarFallback(): void
  {
    [$app, $camera, $root] = $this->makeFixture();
    $current = $root . '/current/demo';
    mkdir($current, 0775, true);
    $old = $current . '/latest_wx.txt';
    file_put_contents($old, 'Temp: 8.9 C');
    $service = new StatusService($app);
    foreach (['success', 'duplicate'] as $case) {
      $service->recordSuccess($camera, weatherPath: $old, weatherData: 'Temp: 8.9 C');
      self::assertSame('Temp: 8.9 C', $service->state($camera)['last_weather_data']);
      if ($case === 'success') {
        $service->recordSuccess($camera, weatherData: '');
      } else {
        $service->recordDuplicate($camera, 'duplicate', weatherData: '');
      }
      // Read through a new service, as a subsequent HTTP request would do.
      $state = (new StatusService($app))->state($camera);
      self::assertSame('', $state['last_weather_data']);
      self::assertNull($state['last_weather_path']);
      self::assertNull($state['last_weather_at']);
      self::assertGreaterThan(0, $state['last_weather_checked_at']);
      self::assertFileExists($old); // Historical source data are preserved.
    }
    $service->recordSuccess($camera, weatherPath: $old, weatherData: 'Temp: 10 C');
    self::assertSame('Temp: 10 C', $service->state($camera)['last_weather_data']);
  }

  public function testStatusReadDoesNotPersistWeatherFallback(): void
  {
    [$app, $camera, $root] = $this->makeFixture();
    $current = $root . '/current/demo';
    mkdir($current, 0775, true);
    file_put_contents($current . '/latest_wx.txt', 'Temp: 12.3 C');
    $stateDir = $root . '/state/cameras';
    mkdir($stateDir, 0775, true);
    $stateFile = $stateDir . '/demo.json';
    $initialState = "{}\n";
    file_put_contents($stateFile, $initialState);

    $service = new StatusService($app);
    // Der Legacy-Sidecar-Leser ist bewusst aus dem schreibenden Statusdienst
    // herausgelöst. Sein öffentlicher, read-only Vertrag ersetzt den früheren
    // Reflection-Zugriff auf eine private Methode.
    self::assertNotNull((new StatusWeatherReader($app))->currentWeather($camera));

    $state = $service->state($camera);

    self::assertSame('ok', $state['weather_quality']['status'], json_encode($state['weather_quality']));
    self::assertSame('Temp: 12.3 C', $state['last_weather_data']);
    self::assertSame($initialState, file_get_contents($stateFile));
  }

  public function testConcurrentFailuresDoNotLoseCounters(): void
  {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec') || !function_exists('pcntl_waitpid')) {
      self::markTestSkipped('pcntl is required for the concurrency regression test');
    }

    [$app, $camera, $root] = $this->makeFixture();
    $children = [];
    $workers = 4;
    $writesPerWorker = 5;

    for ($worker = 0; $worker < $workers; $worker++) {
      $pid = pcntl_fork();
      self::assertNotSame(-1, $pid, 'Unable to fork status test worker');
      if ($pid === 0) {
        pcntl_exec(PHP_BINARY, [
          __DIR__ . '/Support/status_worker.php',
          $root,
          (string) $writesPerWorker,
        ]);
        exit(127);
      }
      $children[] = $pid;
    }

    foreach ($children as $pid) {
      $status = 0;
      pcntl_waitpid($pid, $status);
      self::assertTrue(pcntl_wifexited($status));
      self::assertSame(0, pcntl_wexitstatus($status));
    }

    $state = (new StatusService($app))->state($camera);
    $expected = $workers * $writesPerWorker;
    self::assertSame($expected, $state['total_failures']);
    self::assertSame($expected, $state['consecutive_failures']);
    self::assertCount($expected, $state['events']);
  }

  public function testFailureCredentialsAreRedactedFromStateAndCaptureLog(): void
  {
    [$app, $camera, $root] = $this->makeFixture();
    $service = new StatusService($app);
    $service->recordFailure(
      $camera,
      'RTSP failed for rtsp://status-user:status-secret@example.test/live?token=query-secret'
    );

    $statePath = $root . '/state/cameras/demo.json';
    $captureLog = $root . '/log/capture/demo.log';
    $combined = (string) file_get_contents($statePath) . (string) file_get_contents($captureLog);
    foreach (['status-user', 'status-secret', 'query-secret'] as $secret) {
      self::assertStringNotContainsString($secret, $combined);
    }
    self::assertStringContainsString('[REDACTED]', $combined);
  }

  public function testDuplicateStateStaysActiveUntilNextRealSuccess(): void
  {
    [$app, $camera] = $this->makeFixture();
    $service = new StatusService($app);

    $service->recordDuplicate($camera, 'Duplicate snapshot discarded');
    $duplicateState = $service->state($camera);
    self::assertTrue($duplicateState['duplicate_active']);
    self::assertSame(1, $duplicateState['consecutive_failures']);
    self::assertSame('Duplicate snapshot discarded', $duplicateState['last_failure_note']);

    $service->recordSuccess($camera, 'Snapshot stored');
    $recoveredState = $service->state($camera);
    self::assertFalse($recoveredState['duplicate_active']);
    self::assertSame(0, $recoveredState['consecutive_failures']);
  }

  /** @return array{App,Camera,string} */
  private function makeFixture(): array
  {
    $root = TestTempDir::ensure() . '/status-' . bin2hex(random_bytes(4));
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db', 'demo'] as $dir) {
      mkdir($root . '/' . $dir, 0775, true);
    }

    $cameraConfig = $root . '/cameras.php';
    $config = [[
      'id' => 'demo',
      'slug' => 'demo',
      'name' => 'Demo',
      'title' => 'Demo',
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
    ]];
    file_put_contents($cameraConfig, "<?php\nreturn " . var_export($config, true) . ";\n");

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
    $camera = $app->cameras()->find('demo');
    self::assertInstanceOf(Camera::class, $camera);

    return [$app, $camera, $root];
  }
}
