<?php
declare(strict_types=1);

namespace Webcam\Service\Backup;

use RuntimeException;
use SQLite3;
use Throwable;

/**
 * Erzeugt und prüft geschlossene SQLite-Online-Snapshots.
 *
 * Die Live-DB wird read-only geöffnet und über SQLite::backup() in eine lokale
 * Stagingdatei geschrieben. Erst nach quick_check, Größenvergleich und sicheren
 * Dateirechten gelangt die geschlossene Datei atomar an ihr Backupziel. Es gibt
 * bewusst keinen stillen Fallback von /var/local/webcam auf /tmp.
 */
final class SqliteBackupService
{
  public function backup(string $source, string $destination, string $label): bool
  {
    $sourceSize = filesize($source);
    if ($sourceSize === false || $sourceSize <= 0) {
      fwrite(STDERR, "[WARN] source DB invalid/empty for {$label}: {$source}\n");
      return false;
    }
    if (!class_exists(SQLite3::class)) {
      fwrite(STDERR, "[WARN] SQLite3 extension is required for consistent backups ({$label})\n");
      return false;
    }

    $temporaryDestination = $destination . '.tmp';
    if (is_file($temporaryDestination) && !unlink($temporaryDestination)) {
      fwrite(STDERR, "[WARN] cannot remove stale temp backup: {$temporaryDestination}\n");
      return false;
    }

    try {
      $stagingDirectory = $this->stagingDirectory();
    } catch (Throwable $exception) {
      fwrite(STDERR, "[WARN] local backup staging unavailable for {$label}: {$exception->getMessage()}\n");
      return false;
    }
    $localTemporaryPath = tempnam($stagingDirectory, 'webcam-sqlite-backup-');
    if ($localTemporaryPath === false) {
      fwrite(STDERR, "[WARN] cannot create local backup staging file for {$label}\n");
      return false;
    }

    $sourceDatabase = null;
    $destinationDatabase = null;
    try {
      $sourceDatabase = new SQLite3($source, SQLITE3_OPEN_READONLY);
      $sourceDatabase->busyTimeout(10000);
      $destinationDatabase = new SQLite3(
        $localTemporaryPath,
        SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
      );
      $destinationDatabase->busyTimeout(10000);
      if (!$sourceDatabase->backup($destinationDatabase)) {
        throw new RuntimeException('SQLite online backup returned false');
      }
      $destinationDatabase->close();
      $destinationDatabase = null;
      $sourceDatabase->close();
      $sourceDatabase = null;

      $this->assertValidSnapshot($localTemporaryPath);
      $snapshotSize = filesize($localTemporaryPath);
      if (!copy($localTemporaryPath, $temporaryDestination)) {
        throw new RuntimeException(
          "cannot copy staged backup: {$localTemporaryPath} -> {$temporaryDestination}"
        );
      }
      clearstatcache(true, $temporaryDestination);
      $destinationSize = filesize($temporaryDestination);
      if ($destinationSize === false || $destinationSize !== $snapshotSize) {
        throw new RuntimeException('staged backup copy size mismatch');
      }
      if (!chmod($temporaryDestination, 0660)) {
        throw new RuntimeException("cannot secure backup permissions: {$temporaryDestination}");
      }
      if (!rename($temporaryDestination, $destination)) {
        throw new RuntimeException(
          "cannot finalize backup: {$temporaryDestination} -> {$destination}"
        );
      }
      return true;
    } catch (Throwable $exception) {
      fwrite(STDERR, "[WARN] SQLite backup failed for {$label}: {$exception->getMessage()}\n");
      return false;
    } finally {
      if ($destinationDatabase instanceof SQLite3) {
        $destinationDatabase->close();
      }
      if ($sourceDatabase instanceof SQLite3) {
        $sourceDatabase->close();
      }
      $this->removeTemporaryFiles($temporaryDestination, $localTemporaryPath);
    }
  }

  /**
   * Behält nur die jüngsten abgeschlossenen Backups; SQLite-Sidecars und eine
   * eventuell laufende .tmp-Datei zählen nicht als eigene Generation.
   */
  public function prune(string $directory, string $prefix, int $keep): void
  {
    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . '/' . $prefix . '*');
    if (!$files) {
      return;
    }
    $files = array_values(array_filter(
      $files,
      static fn(string $file): bool => !preg_match('/(?:-wal|-shm|\.tmp)$/', $file)
    ));
    usort($files, static function (string $left, string $right): int {
      $rightMtime = filemtime($right);
      $leftMtime = filemtime($left);
      return ($rightMtime === false ? 0 : $rightMtime)
        <=> ($leftMtime === false ? 0 : $leftMtime);
    });

    foreach (array_slice($files, $keep) as $file) {
      if (!unlink($file)) {
        fwrite(STDERR, "[WARN] cannot remove backup: {$file}\n");
      }
    }
  }

  /** Liefert ausschließlich den explizit geprüften lokalen Stagingpfad. */
  public function stagingDirectory(): string
  {
    $configured = getenv('WEBCAM_SQLITE_BACKUP_TMPDIR');
    $path = is_string($configured) && trim($configured) !== ''
      ? trim($configured)
      : '/var/local/webcam';
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
      throw new RuntimeException('WEBCAM_SQLITE_BACKUP_TMPDIR must be absolute');
    }
    $realPath = realpath($path);
    if ($realPath === false || !is_dir($realPath)) {
      throw new RuntimeException("directory missing: {$path}");
    }
    if (!is_writable($realPath)) {
      throw new RuntimeException("directory not writable: {$realPath}");
    }
    return rtrim($realPath, DIRECTORY_SEPARATOR);
  }

  private function assertValidSnapshot(string $path): void
  {
    $checkDatabase = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $check = $checkDatabase->querySingle('PRAGMA quick_check(1)');
    $checkDatabase->close();
    if ($check !== 'ok') {
      throw new RuntimeException(
        'SQLite quick_check failed: ' . (is_scalar($check) ? (string) $check : 'unknown')
      );
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false || $size <= 0) {
      throw new RuntimeException('SQLite backup is empty');
    }
  }

  private function removeTemporaryFiles(string $temporaryDestination, string $localTemporaryPath): void
  {
    if (is_file($temporaryDestination)) {
      @unlink($temporaryDestination);
    }
    foreach ([
      $localTemporaryPath,
      $localTemporaryPath . '-journal',
      $localTemporaryPath . '-wal',
      $localTemporaryPath . '-shm',
    ] as $localPath) {
      if (is_file($localPath)) {
        @unlink($localPath);
      }
    }
  }
}
