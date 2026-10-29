<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use RuntimeException;

final class FileLockLease
{
  /** @param resource $handle */
  private function __construct(private $handle, public readonly string $path) {}

  /** @param array<string,mixed> $metadata */
  public static function acquire(string $path, array $metadata): ?self
  {
    $handle = fopen($path, 'c');
    if (!is_resource($handle)) {
      throw new RuntimeException('Lock file cannot be opened: ' . $path);
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    return new self($handle, $path);
  }

  public function release(): void
  {
    if (!is_resource($this->handle)) {
      return;
    }
    flock($this->handle, LOCK_UN);
    fclose($this->handle);
    $this->handle = null;
  }

  public function __destruct()
  {
    $this->release();
  }
}
