<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Scheduler\Backoff;

final class SchedulerBackoffTest extends TestCase
{
  public function testBackoffSeconds(): void
  {
    $this->assertSame(0, Backoff::seconds(0));
    $this->assertSame(0, Backoff::seconds(-3));
    $this->assertSame(60, Backoff::seconds(1));
    $this->assertSame(120, Backoff::seconds(2));
    $this->assertSame(240, Backoff::seconds(3));
    $this->assertSame(480, Backoff::seconds(4));
    $this->assertSame(960, Backoff::seconds(5));
    $this->assertSame(1800, Backoff::seconds(6));
    $this->assertSame(1800, Backoff::seconds(10));
  }
}
