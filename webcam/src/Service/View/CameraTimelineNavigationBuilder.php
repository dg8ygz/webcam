<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Baut das vollständige serverseitige Navigationsmodell einer Kameraseite.
 *
 * Die Klasse verbindet drei bereits vorhandene Informationsquellen:
 *
 * 1. die für den Request geladene Timeline,
 * 2. kleine ergänzende Kalenderabfragen aus ArchiveBrowser und
 * 3. die dokumentrelativen Link-Builder des aktuellen Routes.
 *
 * Sie rendert kein HTML und liest keine Request-Globals. Dadurch bleibt in
 * camera.php sichtbar, wann Navigation aufgebaut wird, während Sortierung,
 * Nachbarsuche und die Form der Template-Daten zusammen getestet werden.
 */
final class CameraTimelineNavigationBuilder
{
  public function __construct(
    private readonly App $app,
    private readonly CameraTimelineCalendar $calendar = new CameraTimelineCalendar()
  ) {}

  /**
   * @param array<string,mixed>|null $selectedSnapshot
   * @param array<string,mixed> $timeline
   * @param array<string,mixed>|null $selectedDayData
   * @param list<int|string> $archiveYears
   * @param array{year:int|string|null,month:int|string|null,day:int|string|null,time:string|null,slug:string} $selection
   * @param callable(array<string,mixed>):string $buildTimelineLink
   * @param callable(int,int|null,int|null,mixed,string):string $buildYearWithSelection
   * @param callable(string):void|null $profile
   * @return array{
   *   timeline:array<string,mixed>,
   *   navigation:array<string,mixed>,
   *   time_picker_options:list<array<string,mixed>>,
   *   hour_picker_options:list<array<string,mixed>>,
   *   year_picker_options:list<array<string,mixed>>
   * }
   */
  public function build(
    Camera $camera,
    ?array $selectedSnapshot,
    array $timeline,
    ?array $selectedDayData,
    array $archiveYears,
    array $selection,
    callable $buildTimelineLink,
    callable $buildYearWithSelection,
    ?callable $profile = null
  ): array {
    if ($selectedSnapshot === null) {
      return $this->emptyResult($timeline);
    }

    $selectedTimestamp = (int) ($selectedSnapshot['timestamp'] ?? 0);
    $selectedYear = (int) ($selectedSnapshot['year'] ?? 0);
    $selectedMonth = (int) ($selectedSnapshot['month'] ?? 0);
    $selectedDay = (int) ($selectedSnapshot['day'] ?? 0);
    $displayYear = $selection['year'] !== null ? (int) $selection['year'] : $selectedYear;
    $displayMonth = $selection['month'] !== null ? (int) $selection['month'] : $selectedMonth;
    $displayDay = $selection['day'] !== null ? (int) $selection['day'] : $selectedDay;

    // Zeitnavigation benötigt nur den ausgewählten Tag. Wenn dessen Einträge
    // bereits in der Timeline stecken, vermeiden wir eine zweite DB-Abfrage.
    $dayEntries = $this->dayEntries(
      $camera,
      $timeline,
      $selectedDayData,
      $selectedYear,
      $selectedMonth,
      $selectedDay,
      $profile
    );
    $selectedIndex = $this->selectedIndex($dayEntries, $selectedTimestamp);
    $timePrevious = $selectedIndex > 0 ? $dayEntries[$selectedIndex - 1] : null;
    $timeNext = $selectedIndex >= 0 && $selectedIndex < count($dayEntries) - 1
      ? $dayEntries[$selectedIndex + 1]
      : null;
    $hourPickerOptions = $this->hourPickerOptions(
      $dayEntries,
      $selectedSnapshot,
      $buildTimelineLink
    );

    // Für Jahres-/Monats-/Tagespfeile reichen Kalender-Metadaten des aktuellen
    // und der direkt benachbarten Jahre. Es wird kein kompletter Jahrgang mit
    // Bildvarianten, EXIF oder Wetterwerten materialisiert.
    $years = $this->calendar->normalizedYears($archiveYears, $selectedYear);
    [$yearPrevious, $yearNext] = $this->calendar->neighborYears($years, $selectedYear);
    [$monthsByYear, $daysByYearAndMonth] = $this->calendarMaps(
      $camera,
      $timeline,
      $selectedYear,
      $selectedMonth,
      $selectedDay,
      $yearPrevious,
      $yearNext
    );
    $timeline['months'][$selectedYear] = $monthsByYear[$selectedYear];
    $timeline['days'][$selectedYear][$selectedMonth] = $daysByYearAndMonth[$selectedYear][$selectedMonth];
    if ($profile !== null) {
      $profile('nav prep done');
    }

    // Tageskanten dienen der Bildnavigation ueber Mitternacht. Tagespfeile
    // erhalten weiter unten eigene Ziele nahe der ausgewaehlten Uhrzeit.
    $dayPrevious = $this->adjacentDayEntry(
      $camera,
      $selectedYear,
      $selectedMonth,
      $selectedDay,
      $years,
      $monthsByYear,
      $daysByYearAndMonth,
      false
    );
    $dayNext = $this->adjacentDayEntry(
      $camera,
      $selectedYear,
      $selectedMonth,
      $selectedDay,
      $years,
      $monthsByYear,
      $daysByYearAndMonth,
      true
    );

    // Die Minuten-Navigation darf an Mitternacht nicht enden. Die bereits
    // geladenen Tageskanten reichen auch fuer das begrenzte Zeitmenue;
    // zusaetzliche Tages-/Jahres-Timelines werden dafuer nicht geladen.
    $timePrevious ??= $dayPrevious;
    $timeNext ??= $dayNext;
    $timeEntries = $dayEntries;
    $timeIndex = $selectedIndex;
    if ($dayPrevious !== null) {
      array_unshift($timeEntries, $dayPrevious);
      if ($timeIndex >= 0) $timeIndex++;
    }
    if ($dayNext !== null) $timeEntries[] = $dayNext;
    $timePickerOptions = $this->timePickerOptions(
      $timeEntries,
      $timeIndex,
      $buildTimelineLink
    );

    // Stundenpfeile arbeiten mit einem absoluten Zielzeitpunkt. So bleibt beim
    // Wechsel über Mitternacht beispielsweise 00:50 -> 23:50 erhalten.
    $hourEntries = $this->app->archiveBrowser()->hourlyAdjacentEntries($camera, $selectedTimestamp);
    $hourPrevious = $hourEntries['prev'] ?? null;
    $hourNext = $hourEntries['next'] ?? null;
    $hourPrevious ??= $dayPrevious;
    $hourNext ??= $dayNext;

    // Ein Kalendertag zurueck/vor behaelt die lokale Uhrzeit. Bei Luecken
    // entscheidet der kleinste Zeitabstand innerhalb des vorhandenen Zieltags.
    $selectedTime = (string) ($selectedSnapshot['time'] ?? '');
    $dayPrevious = $this->sameTimeOnDay($camera, $dayPrevious, $selectedTime, 'last');
    $dayNext = $this->sameTimeOnDay($camera, $dayNext, $selectedTime, 'first');

    [$monthPrevious, $monthNext] = $this->calendar->neighborMonths(
      $selectedYear,
      $selection['month'] !== null ? (int) $selection['month'] : $selectedMonth,
      $yearPrevious,
      $yearNext,
      $monthsByYear
    );
    $monthPreviousDay = $monthPrevious === null
      ? null
      : $this->calendar->pickDayForMonth(
        $daysByYearAndMonth,
        $monthPrevious['year'],
        $monthPrevious['month'],
        $displayDay
      );
    $monthNextDay = $monthNext === null
      ? null
      : $this->calendar->pickDayForMonth(
        $daysByYearAndMonth,
        $monthNext['year'],
        $monthNext['month'],
        $displayDay
      );

    $navigation = $this->navigationModel(
      $selectedSnapshot,
      $selection['slug'],
      $displayYear,
      $displayMonth,
      $displayDay,
      $yearPrevious,
      $yearNext,
      $monthPrevious,
      $monthNext,
      $monthPreviousDay,
      $monthNextDay,
      $dayPrevious,
      $dayNext,
      $hourPrevious,
      $hourNext,
      $timePrevious,
      $timeNext,
      $buildTimelineLink,
      $buildYearWithSelection
    );

    return [
      'timeline' => $timeline,
      'navigation' => $navigation,
      'time_picker_options' => $timePickerOptions,
      'hour_picker_options' => $hourPickerOptions,
      'year_picker_options' => $this->yearPickerOptions(
        $years,
        $selectedYear,
        $selectedMonth,
        $selectedDay,
        $selectedSnapshot['time'] ?? null,
        $selection['slug'],
        $buildYearWithSelection
      ),
    ];
  }

