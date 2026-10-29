<?php
declare(strict_types=1);

namespace Webcam\Service\Backup;

use DateTimeImmutable;
use DirectoryIterator;
use RuntimeException;
use Throwable;
use Webcam\App;

/**
 * Sichert physische EXIF-/Wetter-Sidecars unabhaengig von den SQLite-Datenbanken.
 *
 * Der normale Tageslauf uebergibt nur den abgeschlossenen Vortag. Dadurch
 * werden exakt bekannte Cache-/Archiv-Tagesordner gelesen und das langsame
 * HDD-Archiv nicht jeden Morgen rekursiv durchsucht. Zieldateien entstehen
 * gestagt, werden per Groesse und SHA-256 geprueft und atomar veroeffentlicht.
 */
final class SidecarBackup
{
  private readonly string $backupBase;

  public function __construct(private readonly App $app, ?string $backupBase = null)
  {
    $this->backupBase = $backupBase ?? $this->resolveBackupBase();
  }

  /**
   * @param list<DateTimeImmutable> $days
   * @param list<string> $requestedSlugs
   * @return array{
   *   total:int,
   *   copied:int,
   *   unchanged:int,
   *   failed:int,
   *   target_base:string,
   *   dates:list<string>,
   *   ok_slugs:list<string>,
   *   failed_slugs:list<string>
   * }
   */
  public function backupDays(array $days, array $requestedSlugs = [], bool $overwrite = false): array
  {
    $lock = $this->acquireRunLock();
    if ($lock === null) {
      throw new RuntimeException('another sidecar backup is already running');
    }

    $result = [
      'total' => 0,
      'copied' => 0,
      'unchanged' => 0,
      'failed' => 0,
      'target_base' => $this->backupBase,
      'dates' => [],
      'ok_slugs' => [],
      'failed_slugs' => [],
    ];

    try {
      $cameras = $this->app->cameras()->all();
      if ($requestedSlugs !== []) {
        $cameras = array_values(array_filter(
          $cameras,
          static fn($camera): bool => in_array($camera->slug(), $requestedSlugs, true)
        ));
      }

      foreach ($days as $day) {
        $dateKey = $day->format('Y/m/d');
        $result['dates'][] = $day->format('Y-m-d');
        foreach ($cameras as $camera) {
          $slug = $camera->slug();
          $cameraFailed = false;
          $sourceSets = [
            'cache' => $this->app->paths()->cachePath($slug . '/' . $dateKey),
            'archive' => $this->app->paths()->archivePath($slug . '/' . $dateKey),
          ];
          $sourceFiles = 0;
          $captureFilesExist = false;

          foreach ($sourceSets as $sourceKind => $sourceDir) {
            $files = $this->textFiles($sourceDir);
            $sourceFiles += count($files);
            $captureFilesExist = $captureFilesExist || $this->directoryHasJpegs($sourceDir);
            foreach ($files as $sourcePath) {
              $result['total']++;
              $targetPath = $this->backupBase
                . '/' . $slug
                . '/' . $dateKey
                . '/' . $sourceKind
                . '/' . basename($sourcePath);
              $copy = $this->backupFile($sourcePath, $targetPath, $overwrite);
              if ($copy === 'copied') {
                $result['copied']++;
                continue;
              }
              if ($copy === 'unchanged') {
                $result['unchanged']++;
                continue;
              }
              $result['failed']++;
              $cameraFailed = true;
            }
          }

          if ($sourceFiles === 0 && $captureFilesExist) {
            $result['failed']++;
            $cameraFailed = true;
            $this->app->logger()->warning('sidecar backup found captures without text sidecars', [
              'camera' => $slug,
              'date' => $day->format('Y-m-d'),
            ]);
          }

          if ($cameraFailed) {
            $result['failed_slugs'][] = $slug;
          } else {
            $result['ok_slugs'][] = $slug;
          }
        }
      }
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }

    $result['dates'] = array_values(array_unique($result['dates']));
    $result['ok_slugs'] = array_values(array_unique($result['ok_slugs']));
    $result['failed_slugs'] = array_values(array_unique($result['failed_slugs']));
    $result['ok_slugs'] = array_values(array_diff($result['ok_slugs'], $result['failed_slugs']));
    return $result;
  }

