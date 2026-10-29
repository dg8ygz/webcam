<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use Webcam\App;

/** Wählt begrenzt Varianten aus und löst nur bekannte Cache-/Archivpfade auf. */
final class DayvideoFrameSelector
{
  public function __construct(private readonly App $app) {}

  /** @return list<string> */
  public function select(array $entries, int $maxFrames, int $maxWidth): array
  {
    $files = [];
    foreach ($this->sample($entries, $maxFrames) as $entry) {
      $candidate = $this->pickVariant($entry, $maxWidth);
      $resolved = $candidate !== null ? $this->resolvePath($candidate) : null;
      if ($resolved !== null && is_file($resolved)) {
        $files[] = $resolved;
      }
    }
    return array_values(array_unique($files));
  }

  /** @return list<array<string,mixed>> */
  private function sample(array $entries, int $maxFrames): array
  {
    if (count($entries) <= $maxFrames) {
      return array_values(array_filter($entries, 'is_array'));
    }
    $sampled = [];
    $step = count($entries) / $maxFrames;
    for ($index = 0; $index < $maxFrames; $index++) {
      $entry = $entries[min((int) floor($index * $step), count($entries) - 1)];
      if (is_array($entry)) {
        $sampled[] = $entry;
      }
    }
    return $sampled;
  }

  private function pickVariant(array $entry, int $maxWidth): ?string
  {
    $variants = is_array($entry['variants'] ?? null) ? $entry['variants'] : [];
    $chosen = null;
    $bestWidth = -1;
    foreach ($variants as $key => $info) {
      if (!is_array($info) || !is_string($info['url'] ?? null)) {
        continue;
      }
      $width = is_numeric($key) ? (int) $key : (is_numeric($info['width'] ?? null) ? (int) $info['width'] : 0);
      if ($width > $bestWidth && $width <= $maxWidth) {
        $bestWidth = $width;
        $chosen = \Webcam\Service\Storage\DeferredImages::isPath((string) ($info['relative_path'] ?? '')) ? $info['relative_path'] : $info['url'];
      }
    }
    return $chosen ?? (is_string($entry['url'] ?? null) ? $entry['url'] : null);
  }

  private function resolvePath(string $candidate): ?string
  {
    if (\Webcam\Service\Storage\DeferredImages::isPath($candidate)) {
      $parts = \Webcam\Service\Storage\DeferredImages::parse($candidate);
      if ($parts === null) return null;
      return $this->app->resolveCameraStoragePath($this->app->cameraOrFail($parts[0]), $candidate);
    }
    $urlPath = parse_url($candidate, PHP_URL_PATH);
    $relative = ltrim(is_string($urlPath) ? $urlPath : $candidate, '/');
    if ($relative === '' || str_contains($relative, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative)) {
      return null;
    }
    if (str_starts_with($relative, 'cache/')) {
      return $this->app->paths()->cachePath(substr($relative, 6));
    }
    if (str_starts_with($relative, 'storage/archive/')) {
      return $this->app->paths()->archivePath(substr($relative, 16));
    }
    if (str_starts_with($relative, 'archive/')) {
      return $this->app->paths()->archivePath(substr($relative, 8));
    }
    return null;
  }
}
