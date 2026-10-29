<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Öffentliche Tages-Timeline einschließlich Kalender- und Nachbartagsdaten.
 *
 * Der ArchiveBrowser bleibt die einzige Quelle für Archivmetadaten. Dieser
 * Handler normalisiert HTTP-Parameter, ergänzt Likes sowie öffentliche Labels
 * und formt daraus genau die Antwort der Timeline-Route.
 */
final class PublicTimelineHandler
{
  private readonly PublicTemporalRequest $request;

  public function __construct(private readonly App $app)
  {
    $this->request = new PublicTemporalRequest($app);
  }

  /**
   * Baut die Tages-Timeline samt Kalender- und Nachbartag-Navigation.
   * Dateisystem-/DB-Auswahl bleibt im ArchiveBrowser; dieser HTTP-Block
   * normalisiert nur Querywerte und ergänzt die öffentlichen Metadaten.
   *
   * @param array<string,mixed> $query
   */
  public function handle(string $slug, array $query): void
  {
    $camera = $this->request->cameraOr404($slug);
    if (!$camera) {
      return;
    }
    $limit = $this->request->boundedInt($query, 'limit', 2000, 1, 5000);
    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    [$year, $month, $day] = $this->timelineDate($camera, $query, $timezone);
    if ($year === null || $month === null || $day === null) {
      \respond(400, ['detail' => 'No date specified']);
      return;
    }

    $browser = $this->app->archiveBrowser();
    $yearsMap = $browser->listYears($camera);
    $years = array_values(array_unique(array_map('intval', array_merge(
      $yearsMap['current'] ?? [],
      $yearsMap['archive'] ?? []
    ))));
    sort($years);

    // Für Nachbartage werden nur das aktuelle und die beiden angrenzenden
    // Jahre hydriert; ein unnötiger Vollscan aller Archivjahre bleibt aus.
    $metadataYears = [$year];
    $previousYear = null;
    $nextYear = null;
    foreach ($years as $availableYear) {
      if ($availableYear < $year) {
        $previousYear = $availableYear;
      } elseif ($availableYear > $year && $nextYear === null) {
        $nextYear = $availableYear;
      }
    }
    if ($previousYear !== null) {
      $metadataYears[] = $previousYear;
    }
    if ($nextYear !== null) {
      $metadataYears[] = $nextYear;
    }
    $calendar = $browser->availableCalendar($camera, array_values(array_unique($metadataYears)));
    $months = $calendar['months'] ?? [];
    $days = $calendar['days'] ?? [];
    $months[$year] ??= [];
    if (!in_array($month, $months[$year], true)) {
      $months[$year][] = $month;
      sort($months[$year]);
    }
    $days[$year][$month] ??= $browser->availableDays($camera, $year, $month);

    $dayData = $browser->entriesForDay($camera, $year, $month, $day);
    $entries = $dayData['entries'] ?? [];
    if (count($entries) > $limit) {
      $entries = array_slice($entries, -$limit);
    }
    $entries = $this->addLikes($camera, $entries, $year, $month, $day, $timezone);

    $previousDay = $this->neighborDay($years, $months, $days, $year, $month, $day, -1);
    $nextDay = $this->neighborDay($years, $months, $days, $year, $month, $day, 1);
    $targetTime = $this->normalizedTime($query['time'] ?? null);
    $previousEntry = $this->neighborEntry($camera, $previousDay, $targetTime, 'last');
    $nextEntry = $this->neighborEntry($camera, $nextDay, $targetTime, 'first');
    $labelReference = $this->request->referenceTimestamp($camera, $query, $timezone);

    \respond(200, [
      'entries' => $entries,
      'years' => $years,
      'months' => $months,
      'days' => $days,
      'latest' => $dayData['latest'] ?? null,
      'latest_global' => $browser->latestEntry($camera),
      'day_prev_entry' => $previousEntry,
      'day_next_entry' => $nextEntry,
      'labels' => $this->publicLabels($camera->labels(), $labelReference, $timezone),
    ]);
  }

  /** @param array<string,mixed> $query @return array{0:?int,1:?int,2:?int} */
  private function timelineDate(Camera $camera, array $query, DateTimeZone $timezone): array
  {
    $year = isset($query['year']) && is_numeric($query['year']) ? (int) $query['year'] : null;
    $month = isset($query['month']) && is_numeric($query['month']) ? (int) $query['month'] : null;
    $day = isset($query['day']) && is_numeric($query['day']) ? (int) $query['day'] : null;
    if (isset($query['ts']) && is_numeric($query['ts'])) {
      $selected = (new DateTimeImmutable('@' . (int) $query['ts']))->setTimezone($timezone);
      return [(int) $selected->format('Y'), (int) $selected->format('n'), (int) $selected->format('j')];
    }
    if ($year !== null && $month !== null && $day !== null) {
      return [$year, $month, $day];
    }
    $overview = $this->app->cameraPresenter()->overviewData($camera);
    $latest = $overview['last_capture'] ?? null;
    if ($latest instanceof DateTimeImmutable) {
      $latest = $latest->setTimezone($timezone);
      $year ??= (int) $latest->format('Y');
      $month ??= (int) $latest->format('n');
      $day ??= (int) $latest->format('j');
    }
    return [$year, $month, $day];
  }

