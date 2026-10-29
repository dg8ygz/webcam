<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Process;

/**
 * Führt einen kurzlebigen externen Prozess mit festen Ressourcen-Grenzen aus.
 *
 * Der Aufrufer liefert immer eine Argumentliste. Falls bewusst Shell-Syntax
 * benötigt wird, muss er `/bin/sh`, `-c` selbst angeben. Auf Linux wird der
 * Prozess über `setsid` in eine eigene Prozessgruppe gestellt. So beendet ein
 * Timeout nicht nur die Shell, sondern auch deren noch laufende Kindprozesse.
 */
final class BoundedProcessRunner
{
  public const EXIT_TIMEOUT = 124;
  public const EXIT_START_FAILED = 127;

  private const DEFAULT_OUTPUT_MAX_BYTES = 65536;
  private const ABSOLUTE_OUTPUT_MAX_BYTES = 67108864;
  private const TERMINATE_GRACE_SECONDS = 1.0;

  /**
   * @param list<string> $command
   * @param array{
   *   cwd?:string|null,
   *   environment?:array<string,string>|null,
   *   input?:string|null,
   *   output_max_bytes?:int,
   *   output_retention?:'head'|'tail',
   *   stdout_handler?:callable(string):void,
   *   stderr_handler?:callable(string):void,
   *   isolate_process_group?:bool,
   *   require_process_group?:bool
   * } $options
   * @return array{
   *   started:bool,
   *   exit_code:int,
   *   timed_out:bool,
   *   stdout:string,
   *   stderr:string,
   *   timeout_seconds:int,
   *   process_group_isolated:bool
   * }
   */
  public function run(array $command, int $timeoutSeconds, array $options = []): array
  {
    // 1. Eingaben und Ressourcenlimits normalisieren, bevor ein Kindprozess
    //    gestartet wird. Shell-Auswertung entsteht nur, wenn der Aufrufer
    //    ausdrücklich `/bin/sh -c` als Argumentliste übergibt.
    $timeoutSeconds = max(1, $timeoutSeconds);
    if (!$this->validCommand($command) || !function_exists('proc_open')) {
      return $this->startFailure($timeoutSeconds, 'invalid or unavailable process command');
    }

    $outputMaxBytes = max(
      1024,
      min(self::ABSOLUTE_OUTPUT_MAX_BYTES, (int) ($options['output_max_bytes'] ?? self::DEFAULT_OUTPUT_MAX_BYTES))
    );
    $retention = ($options['output_retention'] ?? 'tail') === 'head' ? 'head' : 'tail';
    $isolate = ($options['isolate_process_group'] ?? true) !== false;
    [$processCommand, $groupIsolated] = $this->isolatedCommand($command, $isolate);
    if (($options['require_process_group'] ?? false) === true && !$groupIsolated) {
      return $this->startFailure($timeoutSeconds, 'isolated process group is unavailable');
    }

    // 2. stdin/stdout/stderr als getrennte Pipes öffnen. `setsid` erzeugt auf
    //    Linux eine eigene Prozessgruppe, sodass ein Timeout auch Enkelprozesse
    //    erfassen kann.
    $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
    $pipes = [];
    $process = @proc_open(
      $processCommand,
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $pipes,
      isset($options['cwd']) && is_string($options['cwd']) ? $options['cwd'] : null,
      isset($options['environment']) && is_array($options['environment']) ? $options['environment'] : null,
      ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
      return $this->startFailure($timeoutSeconds, 'process could not be started');
    }
    $initialStatus = proc_get_status($process);
    $processPid = is_int($initialStatus['pid'] ?? null) ? (int) $initialStatus['pid'] : 0;

    // 3. Alle Kanäle nichtblockierend bedienen. Auch ein Kind, das stdin
    //    überhaupt nicht liest, unterliegt derselben Laufzeitgrenze.
    $input = isset($options['input']) && is_string($options['input']) ? $options['input'] : '';
    $inputOffset = 0;
    $inputFailed = false;
    foreach ($pipes as $pipe) {
      stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $exitCode = -1;
    $timedOut = false;

    // 4. Beide Ausgabekanäle fortlaufend leeren. So kann ein gesprächiger
    //    Prozess nicht an einer vollen Pipe hängen; im Speicher bleibt trotzdem
    //    nur der konfigurierte Kopf oder das Ende der Ausgabe.
    try {
      while (true) {
        if (is_resource($pipes[0])) {
          if ($inputOffset < strlen($input)) {
            $written = @fwrite($pipes[0], substr($input, $inputOffset, 65536));
            if ($written === false) {
              $inputFailed = true;
            } else {
              $inputOffset += $written;
            }
          }
          if ($inputFailed || $inputOffset === strlen($input)) {
            fclose($pipes[0]);
          }
        }
        $stdout = $this->readPipe($pipes[1], $stdout, $outputMaxBytes, $retention, $options['stdout_handler'] ?? null);
        $stderr = $this->readPipe($pipes[2], $stderr, $outputMaxBytes, $retention, $options['stderr_handler'] ?? null);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
          if (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
            $exitCode = (int) $status['exitcode'];
          }
          if (feof($pipes[1]) && feof($pipes[2])) {
            break;
          }
        }
        if ($inputFailed) {
          $this->terminate($process, $groupIsolated, $processPid, 9);
          break;
        }
        if (hrtime(true) / 1e9 >= $deadline) {
          // 5. Beim Timeout zuerst geordnet TERM senden. Nach der kurzen Schonfrist
          //    beendet KILL auch Kinder, deren Eltern-Shell bereits ausgestiegen ist.
          $timedOut = true;
          $groupSignalled = $this->terminate($process, $groupIsolated, $processPid, 15);
          $killDeadline = hrtime(true) / 1e9 + self::TERMINATE_GRACE_SECONDS;
          do {
            usleep(50000);
            $stdout = $this->readPipe($pipes[1], $stdout, $outputMaxBytes, $retention, $options['stdout_handler'] ?? null);
            $stderr = $this->readPipe($pipes[2], $stderr, $outputMaxBytes, $retention, $options['stderr_handler'] ?? null);
            $status = proc_get_status($process);
          } while (($status['running'] ?? false) && hrtime(true) / 1e9 < $killDeadline);
          if (($status['running'] ?? false) || ($groupSignalled && $this->processGroupExists($processPid))) {
            $this->terminate($process, $groupIsolated, $processPid, 9);
          }
          break;
        }
        $read = array_filter([$pipes[1], $pipes[2]], static fn ($pipe) => !feof($pipe));
        $write = is_resource($pipes[0]) ? [$pipes[0]] : [];
        $except = null;
        if ($read !== [] || $write !== []) {
          @stream_select($read, $write, $except, 0, 50000);
        } else {
          usleep(50000);
        }
      }

      // 6. Restdaten aus den Pipes lesen, Handles schließen und einen stabilen
      //    Ergebnisdatensatz zurückgeben. Timeout hat unabhängig vom Tool immer
      //    den einheitlichen Exitcode 124.
      if (is_resource($pipes[0])) {
        fclose($pipes[0]);
      }
      $stdout = $this->readPipe($pipes[1], $stdout, $outputMaxBytes, $retention, $options['stdout_handler'] ?? null);
      $stderr = $this->readPipe($pipes[2], $stderr, $outputMaxBytes, $retention, $options['stderr_handler'] ?? null);
    } catch (\Throwable $error) {
      $this->terminate($process, $groupIsolated, $processPid, 9);
      foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
          fclose($pipe);
        }
      }
      proc_close($process);
      throw $error;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExitCode = proc_close($process);
    if (!$timedOut && ($inputFailed || $inputOffset < strlen($input))) {
      return $this->startFailure($timeoutSeconds, 'process input could not be written');
    }
    if ($timedOut) {
      $exitCode = self::EXIT_TIMEOUT;
    } elseif ($exitCode < 0 && is_int($closedExitCode) && $closedExitCode >= 0) {
      $exitCode = $closedExitCode;
    }

