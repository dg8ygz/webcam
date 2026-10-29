<?php
declare(strict_types=1);

namespace Webcam\Service\Interaction;

use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Coordinates the Best-Of image publication with the database like operation.
 *
 * SQLite and the filesystem cannot share one transaction. The image is therefore
 * published atomically first while a per-image lock is held. A rejected or failed
 * database operation removes only the image created by the current request.
 */
final class BestOfLikeCoordinator
{
  public function __construct(private readonly App $app) {}

  /**
   * @param callable():array{ok:bool,code?:string,message?:string,likes_count?:int} $recordLike
   * @return array{
   *   result:array{ok:bool,code?:string,message?:string,likes_count?:int},
   *   target_dir:string,
   *   target_path:string,
   *   base_name:string,
   *   created:bool
   * }
   */
  public function execute(Camera $camera, string $sourcePath, callable $recordLike): array
  {
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
      throw new RuntimeException("Best-Of source is not readable: {$sourcePath}");
    }

    $targetDir = $this->app->bestOfDirectory($camera);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
      throw new RuntimeException("Unable to create Best-Of directory: {$targetDir}");
    }

    $baseName = basename($sourcePath);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $baseName;
    $lock = $this->acquireLock($camera, $baseName);
    $created = false;

    try {
      if (!is_file($targetPath)) {
        $this->publishAtomically($sourcePath, $targetPath);
        $created = true;
      }

      try {
        $result = $recordLike();
      } catch (Throwable $exception) {
        $this->rollbackCreatedImage($targetPath, $created);
        throw $exception;
      }
      if (!is_array($result)) {
        $this->rollbackCreatedImage($targetPath, $created);
        throw new RuntimeException('Best-Of like operation returned an invalid result');
      }

      $repairsExistingLike = ($result['code'] ?? null) === 'duplicate_image';
      if (!($result['ok'] ?? false) && !$repairsExistingLike) {
        $this->rollbackCreatedImage($targetPath, $created);
      }

      return [
        'result' => $result,
        'target_dir' => $targetDir,
        'target_path' => $targetPath,
        'base_name' => $baseName,
        'created' => $created && (($result['ok'] ?? false) || $repairsExistingLike),
      ];
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  /** @return resource */
  private function acquireLock(Camera $camera, string $baseName)
  {
    $lockDir = $this->app->paths()->statePath('bestof-lock/' . $camera->slug());
    if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
      throw new RuntimeException("Unable to create Best-Of lock directory: {$lockDir}");
    }

    $lockPath = $lockDir . DIRECTORY_SEPARATOR . hash('sha256', $baseName) . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
      throw new RuntimeException("Unable to open Best-Of lock: {$lockPath}");
    }
    if (!flock($lock, LOCK_EX)) {
      fclose($lock);
      throw new RuntimeException("Unable to acquire Best-Of lock: {$lockPath}");
    }
    return $lock;
  }

  private function publishAtomically(string $sourcePath, string $targetPath): void
  {
    $targetDir = dirname($targetPath);
    $temporaryPath = tempnam($targetDir, '.bestof-');
    if ($temporaryPath === false) {
      throw new RuntimeException("Unable to create temporary Best-Of file in {$targetDir}");
    }

    try {
      $sourceSize = filesize($sourcePath);
      if ($sourceSize === false || $sourceSize <= 0) {
        throw new RuntimeException("Best-Of source is empty or unreadable: {$sourcePath}");
      }
      if (!copy($sourcePath, $temporaryPath)) {
        throw new RuntimeException("Unable to stage Best-Of image: {$sourcePath}");
      }
      $targetSize = filesize($temporaryPath);
      if ($targetSize === false || $targetSize !== $sourceSize) {
        throw new RuntimeException("Staged Best-Of image has an invalid size: {$temporaryPath}");
      }
      if (!chmod($temporaryPath, 0664)) {
        $this->app->logger()->warning('bestof chmod failed', ['path' => $temporaryPath]);
      }
      if (!rename($temporaryPath, $targetPath)) {
        throw new RuntimeException("Unable to publish Best-Of image: {$targetPath}");
      }
      $temporaryPath = '';
    } finally {
      if ($temporaryPath !== '' && is_file($temporaryPath) && !unlink($temporaryPath)) {
        $this->app->logger()->warning('bestof temporary cleanup failed', ['path' => $temporaryPath]);
      }
    }
  }

  private function rollbackCreatedImage(string $targetPath, bool $created): void
  {
    if ($created && is_file($targetPath) && !unlink($targetPath)) {
      throw new RuntimeException("Unable to roll back Best-Of image: {$targetPath}");
    }
  }
}
