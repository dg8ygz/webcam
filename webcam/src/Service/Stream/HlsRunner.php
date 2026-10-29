<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Fuehrt den geteilten HLS-ffmpeg-Prozess ausserhalb des HTTP-Workers aus.
 *
 * Der Runner haelt den kameraweiten Prozess-Lock, beobachtet die vorhandenen
 * HLS-Client-Heartbeats und beendet ffmpeg kontrolliert, sobald laenger als die
 * konfigurierte Frist kein Zuschauer mehr aktiv war.
 */
final class HlsRunner
{
  private const POLL_MICROSECONDS = 250000;
  private const TERMINATE_GRACE_SECONDS = 3;

  public static function runFromConfig(string $configPath, string $inputManifest = ''): int
  {
    $config = self::readConfig($configPath);
    @unlink($configPath);
    if ($config === null) {
      return 64;
    }

    $command = $config['command'];
    $clientDir = $config['client_dir'];
    $processLockPath = $config['process_lock_path'];
    $pidFile = $config['pid_file'];
    $logPath = $config['log_path'];
    $slug = $config['slug'];
    $stdinManifestRequired = $config['stdin_manifest_required'];
    $idleTimeoutSeconds = $config['idle_timeout_seconds'];
    $maxRuntimeSeconds = $config['max_runtime_seconds'];
    if (
      $stdinManifestRequired
      && ($inputManifest === '' || !str_starts_with($inputManifest, "ffconcat version 1.0\n"))
    ) {
      self::appendLog($logPath, 'runner_protected_input_missing', $slug);
      return 65;
    }

    $lock = @fopen($processLockPath, 'c');
    if (!is_resource($lock)) {
      self::appendLog($logPath, 'runner_lock_open_failed', $slug);
      return 73;
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
      fclose($lock);
      self::appendLog($logPath, 'runner_lock_already_held', $slug);
      return 75;
    }

    $process = null;
    $childPid = null;
    $pipes = [];
    $stderrBuffer = '';
    try {
      $process = @proc_open(
        $command,
        [
          0 => $stdinManifestRequired ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
          1 => ['file', '/dev/null', 'w'],
          2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
      );
      if (!is_resource($process)) {
        self::appendLog($logPath, 'ffmpeg_start_failed', $slug);
        return 70;
      }
      if ($stdinManifestRequired) {
        if (!isset($pipes[0]) || !is_resource($pipes[0]) || !RtspInput::writeManifest($pipes[0], $inputManifest)) {
          self::appendLog($logPath, 'ffmpeg_protected_input_failed', $slug);
          self::terminateProcess($process);
          return 74;
        }
        fclose($pipes[0]);
        unset($pipes[0]);
      }
      if (isset($pipes[2]) && is_resource($pipes[2])) {
        stream_set_blocking($pipes[2], false);
      }

      $status = proc_get_status($process);
      $childPid = isset($status['pid']) ? (int) $status['pid'] : null;
      if ($childPid !== null && $childPid > 0) {
        AtomicFileWriter::write($pidFile, $childPid . PHP_EOL, null, 0664);
      }

      $startedAt = time();
      $lastActivityAt = $startedAt;
      $stopReason = null;
      $childExitCode = null;
      self::appendLog(
        $logPath,
        sprintf(
          'runner_started pid=%d idle_timeout=%d max_runtime=%d',
          $childPid ?? 0,
          $idleTimeoutSeconds,
          $maxRuntimeSeconds
        ),
        $slug
      );

      while (true) {
        self::drainStderr($pipes[2] ?? null, $stderrBuffer, $logPath, false);
        $status = proc_get_status($process);
        if (!$status['running']) {
          $childExitCode = (int) ($status['exitcode'] ?? -1);
          $stopReason = 'ffmpeg_exit';
          break;
        }

        $now = time();
        $latestActivity = self::latestClientActivityAt($clientDir, $now);
        if ($latestActivity !== null) {
          $lastActivityAt = max($lastActivityAt, $latestActivity);
        }
        if (($now - $lastActivityAt) >= $idleTimeoutSeconds) {
          $stopReason = 'idle_timeout';
          break;
        }
        if (($now - $startedAt) >= $maxRuntimeSeconds) {
          $stopReason = 'max_runtime';
          break;
        }

        usleep(self::POLL_MICROSECONDS);
      }

      if ($stopReason !== 'ffmpeg_exit') {
        self::terminateProcess($process);
      }
      self::drainStderr($pipes[2] ?? null, $stderrBuffer, $logPath, true);
      if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
        unset($pipes[2]);
      }
      $closeCode = proc_close($process);
      $process = null;

      self::appendLog(
        $logPath,
        sprintf(
          'runner_stopped reason=%s exit=%d',
          $stopReason ?? 'unknown',
          $childExitCode ?? $closeCode
        ),
        $slug
      );

      if (in_array($stopReason, ['idle_timeout', 'max_runtime'], true)) {
        return 0;
      }
      $exitCode = $childExitCode ?? $closeCode;
      return $exitCode >= 0 ? $exitCode : 1;
    } finally {
      foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
          fclose($pipe);
        }
      }
      if (is_resource($process)) {
        self::terminateProcess($process);
        proc_close($process);
      }
      if ($childPid !== null && self::pidFileMatches($pidFile, $childPid)) {
        @unlink($pidFile);
      }
      @flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  public static function latestClientActivityAt(string $clientDir, ?int $now = null): ?int
  {
    if (!is_dir($clientDir)) {
      return null;
    }
    $now ??= time();
    $latest = null;
    foreach (glob(rtrim($clientDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
      if (!is_file($path) || !is_readable($path)) {
        continue;
      }
      $raw = @file_get_contents($path);
      $decoded = is_string($raw) ? json_decode($raw, true) : null;
      $lastSeen = is_array($decoded) ? (int) ($decoded['last_seen_at'] ?? 0) : 0;
      if ($lastSeen <= 0) {
        $mtime = @filemtime($path);
        $lastSeen = is_int($mtime) ? $mtime : 0;
      }
      if ($lastSeen <= 0) {
        continue;
      }
      if ($lastSeen > ($now + 5)) {
        continue;
      }
      $latest = $latest === null ? $lastSeen : max($latest, $lastSeen);
    }
    return $latest;
  }

  /** @return null|array{command:list<string>,client_dir:string,process_lock_path:string,pid_file:string,log_path:string,slug:string,stdin_manifest_required:bool,idle_timeout_seconds:int,max_runtime_seconds:int} */
  private static function readConfig(string $configPath): ?array
  {
    if (!is_file($configPath) || !is_readable($configPath)) {
      return null;
    }
    $raw = @file_get_contents($configPath);
    $config = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($config)) {
      return null;
    }

    $command = $config['command'] ?? null;
    if (!is_array($command) || $command === []) {
      return null;
    }
    foreach ($command as $argument) {
      if (!is_string($argument)) {
        return null;
      }
    }
    $command = array_values($command);
    if ($command === []) {
      return null;
    }

    $requiredPaths = [];
    foreach (['client_dir', 'process_lock_path', 'pid_file', 'log_path'] as $key) {
      $value = isset($config[$key]) && is_string($config[$key]) ? trim($config[$key]) : '';
      if ($value === '') {
        return null;
      }
      $requiredPaths[$key] = $value;
    }

    $slug = isset($config['slug']) && is_string($config['slug']) ? trim($config['slug']) : '';
    if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
      return null;
    }
    $idleTimeout = (int) ($config['idle_timeout_seconds'] ?? 0);
    $maxRuntime = (int) ($config['max_runtime_seconds'] ?? 0);
    if ($idleTimeout <= 0 || $maxRuntime <= 0) {
      return null;
    }

    return [
      'command' => $command,
      'client_dir' => $requiredPaths['client_dir'],
      'process_lock_path' => $requiredPaths['process_lock_path'],
      'pid_file' => $requiredPaths['pid_file'],
      'log_path' => $requiredPaths['log_path'],
      'slug' => $slug,
      'stdin_manifest_required' => ($config['stdin_manifest_required'] ?? false) === true,
      'idle_timeout_seconds' => $idleTimeout,
      'max_runtime_seconds' => $maxRuntime,
    ];
  }

