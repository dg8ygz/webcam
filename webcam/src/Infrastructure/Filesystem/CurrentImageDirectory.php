<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Filesystem;

use RuntimeException;

/** Stable current-image location shared by admin provisioning and capture. */
final class CurrentImageDirectory
{
  public function __construct(private readonly string $projectRoot) {}

  public function path(string $slug): string
  {
    if (!preg_match('/^[a-z0-9-]+$/D', $slug)) {
      throw new RuntimeException('Invalid camera slug');
    }
    $root = rtrim($this->projectRoot, '/');
    $legacy = "$root/$slug/current";
    $runtime = "$root/cache/$slug/current";
    // Existing installations retain their explicitly writable legacy mount.
    // New cameras always use cache, even when CLI/root could create legacy.
    return is_dir($runtime) || !is_dir($legacy) ? $runtime : $legacy;
  }

  public function prepare(string $slug): string
  {
    $path = $this->path($slug);
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('Current-Verzeichnis konnte nicht angelegt werden.');
    }
    clearstatcache(true, $path);
    if (!is_writable($path)) {
      throw new RuntimeException('Current-Verzeichnis ist nicht schreibbar.');
    }
    return $path;
  }
}
