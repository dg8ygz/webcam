<?php
declare(strict_types=1);

namespace Webcam\Service\Scheduler;

/** Berechnet den exponentiellen Capture-Backoff mit dokumentierter Obergrenze. */
final class Backoff
{
  public static function seconds(int $consecutiveFailures): int
  {
    if ($consecutiveFailures <= 0) {
      return 0;
    }
    $base = 60;
    $backoff = $base * (2 ** min(5, $consecutiveFailures - 1)); // 1m,2m,4m,8m,16m,32m
    return min($backoff, 1800); // max 30m
  }
}