  /** @param resource $process */
  private static function terminateProcess($process): void
  {
    $status = proc_get_status($process);
    if (!$status['running']) {
      return;
    }
    @proc_terminate($process, 15);
    $deadline = microtime(true) + self::TERMINATE_GRACE_SECONDS;
    while (microtime(true) < $deadline) {
      usleep(100000);
      $status = proc_get_status($process);
      if (!$status['running']) {
        return;
      }
    }
    @proc_terminate($process, 9);
  }

  private static function pidFileMatches(string $pidFile, int $pid): bool
  {
    $stored = @file_get_contents($pidFile);
    return is_string($stored) && trim($stored) === (string) $pid;
  }

  private static function appendLog(string $logPath, string $message, string $slug): void
  {
    $line = sprintf(
      '[%s] HLS runner camera=%s %s%s',
      date(DATE_ATOM),
      $slug,
      SecretRedactor::redact($message),
      PHP_EOL
    );
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
  }

  /** @param resource|null $pipe */
  private static function drainStderr($pipe, string &$buffer, string $logPath, bool $flush): void
  {
    if (is_resource($pipe)) {
      $chunk = stream_get_contents($pipe);
      if (is_string($chunk) && $chunk !== '') {
        $buffer .= $chunk;
      }
    }

    while (($newline = strpos($buffer, "\n")) !== false) {
      $line = substr($buffer, 0, $newline + 1);
      $buffer = substr($buffer, $newline + 1);
      self::appendSanitizedProcessLine($logPath, $line);
    }
    if (strlen($buffer) > 65536) {
      self::appendSanitizedProcessLine($logPath, substr($buffer, 0, 32768));
      $buffer = substr($buffer, 32768);
    }
    if ($flush && $buffer !== '') {
      self::appendSanitizedProcessLine($logPath, $buffer);
      $buffer = '';
    }
  }

  private static function appendSanitizedProcessLine(string $logPath, string $line): void
  {
    $line = SecretRedactor::redact($line);
    if (!str_ends_with($line, "\n")) {
      $line .= PHP_EOL;
    }
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
  }
}
