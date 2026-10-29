<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Weather\WeatherCommandRunner;

final class WeatherCommandRunnerTest extends TestCase
{
  public function testFhemAgeFilterWritesOnlyFreshWeatherSidecars(): void
  {
    [$app, $root] = $this->makeApp();
    try {
      $input = $root . '/fhem.html';
      $filter = dirname(__DIR__) . '/bin/weather_fhem.py';
      $command = 'python3 ' . escapeshellarg($filter) . ' < ' . escapeshellarg($input);
      $runner = new WeatherCommandRunner($app);
      foreach ([0, 3600] as $age) {
        $stamp = (new DateTimeImmutable('@' . (time() - $age)))
          ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
        $html = '';
        foreach (['temperature' => 9, 'humidity' => 80, 'dewpoint' => 5,
                  'windSpeed' => 12, 'windDirection' => 145] as $name => $value) {
          $html .= '<div informId="Wetterstation-' . $name . '">' . $value . '</div>';
          $html .= '<div informId="Wetterstation-' . $name . '-ts">' . $stamp . '</div>';
        }
        file_put_contents($input, $html);
        $result = $runner->capture($this->makeCamera([$command]), $root . '/weather', 'image-' . $age);
        if ($age === 0) {
          self::assertNotNull($result);
          self::assertStringContainsString('Temp: 9', file_get_contents($result['path']));
        } else {
          self::assertNull($result);
          self::assertFileDoesNotExist($root . '/weather/image-3600_WX.txt');
          self::assertStringContainsString('reading stale or future', file_get_contents($root . '/log/app.log'));
        }
      }
    } finally { $this->removeTree($root); }
  }

  public function testRedactCommandRemovesCommonSecretForms(): void
  {
    [$app, $root] = $this->makeApp();
    try {
      $runner = new WeatherCommandRunner($app);
      $method = new ReflectionMethod($runner, 'redactCommand');
      $redacted = $method->invoke($runner, 'passwd="fixture-weather-password" wget --http-password=secret https://user:pass@example.test/path?token=abc');

      self::assertStringNotContainsString('fixture-weather-password', $redacted);
      self::assertStringNotContainsString('secret', $redacted);
      self::assertStringNotContainsString('user:pass', $redacted);
      self::assertStringContainsString('[REDACTED]', $redacted);
    } finally {
      $this->removeTree($root);
    }
  }

  public function testRunCommandReturnsOutput(): void
  {
    [$app, $root] = $this->makeApp();
    try {
      $runner = new WeatherCommandRunner($app);
      $method = new ReflectionMethod($runner, 'runCommand');
      $result = $method->invoke($runner, 'printf weather-ok', $this->makeCamera());

      self::assertSame('weather-ok', $result);
    } finally {
      $this->removeTree($root);
    }
  }

  public function testRunCommandReturnsNullOnNonZeroExit(): void
  {
    [$app, $root] = $this->makeApp();
    try {
      $runner = new WeatherCommandRunner($app);
      $method = new ReflectionMethod($runner, 'runCommand');
      $result = $method->invoke($runner, 'echo "curl: resolver failed" >&2; exit 7', $this->makeCamera());

      self::assertNull($result);
    } finally {
      $this->removeTree($root);
    }
  }

  public function testRunCommandTimesOut(): void
  {
    [$app, $root] = $this->makeApp(['weather_command_timeout_seconds' => 1]);
    try {
      $runner = new WeatherCommandRunner($app);
      $method = new ReflectionMethod($runner, 'runCommand');
      $started = microtime(true);
      $result = $method->invoke($runner, "#!/bin/sh\nwhile true; do :; done", $this->makeCamera());

      self::assertNull($result);
      self::assertLessThan(2.5, microtime(true) - $started);
    } finally {
      $this->removeTree($root);
    }
  }

  public function testFailureLogOmitsCommandAndRedactsQuotedDiagnosticSecret(): void
  {
    [$app, $root] = $this->makeApp();
    try {
      $runner = new WeatherCommandRunner($app);
      $method = new ReflectionMethod($runner, 'runCommand');
      $command = "printf '%s\\n' '--password \"dummy weather secret\"' >&2; exit 7";
      self::assertNull($method->invoke($runner, $command, $this->makeCamera()));
      $raw = file_get_contents($root . '/log/app.log');
      self::assertStringNotContainsString('dummy weather secret', $raw);
      $event = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
      self::assertArrayNotHasKey('command_preview', $event['context']);
      self::assertSame(hash('sha256', $command), $event['context']['command_hash']);
      self::assertStringContainsString('[REDACTED]', $event['context']['stderr']);
    } finally { $this->removeTree($root); }
  }

  /**
   * @return array{0:App,1:string}
   */
  private function makeApp(array $overrides = []): array
  {
    $root = sys_get_temp_dir() . '/webcam-weather-test-' . bin2hex(random_bytes(6));
    $configPath = $root . '/cameras.php';
    mkdir($root . '/storage', 0775, true);
    mkdir($root . '/archive', 0775, true);
    mkdir($root . '/state', 0775, true);
    mkdir($root . '/log', 0775, true);
    mkdir($root . '/info', 0775, true);
    mkdir($root . '/cache', 0775, true);
    mkdir($root . '/camera-db', 0775, true);
    $runtime = [
      'weather_command_timeout_seconds' => isset($overrides['weather_command_timeout_seconds'])
        ? (int) $overrides['weather_command_timeout_seconds']
        : 20,
    ];
    file_put_contents(
      $configPath,
      "<?php\n\$GLOBALS['webcam_cameras_meta'] = ['runtime' => " . var_export($runtime, true) . "];\nreturn [];\n"
    );

    return [
      new App(array_replace([
        'camera_config_path' => $configPath,
        'storage_path' => $root . '/storage',
        'archive_path' => $root . '/archive',
        'state_path' => $root . '/state',
        'log_path' => $root . '/log',
        'info_path' => $root . '/info',
        'cache_path' => $root . '/cache',
        'camera_db_path' => $root . '/camera-db',
      ], $overrides)),
      $root,
    ];
  }

  private function makeCamera(array $commands = []): Camera
  {
    return new Camera([
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'testcam',
      'title' => 'Test Cam',
      'location' => [
        'latitude' => 0,
        'longitude' => 0,
        'map_center_offset' => 0,
        'map_center_offset_x' => 0,
      ],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => sys_get_temp_dir() . '/testcam',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'stream' => ['mode' => 'none'],
      'weather_commands' => $commands,
    ]);
  }

  private function removeTree(string $path): void
  {
    if (!is_dir($path)) {
      return;
    }
    $items = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
      $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
  }
}
