<?php
declare(strict_types=1);

namespace Webcam\Service\Interaction;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/** Legt für historische Dateien bei Bedarf einen minimalen Capture-Datensatz an. */
final class BestOfCaptureRegistrar
{
  public function __construct(private readonly App $app) {}

  public function ensure(Camera $camera, string $relativePath, string $sourcePath): ?int
  {
    try {
      $store = new CameraDataStore($this->app, $camera);
      $normalized = $store->normalizeRelativePath($relativePath);
      $existingId = $store->findCaptureIdByAnyPath($normalized);
      if ($existingId !== null) {
        return $existingId;
      }

      $slotTs = $this->deriveSlotTimestamp($normalized, $sourcePath);
      for ($attempt = 0; $store->findCaptureIdBySlot($slotTs) !== null && $attempt < 5; $attempt++) {
        $slotTs++;
      }
      $variants = $this->variantMetadata($normalized, $sourcePath);
      $store->recordCapture([
        'slot_timestamp' => $slotTs,
        'captured_timestamp' => $slotTs,
        'relative_path' => $normalized,
        'variants' => $variants,
      ]);
      $this->app->logger()->warning('like capture missing; created placeholder', [
        'camera' => $camera->slug(),
        'path' => $normalized,
        'slot_ts' => $slotTs,
      ]);
      return $store->findCaptureIdByAnyPath($normalized);
    } catch (Throwable $exception) {
      $this->app->logger()->warning('like capture placeholder failed', [
        'camera' => $camera->slug(),
        'path' => $relativePath,
        'error' => $exception->getMessage(),
      ]);
      return null;
    }
  }

  private function deriveSlotTimestamp(string $relativePath, string $sourcePath): int
  {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})(?:_utc([1-9]\d{0,10}))?(?:_(la|lm|sm|hu|full))?\.jpe?g$/i', basename($relativePath), $match)) {
      try {
        $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
      } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
      }
      $date = \Webcam\Service\Storage\CaptureFilename::date(
        $match[1] . $match[2] . $match[3] . $match[4] . $match[5] . $match[6], $timezone, $match[7] ?? '');
      if ($date) return $date->getTimestamp();
    }

    $mtime = filemtime($sourcePath);
    if ($mtime !== false && $mtime > 0) {
      return (int) $mtime;
    }
    if ($mtime === false) {
      $this->app->logger()->warning('bestof source mtime failed', ['path' => $sourcePath]);
    }
    return time();
  }

  /** @return list<array{variant:string,relative_path:string,mtime:?int,size:?int}> */
  private function variantMetadata(string $relativePath, string $sourcePath): array
  {
    if (!preg_match('/_(la|lm|sm|hu|full)\.jpe?g$/i', $relativePath, $match)) {
      return [];
    }
    $mtime = filemtime($sourcePath);
    $size = filesize($sourcePath);
    if ($mtime === false) {
      $this->app->logger()->warning('bestof source mtime failed', ['path' => $sourcePath]);
    }
    if ($size === false) {
      $this->app->logger()->warning('bestof source size failed', ['path' => $sourcePath]);
    }
    return [[
      'variant' => strtolower($match[1]),
      'relative_path' => $relativePath,
      'mtime' => $mtime === false ? null : $mtime,
      'size' => $size === false ? null : $size,
    ]];
  }
}