  /** @return array{timeline:array,navigation:array,time_picker_options:array,hour_picker_options:array,year_picker_options:array} */
  private function emptyResult(array $timeline): array
  {
    return [
      'timeline' => $timeline,
      'navigation' => ['has_data' => false],
      'time_picker_options' => [],
      'hour_picker_options' => [],
      'year_picker_options' => [],
    ];
  }

  /**
   * @param array<string,mixed> $timeline
   * @param array<string,mixed>|null $selectedDayData
   * @return list<array<string,mixed>>
   */
  private function dayEntries(
    Camera $camera,
    array $timeline,
    ?array $selectedDayData,
    int $year,
    int $month,
    int $day,
    ?callable $profile
  ): array {
    $entries = [];
    foreach ($timeline['entries'] ?? [] as $entry) {
      if (
        (int) ($entry['year'] ?? 0) === $year
        && (int) ($entry['month'] ?? 0) === $month
        && (int) ($entry['day'] ?? 0) === $day
      ) {
        $entries[] = $entry;
      }
    }

    if ($entries !== []) {
      if ($profile !== null) {
        $profile('entriesForDay reused');
      }
    } else {
      $dayData = $selectedDayData
        ?? $this->app->archiveBrowser()->entriesForDay($camera, $year, $month, $day);
      $entries = is_array($dayData['entries'] ?? null) ? $dayData['entries'] : [];
      if ($profile !== null) {
        $profile('entriesForDay loaded');
      }
    }

    usort(
      $entries,
      static fn(array $left, array $right): int => ($left['timestamp'] ?? 0) <=> ($right['timestamp'] ?? 0)
    );
    return $entries;
  }

