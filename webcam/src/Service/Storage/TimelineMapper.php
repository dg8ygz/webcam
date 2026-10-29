<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

/**
 * Baut aus bereits gelesenen Archiveinträgen die sortierte Timeline- und
 * Kalenderstruktur. Diese Klasse führt weder DB- noch Dateisystemzugriffe aus.
 */
final class TimelineMapper
{
  /**
   * @param list<array<string,mixed>> $entries
   * @param list<int> $availableYears
   * @param array<int,list<int>> $prefilledMonths
   * @return array<string,mixed>
   */
  public function assemble(
    array $entries,
    array $availableYears,
    ?int $yearFilter,
    array $prefilledMonths = []
  ): array {
    usort($entries, static fn(array $a, array $b): int => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

    $years = $availableYears;
    $months = $prefilledMonths;
    $days = [];
    $times = [];
    $maxFuture = time() + 300;
    $cleanEntries = [];

    foreach ($entries as $entry) {
      if (empty($entry['variants']) || empty($entry['preview_url'])) {
        continue;
      }
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp === 0 || $timestamp > $maxFuture) {
        continue;
      }
      $year = (int) $entry['year'];
      $month = (int) $entry['month'];
      $day = (int) $entry['day'];
      $cleanEntries[] = $entry;

      if (!in_array($year, $years, true)) {
        $years[] = $year;
      }
      $months[$year] ??= [];
      if (!in_array($month, $months[$year], true)) {
        $months[$year][] = $month;
        sort($months[$year]);
      }
      $days[$year][$month] ??= [];
      if (!in_array($day, $days[$year][$month], true)) {
        $days[$year][$month][] = $day;
        sort($days[$year][$month]);
      }
      $times[$year][$month][$day][] = $entry;
    }

    sort($years);
    return [
      'entries' => $cleanEntries,
      'years' => $years,
      'months' => $months,
      'days' => $days,
      'times' => $times,
      'latest' => $cleanEntries !== [] ? $cleanEntries[array_key_last($cleanEntries)] : null,
      'year_filter' => $yearFilter,
    ];
  }

  /**
   * @param array<string,mixed> $timeline
   * @param list<array<string,mixed>> $additionalEntries
   * @return array<string,mixed>
   */
  public function merge(array $timeline, array $additionalEntries, ?int $yearFilter): array
  {
    $merged = [];
    foreach (($timeline['entries'] ?? []) as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp > 0) {
        $merged[$timestamp] = $entry;
      }
    }
    foreach ($additionalEntries as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp > 0 && !isset($merged[$timestamp])) {
        $merged[$timestamp] = $entry;
      }
    }

    return $this->assemble(
      array_values($merged),
      array_map('intval', $timeline['years'] ?? []),
      $yearFilter,
      is_array($timeline['months'] ?? null) ? $timeline['months'] : []
    );
  }

  /** @param list<string|int> $variants */
  public function preferredVariant(array $variants): ?string
  {
    if ($variants === []) {
      return null;
    }
    $normalized = array_values(array_map(static fn($variant): string => (string) $variant, $variants));
    foreach (['la', 'lm', 'sm', 'original', 'full', 'hu'] as $candidate) {
      if (in_array($candidate, $normalized, true)) {
        return $candidate;
      }
    }
    return $normalized[0] ?? null;
  }
}