    return [
      'started' => true,
      'exit_code' => $exitCode,
      'timed_out' => $timedOut,
      'stdout' => $stdout,
      'stderr' => $stderr,
      'timeout_seconds' => $timeoutSeconds,
      'process_group_isolated' => $groupIsolated,
    ];
  }

  /** @param list<string> $command */
  private function validCommand(array $command): bool
  {
    if ($command === []) {
      return false;
    }
    foreach ($command as $argument) {
      if (!is_string($argument) || str_contains($argument, "\0")) {
        return false;
      }
    }
    return $command[0] !== '';
  }

  /** @param list<string> $command @return array{0:list<string>,1:bool} */
  private function isolatedCommand(array $command, bool $requested): array
  {
    $setsid = '/usr/bin/setsid';
    if (
      !$requested
      || !is_executable($setsid)
      || !function_exists('posix_kill')
      || !function_exists('posix_getpgid')
    ) {
      return [$command, false];
    }
    return [[$setsid, '--wait', '--', ...$command], true];
  }

  /**
   * @param resource $pipe
   * @param callable(string):void|null $handler
   */
  private function readPipe(
    $pipe,
    string $buffer,
    int $limit,
    string $retention,
    mixed $handler
  ): string {
    $chunk = stream_get_contents($pipe, 65536);
    if (!is_string($chunk) || $chunk === '') {
      return $buffer;
    }
    if (is_callable($handler)) {
      $handler($chunk);
    }
    $buffer .= $chunk;
    if (strlen($buffer) <= $limit) {
      return $buffer;
    }
    return $retention === 'head' ? substr($buffer, 0, $limit) : substr($buffer, -$limit);
  }

  /** @param resource $process */
  private function terminate($process, bool $groupIsolated, int $processPid, int $signal): bool
  {
    if (
      $groupIsolated
      && $processPid > 1
      && (@posix_getpgid($processPid) === $processPid || $this->processGroupExists($processPid))
    ) {
      @posix_kill(-$processPid, $signal);
      return true;
    }
    @proc_terminate($process, $signal);
    return false;
  }

  private function processGroupExists(int $processPid): bool
  {
    return $processPid > 1 && function_exists('posix_kill') && @posix_kill(-$processPid, 0);
  }

  /**
   * @return array{started:bool,exit_code:int,timed_out:bool,stdout:string,stderr:string,timeout_seconds:int,process_group_isolated:bool}
   */
  private function startFailure(int $timeoutSeconds, string $message): array
  {
    return [
      'started' => false,
      'exit_code' => self::EXIT_START_FAILED,
      'timed_out' => false,
      'stdout' => '',
      'stderr' => $message,
      'timeout_seconds' => $timeoutSeconds,
      'process_group_isolated' => false,
    ];
  }
}
