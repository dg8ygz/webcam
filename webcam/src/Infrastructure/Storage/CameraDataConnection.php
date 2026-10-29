<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;
use PDOException;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Support\BooleanEnvironment;

/**
 * Gemeinsame SQLite-, Lock- und Staging-Infrastruktur aller Kamera-Repositories.
 *
 * Leseführung:
 * 1. `pdo()` öffnet die Verbindung lazy und initialisiert schreibend das Schema.
 * 2. `read()` kapselt Busy-/Reconnect-Retry für reine Abfragen.
 * 3. `write()` hält genau einen Kamera-Lock und aktiviert bei erlaubten
 *    Mountfehlern den lokalen Staging-Ablauf.
 * 4. Der hintere Teil verwaltet Lockdateien und den atomaren DB-Austausch.
 *
 * Diese Klasse enthält bewusst kein fachliches SQL. Dadurch teilen Capture-,
 * Like- und Wetter-Repositories dieselbe robuste Betriebsgrenze, ohne sie zu
 * duplizieren.
 */
final class CameraDataConnection
{
  private const BUSY_RETRY_ATTEMPTS = 4;
  private const WRITE_LOCK_ATTEMPTS = 8;

  private ?PDO $pdo = null;
  private bool $readOnly = false;
  /** @var resource|null */
  private $activeWriteLockHandle = null;
  private ?string $activeWriteLockPath = null;

  public function __construct(
    private readonly App $app,
    private readonly Camera $camera,
    private readonly CameraDataSchemaRepository $schema,
    private readonly ?string $dbPathOverride = null,
    private readonly ?bool $readOnlyOverride = null
  ) {}

  // -----------------------------------------------------------------------
  // Verbindung und fachneutrale Kontextwerte.
  // -----------------------------------------------------------------------

  public function pdo(): PDO
  {
    if ($this->pdo instanceof PDO) {
      return $this->pdo;
    }

    // Vor Verzeichnisanlage und PDO-Oeffnung validieren. Ein expliziter
    // bool-Override hat wie bisher Vorrang vor der Umgebungsvariable.
    $this->readOnly = $this->readOnlyOverride
      ?? BooleanEnvironment::read('WEBCAM_CAMERA_DB_READONLY');

    $dbPath = $this->dbPath();
    $directory = dirname($dbPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException("Unable to create camera DB directory: {$directory}");
    }

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
      $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = $this->readOnly
        ? \SQLITE3_OPEN_READONLY
        : (\SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, $options);
    $pdo->exec($this->readOnly ? 'PRAGMA query_only = ON' : 'PRAGMA foreign_keys = ON');
    if ($this->readOnly) {
      $pdo->exec('PRAGMA foreign_keys = ON');
    }
    $pdo->exec('PRAGMA busy_timeout = 2000');

    $this->pdo = $pdo;
    if (!$this->readOnly) {
      $this->schema->ensure($pdo);
    }
    return $pdo;
  }

  public function dbPath(): string
  {
    if (is_string($this->dbPathOverride) && $this->dbPathOverride !== '') {
      return $this->dbPathOverride;
    }
    $root = $this->app->paths()->cameraDbPath($this->camera->slug());
    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'db.sqlite';
  }

  public function cameraSlug(): string
  {
    return $this->camera->slug();
  }

  public function appTimezone(): \DateTimeZone
  {
    $timezone = $this->app->config('timezone', 'UTC');
    try {
      return new \DateTimeZone($timezone);
    } catch (\Throwable) {
      return new \DateTimeZone('UTC');
    }
  }

  public function isReadOnly(): bool
  {
    $this->pdo();
    return $this->readOnly;
  }

  public function ensureWritableSchema(): void
  {
    $this->pdo();
    if ($this->readOnly) {
      throw new RuntimeException('Camera DB opened read-only; cannot initialize schema.');
    }
  }

  public function close(): void
  {
    $this->pdo = null;
    $this->readOnly = false;
  }

  // -----------------------------------------------------------------------
  // Gemeinsame Lese- und Schreibgrenzen.
  // -----------------------------------------------------------------------

  /**
   * @template T
   * @param callable(PDO):T $query
   * @return T
   */
  public function read(callable $query)
  {
    return $this->withBusyRetry(fn() => $query($this->pdo()));
  }

