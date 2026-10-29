<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Begrenzt Timeline-Abfragen auf den tatsächlich benötigten Zeitraum und
 * bestimmt für leere Tage das nächste verwendbare DB-Bild, ohne einen ganzen
 * Jahrgang samt Varianten, EXIF und Wetterdaten in PHP zu materialisieren.
 */
final class CameraTimelineResolver
{
  public function __construct(private readonly App $app) {}

  /**
   * @param array<string,int|string|null> $selectionCriteria
   * @param array<string,mixed> $archiveYears
   * @return array{
   *   timeline:array<string,mixed>,
   *   selected_snapshot:?array,
   *   selected_day_data:?array,
   *   requested_year:?int,
   *   range_from:?int,
   *   range_to:?int
   * }
   */
  public function resolve(
    Camera $camera,
    array $selectionCriteria,
    ?int $year,
    ?int $month,
    ?int $day,
    bool $liveView,
    ?int $lastCaptureTimestamp,
    array $archiveYears = []
  ): array {
    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $requestedYear = $year;
    $rangeFrom = null;
    $rangeTo = null;
    $liveDaySelection = null;

    if ($liveView && $lastCaptureTimestamp !== null) {
      $lastCaptureDay = (new DateTimeImmutable('@' . $lastCaptureTimestamp))->setTimezone($timezone);
      $rangeFrom = $lastCaptureDay->setTime(0, 0, 0)->getTimestamp();
      $rangeTo = $lastCaptureDay->setTime(23, 59, 59)->getTimestamp();
      $requestedYear = (int) $lastCaptureDay->format('Y');
      $liveDaySelection = [
        'year' => (int) $lastCaptureDay->format('Y'),
        'month' => (int) $lastCaptureDay->format('m'),
        'day' => (int) $lastCaptureDay->format('d'),
      ];
    }

    if ($requestedYear !== null && $month !== null && $day !== null) {
      $selectedDay = DateTimeImmutable::createFromFormat(
        '!Y-n-j H:i:s',
        sprintf('%04d-%d-%d 00:00:00', $requestedYear, $month, $day),
        $timezone
      );
      if ($selectedDay instanceof DateTimeImmutable) {
        $rangeFrom = $selectedDay->getTimestamp();
        $rangeTo = $selectedDay->modify('+1 day')->getTimestamp() - 1;
      }
    }

    if ($rangeFrom === null && $rangeTo === null && $requestedYear !== null && $month !== null) {
      $selectedMonth = DateTimeImmutable::createFromFormat(
        '!Y-n-j H:i:s',
        sprintf('%04d-%d-01 00:00:00', $requestedYear, $month),
        $timezone
      );
      if ($selectedMonth instanceof DateTimeImmutable) {
        $rangeFrom = $selectedMonth->getTimestamp();
        $rangeTo = $selectedMonth->modify('+1 month')->getTimestamp() - 1;
      }
    }

    $archive = $this->app->archiveBrowser();
    // A month-only camera/playback page selects its newest photo. Resolve
    // that one slot first instead of hydrating every variant/EXIF/weather row
    // of the month across the storage mount. Calendar navigation is loaded
    // separately by CameraTimelineNavigationBuilder. Missing DB/files retain
    // the existing bounded legacy path below.
    $monthLatest = null;
    if (!$liveView && $day === null && $month !== null && $requestedYear !== null
      && ($selectionCriteria['timestamp'] ?? null) === null
      && ($selectionCriteria['time'] ?? null) === null
      && $rangeFrom !== null && $rangeTo !== null) {
      $monthLatest = $archive->nearestEntryFromDatabase($camera, $rangeTo, $rangeFrom, $rangeTo);
    }
    if ($monthLatest !== null) {
      $timeline = $this->timelineAroundEntry($camera, $monthLatest, $archiveYears, $requestedYear, $timezone);
    } elseif ($liveView && is_array($liveDaySelection)) {
      $dayTimeline = $archive->entriesForDay(
        $camera,
        $liveDaySelection['year'],
        $liveDaySelection['month'],
        $liveDaySelection['day'],
        true
      );
      $entries = is_array($dayTimeline['entries'] ?? null) ? $dayTimeline['entries'] : [];
      $timeline = $this->timelineFromEntries(
        $entries,
        $this->availableYears($archiveYears, $requestedYear),
        $requestedYear,
        is_array($dayTimeline['latest'] ?? null) ? $dayTimeline['latest'] : null
      );
    } else {
      $timeline = $archive->timeline($camera, $requestedYear, $rangeFrom, $rangeTo);
    }

    $selectedSnapshot = $archive->selectFromTimeline($timeline, $selectionCriteria);
    $selectedDayData = null;
    $targetTimestamp = $selectedSnapshot === null
      ? $this->selectionTargetTimestamp($selectionCriteria, $timezone)
      : null;

    if ($selectedSnapshot === null && $targetTimestamp !== null && !empty($timeline['entries'])) {
      $selectedSnapshot = $this->nearestEntry($timeline['entries'], $targetTimestamp);
    }

    if ($selectedSnapshot === null && $requestedYear !== null && $month !== null && $day !== null) {
      $selectedDayData = $archive->entriesForDay($camera, $requestedYear, $month, $day);
      $dayEntries = is_array($selectedDayData['entries'] ?? null) ? $selectedDayData['entries'] : [];
      if ($dayEntries !== []) {
        $timeline['entries'] = $dayEntries;
        if (is_array($selectedDayData['latest'] ?? null)) {
          $timeline['latest'] = $selectedDayData['latest'];
        }
        $selectedSnapshot = $archive->selectFromTimeline($timeline, $selectionCriteria);
      }
    }

    // Ein leerer/duplizierter Tag darf niemals mehr den kompletten Jahrgang
    // laden. Die indexierte DB-Suche liefert höchstens einen Capture; nur dessen
    // Tag wird anschließend für Zeitnavigation und Varianten aufbereitet.
    if ($selectedSnapshot === null && $targetTimestamp !== null && $requestedYear !== null) {
      [$yearFrom, $yearTo] = $this->yearRange($requestedYear, $timezone);
      $nearest = $archive->nearestEntryFromDatabase(
        $camera,
        $targetTimestamp,
        $yearFrom,
        $yearTo
      );
      if ($nearest !== null) {
        $selectedSnapshot = $nearest;
        $timeline = $this->timelineAroundEntry($camera, $nearest, $archiveYears, $requestedYear, $timezone);
      }
    }

    // DB-unabhängiger Legacy-Fallback bleibt auf den ausgewählten Monat
    // begrenzt. Er darf keine vollständige Jahres-Timeline materialisieren.
    if (
      $selectedSnapshot === null
      && $targetTimestamp !== null
      && $requestedYear !== null
      && $rangeFrom !== null
      && $rangeTo !== null
      && ($rangeTo - $rangeFrom) <= 86400
    ) {
      $selectedMonth = DateTimeImmutable::createFromFormat(
        '!Y-n-j H:i:s',
        sprintf('%04d-%02d-01 00:00:00', $requestedYear, $month ?? 1),
        $timezone
      );
      if ($selectedMonth instanceof DateTimeImmutable) {
        $monthTimeline = $archive->timeline(
          $camera,
          $requestedYear,
          $selectedMonth->getTimestamp(),
          $selectedMonth->modify('+1 month')->getTimestamp() - 1
        );
        $monthEntries = is_array($monthTimeline['entries'] ?? null) ? $monthTimeline['entries'] : [];
        if ($monthEntries !== []) {
          $timeline = $monthTimeline;
          $selectedSnapshot = $this->nearestEntry($monthEntries, $targetTimestamp);
        }
      }
    }

    if ($selectedSnapshot === null && $lastCaptureTimestamp !== null) {
      $selectedSnapshot = $archive->selectFromTimeline($timeline, [
        'timestamp' => $lastCaptureTimestamp,
      ]);
    }
    if ($selectedSnapshot === null && is_array($timeline['latest'] ?? null)) {
      $selectedSnapshot = $timeline['latest'];
    }

    return [
      'timeline' => $timeline,
      'selected_snapshot' => $selectedSnapshot,
      'selected_day_data' => $selectedDayData,
      'requested_year' => $requestedYear,
      'range_from' => $rangeFrom,
      'range_to' => $rangeTo,
    ];
  }

