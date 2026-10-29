<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Config;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Verwaltet die getrennten Read-only- und Read-write-Verbindungen der Config-DB.
 *
 * Lesezugriffe laufen mit query_only, Schreibzugriffe erzwingen wegen CIFS den
 * DELETE-Journalmodus. Kurzlebige SQLITE_BUSY-/LOCKED-Probleme lösen einen
 * begrenzten Reconnect aus; alle anderen Fehler werden sofort weitergereicht.
 */
final class ConfigDatabaseConnection
{
  /** @var array<string,PDO> */
  private static array $connections = [];

  /** @var array<string,string> */
  private static array $paths = [];

  /** @template T @param callable(PDO):T $operation @return T */
  public function run(bool $write, callable $operation): mixed
  {
    $maxAttempts = $write ? 4 : 3;
    for ($attempt = 0; ; $attempt++) {
      try {
        return $operation($this->open($write));
      } catch (Throwable $exception) {
        if (!$this->retryable($exception) || $attempt >= $maxAttempts - 1) {
          throw $exception;
        }
        self::reset();
        usleep(150000 * ($attempt + 1));
      }
    }
  }

  public function open(bool $write = false): PDO
  {
    $databasePath = \webcam_camera_store_db_path();
    if (!is_file($databasePath)) {
      throw new RuntimeException("SQLite config database not found: {$databasePath}");
    }
    $resolvedPath = realpath($databasePath) ?: $databasePath;
    $key = $write ? 'rw' : 'ro';

    // Vor einem Write wird die gleichnamige Read-only-Verbindung gelöst. Das
    // verhindert alte CIFS-Inodes nach einem atomaren Austausch der Live-DB.
    if ($write && isset(self::$connections['ro']) && (self::$paths['ro'] ?? null) === $resolvedPath) {
      unset(self::$connections['ro'], self::$paths['ro']);
    }
    if (isset(self::$connections[$key]) && (self::$paths[$key] ?? null) === $resolvedPath) {
      return self::$connections[$key];
    }

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')
      && defined('SQLITE3_OPEN_READONLY')
      && defined('SQLITE3_OPEN_READWRITE')
      && defined('SQLITE3_OPEN_CREATE')
    ) {
      $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $write
        ? (\SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE)
        : \SQLITE3_OPEN_READONLY;
    }
    $pdo = new PDO('sqlite:' . $databasePath, null, null, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec($write ? 'PRAGMA journal_mode = DELETE' : 'PRAGMA query_only = ON');

    self::$connections[$key] = $pdo;
    self::$paths[$key] = $resolvedPath;
    return $pdo;
  }

  public static function reset(): void
  {
    self::$connections = [];
    self::$paths = [];
  }

  private function retryable(Throwable $exception): bool
  {
    $code = $this->sqliteCode($exception);
    if (in_array($code, [5, 6, 15], true)) {
      return true;
    }
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'database is locked')
      || str_contains($message, 'database table is locked')
      || str_contains($message, 'locking protocol');
  }

  private function sqliteCode(Throwable $exception): ?int
  {
    if ($exception instanceof PDOException
      && is_array($exception->errorInfo)
      && isset($exception->errorInfo[1])
      && is_numeric($exception->errorInfo[1])
    ) {
      return (int) $exception->errorInfo[1];
    }
    return is_numeric($exception->getCode()) ? (int) $exception->getCode() : null;
  }
}
