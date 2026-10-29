<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use RuntimeException;

/** Hostweit geteilter Slot-Pool; derselbe Pfad gilt für DEV und PROD. */
final class DayvideoBuildSlotPool
{
  public function __construct(
    private readonly string $directory,
    private readonly int $maxConcurrent
  ) {
    if ($maxConcurrent < 1 || $maxConcurrent > 2) {
      throw new \InvalidArgumentException('Dayvideo concurrency must be 1 or 2');
    }
  }

  /** @param array<string,mixed> $metadata */
  public function acquire(array $metadata): ?FileLockLease
  {
    if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
      throw new RuntimeException('Global dayvideo lock directory unavailable');
    }
    for ($slot = 1; $slot <= $this->maxConcurrent; $slot++) {
      $lease = FileLockLease::acquire(
        $this->directory . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock',
        $metadata + ['slot' => $slot]
      );
      if ($lease !== null) {
        return $lease;
      }
    }
    return null;
  }
}
