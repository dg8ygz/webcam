<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

/**
 * Wählt Einträge aus einer bereits aufgebauten Timeline aus.
 *
 * Die Klasse kennt weder Kamera, Datenbank noch Dateisystem. Sie vereinheitlicht
 * lediglich Datum-/Zeitkriterien, berechnet Zielzeitpunkte und sucht den
 * passendsten Eintrag. Dadurch bleibt ArchiveBrowser für die Wahl der
 * Datenquelle zuständig, während diese gut testbaren Navigationsregeln an einer
 * Stelle liegen.
 */
final class TimelineNavigator
{
  /**
   * Wählt das passendste Bild für die übergebenen Kriterien.
   *
   * Ein vollständiger Tag mit Uhrzeit sucht den zeitlich nächsten Eintrag am
   * selben Tag. Unvollständige Kriterien werden schrittweise reduziert. Ohne
   * brauchbare Auswahl wird der neueste Timeline-Eintrag geliefert.
   *
   * @param array<string,mixed> $timeline
   * @param array<string,int|string|null> $criteria
   * @return array<string,mixed>|null
   */
  public function select(array $timeline, array $criteria, string $timezone): ?array
  {
    $entries = $timeline['entries'] ?? [];
    if (!is_array($entries) || $entries === []) {
      return null;
    }

    $normalized = $this->normalizeCriteria($criteria);
    if ($normalized === []) {
      return $entries[array_key_last($entries)];
    }

    // Ein expliziter Unix-Zeitpunkt unterscheidet die beiden 02-Uhr-Stunden.
    // Lokale Datumsfelder duerfen diese Auswahl nicht vorher ueberschreiben.
    if (isset($normalized['timestamp'])) {
      $exact = $this->filterEntries($entries, ['timestamp' => $normalized['timestamp']]);
      if ($exact !== []) return $exact[array_key_last($exact)];
    }

    if (isset($normalized['year'], $normalized['month'], $normalized['day'], $normalized['time'])) {
      $targetTs = $this->criteriaToTimestamp($normalized, $timezone);
      if ($targetTs !== null) {
        $sameDay = $this->filterEntries($entries, [
          'year' => $normalized['year'],
          'month' => $normalized['month'],
          'day' => $normalized['day'],
        ]);
        $closest = $this->closestEntry($sameDay, $targetTs);
        if ($closest !== null) {
          return $closest;
        }
      }
    }

    foreach ([
      ['timestamp'],
      ['year', 'month', 'day', 'time'],
      ['year', 'month', 'day'],
      ['year', 'month'],
      ['year'],
    ] as $fields) {
      $subset = $this->criteriaSubset($normalized, $fields);
      if ($subset === []) {
        continue;
      }
      $filtered = $this->filterEntries($entries, $subset);
      if ($filtered !== []) {
        return $filtered[array_key_last($filtered)];
      }
    }

    // Wenn das angefragte Datum eine Lücke enthält, bleibt die Navigation
    // brauchbar: gleicher Abstand gewinnt zugunsten des früheren Bildes.
    if (isset($normalized['year'])) {
      $targetTs = $this->criteriaToTimestamp($normalized, $timezone);
      if ($targetTs !== null) {
        $closest = $this->closestEntry($entries, $targetTs);
        if ($closest !== null) {
          return $closest;
        }
      }
    }

    return $entries[array_key_last($entries)];
  }

  /** Vereinheitlicht eine Eingabe wie „12:30“ zu „123000“. */
  public function normalizeTime(string $time): ?string
  {
    $digits = preg_replace('/[^0-9]/', '', $time);
    if ($digits === null || $digits === '') {
      return null;
    }
    if (strlen($digits) < 6) {
      $digits = str_pad($digits, 6, '0');
    } elseif (strlen($digits) > 6) {
      $digits = substr($digits, 0, 6);
    }
    return $digits;
  }

  /**
   * @param array<string,int|string> $criteria
   */
  public function criteriaToTimestamp(array $criteria, string $timezone): ?int
  {
    if (!isset($criteria['year'])) {
      return null;
    }
    $year = (int) $criteria['year'];
    $month = isset($criteria['month']) ? (int) $criteria['month'] : 1;
    $day = isset($criteria['day']) ? (int) $criteria['day'] : 1;
    $time = isset($criteria['time']) ? (string) $criteria['time'] : '000000';
    $timeDigits = $this->normalizeTime($time) ?? '000000';
    $hour = (int) substr($timeDigits, 0, 2);
    $minute = (int) substr($timeDigits, 2, 2);
    $second = (int) substr($timeDigits, 4, 2);
    $date = \DateTimeImmutable::createFromFormat(
      '!Y-n-j H:i:s',
      sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
      new \DateTimeZone($timezone)
    );
    return $date ? $date->getTimestamp() : null;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return array<string,mixed>|null
   */
  public function closestEntry(array $entries, int $targetTs): ?array
  {
    $best = null;
    $bestDelta = null;
    $bestTs = null;

    foreach ($entries as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp <= 0) {
        continue;
      }
      $delta = abs($timestamp - $targetTs);
      if ($bestDelta === null || $delta < $bestDelta || ($delta === $bestDelta && ($bestTs === null || $timestamp < $bestTs))) {
        $best = $entry;
        $bestDelta = $delta;
        $bestTs = $timestamp;
      }
    }

    return $best;
  }

