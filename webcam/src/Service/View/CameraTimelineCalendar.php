<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use Webcam\Service\Storage\TimelineNavigator;

/**
 * Kleine, UI-neutrale Kalenderregeln der Kameraseite.
 *
 * Die eigentlichen Timeline-Einträge kommen weiterhin aus ArchiveBrowser.
 * Diese Klasse bewegt sich nur durch bereits ermittelte Jahres-, Monats- und
 * Tageslisten und führt deshalb keine Datenbank- oder Dateisystemzugriffe aus.
 */
final class CameraTimelineCalendar
{
  public function __construct(
    private readonly TimelineNavigator $timelineNavigator = new TimelineNavigator()
  ) {}

  /** Vereinheitlicht HH:MM, HHMM oder HHMMSS zu sechs Ziffern. */
  public function normalizeTime(?string $time): ?string
  {
    if ($time === null) {
      return null;
    }

    // Die allgemeine Zeitnormalisierung existiert bereits für Archivabfragen.
    // Delegation hält beide Navigationspfade bytegenau auf derselben Regel.
    return $this->timelineNavigator->normalizeTime($time);
  }

  /**
   * Vereinheitlicht konfigurierte Jahreswerte, ergänzt das aktuell ausgewählte
   * Jahr und sortiert aufsteigend für Pfeile und Jahresauswahl.
   *
   * @param list<int|string> $years
   * @return list<int>
   */
  public function normalizedYears(array $years, int $selectedYear): array
  {
    $normalized = array_map('intval', $years);
    $normalized[] = $selectedYear;
    $normalized = array_values(array_unique($normalized));
    sort($normalized);
    return $normalized;
  }

  /** @param list<int> $years @return array{0:?int,1:?int} */
  public function neighborYears(array $years, int $selectedYear): array
  {
    $previous = null;
    $next = null;
    foreach ($years as $year) {
      if ($year < $selectedYear) {
        $previous = $year;
      } elseif ($year > $selectedYear && $next === null) {
        $next = $year;
      }
    }
    return [$previous, $next];
  }

  /**
   * Führt bereits vorhandene Timeline-Maps und ergänzende DB-Kalenderdaten
   * zusammen. Der ausgewählte Tag bleibt immer enthalten, auch wenn er aus
   * einem Legacy-Dateisystem-Fallback stammt.
   *
   * @param array<int,list<int>> $monthsByYear
   * @param array<int,array<int,list<int>>> $daysByYearAndMonth
   * @param array{months?:array,days?:array} $calendarMetadata
   * @return array{0:array<int,list<int>>,1:array<int,array<int,list<int>>>}
   */
  public function mergeAvailability(
    array $monthsByYear,
    array $daysByYearAndMonth,
    array $calendarMetadata,
    int $selectedYear,
    int $selectedMonth,
    int $selectedDay
  ): array {
    foreach ($calendarMetadata['months'] ?? [] as $year => $months) {
      $year = (int) $year;
      $existing = array_map('intval', $monthsByYear[$year] ?? []);
      $merged = array_values(array_unique(array_merge($existing, array_map('intval', $months))));
      sort($merged);
      $monthsByYear[$year] = $merged;
    }
    foreach ($calendarMetadata['days'] ?? [] as $year => $months) {
      $year = (int) $year;
      foreach ($months as $month => $days) {
        $month = (int) $month;
        $existing = array_map('intval', $daysByYearAndMonth[$year][$month] ?? []);
        $merged = array_values(array_unique(array_merge($existing, array_map('intval', $days))));
        sort($merged);
        $daysByYearAndMonth[$year][$month] = $merged;
      }
    }

    $monthsByYear[$selectedYear] ??= [];
    if (!in_array($selectedMonth, $monthsByYear[$selectedYear], true)) {
      $monthsByYear[$selectedYear][] = $selectedMonth;
      sort($monthsByYear[$selectedYear]);
    }
    $daysByYearAndMonth[$selectedYear][$selectedMonth] ??= [];
    if (!in_array($selectedDay, $daysByYearAndMonth[$selectedYear][$selectedMonth], true)) {
      $daysByYearAndMonth[$selectedYear][$selectedMonth][] = $selectedDay;
      sort($daysByYearAndMonth[$selectedYear][$selectedMonth]);
    }

    return [$monthsByYear, $daysByYearAndMonth];
  }

