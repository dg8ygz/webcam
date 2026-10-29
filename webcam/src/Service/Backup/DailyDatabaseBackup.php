<?php
declare(strict_types=1);

namespace Webcam\Service\Backup;

use RuntimeException;
use Webcam\App;

/**
 * Sichert Config-DB und ausgewählte Kamera-DBs in ihre Retention-Verzeichnisse.
 *
 * Zielauswahl, pro-Kamera-Fehlerzählung und Dateirechte gehören hierher. Die
 * eigentliche konsistente SQLite-Kopie bleibt vollständig im
 * SqliteBackupService und wird dadurch nicht für Config und Kameras dupliziert.
 */
final class DailyDatabaseBackup
{
  public function __construct(
    private readonly App $app,
    private readonly SqliteBackupService $sqliteBackup
  ) {
  }

  public function backupConfig(string $timestamp): bool
  {
    $source = (string) $this->app->config('camera_config_db');
    if (!is_file($source)) {
      fwrite(STDERR, "[WARN] config DB not found: {$source}\n");
      return false;
    }
    $directory = $this->resolveConfigBackupDirectory();
    if ($directory === null) {
      fwrite(STDERR, "[WARN] no writable config backup dir available\n");
      return false;
    }

    $destination = $directory . '/webcams.sqlite.' . $timestamp . '.bak';
    if (!$this->sqliteBackup->backup($source, $destination, 'config DB')) {
      return false;
    }
    echo "[OK] config DB backup: {$destination}\n";
    $this->fixOwner($destination);
    $this->sqliteBackup->prune($directory, 'webcams.sqlite.', 10);
    return true;
  }

  /**
   * @param list<string> $requestedSlugs
   * @return array{total:int,failed:int,target_base:string,ok_slugs:list<string>,failed_slugs:list<string>}
   */
  public function backupCameras(string $timestamp, array $requestedSlugs = []): array
  {
    $primaryBase = $this->projectRoot() . '/var/backups';
    $fallbackBase = $this->app->paths()->statePath('backup/db');
    [$backupBase, $targetBaseUsed] = $this->selectCameraBackupBase(
      $primaryBase,
      $fallbackBase
    );
    if ($backupBase === null) {
      return [
        'total' => 0,
        'failed' => 0,
        'target_base' => $fallbackBase,
        'ok_slugs' => [],
        'failed_slugs' => [],
      ];
    }

    $total = 0;
    $failed = 0;
    $okSlugs = [];
    $failedSlugs = [];
    $cameras = $this->app->cameras()->all();
    if ($requestedSlugs !== []) {
      $cameras = array_values(array_filter(
        $cameras,
        static fn($camera): bool => in_array($camera->slug(), $requestedSlugs, true)
      ));
    }

    foreach ($cameras as $camera) {
      $slug = $camera->slug();
      $source = $this->app->paths()->cameraDbPath($slug . '/db.sqlite');
      if (!is_file($source)) {
        continue;
      }
      $sourceSize = filesize($source);
      if ($sourceSize === false || $sourceSize <= 0) {
        fwrite(STDERR, "[WARN] source DB invalid/empty for {$slug}: {$source}\n");
        $failed++;
        $failedSlugs[] = $slug;
        continue;
      }

      [$targetDirectory, $usedFallback] = $this->cameraTargetDirectory(
        $backupBase,
        $primaryBase,
        $fallbackBase,
        $slug
      );
      if ($targetDirectory === null) {
        $failed++;
        $failedSlugs[] = $slug;
        continue;
      }
      if ($usedFallback) {
        $targetBaseUsed = $fallbackBase;
      }

      $destination = $targetDirectory . '/db.sqlite.bak-' . $timestamp;
      if (!$this->sqliteBackup->backup($source, $destination, $slug)) {
        $failed++;
        $failedSlugs[] = $slug;
        continue;
      }
      try {
        $originals = (new DeferredImageBackup($this->app))->backup($camera, $destination, $timestamp);
        if ($originals > 0) echo "[OK] {$slug}: private originals/recipes backed up: {$originals}\n";
      } catch (\Throwable $error) {
        fwrite(STDERR, "[FAIL] {$slug}: private image backup: " . $error->getMessage() . "\n");
        $failed++; $failedSlugs[] = $slug;
        continue;
      }
      echo "[OK] {$slug}: {$destination}\n";
      $this->fixOwner($destination);
      $this->sqliteBackup->prune($targetDirectory, 'db.sqlite.bak-', 10);
      $total++;
      $okSlugs[] = $slug;
    }

    return [
      'total' => $total,
      'failed' => $failed,
      'target_base' => $targetBaseUsed,
      'ok_slugs' => $okSlugs,
      'failed_slugs' => $failedSlugs,
    ];
  }

  private function resolveConfigBackupDirectory(): ?string
  {
    $override = getenv('WEBCAM_CONFIG_BACKUP_DIR');
    $candidates = [];
    if (is_string($override) && trim($override) !== '') {
      $candidates[] = trim($override);
    }
    $candidates[] = $this->projectRoot() . '/var/backups/config';
    try {
      $candidates[] = $this->app->paths()->statePath('backup/config');
    } catch (RuntimeException) {
      // Ohne State-Pfad bleiben ausschließlich die expliziten Ziele übrig.
    }

    foreach ($candidates as $candidate) {
      if ((is_dir($candidate) || mkdir($candidate, 0775, true)) && is_writable($candidate)) {
        return $candidate;
      }
      fwrite(STDERR, "[WARN] config backup dir not writable: {$candidate}\n");
    }
    return null;
  }

  /** @return array{0:?string,1:string} */
  private function selectCameraBackupBase(string $primaryBase, string $fallbackBase): array
  {
    if ((is_dir($primaryBase) || mkdir($primaryBase, 0775, true)) && is_writable($primaryBase)) {
      return [$primaryBase, $primaryBase];
    }
    fwrite(STDERR, "[WARN] backup base not writable: {$primaryBase}\n");

    if (!is_dir($fallbackBase) && !mkdir($fallbackBase, 0775, true) && !is_dir($fallbackBase)) {
      fwrite(STDERR, "[WARN] cannot create fallback backup base: {$fallbackBase}\n");
      return [null, $fallbackBase];
    }
    return [$fallbackBase, $fallbackBase];
  }

  /** @return array{0:?string,1:bool} */
  private function cameraTargetDirectory(
    string $backupBase,
    string $primaryBase,
    string $fallbackBase,
    string $slug
  ): array {
    $directory = rtrim($backupBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug;
    if (is_dir($directory) || mkdir($directory, 0775, true)) {
      return [$directory, false];
    }

    if ($backupBase === $primaryBase && $primaryBase !== $fallbackBase) {
      $directory = rtrim($fallbackBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug;
      if (is_dir($directory) || mkdir($directory, 0775, true)) {
        return [$directory, true];
      }
    }
    fwrite(STDERR, "[WARN] cannot create backup dir for {$slug}: {$directory}\n");
    return [null, false];
  }

  private function projectRoot(): string
  {
    $root = realpath(dirname(__DIR__, 3));
    if ($root === false) {
      throw new RuntimeException('Unable to resolve project root');
    }
    return $root;
  }

  private function fixOwner(string $path): void
  {
    if (!function_exists('ensureWwwDataOwner')) {
      throw new RuntimeException('Filesystem ownership helper is not loaded');
    }
    \ensureWwwDataOwner($path);
  }
}