  /**
   * Erkennt, ob ein expliziter Bereich genau innerhalb eines lokalen Tages
   * liegt. Nur dann darf ArchiveBrowser den günstigen Tagesleser verwenden.
   *
   * @return array{year:int,month:int,day:int}|null
   */
  public function singleDayRange(
    ?int $yearFilter,
    ?int $fromTs,
    ?int $toTs,
    string $timezone
  ): ?array {
    if ($fromTs === null || $toTs === null || ($toTs - $fromTs) > 86400) {
      return null;
    }

    $tz = new \DateTimeZone($timezone);
    $from = (new \DateTimeImmutable('@' . $fromTs))->setTimezone($tz);
    $to = (new \DateTimeImmutable('@' . $toTs))->setTimezone($tz);
    if ($from->format('Y-m-d') !== $to->format('Y-m-d')) {
      return null;
    }

    $resolvedYear = (int) $from->format('Y');
    if ($yearFilter !== null && $resolvedYear !== $yearFilter) {
      return null;
    }

    return [
      'year' => $resolvedYear,
      'month' => (int) $from->format('m'),
      'day' => (int) $from->format('d'),
    ];
  }

  /**
   * Baut die kleine, UI-neutrale Form eines Navigationsziels auf.
   *
   * @return array{
   *   timestamp:int,
   *   year:int,
   *   month:int,
   *   day:int,
   *   time:string,
   *   time_label:string,
   *   datetime_label:string,
   *   datetime_iso:string
   * }
   */
  public function navigationEntry(int $timestamp, \DateTimeZone $timezone): array
  {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);

    return [
      'timestamp' => $timestamp,
      'year' => (int) $date->format('Y'),
      'month' => (int) $date->format('m'),
      'day' => (int) $date->format('d'),
      'time' => $date->format('His'),
      'time_label' => $date->format('H:i'),
      'datetime_label' => $date->format('d.m.Y H:i'),
      'datetime_iso' => $date->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * @param array<string,int|string|null> $criteria
   * @return array<string,int|string>
   */
  private function normalizeCriteria(array $criteria): array
  {
    $normalized = [];
    foreach (['timestamp', 'year', 'month', 'day'] as $field) {
      if (array_key_exists($field, $criteria) && $criteria[$field] !== null && $criteria[$field] !== '') {
        $normalized[$field] = (int) $criteria[$field];
      }
    }

    if (array_key_exists('time', $criteria) && $criteria['time'] !== null && $criteria['time'] !== '') {
      $time = preg_replace('/[^0-9]/', '', (string) $criteria['time']);
      if ($time !== null && $time !== '') {
        if (strlen($time) <= 4) {
          $time = str_pad($time, 4, '0', STR_PAD_LEFT);
        } elseif (strlen($time) < 6) {
          $time = str_pad($time, 6, '0');
        } elseif (strlen($time) > 6) {
          $time = substr($time, 0, 6);
        }
        $normalized['time'] = $time;
      }
    }
    return $normalized;
  }

  /**
   * @param array<string,int|string> $criteria
   * @param list<string> $fields
   * @return array<string,int|string>
   */
  private function criteriaSubset(array $criteria, array $fields): array
  {
    if ($fields === []) {
      return [];
    }
    $subset = [];
    foreach ($fields as $field) {
      if (!array_key_exists($field, $criteria)) {
        return [];
      }
      $subset[$field] = $criteria[$field];
    }
    return $subset;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @param array<string,int|string> $criteria
   * @return list<array<string,mixed>>
   */
  private function filterEntries(array $entries, array $criteria): array
  {
    if ($criteria === []) {
      return $entries;
    }
    $filtered = [];
    foreach ($entries as $entry) {
      foreach ($criteria as $field => $value) {
        if ($field === 'time') {
          $target = (string) $value;
          $entryTime = (string) ($entry['time'] ?? '');
          if ((strlen($target) === 4 && substr($entryTime, 0, 4) !== $target) || (strlen($target) !== 4 && $target !== $entryTime)) {
            continue 2;
          }
          continue;
        }
        if ($field === 'timestamp') {
          if ((int) $value !== (int) ($entry['timestamp'] ?? 0)) {
            continue 2;
          }
          continue;
        }
        if ((int) $value !== (int) ($entry[$field] ?? 0)) {
          continue 2;
        }
      }
      $filtered[] = $entry;
    }
    return $filtered;
  }
}
