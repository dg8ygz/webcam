<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Logging;

use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Schreibt strukturierte, zeitgestempelte Anwendungsereignisse in eine Logdatei.
 * Fachcode entscheidet Level und Kontext; der Logger führt keine stillen
 * Erfolgs-Fallbacks aus.
 */
final class Logger
{
  public function __construct(private readonly string $logFile)
  {
    $dir = dirname($this->logFile);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      error_log('Logger: mkdir failed for ' . $dir);
    }
  }

  public function info(string $message, array $context = []): void
  {
    $this->write('INFO', $message, $context);
  }

  public function warning(string $message, array $context = []): void
  {
    $this->write('WARNING', $message, $context);
  }

  public function error(string $message, array $context = []): void
  {
    $this->write('ERROR', $message, $context);
  }

  private function write(string $level, string $message, array $context): void
  {
    $timestamp = date('c');
    $payload = [
      'time' => $timestamp,
      'level' => $level,
      'message' => SecretRedactor::redact($message),
      'context' => SecretRedactor::redactValue($context),
    ];
    $json = json_encode($payload);
    if ($json === false) {
      error_log('Logger: JSON encode failed for ' . $this->logFile);
      return;
    }
    if (file_put_contents($this->logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
      error_log('Logger: write failed for ' . $this->logFile);
    }
  }
}
