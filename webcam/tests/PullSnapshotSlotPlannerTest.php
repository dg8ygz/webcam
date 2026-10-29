<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\PullSnapshotSlotPlanner;

final class PullSnapshotSlotPlannerTest extends TestCase
{
  public function testKeepsImmediatelyPreviousSlotAfterLongPullRound(): void
  {
    $midnight = strtotime('2026-08-12 00:00:00 UTC');
    $this->assertIsInt($midnight);

    $nextDue = $midnight + 300;
    $captureAt = $midnight + 613;

    $this->assertSame(
      $nextDue,
      PullSnapshotSlotPlanner::targetSlot($nextDue, 300, $captureAt)
    );
  }

  public function testDoesNotFabricateSlotsAfterLongOutage(): void
  {
    $midnight = strtotime('2026-08-12 00:00:00 UTC');
    $this->assertIsInt($midnight);

    $this->assertNull(
      PullSnapshotSlotPlanner::targetSlot($midnight + 300, 300, $midnight + 1200)
    );
  }

  public function testReturnsNullForMissingOrFutureDueSlot(): void
  {
    $this->assertNull(PullSnapshotSlotPlanner::targetSlot(0, 300, 1000));
    $this->assertNull(PullSnapshotSlotPlanner::targetSlot(1200, 300, 1000));
  }
}
