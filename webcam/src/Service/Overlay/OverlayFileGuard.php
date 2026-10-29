<?php
declare(strict_types=1);

namespace Webcam\Service\Overlay;

use RuntimeException;
use Webcam\App;

/** Koordiniert Overlay-Publikation und Orphan-Cleanup ueber denselben Lock. */
final class OverlayFileGuard
{
  /** @param resource $handle */
  private function __construct(
    private $handle,
    private readonly string $path
  ) {}

  public static function acquire(App $app, int $timeoutSeconds = 30): self
  {
    $dir = $app->paths()->statePath('overlay-files');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('Overlay-Lockverzeichnis konnte nicht erstellt werden: ' . $dir);
    }
    $path = $dir . DIRECTORY_SEPARATOR . 'operation.lock';
    $handle = @fopen($path, 'c');
    if (!is_resource($handle)) {
      throw new RuntimeException('Overlay-Lock konnte nicht geoeffnet werden: ' . $path);
    }

    $deadline = microtime(true) + max(1, $timeoutSeconds);
    do {
      if (@flock($handle, LOCK_EX | LOCK_NB)) {
        return new self($handle, $path);
      }
      usleep(100000);
    } while (microtime(true) < $deadline);

    fclose($handle);
    throw new RuntimeException('Overlay-Lock ist belegt: ' . $path);
  }

  public static function recentlyPublished(string $path, int $now, int $graceSeconds): bool
  {
    if ($graceSeconds <= 0 || !is_file($path)) {
      return false;
    }
    $mtime = @filemtime($path);
    return is_int($mtime) && $mtime > 0 && ($now - $mtime) < $graceSeconds;
  }

  public function release(): void
  {
    if (!is_resource($this->handle)) {
      return;
    }
    @flock($this->handle, LOCK_UN);
    fclose($this->handle);
    $this->handle = null;
  }

  public function path(): string
  {
    return $this->path;
  }

  public function __destruct()
  {
    $this->release();
  }
}