  /**
   * Führt eine fachliche Schreibtransaktion unter Kamera-Lock aus.
   *
   * Der Callback beginnt und beendet seine SQLite-Transaktion selbst. Dadurch
   * kann etwa ein Capture seine Hauptzeile, Varianten, EXIF und Wetter atomar
   * über mehrere Repository-Helfer schreiben.
   *
   * @template T
   * @param callable(self):T $mutator
   * @return T
   */
  public function write(callable $mutator, string $operation, bool $allowStagedFallback = true)
  {
    return $this->withWriteLock(function () use ($mutator, $operation, $allowStagedFallback) {
      try {
        return $this->withBusyRetry(fn() => $mutator($this));
      } catch (PDOException $exception) {
        if (!$allowStagedFallback || !$this->shouldFallbackToStagedWrite($exception)) {
          throw $exception;
        }
        return $this->withStagedWrite($mutator, $operation);
      }
    });
  }

  public function beginWriteTransaction(PDO $pdo): void
  {
    if ($this->readOnly) {
      throw new RuntimeException('Camera DB opened read-only; cannot start write transaction.');
    }
    $pdo->exec('BEGIN IMMEDIATE');
  }

  public function rollBackQuietly(PDO $pdo): void
  {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
  }

  /**
   * @template T
   * @param callable():T $callback
   * @return T
   */
  public function withWriteLock(callable $callback)
  {
    if ($this->readOnlyOverride === true) {
      throw new RuntimeException('Camera DB store forced read-only; cannot acquire write lock.');
    }

    // Explizite Test-/Migrationspfade werden bereits vom Aufrufer isoliert.
    if ($this->dbPathOverride !== null) {
      $this->resetConnection();
      try {
        return $callback();
      } finally {
        $this->resetConnection();
      }
    }

    $lockPath = $this->dbPath() . '.write.lock';
    if ($this->hasActiveWriteLock($lockPath)) {
      return $callback();
    }

    [$lockHandle, $lockPath] = $this->acquireWriteLock();
    try {
      $this->activeWriteLockHandle = $lockHandle;
      $this->activeWriteLockPath = $lockPath;
      $this->resetConnection();
      $this->withBusyRetry(function (): void {
        $this->ensureDeleteJournalMode($this->pdo());
      });
      return $callback();
    } finally {
      $this->activeWriteLockHandle = null;
      $this->activeWriteLockPath = null;
      $this->resetConnection();
      flock($lockHandle, LOCK_UN);
      fclose($lockHandle);
    }
  }

  /**
   * Mutiert eine lokale DB-Kopie und veröffentlicht sie anschließend atomar.
   *
   * @template T
   * @param callable(self):T $mutator
   * @return T
   */
  public function withStagedWrite(callable $mutator, string $operation)
  {
    $dbPath = $this->dbPath();
    $directory = dirname($dbPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException("Unable to create camera DB directory: {$directory}");
    }

    $lockPath = $dbPath . '.write.lock';
    $ownsLock = false;
    if ($this->hasActiveWriteLock($lockPath)) {
      $lockHandle = $this->activeWriteLockHandle;
    } else {
      [$lockHandle, $lockPath] = $this->acquireWriteLock();
      $ownsLock = true;
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'wcam_camdb_');
    if ($tempPath === false) {
      if ($ownsLock) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
      }
      throw new RuntimeException('Unable to create temp staging DB path');
    }

    $swapPath = $dbPath . '.swap';
    try {
      $this->resetConnection();
      if (is_file($dbPath) && filesize($dbPath) > 0) {
        if (!copy($dbPath, $tempPath)) {
          throw new RuntimeException("Unable to copy camera DB to staging: {$dbPath}");
        }
      } elseif (is_file($tempPath) && !unlink($tempPath)) {
        throw new RuntimeException("Unable to reset temp staging DB path: {$tempPath}");
      }

      $staging = new self($this->app, $this->camera, $this->schema, $tempPath, false);
      $staging->ensureWritableSchema();
      $result = $mutator($staging);
      $staging->close();

      $this->replaceLiveDatabaseFromStaging($tempPath, $dbPath, $swapPath, $operation);
      foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $sidecar) {
        if (is_file($sidecar) && !unlink($sidecar)) {
          $this->app->logger()->warning('camera db staged sidecar cleanup failed', [
            'camera' => $this->camera->slug(),
            'path' => $sidecar,
            'operation' => $operation,
          ]);
        }
      }

