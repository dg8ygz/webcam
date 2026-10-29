<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use RuntimeException;
use Webcam\Domain\Camera;

/**
 * Verwaltet den atomaren Austausch einer vollständig neu aufgebauten Kamera-DB.
 *
 * Der Import schreibt nie direkt in die aktive Datei. Er arbeitet zunächst in
 * einer lokalen temporären SQLite-Datei, kopiert das geschlossene Ergebnis in
 * das Zielverzeichnis und aktiviert es erst nach einem Backup des Altstands.
 * Bei einem Fehler wird der bisherige Stand soweit möglich zurückgelegt.
 */
final class CameraDatabaseRebuildManager
{
  public function allocateStagingDatabase(Camera $camera, bool $verbose): string
  {
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR . 'webcam-camera-db-rebuild';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException('Unable to create rebuild staging dir: ' . $directory);
    }

    $path = tempnam($directory, $camera->slug() . '-');
    if ($path === false) {
      throw new RuntimeException('Unable to allocate rebuild staging DB for ' . $camera->slug());
    }
    if ($verbose) {
      fwrite(STDOUT, "[INFO] {$camera->slug()}: staging DB -> {$path}\n");
    }
    return $path;
  }

  public function activate(
    string $targetPath,
    string $workingPath,
    bool $verbose,
    string $slug
  ): void {
    $directory = dirname($targetPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException("Unable to create camera DB directory: {$directory}");
    }

    $timestamp = date('YmdHis');
    $stagedTarget = $targetPath . '.rebuild-' . $timestamp;
    if (!copy($workingPath, $stagedTarget)) {
      throw new RuntimeException("Unable to copy rebuilt DB into place: {$stagedTarget}");
    }
    @chmod($stagedTarget, 0664);
    $this->fixOwner($stagedTarget, $verbose, $slug);

    $backupPath = null;
    if (is_file($targetPath)) {
      $backupPath = $targetPath . '.bak-' . $timestamp;
      if (!rename($targetPath, $backupPath)) {
        @unlink($stagedTarget);
        throw new RuntimeException("Unable to move current DB to {$backupPath}");
      }
      if ($verbose) {
        fwrite(STDOUT, "[INFO] {$slug}: altes DB-Backup -> {$backupPath}\n");
      }
    }

    $this->backupSqliteSidecars($targetPath, $timestamp, $verbose, $slug);

    if (!rename($stagedTarget, $targetPath)) {
      if ($backupPath !== null && is_file($backupPath)) {
        @rename($backupPath, $targetPath);
      }
      @unlink($stagedTarget);
      throw new RuntimeException("Unable to activate rebuilt DB at {$targetPath}");
    }

    @chmod($targetPath, 0664);
    $this->fixOwner($targetPath, $verbose, $slug);
  }

  private function backupSqliteSidecars(
    string $targetPath,
    string $timestamp,
    bool $verbose,
    string $slug
  ): void {
    foreach ([$targetPath . '-wal', $targetPath . '-shm'] as $sidecarPath) {
      if (!is_file($sidecarPath)) {
        continue;
      }
      $backupPath = $sidecarPath . '.bak-' . $timestamp;
      if (!rename($sidecarPath, $backupPath)) {
        fwrite(STDERR, "[WARN] {$slug}: cannot move sidecar to {$backupPath}\n");
        continue;
      }
      if ($verbose) {
        fwrite(STDOUT, "[INFO] {$slug}: altes Sidecar-Backup -> {$backupPath}\n");
      }
    }
  }

  private function fixOwner(string $path, bool $verbose, string $slug): void
  {
    if (!function_exists('ensureWwwDataOwner')) {
      throw new RuntimeException('Filesystem ownership helper is not loaded');
    }
    \ensureWwwDataOwner($path, $verbose, $slug);
  }
}
