<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Webcam\Domain\Camera;
use Webcam\Service\Scheduler\CaptureSchedule;

final class CaptureScheduleTest extends TestCase
{
  private function camera(array $capture): Camera
  {
    return new Camera(['id'=>'schedule-test','slug'=>'schedule-test','name'=>'Schedule', 'title'=>'Schedule',
      'mode'=>'active-capture','location'=>[], 'storage'=>[], 'status'=>[], 'capture'=>$capture]);
  }
  public function testNightWindowExcludesNoonAndHandlesBothSidesOfMidnight(): void
  {
    $camera = $this->camera(['window'=>['start'=>'20:00','end'=>'06:00']]);
    foreach (['2026-10-17 02:00'=>true, '2026-10-17 21:00'=>true, '2026-10-17 12:00'=>false] as $date=>$expected) {
      $now = (new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')))->getTimestamp();
      self::assertSame($expected, CaptureSchedule::withinWindow($camera,$now,'Europe/Berlin'));
      self::assertSame($expected, CaptureSchedule::dueSlot($camera,$now,'Europe/Berlin') !== null);
    }
  }
  public function testExtraTimeIsDistinctFromRegularSlotAndNeverIssuedEarly(): void
  {
    $camera = $this->camera(['interval_seconds'=>600,'extra_times'=>['12:05']]);
    $slot = strtotime('2026-10-17 12:05 UTC');
    self::assertNull(CaptureSchedule::extraSlot($camera,$slot-1,'UTC'));
    self::assertSame($slot,CaptureSchedule::dueSlot($camera,$slot+30,'UTC'));
    self::assertSame($slot-300,CaptureSchedule::dueSlot($camera,$slot-1,'UTC'));
  }
  public function testWindowStartUsesCalendarDayAcrossDst(): void
  {
    $camera = $this->camera(['window'=>['start'=>'06:00','end'=>'07:00']]);
    $now = (new DateTimeImmutable('2026-10-24 08:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
    self::assertSame(23*3600,CaptureSchedule::nextWindowStart($camera,$now,'Europe/Berlin'));
    self::assertNull(CaptureSchedule::dueSlot($this->camera(['window'=>['start'=>'invalid','end'=>'12:00']]),$now,'UTC'));
  }
}