      $this->app->logger()->warning('camera db staged write fallback', [
        'camera' => $this->camera->slug(),
        'operation' => $operation,
      ]);
      $this->resetConnection();
      return $result;
    } finally {
      if (is_file($swapPath)) {
        @unlink($swapPath);
      }
      if (is_file($tempPath)) {
        @unlink($tempPath);
      }
      if ($ownsLock) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
      }
    }
  }

  // -----------------------------------------------------------------------
  // Retry, Fehlerklassifikation und Lockdateien.
  // -----------------------------------------------------------------------

  /** @template T @param callable():T $callback @return T */
  private function withBusyRetry(callable $callback, int $maxAttempts = self::BUSY_RETRY_ATTEMPTS)
  {
    $busyAttempts = 0;
    $reconnectAttempts = 0;
    while (true) {
      try {
        return $callback();
      } catch (PDOException $exception) {
        if ($this->isBusy($exception)) {
          $busyAttempts++;
          if ($busyAttempts >= $maxAttempts) {
            throw $exception;
          }
          $this->resetConnection();
          usleep(min(250000 * $busyAttempts, 1500000));
          continue;
        }

        if ($this->isReconnectable($exception) && $reconnectAttempts < 1) {
          $reconnectAttempts++;
          $this->app->logger()->warning('camera db reconnect', [
            'camera' => $this->camera->slug(),
            'error' => $exception->getMessage(),
            'code' => $this->sqliteErrorCode($exception),
          ]);
          $this->resetConnection();
          usleep(200000);
          continue;
        }
        throw $exception;
      }
    }
  }

  private function isBusy(PDOException $exception): bool
  {
    $code = $this->sqliteErrorCode($exception);
    if (in_array($code, [5, 6, 15], true)) {
      return true;
    }
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'database is locked')
      || str_contains($message, 'database locked')
      || str_contains($message, 'busy')
      || str_contains($message, 'locking protocol');
  }

  private function isReconnectable(PDOException $exception): bool
  {
    $code = $this->sqliteErrorCode($exception);
    if (in_array($code, [8, 10, 14], true)) {
      return true;
    }
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'unable to open database file')
      || str_contains($message, 'disk i/o error')
      || str_contains($message, 'database is closed')
      || str_contains($message, 'attempt to write a readonly database');
  }

  private function shouldFallbackToStagedWrite(PDOException $exception): bool
  {
    if ($this->readOnly) {
      return false;
    }
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'readonly database')
      || str_contains($message, 'attempt to write a readonly database')) {
      return true;
    }
    return $this->dbPathOverride !== null
      && ($this->isBusy($exception) || $this->isReconnectable($exception));
  }

  private function sqliteErrorCode(PDOException $exception): ?int
  {
    $info = $exception->errorInfo;
    return is_array($info) && isset($info[1]) && is_numeric($info[1])
      ? (int) $info[1]
      : null;
  }

  private function resetConnection(): void
  {
    $this->pdo = null;
    $this->readOnly = false;
  }

  private function hasActiveWriteLock(string $lockPath): bool
  {
    return is_resource($this->activeWriteLockHandle) && $this->activeWriteLockPath === $lockPath;
  }

  /** @return array{0:resource,1:string} */
  private function acquireWriteLock(): array
  {
    $lockPath = $this->dbPath() . '.write.lock';
    [$lockHandle, $openError] = $this->openWriteLockHandle($lockPath);
    if ($lockHandle === false && $this->canRecreateStaleWriteLock($lockPath)) {
      @unlink($lockPath);
      clearstatcache(true, $lockPath);
      [$lockHandle, $openError] = $this->openWriteLockHandle($lockPath);
      if ($lockHandle !== false) {
        $this->normalizeWriteLockPermissions($lockPath);
        $this->app->logger()->warning('camera db write lock recreated', [
          'camera' => $this->camera->slug(),
          'path' => $lockPath,
        ]);
      }
    }

    if ($lockHandle === false) {
      $detail = $openError !== null && $openError !== '' ? " ({$openError})" : '';
      throw new RuntimeException("Unable to open camera DB write lock: {$lockPath}{$detail}");
    }
    $this->normalizeWriteLockPermissions($lockPath);

    for ($attempt = 1; $attempt <= self::WRITE_LOCK_ATTEMPTS; $attempt++) {
      if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
        return [$lockHandle, $lockPath];
      }
      usleep(200000 * $attempt);
    }

    fclose($lockHandle);
    throw new RuntimeException("Unable to acquire camera DB write lock: {$lockPath}");
  }

  /** @return array{0:resource|false,1:?string} */
  private function openWriteLockHandle(string $lockPath): array
  {
    $openError = null;
    set_error_handler(static function (int $severity, string $message) use (&$openError): bool {
      $openError = $message;
      return true;
    });
    try {
      $lockHandle = fopen($lockPath, 'c');
    } finally {
      restore_error_handler();
    }
    return [$lockHandle, $openError];
  }

  private function canRecreateStaleWriteLock(string $lockPath): bool
  {
    return is_file($lockPath) && (filesize($lockPath) ?: 0) === 0;
  }

  private function normalizeWriteLockPermissions(string $lockPath): void
  {
    if (!is_file($lockPath)) {
      return;
    }
    // Der dedizierte DB-Root ist 2770 und gehört der Webgruppe. Gruppenrechte
    // reichen deshalb für CLI- und Web-Schreiber aus; world-write wäre eine
    // unnötige zweite Angriffsfläche.
    if (!@chmod($lockPath, 0660) && !is_writable($lockPath)) {
      $this->app->logger()->warning('camera db write lock chmod failed', [
        'camera' => $this->camera->slug(),
        'path' => $lockPath,
      ]);
    }
  }

  // -----------------------------------------------------------------------
  // Validierter Austausch einer lokal gestagten Datenbank.
  // -----------------------------------------------------------------------

  private function replaceLiveDatabaseFromStaging(
    string $tempPath,
    string $dbPath,
    string $swapPath,
    string $operation
  ): void {
    if (is_file($swapPath) && !unlink($swapPath)) {
      throw new RuntimeException("Unable to remove stale camera DB swap file: {$swapPath}");
    }

    $swapCopyError = $this->copyFileError($tempPath, $swapPath);
    if ($swapCopyError === null) {
      $swapRenameError = $this->renameFileError($swapPath, $dbPath);
      if ($swapRenameError === null) {
        return;
      }
      @unlink($swapPath);
      $this->app->logger()->warning('camera db staged rename fallback', [
        'camera' => $this->camera->slug(),
        'operation' => $operation,
        'path' => $dbPath,
        'error' => $swapRenameError,
      ]);
    } else {
      $this->app->logger()->warning('camera db staged swap copy fallback', [
        'camera' => $this->camera->slug(),
        'operation' => $operation,
        'path' => $swapPath,
        'error' => $swapCopyError,
      ]);
    }

    $directCopyError = $this->copyFileError($tempPath, $dbPath);
    if ($directCopyError !== null) {
      throw new RuntimeException("Unable to replace camera DB from staged copy: {$dbPath} ({$directCopyError})");
    }
    $this->app->logger()->warning('camera db staged direct copy fallback', [
      'camera' => $this->camera->slug(),
      'operation' => $operation,
      'path' => $dbPath,
    ]);
  }

  private function copyFileError(string $source, string $target): ?string
  {
    $error = null;
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
      $error = $message;
      return true;
    });
    try {
      $ok = copy($source, $target);
    } finally {
      restore_error_handler();
    }
    return $ok ? null : ($error ?: 'copy() failed');
  }

  private function renameFileError(string $source, string $target): ?string
  {
    $error = null;
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
      $error = $message;
      return true;
    });
    try {
      $ok = rename($source, $target);
    } finally {
      restore_error_handler();
    }
    return $ok ? null : ($error ?: 'rename() failed');
  }

  private function ensureDeleteJournalMode(PDO $pdo): void
  {
    if ($this->readOnly) {
      return;
    }
    try {
      $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (\Throwable) {
      // Eine DB im DELETE-Modus besitzt keinen WAL-Checkpoint.
    }
    $statement = $pdo->query('PRAGMA journal_mode = DELETE');
    $mode = strtolower((string) ($statement ? $statement->fetchColumn() : ''));
    if ($mode !== 'delete') {
      throw new RuntimeException(
        'Camera DB journal_mode must be DELETE, got: ' . ($mode !== '' ? $mode : 'unknown')
      );
    }
  }
}
