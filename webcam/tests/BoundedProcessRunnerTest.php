<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Process\BoundedProcessRunner;

final class BoundedProcessRunnerTest extends TestCase
{
  public function testCapturesSeparateOutputAndExitCode(): void
  {
    $result = (new BoundedProcessRunner())->run(
      ['/bin/sh', '-c', 'printf stdout-value; printf stderr-value >&2; exit 7'],
      2
    );

    self::assertTrue($result['started']);
    self::assertFalse($result['timed_out']);
    self::assertSame(7, $result['exit_code']);
    self::assertSame('stdout-value', $result['stdout']);
    self::assertSame('stderr-value', $result['stderr']);
  }

  public function testPassesInputWithoutPuttingItInArguments(): void
  {
    $result = (new BoundedProcessRunner())->run(['/bin/sh', '-c', 'read value; printf "%s" "$value"'], 2, [
      'input' => "protected-value\n",
    ]);

    self::assertSame(0, $result['exit_code']);
    self::assertSame('protected-value', $result['stdout']);
  }

  public function testKeepsConfiguredEndOfBoundedOutput(): void
  {
    $result = (new BoundedProcessRunner())->run(['/bin/sh', '-c', 'printf start; head -c 4096 /dev/zero | tr "\\0" x; printf end'], 2, [
      'output_max_bytes' => 1024,
      'output_retention' => 'tail',
    ]);

    self::assertSame(1024, strlen($result['stdout']));
    self::assertStringNotContainsString('start', $result['stdout']);
    self::assertStringEndsWith('end', $result['stdout']);
  }

  public function testRequiredIsolationFailsClosedWhenItWasDisabled(): void
  {
    $result = (new BoundedProcessRunner())->run(['/bin/true'], 2, [
      'isolate_process_group' => false,
      'require_process_group' => true,
    ]);

    self::assertFalse($result['started']);
    self::assertSame(BoundedProcessRunner::EXIT_START_FAILED, $result['exit_code']);
    self::assertStringContainsString('process group', $result['stderr']);
  }

  public function testTimeoutIncludesBlockedStandardInput(): void
  {
    $start = microtime(true);
    $result = (new BoundedProcessRunner())->run([PHP_BINARY, '-r', 'sleep(5);'], 1, [
      'input' => str_repeat('x', 1024 * 1024),
    ]);
    self::assertTrue($result['timed_out']);
    self::assertSame(124, $result['exit_code']);
    self::assertLessThan(2.5, microtime(true) - $start);
  }

  public function testSimultaneousLargeInputAndOutputCannotDeadlock(): void
  {
    $result = (new BoundedProcessRunner())->run([PHP_BINARY, '-r',
      'fwrite(STDOUT, str_repeat("o", 262144)); fwrite(STDERR, str_repeat("e", 262144)); echo strlen(stream_get_contents(STDIN));',
    ], 3, ['input' => str_repeat('i', 1048576), 'output_max_bytes' => 1024]);
    self::assertFalse($result['timed_out']);
    self::assertSame(0, $result['exit_code']);
    self::assertSame(1024, strlen($result['stdout']));
    self::assertSame(1024, strlen($result['stderr']));
    self::assertStringEndsWith('1048576', $result['stdout']);
  }

  public function testOutputHandlerExceptionDoesNotLeaveTheChildRunning(): void
  {
    $pid = 0;
    try {
      (new BoundedProcessRunner())->run([PHP_BINARY, '-r', 'echo getmypid(); fflush(STDOUT); sleep(10);'], 3, [
        'require_process_group' => true,
        'stdout_handler' => static function (string $chunk) use (&$pid): void {
          $pid = (int) $chunk;
          throw new RuntimeException('fixture handler failure');
        },
      ]);
      self::fail('Handler exception was lost');
    } catch (RuntimeException $error) {
      self::assertSame('fixture handler failure', $error->getMessage());
      self::assertGreaterThan(1, $pid);
      self::assertFalse(posix_kill($pid, 0));
    }
  }

  public function testTimeoutTerminatesShellChildProcessGroup(): void
  {
    if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
      self::markTestSkipped('POSIX process-group support is unavailable');
    }

    $pidFile = tempnam(sys_get_temp_dir(), 'process-runner-child-');
    self::assertIsString($pidFile);
    $childPid = 0;
    try {
      // Das Kind ignoriert TERM absichtlich. Nur ein anschließendes KILL an
      // dieselbe Prozessgruppe kann verhindern, dass es verwaist weiterläuft.
      $shell = '/bin/sh -c \'trap "" TERM; while :; do sleep 1; done\' & child=$!; printf "%s" "$child" > '
        . escapeshellarg($pidFile)
        . '; wait';
      $started = microtime(true);
      $result = (new BoundedProcessRunner())->run(['/bin/sh', '-c', $shell], 1);

      self::assertTrue($result['process_group_isolated']);
      self::assertTrue($result['timed_out']);
      self::assertSame(BoundedProcessRunner::EXIT_TIMEOUT, $result['exit_code']);
      self::assertLessThan(3.0, microtime(true) - $started);

      $childPid = (int) trim((string) file_get_contents($pidFile));
      self::assertGreaterThan(1, $childPid);
      $deadline = microtime(true) + 2.0;
      while (@posix_kill($childPid, 0) && microtime(true) < $deadline) {
        usleep(50000);
      }
      self::assertFalse(@posix_kill($childPid, 0), 'child process survived the timeout');
    } finally {
      if ($childPid > 1 && @posix_kill($childPid, 0)) {
        @posix_kill($childPid, 9);
      }
      @unlink($pidFile);
    }
  }
}