  private function resolveBackupBase(): string
  {
    $override = getenv('WEBCAM_SIDECAR_BACKUP_DIR');
    if (is_string($override) && trim($override) !== '') {
      return rtrim(trim($override), DIRECTORY_SEPARATOR);
    }
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
      throw new RuntimeException('cannot resolve project root for sidecar backups');
    }
    return $projectRoot . '/var/backups/sidecars';
  }

  /** @return resource|null */
  private function acquireRunLock()
  {
    $lockDir = $this->app->paths()->statePath('backup');
    if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
      throw new RuntimeException("cannot create sidecar backup lock dir: {$lockDir}");
    }
    $lockPath = $lockDir . '/sidecar_backup.run.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
      throw new RuntimeException("cannot open sidecar backup lock: {$lockPath}");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    return $handle;
  }

  /** @return list<string> */
  private function textFiles(string $directory): array
  {
    if (!is_dir($directory)) {
      return [];
    }
    $files = [];
    foreach (new DirectoryIterator($directory) as $entry) {
      if ($entry->isDot() || !$entry->isFile()) {
        continue;
      }
      if (preg_match('/\.txt$/i', $entry->getFilename()) !== 1) {
        continue;
      }
      $files[] = $entry->getPathname();
    }
    sort($files, SORT_STRING);
    return $files;
  }

  private function directoryHasJpegs(string $directory): bool
  {
    if (!is_dir($directory)) {
      return false;
    }
    foreach (new DirectoryIterator($directory) as $entry) {
      if ($entry->isFile() && preg_match('/\.jpe?g$/i', $entry->getFilename()) === 1) {
        return true;
      }
    }
    return false;
  }

  /** @return 'copied'|'unchanged'|'failed' */
  private function backupFile(string $sourcePath, string $targetPath, bool $overwrite): string
  {
    $sourceSize = filesize($sourcePath);
    $sourceHash = hash_file('sha256', $sourcePath);
    if ($sourceSize === false || $sourceSize <= 0 || $sourceHash === false) {
      $this->logFailure($sourcePath, $targetPath, 'source is empty or cannot be read');
      return 'failed';
    }

    if (is_file($targetPath)) {
      $targetSize = filesize($targetPath);
      $targetHash = hash_file('sha256', $targetPath);
      if ($targetSize === $sourceSize && $targetHash === $sourceHash) {
        return 'unchanged';
      }
      if (!$overwrite) {
        $this->logFailure($sourcePath, $targetPath, 'existing backup differs; refusing to overwrite');
        return 'failed';
      }
    }

    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
      $this->logFailure($sourcePath, $targetPath, 'target directory cannot be created');
      return 'failed';
    }
    $temporary = tempnam($targetDir, '.sidecar-');
    if ($temporary === false) {
      $this->logFailure($sourcePath, $targetPath, 'temporary target cannot be created');
      return 'failed';
    }

    try {
      if (!copy($sourcePath, $temporary)) {
        throw new RuntimeException('copy to temporary target failed');
      }
      clearstatcache(true, $temporary);
      $temporarySize = filesize($temporary);
      $temporaryHash = hash_file('sha256', $temporary);
      if ($temporarySize !== $sourceSize || $temporaryHash !== $sourceHash) {
        throw new RuntimeException('staged sidecar verification failed');
      }
      if (!chmod($temporary, 0660)) {
        throw new RuntimeException('cannot secure staged sidecar permissions');
      }
      if (!rename($temporary, $targetPath)) {
        throw new RuntimeException('atomic sidecar publication failed');
      }
      $temporary = '';
      return 'copied';
    } catch (Throwable $exception) {
      $this->logFailure($sourcePath, $targetPath, $exception->getMessage());
      return 'failed';
    } finally {
      if ($temporary !== '' && is_file($temporary) && !unlink($temporary)) {
        $this->app->logger()->warning('sidecar backup temporary cleanup failed', ['path' => $temporary]);
      }
    }
  }

  private function logFailure(string $sourcePath, string $targetPath, string $reason): void
  {
    $this->app->logger()->warning('sidecar backup failed', [
      'source' => $sourcePath,
      'target' => $targetPath,
      'reason' => $reason,
    ]);
  }
}
