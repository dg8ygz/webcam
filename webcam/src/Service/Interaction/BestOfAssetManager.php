<?php
declare(strict_types=1);

namespace Webcam\Service\Interaction;

use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/** Pflegt nach erfolgreicher Veröffentlichung Sidecar, Thumbnail, Log und Caches. */
final class BestOfAssetManager
{
  public function __construct(private readonly App $app) {}

  public function finish(Camera $camera, string $sourcePath, string $targetDir, string $targetPath, string $baseName): void
  {
    $this->copySidecar($sourcePath, $targetPath, '_ex.txt');
    $this->createThumbnail($sourcePath, $targetDir, $baseName);
    $this->appendLog($targetDir . DIRECTORY_SEPARATOR . 'add.log', $targetPath);
    $this->invalidatePageCache($camera->slug());
    $this->invalidateArchiveCache($camera->slug(), $sourcePath);
  }

  private function appendLog(string $logPath, string $filePath): void
  {
    if (file_put_contents($logPath, sprintf("%s\t%s\n", date('c'), $filePath), FILE_APPEND) === false) {
      $this->app->logger()->warning('bestof log write failed', ['path' => $logPath]);
    }
  }

  private function copySidecar(string $source, string $target, string $suffix): void
  {
    $sourceCandidate = preg_replace('/\.jpg$/i', $suffix, $source);
    $targetCandidate = preg_replace('/\.jpg$/i', $suffix, $target);
    if (!$sourceCandidate || !is_file($sourceCandidate) || !$targetCandidate) {
      return;
    }
    if (!AtomicFileWriter::copy($sourceCandidate, $targetCandidate)) {
      $this->app->logger()->warning('bestof sidecar copy failed', [
        'source' => $sourceCandidate,
        'target' => $targetCandidate,
      ]);
    }
  }

  private function invalidatePageCache(string $slug): void
  {
    try {
      foreach (glob($this->app->paths()->statePath('page-cache/' . $slug) . '/live_*.html') ?: [] as $file) {
        if (!unlink($file)) {
          $this->app->logger()->warning('page cache invalidate failed', ['path' => $file, 'camera' => $slug]);
        }
      }
    } catch (Throwable) {
      // Cache-Invalidierung ist Best-Effort und darf den Like nicht zurückrollen.
    }
  }

  private function invalidateArchiveCache(string $slug, string $sourcePath): void
  {
    try {
      $year = preg_match('#/(\d{4})/#', str_replace('\\', '/', $sourcePath), $match)
        ? $match[1]
        : date('Y');
      $path = $this->app->paths()->statePath('archive-cache/' . $slug)
        . DIRECTORY_SEPARATOR . $year . '.json';
      if (is_file($path) && !unlink($path)) {
        $this->app->logger()->warning('archive cache invalidate failed', ['path' => $path, 'camera' => $slug]);
      }
    } catch (Throwable) {
      // Best-Effort wie beim Seiten-Cache.
    }
  }

  private function createThumbnail(string $source, string $targetDir, string $baseName): void
  {
    if (!function_exists('imagecreatefromjpeg')) {
      return;
    }
    $image = imagecreatefromjpeg($source);
    if (!$image) {
      return;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width <= 0 || $height <= 0) {
      imagedestroy($image);
      return;
    }
    $targetWidth = min(200, $width);
    $targetHeight = (int) max(1, round($height * ($targetWidth / $width)));
    $thumbnail = $targetWidth >= $width
      ? $image
      : imagescale($image, $targetWidth, $targetHeight, IMG_BICUBIC);
    if (!$thumbnail) {
      imagedestroy($image);
      return;
    }
    $thumbName = preg_replace('/\.jpg$/i', '_thumb.jpg', $baseName) ?: $baseName . '_thumb.jpg';
    $thumbPath = $targetDir . DIRECTORY_SEPARATOR . $thumbName;
    $written = AtomicFileWriter::writeWithCallback(
      $thumbPath,
      static fn($handle): bool => imagejpeg($thumbnail, $handle, 85),
      static function (string $path): bool {
        $info = @getimagesize($path);
        return is_array($info) && ($info[2] ?? null) === IMAGETYPE_JPEG;
      }
    );
    if (!$written) {
      $this->app->logger()->warning('bestof thumbnail atomic publish failed', ['path' => $thumbPath]);
    }
    if ($thumbnail !== $image) {
      imagedestroy($thumbnail);
    }
    imagedestroy($image);
  }
}
