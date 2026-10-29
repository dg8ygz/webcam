<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DirectoryIterator;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;

/**
 * Verschiebt bzw. erzeugt die archivierten Varianten eines abgeschlossenen Tages.
 * Der Aufrufer wählt Datum und overwrite-Verhalten; diese Klasse prüft Quellen,
 * Zielpfade und vorhandene Dateien für jeden Capture inhaltlich.
 */
final class DailyArchiver
{

  private int $minCaptureBytes;
  private readonly CameraStoragePathResolver $storagePaths;

  public function __construct(private readonly App $app)
  {
    $this->storagePaths = $app->cameraStoragePaths();
    $minBytes = (int) ($this->app->config('archive_min_jpeg_bytes') ?? 8192);
    $this->minCaptureBytes = max(1024, $minBytes);
  }

  /**
   * @return array{processed:int,skipped:int,missing:bool}
   */
  public function archiveDay(Camera $camera, DateTimeImmutable $day, bool $overwrite = false, ?callable $progress = null): array
  {
    (new ArchiveMountGuard($this->app))->assertAvailable('daily archive rollover');

    $deferredVerified = $this->app->deferredImages()->verifyDay($camera, $day);
    $slug = $camera->slug();
    $baseRoot = $this->storagePaths->baseRoot($camera);
    $dateKey = $day->format('Y/m/d');
    $archiveDirName = $camera->storageArchiveDir();
    $this->emitProgress($progress, 'checking', 0, 0, null);

    $sourceDir = $this->resolveSourceDirectory($camera, $dateKey);
    if ($sourceDir === null) {
      $this->emitProgress($progress, 'missing', 0, 0, null);
      return [
        'processed' => 0,
        'skipped' => $deferredVerified,
        'deferred_verified' => $deferredVerified,
        'missing' => $deferredVerified === 0,
      ];
    }

    $targetDir = $this->storagePaths->join($baseRoot, $archiveDirName . '/' . $slug . '/' . $dateKey);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
      clearstatcache(true, $targetDir);
    }
    if (!is_dir($targetDir)) {
      throw new RuntimeException("Unable to create archive directory: {$targetDir}");
    }

    $processed = 0;
    $skipped = $deferredVerified;
    $verifier = new ArchiveCompletenessVerifier($this->app);

    // Archive content is intentionally mount-managed. We do not chown/chgrp the
    // generated files here because archive paths can live on CIFS/NFS, and the
    // separate permission fixer already excludes archive payloads.
    $ownership = ['uid' => null, 'gid' => null];
    $this->emitProgress($progress, 'start', 0, 0, null);

