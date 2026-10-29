<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Http\Public\HistoryCalendar;

final class HistoryCalendarTest extends TestCase
{
  public function testMonthEndsNeverSkipOrDuplicateAMonth(): void
  {
    $reference = new DateTimeImmutable('2026-03-31 12:34:56', new DateTimeZone('Europe/Berlin'));
    self::assertSame('2026-02-28 12:34:56', HistoryCalendar::month($reference, 1)->format('Y-m-d H:i:s'));
    self::assertSame('2026-01-31', HistoryCalendar::month($reference, 2)->format('Y-m-d'));
    self::assertSame('2025-12-31', HistoryCalendar::month($reference, 3)->format('Y-m-d'));
    $months = [];
    for ($i = 0; $i < 24; $i++) {
      $months[] = HistoryCalendar::month($reference, $i)->format('Y-m');
    }
    self::assertCount(24, array_unique($months));
    self::assertSame('2024-02-29', HistoryCalendar::month($reference, 25)->format('Y-m-d'));
  }

  public function testDaysPreserveLocalTimeAcrossBothDstTransitions(): void
  {
    foreach (['2026-03-30 00:30:00', '2026-10-26 00:30:00'] as $date) {
      $reference = new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin'));
      $days = [];
      for ($i = 0; $i < 4; $i++) {
        $target = HistoryCalendar::day($reference, $i);
        self::assertSame('00:30:00', $target->format('H:i:s'));
        $days[] = $target->format('Y-m-d');
      }
      self::assertCount(4, array_unique($days));
    }
  }

  public function testLeapDayIsClampedWithoutDriftingToMarch(): void
  {
    $reference = new DateTimeImmutable('2024-02-29 12:00:00', new DateTimeZone('Europe/Berlin'));
    self::assertSame('2023-02-28 12:00:00', HistoryCalendar::year($reference, 2023)->format('Y-m-d H:i:s'));
    self::assertSame('2020-02-29', HistoryCalendar::year($reference, 2020)->format('Y-m-d'));
  }
}
