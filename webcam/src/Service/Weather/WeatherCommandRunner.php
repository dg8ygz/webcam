<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Process\BoundedProcessRunner;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Führt konfigurierte Wetterkommandos ohne unbegrenzte Laufzeit aus.
 * stdout/stderr werden begrenzt gelesen, Timeout-Prozesse beendet und das
 * Ergebnis strukturiert an CaptureManager zurückgegeben.
 */
final class WeatherCommandRunner
{
  private const OUTPUT_MAX_BYTES = 65536;

  public function __construct(private readonly App $app) {}

  /**
   * @return array{path:string,data:string}|null
   */
  public function capture(Camera $camera, string $targetDir, string $baseName): ?array
  {
    $rawCommands = $camera->weatherCommands();
    if (!$rawCommands) {
      return null;
    }

    $first = $rawCommands[0] ?? '';
    $firstTrimmed = is_string($first) ? ltrim($first) : '';
    if (is_string($firstTrimmed) && $firstTrimmed !== '' && str_starts_with($firstTrimmed, '#!') && count($rawCommands) > 1) {
      $joined = trim(implode(PHP_EOL, array_map(static fn($entry) => is_string($entry) ? rtrim($entry) : '', $rawCommands)));
      $commands = $joined !== '' ? [$joined] : [];
    } else {
      $commands = array_values(array_filter(array_map('trim', $rawCommands)));
    }
    if (!$commands) {
      return null;
    }

    if (!is_dir($targetDir)) {
      if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException("Unable to create weather target directory: {$targetDir}");
      }
    }

    $outputLines = [];
    foreach ($commands as $command) {
      $result = $this->runCommand($command, $camera);
      if ($result === null) {
        continue;
      }
      if ($result !== '') {
        $outputLines[] = $result;
      }
    }

    if (!$outputLines) {
      return null;
    }

    $payload = implode(PHP_EOL, $outputLines);
    $filePath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '_WX.txt';
    if (!AtomicFileWriter::write($filePath, $payload . PHP_EOL)) {
      throw new RuntimeException("Unable to write weather log: {$filePath}");
    }

    return [
      'path' => $filePath,
      'data' => $payload,
    ];
  }

  private function runCommand(string $command, Camera $camera): ?string
  {
    $isScript = str_contains($command, "\n");
    $scriptPath = null;
    $shellCommand = $command;

    if ($isScript) {
      $scriptPath = $this->writeTemporaryScript($command);
      if ($scriptPath === null) {
        $this->app->logger()->warning('weather script could not be written', [
          'camera' => $camera->slug(),
        ]);
        return null;
      }
      $shellCommand = $scriptPath;
    }

    $cmd = $isScript ? [$shellCommand] : ['/bin/sh', '-c', $shellCommand];
    $result = (new BoundedProcessRunner())->run($cmd, $this->commandTimeoutSeconds(), [
      'output_max_bytes' => self::OUTPUT_MAX_BYTES,
      // Wetterdaten stehen meist am Anfang; Diagnoseausgabe darf sie bei sehr
      // gesprächigen Kommandos nicht aus dem Puffer verdrängen.
      'output_retention' => 'head',
      'require_process_group' => true,
    ]);
    if (!$result['started']) {
      $this->app->logger()->warning('weather command failed to start', [
        'camera' => $camera->slug(),
        'command_hash' => $this->commandHash($command),
      ]);
      if ($scriptPath) {
        if (!unlink($scriptPath)) {
          $this->app->logger()->warning('weather script cleanup failed', ['path' => $scriptPath]);
        }
      }
      return null;
    }

    if ($scriptPath) {
      if (!unlink($scriptPath)) {
        $this->app->logger()->warning('weather script cleanup failed', ['path' => $scriptPath]);
      }
    }

    if ($result['timed_out']) {
      $this->app->logger()->warning('weather command timed out', [
        'camera' => $camera->slug(),
        'command_hash' => $this->commandHash($command),
        'timeout_seconds' => $this->commandTimeoutSeconds(),
        'stderr' => $this->redactCommand(trim($result['stderr'])),
      ]);
      return null;
    }

    if ($result['exit_code'] !== 0) {
      $this->app->logger()->warning('weather command returned non-zero exit code', [
        'camera' => $camera->slug(),
        'command_hash' => $this->commandHash($command),
        'exit_code' => $result['exit_code'],
        'stderr' => $this->redactCommand(trim($result['stderr'])),
      ]);
      return null;
    }

    $output = trim($result['stdout']);
    return $output;
  }

  private function commandTimeoutSeconds(): int
  {
    $runtime = $this->app->camerasMeta()['runtime'] ?? [];
    $value = is_array($runtime) && array_key_exists('weather_command_timeout_seconds', $runtime)
      ? $runtime['weather_command_timeout_seconds']
      : $this->app->config('weather_command_timeout_seconds', 20);
    if (!is_numeric($value)) {
      return 20;
    }

    return max(1, min(300, (int) $value));
  }

  private function commandHash(string $command): string
  {
    return hash('sha256', $command);
  }

  private function redactCommand(string $command): string
  {
    $redacted = SecretRedactor::redact($command);

    if (strlen($redacted) > 500) {
      $redacted = substr($redacted, 0, 500) . '...';
    }
    return $redacted;
  }

  private function writeTemporaryScript(string $contents): ?string
  {
    $script = ltrim($contents);
    if (!str_starts_with($script, '#!')) {
      $script = "#!/bin/sh\n" . $contents;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'wx-');
    if ($tmp === false) {
      $this->app->logger()->warning('weather temp script create failed');
      return null;
    }
    if (file_put_contents($tmp, $script . "\n") === false) {
      $this->app->logger()->warning('weather temp script write failed', ['path' => $tmp]);
      if (!unlink($tmp)) {
        $this->app->logger()->warning('weather temp script cleanup failed', ['path' => $tmp]);
      }
      return null;
    }
    if (chmod($tmp, 0700) === false) {
      $this->app->logger()->warning('weather temp script chmod failed', ['path' => $tmp]);
    }
    return $tmp;
  }
}
