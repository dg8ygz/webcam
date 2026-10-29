<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

/**
 * Entscheidet, ob ein knapp verpasster Pull-Slot kontrolliert nachgeholt wird.
 *
 * Eine lange Capture-Runde darf den unmittelbar vorherigen Slot nicht verlieren.
 * Nach einer längeren Kamera-/Serverunterbrechung dürfen aktuelle Bilder aber
 * nicht rückwirkend in viele historische Slots geschrieben werden.
 */
final class PullSnapshotSlotPlanner
{
  private const OVERDUE_GRACE_SECONDS = 60;

  public static function targetSlot(int $nextDue, int $interval, int $now): ?int
  {
    if ($nextDue <= 0 || $nextDue > $now) {
      return null;
    }

    $interval = max(60, $interval);
    $lag = $now - $nextDue;
    if ($lag > $interval + self::OVERDUE_GRACE_SECONDS) {
      return null;
    }

    return $nextDue;
  }
}