  /**
   * Findet die benachbarten belegten Monate und wechselt bei Bedarf über eine
   * Jahresgrenze. Die Rückgabe enthält nur Kalenderkoordinaten, keine Bilder.
   *
   * @param array<int,list<int>> $monthsByYear
   * @return array{0:?array{year:int,month:int},1:?array{year:int,month:int}}
   */
  public function neighborMonths(
    int $selectedYear,
    int $navigationMonth,
    ?int $yearPrevious,
    ?int $yearNext,
    array $monthsByYear
  ): array {
    $previous = null;
    $next = null;
    $currentYearMonths = $monthsByYear[$selectedYear] ?? [];
    sort($currentYearMonths);
    foreach ($currentYearMonths as $month) {
      if ($month < $navigationMonth) {
        $previous = ['year' => $selectedYear, 'month' => $month];
      } elseif ($month > $navigationMonth && $next === null) {
        $next = ['year' => $selectedYear, 'month' => $month];
      }
    }

    if ($previous === null && $yearPrevious !== null) {
      $months = $monthsByYear[$yearPrevious] ?? [];
      if ($months !== []) {
        $previous = ['year' => $yearPrevious, 'month' => max($months)];
      }
    }
    if ($next === null && $yearNext !== null) {
      $months = $monthsByYear[$yearNext] ?? [];
      if ($months !== []) {
        $next = ['year' => $yearNext, 'month' => min($months)];
      }
    }

    return [$previous, $next];
  }

  /**
   * Wählt im Zielmonat den letzten Tag bis zum gewünschten Tageswert. Existiert
   * nur ein späterer Tag, bleibt die Navigation am vorhandenen Monatsrand.
   *
   * @param array<int,array<int,list<int>>> $daysByYearAndMonth
   */
  public function pickDayForMonth(
    array $daysByYearAndMonth,
    int $year,
    int $month,
    int $desiredDay
  ): ?int {
    $days = $daysByYearAndMonth[$year][$month] ?? [];
    if ($days === []) {
      return null;
    }
    sort($days);
    $best = null;
    foreach ($days as $day) {
      if ($day <= $desiredDay) {
        $best = $day;
      }
    }
    return $best ?? max($days);
  }

  /**
   * Findet den vorherigen oder folgenden belegten Tag. Die Suche überschreitet
   * Monats- und Jahresgrenzen, verwendet aber ausschließlich die bereits für
   * die Navigation geladenen Kalender-Maps.
   *
   * @param list<int> $years
   * @param array<int,list<int>> $monthsByYear
   * @param array<int,array<int,list<int>>> $daysByYearAndMonth
   * @return array{year:int,month:int,day:int}|null
   */
  public function adjacentAvailableDay(
    int $year,
    int $month,
    int $day,
    array $years,
    array $monthsByYear,
    array $daysByYearAndMonth,
    bool $forward
  ): ?array {
    $orderedYears = $years;
    sort($orderedYears);
    $yearIndex = array_search($year, $orderedYears, true);
    if ($yearIndex === false) {
      return null;
    }

    return $forward
      ? $this->findForward($year, $month, $day, $orderedYears, $monthsByYear, $daysByYearAndMonth, $yearIndex)
      : $this->findBackward($year, $month, $day, $orderedYears, $monthsByYear, $daysByYearAndMonth, $yearIndex);
  }

  /** @return array{year:int,month:int,day:int}|null */
  private function findForward(
    int $selectedYear,
    int $selectedMonth,
    int $selectedDay,
    array $years,
    array $monthsByYear,
    array $daysByYearAndMonth,
    int $yearIndex
  ): ?array {
    for ($yi = $yearIndex; $yi < count($years); $yi++) {
      $year = $years[$yi];
      $months = $monthsByYear[$year] ?? [];
      sort($months);
      foreach ($months as $month) {
        if ($year === $selectedYear && $month < $selectedMonth) {
          continue;
        }
        $days = $daysByYearAndMonth[$year][$month] ?? [];
        sort($days);
        foreach ($days as $day) {
          if ($year === $selectedYear && $month === $selectedMonth && $day <= $selectedDay) {
            continue;
          }
          return ['year' => $year, 'month' => $month, 'day' => $day];
        }
      }
    }

    return null;
  }

  /** @return array{year:int,month:int,day:int}|null */
  private function findBackward(
    int $selectedYear,
    int $selectedMonth,
    int $selectedDay,
    array $years,
    array $monthsByYear,
    array $daysByYearAndMonth,
    int $yearIndex
  ): ?array {
    for ($yi = $yearIndex; $yi >= 0; $yi--) {
      $year = $years[$yi];
      $months = $monthsByYear[$year] ?? [];
      rsort($months);
      foreach ($months as $month) {
        if ($year === $selectedYear && $month > $selectedMonth) {
          continue;
        }
        $days = $daysByYearAndMonth[$year][$month] ?? [];
        rsort($days);
        foreach ($days as $day) {
          if ($year === $selectedYear && $month === $selectedMonth && $day >= $selectedDay) {
            continue;
          }
          return ['year' => $year, 'month' => $month, 'day' => $day];
        }
      }
    }

    return null;
  }
}
