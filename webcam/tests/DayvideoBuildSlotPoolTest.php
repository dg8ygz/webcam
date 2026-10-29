<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Dayvideo\DayvideoBuildSlotPool;

final class DayvideoBuildSlotPoolTest extends TestCase
{
  public function testGlobalPoolAllowsOnlyConfiguredNumberOfBuilds(): void
  {
    $directory = TestTempDir::ensure() . '/dayvideo-slots-' . bin2hex(random_bytes(4));
    $pool = new DayvideoBuildSlotPool($directory, 2);

    $first = $pool->acquire(['camera' => 'one']);
    $second = $pool->acquire(['camera' => 'two']);
    $third = $pool->acquire(['camera' => 'three']);

    self::assertNotNull($first);
    self::assertNotNull($second);
    self::assertNull($third);
    $first->release();
    self::assertNotNull($pool->acquire(['camera' => 'three']));
    $second->release();
  }

  public function testPoolRejectsMoreThanTwoSlots(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new DayvideoBuildSlotPool(TestTempDir::ensure() . '/invalid-slots', 3);
  }
}