  /** Ergänzt Likes fehlertolerant; ein DB-Lesefehler darf die Timeline nicht sperren. */
  private function addLikes(
    Camera $camera,
    array $entries,
    int $year,
    int $month,
    int $day,
    DateTimeZone $timezone
  ): array {
    try {
      $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $timezone);
      $likes = (new CameraDataStore($this->app, $camera, null, true))->likesByRange(
        $start->getTimestamp(),
        $start->setTime(23, 59, 59)->getTimestamp()
      );
    } catch (\Throwable) {
      $likes = [];
    }
    foreach ($entries as &$entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      $entry['likes_count'] = $timestamp > 0 && isset($likes[$timestamp])
        ? $likes[$timestamp]
        : (int) ($entry['likes_count'] ?? 0);
    }
    unset($entry);
    return $entries;
  }

  /** @return array{year:int,month:int,day:int}|null */
  private function neighborDay(
    array $years,
    array $months,
    array $days,
    int $year,
    int $month,
    int $day,
    int $direction
  ): ?array {
    $candidates = [];
    foreach ($years as $candidateYear) {
      foreach ($months[$candidateYear] ?? [] as $candidateMonth) {
        foreach ($days[$candidateYear][$candidateMonth] ?? [] as $candidateDay) {
          $key = sprintf('%04d%02d%02d', $candidateYear, $candidateMonth, $candidateDay);
          $current = sprintf('%04d%02d%02d', $year, $month, $day);
          if (($direction < 0 && $key < $current) || ($direction > 0 && $key > $current)) {
            $candidates[$key] = [
              'year' => (int) $candidateYear,
              'month' => (int) $candidateMonth,
              'day' => (int) $candidateDay,
            ];
          }
        }
      }
    }
    if (!$candidates) {
      return null;
    }
    $direction < 0 ? krsort($candidates) : ksort($candidates);
    return reset($candidates) ?: null;
  }

  private function neighborEntry(Camera $camera, ?array $day, ?string $time, string $edge): ?array
  {
    if ($day === null) {
      return null;
    }
    $browser = $this->app->archiveBrowser();
    if ($time !== null) {
      return $browser->dayNearestEntry(
        $camera,
        $day['year'],
        $day['month'],
        $day['day'],
        $time,
        $edge
      );
    }
    return $browser->dayEdgeEntry($camera, $day['year'], $day['month'], $day['day'], $edge);
  }

  private function normalizedTime(mixed $value): ?string
  {
    $time = preg_replace('/[^0-9]/', '', (string) ($value ?? ''));
    if ($time === '') {
      return null;
    }
    return substr(str_pad($time, 6, '0'), 0, 6);
  }

  /**
   * Filtert Kamera-Labels auf gültigen Zeitraum und erlaubte öffentliche
   * Darstellungswerte. Interne bzw. unvollständige Labels werden übersprungen.
   */
  private function publicLabels(array $labels, int $reference, DateTimeZone $timezone): array
  {
    $result = [];
    foreach ($labels as $label) {
      if (!is_array($label) || !is_numeric($label['x'] ?? null) || !is_numeric($label['y'] ?? null)) {
        continue;
      }
      $text = trim((string) ($label['text'] ?? $label['txt'] ?? ''));
      if ($text === '') {
        continue;
      }
      $validFrom = $this->labelTimestamp($label['valid_from'] ?? null, $timezone);
      $validTo = $this->labelTimestamp($label['valid_to'] ?? null, $timezone, true);
      if (($validFrom !== null && $reference < $validFrom) || ($validTo !== null && $reference > $validTo)) {
        continue;
      }
      $entry = [
        'x' => (float) $label['x'],
        'y' => (float) $label['y'],
        'text' => $text,
        'href' => (string) ($label['href'] ?? ''),
        'type' => is_numeric($label['type'] ?? null) ? (int) $label['type'] : 0,
        'resolution' => is_numeric($label['resolution'] ?? null) ? (int) $label['resolution'] : 0,
      ];
      $color = strtolower(trim((string) ($label['color'] ?? '')));
      if (in_array($color, ['blue', 'teal', 'green', 'yellow', 'orange', 'red', 'purple', 'gray'], true)
        || preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)
      ) {
        $entry['color'] = $color;
      }
      $tone = strtolower(trim((string) ($label['text_tone'] ?? '')));
      if (in_array($tone, ['light', 'dark'], true)) {
        $entry['text_tone'] = $tone;
      }
      if (isset($label['id']) && is_numeric($label['id'])) {
        $entry['id'] = (int) $label['id'];
      }
      $result[] = $entry;
    }
    return $result;
  }

  private function labelTimestamp(mixed $value, DateTimeZone $timezone, bool $endOfDay = false): ?int
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }
    if (is_numeric($value)) {
      return (int) $value > 0 ? (int) $value : null;
    }
    $raw = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
      if ($date instanceof DateTimeImmutable) {
        return ($endOfDay ? $date->setTime(23, 59, 59) : $date)->getTimestamp();
      }
    }
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
      $date = DateTimeImmutable::createFromFormat('!' . $format, $raw, $timezone);
      if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
      }
    }
    try {
      return (new DateTimeImmutable($raw, $timezone))->getTimestamp();
    } catch (\Exception) {
      return null;
    }
  }
}
