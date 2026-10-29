<?php
declare(strict_types=1);

namespace Webcam\Service\Interaction;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Übersetzt öffentliche Bildpfade in eine erlaubte, vorhandene Quelldatei.
 *
 * Leseführung:
 * 1. mapBestOfToCapture bevorzugt bei Galeriepfaden den Original-Capture.
 * 2. belongsToCamera verhindert kameraübergreifende Pfadauflösung.
 * 3. resolveSource liefert Pfad und gegebenenfalls korrigierten Browserpfad.
 */
final class BestOfSourceResolver
{
  public function __construct(private readonly App $app) {}

  public function mapBestOfToCapture(Camera $camera, string $relativePath): string
  {
    if (!preg_match('#^bestof/([a-z0-9-]+)/(.+)$#i', $relativePath, $match)) {
      return $relativePath;
    }
    if ($match[1] !== $camera->slug()) {
      return $relativePath;
    }

    $fileName = basename($match[2]);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})(?:_utc([1-9]\d{0,10}))?_(la|lm|sm|hu|full)\.jpe?g$/i', $fileName, $fileMatch)) {
      $cameraDay = sprintf('%s/%s/%s/%s', $camera->slug(), $fileMatch[1], $fileMatch[2], $fileMatch[3]);
      foreach (['cache/' . $cameraDay . '/' . $fileName, 'archive/' . $cameraDay . '/' . $fileName] as $candidate) {
        $absolute = str_starts_with($candidate, 'cache/')
          ? $this->app->paths()->cachePath(substr($candidate, strlen('cache/')))
          : $this->app->paths()->storagePath($candidate);
        if (is_file($absolute)) {
          return $candidate;
        }
      }
    }

    if ($this->app->config('deferred_image_root') && isset($fileMatch[6])) {
      $date = \Webcam\Service\Storage\CaptureFilename::date(
        $fileMatch[1] . $fileMatch[2] . $fileMatch[3] . $fileMatch[4] . $fileMatch[5] . $fileMatch[6],
        new \DateTimeZone($this->app->config('timezone', 'UTC')), $fileMatch[7] ?? '');
      if ($date) {
        $row = $this->app->deferredImages()->forSlot($camera, $date->getTimestamp());
        if ($row) return \Webcam\Service\Storage\DeferredImages::relative($camera, $row['image_key'], $fileMatch[8]);
      }
    }

    // Alte Best-Of-Einträge dürfen weiterhin direkt auf die Galeriedatei zeigen.
    return 'bestof/' . $camera->slug() . '/' . $fileName;
  }

  public function belongsToCamera(string $relativePath, string $slug): bool
  {
    if (\Webcam\Service\Storage\DeferredImages::isPath($relativePath)) {
      $parts = \Webcam\Service\Storage\DeferredImages::parse($relativePath);
      return $parts !== null && $parts[0] === $slug;
    }
    $path = strtolower(ltrim(str_replace('\\', '/', $relativePath), '/'));
    $slug = strtolower($slug);
    if ($slug === '') {
      return false;
    }

    foreach ([
      'cache/' . $slug . '/',
      'archive/' . $slug . '/',
      'storage/archive/' . $slug . '/',
      'bestof/' . $slug . '/',
      'storage/bestof/' . $slug . '/',
      $slug . '/',
    ] as $prefix) {
      if (str_starts_with($path, $prefix)) {
        return true;
      }
    }
    return false;
  }

  /** @return array{relative_path:string,source_path:string}|null */
  public function resolveSource(Camera $camera, string $relativePath): ?array
  {
    if (str_starts_with($relativePath, 'bestof/')) {
      $sourcePath = $this->app->paths()->storagePath($relativePath);
    } elseif (str_starts_with($relativePath, 'storage/bestof/')) {
      $sourcePath = $this->app->paths()->storagePath(substr($relativePath, strlen('storage/')));
    } else {
      $sourcePath = $this->app->resolveCameraStoragePath($camera, $relativePath);
    }

    if ($sourcePath !== null && is_file($sourcePath)) {
      return ['relative_path' => $relativePath, 'source_path' => $sourcePath];
    }

    // Galeriepfade bleiben als Fallback wichtig, wenn Cache oder Archiv rotiert wurde.
    $fallback = rtrim($this->app->bestOfDirectory($camera), DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR . basename($relativePath);
    if (!is_file($fallback)) {
      return null;
    }
    return [
      'relative_path' => 'bestof/' . $camera->slug() . '/' . basename($fallback),
      'source_path' => $fallback,
    ];
  }
}
