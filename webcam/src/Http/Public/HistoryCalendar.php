<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;

/** Calendar offsets preserve local time and clamp missing month-end days. */
final class HistoryCalendar
{
  /** Lokale Kalendertage statt fester 86400s-Schritte, auch ueber Sommerzeitwechsel. */
  public static function day(DateTimeImmutable $reference, int $daysAgo): DateTimeImmutable
  {
    return $reference->modify(sprintf('-%d days', $daysAgo));
  }

  /** Erst den Zielmonat bestimmen, damit der 31. nicht in den Folgemonat ueberlaeuft. */
  public static function month(DateTimeImmutable $reference, int $monthsAgo): DateTimeImmutable
  {
    $first = $reference->setDate((int) $reference->format('Y'), (int) $reference->format('m'), 1)
      ->modify(sprintf('-%d months', $monthsAgo));
    return self::clampDay($reference, $first);
  }

  /** Monat/Uhrzeit erhalten; Schaltjahrestag bei Bedarf auf Monatsende begrenzen. */
  public static function year(DateTimeImmutable $reference, int $year): DateTimeImmutable
  {
    return self::clampDay($reference, $reference->setDate($year, (int) $reference->format('m'), 1));
  }

  /** Referenztag auf die vorhandenen Tage des bereits bestimmten Zielmonats klemmen. */
  private static function clampDay(DateTimeImmutable $reference, DateTimeImmutable $first): DateTimeImmutable
  {
    return $first->setDate((int) $first->format('Y'), (int) $first->format('m'),
      min((int) $reference->format('d'), (int) $first->format('t')));
  }
}