    foreach (new DirectoryIterator($sourceDir) as $entry) {
      if ($entry->isDot() || !$entry->isFile()) {
        continue;
      }
      $filename = $entry->getFilename();
      if (!preg_match('/^(\d{8}_\d{6}(?:_utc[1-9]\d{0,10})?)\.jpg$/D', $filename, $matches)) {
        continue;
      }
      if (preg_match('/_\d{3,4}\.jpg$/i', $filename) || preg_match('/_[a-z]{2,4}\.jpg$/i', $filename)) {
        continue;
      }
      $baseKey = $matches[1];
      $timeLabel = CaptureFilename::archiveFromCache($baseKey);

      $sourcePath = $entry->getPathname();
      $destBase = $targetDir . '/' . $timeLabel;
      $destFiles = [
        'sm' => $destBase . '_sm.jpg',
        'la' => $destBase . '_la.jpg',
        'lm' => $destBase . '_lm.jpg',
        'hu' => $destBase . '_hu.jpg',
        'full' => $destBase . '_full.jpg',
      ];
      $sidecars = [
        $sourceDir . '/' . $baseKey . '_ex.txt' => $destBase . '_ex.txt',
        $sourceDir . '/' . $baseKey . '_WX.txt' => $destBase . '_wx.txt',
      ];
      $verification = $verifier->verifyCapture($sourcePath, $destBase, $sidecars);
      if (!$overwrite && $verification['complete']) {
        $skipped++;
        $this->emitProgress($progress, 'progress', $processed, $skipped, $timeLabel);
        continue;
      }

      $repairExistingSet = !$verification['complete'] && $this->archivePayloadPresent($destBase);
      if (!$this->isValidSourceImage($sourcePath, $slug, $timeLabel)) {
        $skipped++;
        $this->emitProgress($progress, 'progress', $processed, $skipped, $timeLabel);
        continue;
      }
      if ($repairExistingSet) {
        $this->app->logger()->warning('daily archiver repairing invalid target set', [
          'camera' => $slug,
          'capture' => $timeLabel,
          'reason' => $verification['reason'],
        ]);
        printf(
          "[daily-archiver] %s %s repairing invalid target set: %s\n",
          $slug,
          $timeLabel,
          $verification['reason']
        );
      }

      $image = imagecreatefromjpeg($sourcePath);
      if (!$image) {
        $this->logWarning($slug, $timeLabel, 'imagecreatefromjpeg failed');
        $skipped++;
        $this->emitProgress($progress, 'progress', $processed, $skipped, $timeLabel);
        continue;
      }
      $sourceWidth = imagesx($image);
      $sourceHeight = imagesy($image);

      try {
        $this->writeVariants(
          $image,
          $sourcePath,
          $sourceWidth,
          $sourceHeight,
          $destFiles,
          $overwrite || $repairExistingSet
        );
      } finally {
        imagedestroy($image);
      }

      $exifFile = $this->copyExifSidecar($sourceDir, $destBase, $baseKey);
      if ($exifFile) {
        $destFiles['exif'] = $exifFile;
      }
      $weatherFile = $this->copyWeatherSidecar($sourceDir, $destBase, $baseKey);
      if ($weatherFile) {
        $destFiles['weather'] = $weatherFile;
      }
      $this->syncTimestamps($destFiles, $entry->getMTime());
      $this->applyOwnership($destFiles, $ownership['uid'], $ownership['gid']);

      $verification = $verifier->verifyCapture($sourcePath, $destBase, $sidecars);
      if (!$verification['complete']) {
        throw new RuntimeException(sprintf(
          'Archive target verification failed for %s %s: %s',
          $slug,
          $timeLabel,
          $verification['reason']
        ));
      }

      $processed++;
      $this->emitProgress($progress, 'progress', $processed, $skipped, $timeLabel);
    }

    $this->emitProgress($progress, 'done', $processed, $skipped, null);

