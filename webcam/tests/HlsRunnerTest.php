<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Stream\HlsRunner;

final class HlsRunnerTest extends TestCase
{
  public function testLatestClientActivityUsesNewestValidHeartbeat(): void
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'hls-activity-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    file_put_contents($root . '/old.json', json_encode(['last_seen_at' => 100]));
    file_put_contents($root . '/new.json', json_encode(['last_seen_at' => 180]));
    file_put_contents($root . '/future.json', json_encode(['last_seen_at' => 999]));

    self::assertSame(180, HlsRunner::latestClientActivityAt($root, 200));
  }

  public function testRunnerStopsChildAfterIdleTimeoutAndReleasesLock(): void
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'hls-runner-' . bin2hex(random_bytes(4));
    $clients = $root . '/clients';
    mkdir($clients, 0775, true);
    $configPath = $root . '/runner.json';
    $lockPath = $root . '/process.lock';
    $pidFile = $root . '/ffmpeg.pid';
    $logPath = $root . '/ffmpeg.log';
    file_put_contents($configPath, json_encode([
      'command' => [PHP_BINARY, '-r', 'usleep(5000000);'],
      'client_dir' => $clients,
      'process_lock_path' => $lockPath,
      'pid_file' => $pidFile,
      'log_path' => $logPath,
      'slug' => 'test-camera',
      'idle_timeout_seconds' => 1,
      'max_runtime_seconds' => 10,
    ], JSON_THROW_ON_ERROR));

    $started = microtime(true);
    self::assertSame(0, HlsRunner::runFromConfig($configPath));
    self::assertLessThan(4.5, microtime(true) - $started);
    self::assertFileDoesNotExist($configPath);
    self::assertFileDoesNotExist($pidFile);
    self::assertStringContainsString('reason=idle_timeout', (string) file_get_contents($logPath));

    $lock = fopen($lockPath, 'c');
    self::assertIsResource($lock);
    self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
    flock($lock, LOCK_UN);
    fclose($lock);
  }

  public function testRunnerPassesProtectedInputOnlyViaStdinAndRedactsChildLog(): void
  {
    $root = TestTempDir::ensure() . DIRECTORY_SEPARATOR . 'hls-protected-' . bin2hex(random_bytes(4));
    $clients = $root . '/clients';
    mkdir($clients, 0775, true);
    $configPath = $root . '/runner.json';
    $receivedPath = $root . '/received.txt';
    $logPath = $root . '/ffmpeg.log';
    $secret = 'runner-secret-value';
    $manifest = "ffconcat version 1.0\nfile 'rtsp://user:{$secret}@example.test/live'\n";
    $command = [
      PHP_BINARY,
      '-r',
      'file_put_contents($argv[1], stream_get_contents(STDIN)); '
        . '$secret = "runner-" . "secret-value"; '
        . 'fwrite(STDERR, "rtsp://user:{$secret}@example.test/live\\n"); '
        . 'usleep(5000000);',
      $receivedPath,
    ];
    file_put_contents($configPath, json_encode([
      'command' => $command,
      'client_dir' => $clients,
      'process_lock_path' => $root . '/process.lock',
      'pid_file' => $root . '/ffmpeg.pid',
      'log_path' => $logPath,
      'slug' => 'test-camera',
      'stdin_manifest_required' => true,
      'idle_timeout_seconds' => 1,
      'max_runtime_seconds' => 10,
    ], JSON_THROW_ON_ERROR));

    self::assertStringNotContainsString($secret, implode(' ', $command));
    self::assertSame(0, HlsRunner::runFromConfig($configPath, $manifest));
    self::assertSame($manifest, file_get_contents($receivedPath));
    $log = (string) file_get_contents($logPath);
    self::assertStringNotContainsString($secret, $log);
    self::assertStringContainsString('[REDACTED]', $log);
  }
}