  /** @return array{0:int,1:int} */
  private function yearRange(int $year, DateTimeZone $timezone): array
  {
    $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $timezone);
    return [$start->getTimestamp(), $start->modify('+1 year')->getTimestamp() - 1];
  }

  /** @param array<string,int|string|null> $criteria */
  private function selectionTargetTimestamp(array $criteria, DateTimeZone $timezone): ?int
  {
    if (($criteria['timestamp'] ?? null) !== null) {
      return (int) $criteria['timestamp'];
    }

    $year = $criteria['year'] ?? null;
    $month = $criteria['month'] ?? null;
    $day = $criteria['day'] ?? null;
    $time = $criteria['time'] ?? null;
    if ($year === null || $month === null || $day === null || $time === null) {
      return null;
    }

    $digits = preg_replace('/[^0-9]/', '', (string) $time);
    if ($digits === null || $digits === '') {
      return null;
    }
    $digits = str_pad(substr($digits, 0, 6), 6, '0');
    $target = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i:s',
      sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        (int) $year,
        (int) $month,
        (int) $day,
        (int) substr($digits, 0, 2),
        (int) substr($digits, 2, 2),
        (int) substr($digits, 4, 2)
      ),
      $timezone
    );
    return $target?->getTimestamp();
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return array<string,mixed>|null
   */
  private function nearestEntry(array $entries, int $targetTimestamp): ?array
  {
    $best = null;
    $bestDelta = null;
    foreach ($entries as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp <= 0) {
        continue;
      }
      $delta = abs($timestamp - $targetTimestamp);
      if ($bestDelta === null || $delta < $bestDelta) {
        $best = $entry;
        $bestDelta = $delta;
      }
    }
    return $best;
  }

  /**
   * @param array<string,mixed> $entry
   * @param array<string,mixed> $archiveYears
   * @return array<string,mixed>
   */
  private function timelineAroundEntry(
    Camera $camera,
    array $entry,
    array $archiveYears,
    int $requestedYear,
    DateTimeZone $timezone
  ): array {
    $timestamp = (int) ($entry['timestamp'] ?? 0);
    $day = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    $from = $day->setTime(0, 0, 0)->getTimestamp();
    $to = $day->setTime(23, 59, 59)->getTimestamp();
    $timeline = $this->app->archiveBrowser()->timeline($camera, (int) $day->format('Y'), $from, $to);
    if (!empty($timeline['entries'])) {
      return $timeline;
    }

    return $this->timelineFromEntries(
      [$entry],
      $this->availableYears($archiveYears, $requestedYear),
      (int) $day->format('Y'),
      $entry
    );
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @param list<int> $years
   * @param array<string,mixed>|null $latest
   * @return array<string,mixed>
   */
  private function timelineFromEntries(array $entries, array $years, ?int $yearFilter, ?array $latest): array
  {
    $maps = $this->calendarMaps($entries);
    return [
      'entries' => $entries,
      'years' => $years,
      'months' => $maps['months'],
      'days' => $maps['days'],
      'times' => [],
      'latest' => $latest ?? ($entries !== [] ? $entries[array_key_last($entries)] : null),
      'year_filter' => $yearFilter,
    ];
  }

  /** @return list<int> */
  private function availableYears(array $archiveYears, ?int $fallbackYear): array
  {
    $years = [];
    foreach (['current', 'archive'] as $bucket) {
      foreach ($archiveYears[$bucket] ?? [] as $year) {
        if (is_numeric($year)) {
          $years[] = (int) $year;
        }
      }
    }
    $years = array_values(array_unique($years));
    if ($years === [] && $fallbackYear !== null) {
      $years[] = $fallbackYear;
    }
    sort($years);
    return $years;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return array{months:array<int,list<int>>,days:array<int,array<int,list<int>>>}
   */
  private function calendarMaps(array $entries): array
  {
    $months = [];
    $days = [];
    foreach ($entries as $entry) {
      $year = (int) ($entry['year'] ?? 0);
      $month = (int) ($entry['month'] ?? 0);
      $day = (int) ($entry['day'] ?? 0);
      if ($year <= 0 || $month <= 0) {
        continue;
      }
      $months[$year] ??= [];
      if (!in_array($month, $months[$year], true)) {
        $months[$year][] = $month;
      }
      if ($day <= 0) {
        continue;
      }
      $days[$year][$month] ??= [];
      if (!in_array($day, $days[$year][$month], true)) {
        $days[$year][$month][] = $day;
      }
    }
    foreach ($months as &$values) {
      sort($values);
    }
    unset($values);
    foreach ($days as &$monthDays) {
      foreach ($monthDays as &$values) {
        sort($values);
      }
      unset($values);
    }
    unset($monthDays);
    return ['months' => $months, 'days' => $days];
  }
}