  /**
   * @param list<array<string,mixed>> $entries
   */
  private function selectedIndex(array $entries, int $selectedTimestamp): int
  {
    foreach ($entries as $index => $entry) {
      if ((int) ($entry['timestamp'] ?? 0) === $selectedTimestamp) {
        return $index;
      }
    }

    // Historische Altbestände können einen leicht abweichenden Timestamp
    // besitzen. Dann dient der erste spätere Eintrag als stabiler Anker.
    foreach ($entries as $index => $entry) {
      if ((int) ($entry['timestamp'] ?? 0) > $selectedTimestamp) {
        return $index;
      }
    }

    return $entries === [] ? -1 : count($entries) - 1;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @param callable(array<string,mixed>):string $buildTimelineLink
   * @return list<array<string,mixed>>
   */
  private function timePickerOptions(array $entries, int $selectedIndex, callable $buildTimelineLink): array
  {
    if ($selectedIndex < 0) {
      return [];
    }

    $options = [];
    $window = 6;
    $from = max(0, $selectedIndex - $window);
    $to = min(count($entries) - 1, $selectedIndex + $window);
    for ($index = $from; $index <= $to; $index++) {
      $entry = $entries[$index];
      $label = $this->timeLabel($entry);
      $selected = $entries[$selectedIndex];
      if ([$entry['year'], $entry['month'], $entry['day']]
        !== [$selected['year'], $selected['month'], $selected['day']]) {
        $label .= sprintf(' (%02d.%02d.)', $entry['day'], $entry['month']);
      }
      $options[] = [
        'label' => $label,
        'url' => $buildTimelineLink($entry),
        'selected' => $index === $selectedIndex,
        'kind' => $index < $selectedIndex
          ? 'past'
          : ($index === $selectedIndex ? 'current' : 'future'),
      ];
    }
    return $options;
  }

  /** @param array<string,mixed> $entry */
  private function timeLabel(array $entry): string
  {
    $time = $entry['time'] ?? '';
    if (is_numeric($time)) {
      $digits = str_pad((string) $time, 6, '0', STR_PAD_LEFT);
      return substr($digits, 0, 2) . ':' . substr($digits, 2, 2);
    }
    return (string) ($entry['time_label'] ?? '—');
  }

  /**
   * Verdichtet alle Aufnahmen des ausgewählten Tages auf einen Eintrag je
   * voller Stunde. Das Stundenmenü springt jeweils zur ersten vorhandenen
   * Aufnahme dieser Stunde; fehlende Stunden werden bewusst nicht erfunden.
   *
   * @param list<array<string,mixed>> $entries
   * @param array<string,mixed> $selectedSnapshot
   * @param callable(array<string,mixed>):string $buildTimelineLink
   * @return list<array{label:string,url:string,selected:bool}>
   */
  private function hourPickerOptions(
    array $entries,
    array $selectedSnapshot,
    callable $buildTimelineLink
  ): array {
    if ($entries === []) {
      return [];
    }

    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $selectedHour = $this->snapshotHour($selectedSnapshot, $timezone);
    $entriesByHour = [];

    foreach ($entries as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp <= 0) {
        continue;
      }

      $hour = (int) (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone($timezone)
        ->format('G');

      // Die Einträge sind chronologisch sortiert. Der erste Treffer ist daher
      // ein nachvollziehbares Sprungziel am Anfang der jeweiligen Stunde.
      $entriesByHour[$hour] ??= $entry;
    }

    ksort($entriesByHour, SORT_NUMERIC);
    $options = [];
    foreach ($entriesByHour as $hour => $entry) {
      $options[] = [
        'label' => sprintf('%02d Uhr', $hour),
        'url' => $buildTimelineLink($entry),
        'selected' => $selectedHour !== null && $hour === $selectedHour,
      ];
    }
    return $options;
  }

  /**
   * Bestimmt die angezeigte Stunde bevorzugt aus dem absoluten Timestamp.
   * Das vermeidet Fehler an Sommerzeitgrenzen; Legacy-Einträge ohne Timestamp
   * können weiterhin über ihr HHMMSS-Feld markiert werden.
   *
   * @param array<string,mixed> $snapshot
   */
  private function snapshotHour(array $snapshot, DateTimeZone $timezone): ?int
  {
    $timestamp = (int) ($snapshot['timestamp'] ?? 0);
    if ($timestamp > 0) {
      return (int) (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone($timezone)
        ->format('G');
    }

    $time = $snapshot['time'] ?? null;
    if ($time === null || !is_numeric($time)) {
      return null;
    }

    $digits = str_pad((string) $time, 6, '0', STR_PAD_LEFT);
    return (int) substr($digits, 0, 2);
  }

  /**
   * Ergänzt die schmale Request-Timeline um die Kalenderlisten der direkten
   * Nachbarjahre. Der ausgewählte Tag wird immer aufgenommen, auch wenn er aus
   * einem Legacy-Fallback stammt und noch nicht in der DB-Kalenderliste steht.
   *
   * @return array{0:array<int,list<int>>,1:array<int,array<int,list<int>>>}
   */
  private function calendarMaps(
    Camera $camera,
    array $timeline,
    int $selectedYear,
    int $selectedMonth,
    int $selectedDay,
    ?int $yearPrevious,
    ?int $yearNext
  ): array {
    $monthsByYear = is_array($timeline['months'] ?? null) ? $timeline['months'] : [];
    $daysByYearAndMonth = is_array($timeline['days'] ?? null) ? $timeline['days'] : [];
    $yearsToLoad = array_values(array_filter(
      [$selectedYear, $yearPrevious, $yearNext],
      static fn(?int $year): bool => $year !== null
    ));
    $calendarMetadata = $this->app->archiveBrowser()->availableCalendar($camera, $yearsToLoad);

    return $this->calendar->mergeAvailability(
      $monthsByYear,
      $daysByYearAndMonth,
      $calendarMetadata,
      $selectedYear,
      $selectedMonth,
      $selectedDay
    );
  }

  /** @return array<string,mixed>|null */
  private function adjacentDayEntry(
    Camera $camera,
    int $year,
    int $month,
    int $day,
    array $years,
    array $monthsByYear,
    array $daysByYearAndMonth,
    bool $forward
  ): ?array {
    $date = $this->calendar->adjacentAvailableDay(
      $year,
      $month,
      $day,
      $years,
      $monthsByYear,
      $daysByYearAndMonth,
      $forward
    );
    if ($date === null) {
      return null;
    }

    return $this->app->archiveBrowser()->dayEdgeEntry(
      $camera,
      $date['year'],
      $date['month'],
      $date['day'],
      $forward ? 'first' : 'last'
    );
  }

  /** @param array<string,mixed>|null $dayEntry @return array<string,mixed>|null */
  private function sameTimeOnDay(Camera $camera, ?array $dayEntry, string $time, string $edge): ?array
  {
    if ($dayEntry === null) {
      return null;
    }
    return $this->app->archiveBrowser()->dayNearestEntry(
      $camera,
      (int) $dayEntry['year'],
      (int) $dayEntry['month'],
      (int) $dayEntry['day'],
      $time,
      $edge
    );
  }

  /**
   * Formt die gefundenen Nachbareinträge in die vom Kamera-Template erwartete
   * Struktur. Linkaufbau bleibt beim Route, damit History und Playback ihre
   * bereits angehängten Parameter unverändert behalten.
   *
   * @param array<string,mixed> $selectedSnapshot
   * @param array<string,mixed>|null $dayPrevious
   * @param array<string,mixed>|null $dayNext
   * @param array<string,mixed>|null $hourPrevious
   * @param array<string,mixed>|null $hourNext
   * @param array<string,mixed>|null $timePrevious
   * @param array<string,mixed>|null $timeNext
   * @return array<string,mixed>
   */
  private function navigationModel(
    array $selectedSnapshot,
    string $slug,
    int $displayYear,
    int $displayMonth,
    int $displayDay,
    ?int $yearPrevious,
    ?int $yearNext,
    ?array $monthPrevious,
    ?array $monthNext,
    ?int $monthPreviousDay,
    ?int $monthNextDay,
    ?array $dayPrevious,
    ?array $dayNext,
    ?array $hourPrevious,
    ?array $hourNext,
    ?array $timePrevious,
    ?array $timeNext,
    callable $buildTimelineLink,
    callable $buildYearWithSelection
  ): array {
    $time = $selectedSnapshot['time'] ?? null;
    $dayLabel = sprintf('%02d.%02d.', $displayDay, $displayMonth);
    $hourLabel = sprintf('%02d Uhr', (int) floor(((int) ($time ?? 0)) / 10000));
    $monthLabel = date('F', strtotime(sprintf('%04d-%02d-01', $displayYear, $displayMonth)));
    $timeLabel = $selectedSnapshot['time_label'] ?? ($time ?? 'Zeit');

    return [
      'has_data' => true,
      'year' => [
        'label' => (string) $displayYear,
        'selected_label' => (string) $displayYear,
        'prev_url' => $yearPrevious === null ? null : $buildYearWithSelection(
          $yearPrevious,
          $displayMonth,
          $displayDay,
          $time,
          $slug
        ),
        'next_url' => $yearNext === null ? null : $buildYearWithSelection(
          $yearNext,
          $displayMonth,
          $displayDay,
          $time,
          $slug
        ),
      ],
      'month' => [
        'label' => $monthLabel,
        'selected_label' => $monthLabel,
        'prev_url' => $monthPrevious === null ? null : $buildYearWithSelection(
          $monthPrevious['year'],
          $monthPrevious['month'],
          $monthPreviousDay,
          $monthPreviousDay !== null ? $time : null,
          $slug
        ),
        'next_url' => $monthNext === null ? null : $buildYearWithSelection(
          $monthNext['year'],
          $monthNext['month'],
          $monthNextDay,
          $monthNextDay !== null ? $time : null,
          $slug
        ),
      ],
      'day' => [
        'label' => $dayLabel,
        'selected_label' => $dayLabel,
        'prev_url' => $dayPrevious !== null ? $buildTimelineLink($dayPrevious) : null,
        'next_url' => $dayNext !== null ? $buildTimelineLink($dayNext) : null,
      ],
      'hour' => [
        'label' => $hourLabel,
        'selected_label' => $hourLabel,
        'prev_url' => $hourPrevious !== null ? $buildTimelineLink($hourPrevious) : null,
        'next_url' => $hourNext !== null ? $buildTimelineLink($hourNext) : null,
      ],
      'time' => [
        'label' => $timeLabel,
        'selected_label' => $timeLabel,
        'prev_url' => $timePrevious !== null ? $buildTimelineLink($timePrevious) : null,
        'next_url' => $timeNext !== null ? $buildTimelineLink($timeNext) : null,
        'prev_timestamp' => $timePrevious['timestamp'] ?? null,
        'next_timestamp' => $timeNext['timestamp'] ?? null,
      ],
    ];
  }

  /**
   * @param list<int> $years
   * @return list<array<string,mixed>>
   */
  private function yearPickerOptions(
    array $years,
    int $selectedYear,
    int $selectedMonth,
    int $selectedDay,
    mixed $selectedTime,
    string $slug,
    callable $buildYearWithSelection
  ): array {
    $options = [];
    foreach ($years as $year) {
      $options[] = [
        'label' => (string) $year,
        'url' => $buildYearWithSelection(
          $year,
          $selectedMonth,
          $selectedDay,
          $selectedTime,
          $slug
        ),
        'selected' => $year === $selectedYear,
      ];
    }
    return $options;
  }
}