    return [
      'processed' => $processed,
      'skipped' => $skipped,
      'missing' => false,
    ];
  }

  private function resolveSourceDirectory(Camera $camera, string $dateKey): ?string
  {
    $cacheDir = $this->app->paths()->cachePath($camera->slug() . '/' . $dateKey);
    if (is_dir($cacheDir)) {
      return $cacheDir;
    }

    $baseRoot = $this->storagePaths->baseRoot($camera);
    $legacyDir = $this->storagePaths->join($baseRoot, $camera->storageCurrentDir() . '/' . $camera->slug() . '/' . $dateKey);
    if (is_dir($legacyDir)) {
      return $legacyDir;
    }

    return null;
  }

  private function emitProgress(?callable $progress, string $phase, int $processed, int $skipped, ?string $timeLabel): void
  {
    if ($progress === null) {
      return;
    }

    $progress([
      'phase' => $phase,
      'processed' => $processed,
      'skipped' => $skipped,
      'current' => $timeLabel,
    ]);
  }

  private function archivePayloadPresent(string $destBase): bool
  {
    $paths = [];
    foreach (array_keys(VariantProfiles::CANONICAL_WIDTHS) as $suffix) {
      $paths[] = $destBase . '_' . $suffix . '.jpg';
    }
    foreach (VariantProfiles::ORIGINAL_CLONES as $suffix) {
      $paths[] = $destBase . '_' . $suffix . '.jpg';
    }
    $paths[] = $destBase . '_ex.txt';
    $paths[] = $destBase . '_wx.txt';
    foreach ($paths as $path) {
      if (!is_file($path)) {
        continue;
      }
      return true;
    }
    return false;
  }

  /**
   * @param array<string,string> $destFiles
   */
  private function writeVariants(\GdImage $image, string $sourcePath, int $sourceWidth, int $sourceHeight, array $destFiles, bool $overwrite): void
  {
    foreach (VariantProfiles::CANONICAL_WIDTHS as $suffix => $width) {
      $targetWidth = (int) min($width, $sourceWidth);
      $destination = $destFiles[$suffix];
      if (!$overwrite && is_file($destination)) {
        continue;
      }
      $this->exportResizedVariant($image, $sourceWidth, $sourceHeight, $targetWidth, $destination);
    }

    foreach (VariantProfiles::ORIGINAL_CLONES as $suffix) {
      if (!isset($destFiles[$suffix])) {
        continue;
      }
      $target = $destFiles[$suffix];
      if ($overwrite || !is_file($target)) {
        if (!AtomicFileWriter::copy($sourcePath, $target)) {
          $this->app->logger()->warning('daily archiver copy failed', [
            'source' => $sourcePath,
            'target' => $target,
          ]);
          throw new RuntimeException("Unable to publish archive clone: {$target}");
        }
      }
    }
  }

  private function exportResizedVariant(\GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, string $destination): void
  {
    $outputImage = $source;
    $destroyOutput = false;
    if ($targetWidth <= 0 || $targetWidth >= $sourceWidth) {
      $targetWidth = $sourceWidth;
    } else {
      $targetHeight = (int) max(1, round($sourceHeight * ($targetWidth / $sourceWidth)));
      $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
      if ($canvas) {
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        $outputImage = $canvas;
        $destroyOutput = true;
      }
    }

    $written = AtomicFileWriter::writeWithCallback(
      $destination,
      static fn($handle): bool => imagejpeg($outputImage, $handle, 92),
      static function (string $path): bool {
        $info = @getimagesize($path);
        return is_array($info) && ($info[2] ?? null) === IMAGETYPE_JPEG;
      }
    );
    if ($destroyOutput) {
      imagedestroy($outputImage);
    }
    if (!$written) {
      throw new RuntimeException("Unable to publish archive variant: {$destination}");
    }
  }

  private function copyExifSidecar(string $sourceDir, string $destBase, string $baseKey): ?string
  {
    $sourceExif = $sourceDir . '/' . $baseKey . '_ex.txt';
    if (!is_file($sourceExif)) {
      return null;
    }
    $destExif = $destBase . '_ex.txt';
    if (AtomicFileWriter::copy($sourceExif, $destExif)) {
      return $destExif;
    }
    $this->app->logger()->warning('daily archiver exif copy failed', [
      'source' => $sourceExif,
      'target' => $destExif,
    ]);
    return null;
  }

  private function copyWeatherSidecar(string $sourceDir, string $destBase, string $baseKey): ?string
  {
    $sourceWeather = $sourceDir . '/' . $baseKey . '_WX.txt';
    if (!is_file($sourceWeather)) {
      return null;
    }
    $destWeather = $destBase . '_wx.txt';
    if (AtomicFileWriter::copy($sourceWeather, $destWeather)) {
      return $destWeather;
    }
    $this->app->logger()->warning('daily archiver weather copy failed', [
      'source' => $sourceWeather,
      'target' => $destWeather,
    ]);
    return null;
  }

  /**
   * @param array<string,string> $files
   */
  private function syncTimestamps(array $files, int $mtime): void
  {
    foreach ($files as $path) {
      if (is_file($path)) {
        if (!touch($path, $mtime)) {
          $this->app->logger()->warning('daily archiver touch failed', ['path' => $path]);
        }
      }
    }
  }

  private function isValidSourceImage(string $sourcePath, string $slug, string $timeLabel): bool
  {
    if (!is_readable($sourcePath)) {
      $this->logWarning($slug, $timeLabel, 'source not readable');
      return false;
    }
    $size = filesize($sourcePath);
    if ($size === false || $size < $this->minCaptureBytes) {
      $this->logWarning($slug, $timeLabel, sprintf('filesize too small (%d bytes)', (int) $size));
      return false;
    }
    $dimensions = getimagesize($sourcePath);
    if ($dimensions === false || (int) ($dimensions[0] ?? 0) < 2 || (int) ($dimensions[1] ?? 0) < 2) {
      $this->logWarning($slug, $timeLabel, 'invalid jpeg dimensions');
      return false;
    }
    return true;
  }

  private function logWarning(string $slug, string $timeLabel, string $message): void
  {
    printf("[daily-archiver] %s %s skipped: %s\n", $slug, $timeLabel, $message);
  }

  /**
   * @return array{uid:?int,gid:?int}
   */
  private function applyOwnership(array $paths, ?int $uid, ?int $gid): void
  {
    if ($uid === null && $gid === null) {
      return;
    }
    foreach ($paths as $path) {
      if (!is_string($path) || $path === '' || !file_exists($path)) {
        continue;
      }
      if ($uid !== null && !@chown($path, $uid)) {
        $this->app->logger()->warning('daily archiver chown failed', ['path' => $path, 'uid' => $uid]);
      }
      if ($gid !== null && !@chgrp($path, $gid)) {
        $this->app->logger()->warning('daily archiver chgrp failed', ['path' => $path, 'gid' => $gid]);
      }
    }
  }

}
