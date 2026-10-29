<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Beantwortet die lesenden Capture-Abfragen des Admin-Editors.
 *
 * Die HTTP-Route übergibt nur noch die Query-Parameter und den Kamera-Store.
 * Dieser Service erledigt danach die bislang im Router vermischten Schritte:
 *
 * 1. bekannte Parameter in begrenzte interne Werte übersetzen,
 * 2. Jahr/Monat/Tag in einen Unix-Zeitbereich umrechnen,
 * 3. Captures aus SQLite laden und optional nach Pfad filtern,
 * 4. einen exakten, neuesten oder zeitlich nächsten Capture auswählen,
 * 5. das stabile JSON-Datenmodell des Admin-Editors zusammensetzen.
 *
 * Der Service kennt weder $_GET noch HTTP-Statuscodes. Ungültige Abfragen
 * werden als InvalidArgumentException gemeldet; die Route übersetzt das in 400.
 * null bedeutet ausschließlich: Ein explizit angefragter slot_ts existiert
 * nicht. Ein gültiger Zeitbereich ohne Treffer bleibt eine leere Collection.
 */
final class CameraCaptureQueryService
{
  public function __construct(
    private readonly CameraCaptureEditor $editor,
    private readonly DateTimeZone $timezone
  ) {}

  /**
   * Führt eine Admin-Capture-Abfrage aus.
   *
   * @param array<string,mixed> $query Rohwerte aus der HTTP-Query
   * @return array<string,mixed>|null
   */
  public function query(
    CameraDataStore $store,
    array $query,
    ?int $nowTimestamp = null
  ): ?array {
    $request = $this->normalizeQuery($query);

    // Ein exakter Slot benötigt weder eine Bereichsabfrage noch Pagination.
    // Dieser kurze Pfad ist wichtig für das Öffnen einer Tabellenzeile im
    // Capture-Editor und darf auch als Duplikat markierte Zeilen lesen.
    if ($request['slot_ts'] !== null) {
      $detail = $this->editor->detail($store, $request['slot_ts']);
      return $detail === null ? null : $this->detailPayload($detail);
    }

    [$fromTimestamp, $toTimestamp] = $this->resolveRange(
      $request,
      $nowTimestamp ?? time()
    );
    $targetTimestamp = $this->resolveTargetTimestamp($request);

    // CameraDataStore liefert die Captures aufsteigend und hängt Varianten,
    // EXIF sowie Wetter bereits blockweise an. So entsteht kein N+1-Query pro
    // Tabellenzeile und es wird kein zusätzlicher JSON-/Kalendercache benötigt.
    $rows = $store->capturesForRange($fromTimestamp, $toTimestamp);
    if ($request['search'] !== '') {
      $needle = $request['search'];
      $rows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => str_contains(
          (string) ($row['relative_path'] ?? ''),
          $needle
        )
      ));
    }

    // latest ist ein bewusster Detail-Shortcut. Ohne Treffer bleibt die
    // Antwort wie bisher eine leere Collection statt eines künstlichen 404.
    if ($request['latest'] && $rows !== []) {
      return $this->detailPayloadForRow($store, $rows[count($rows) - 1]);
    }

    // year/month/day + time wählt den zeitlich nächsten Eintrag innerhalb des
    // zuvor bestimmten Bereichs. Erst danach darf Pagination Zeilen entfernen.
    if ($targetTimestamp !== null && $rows !== []) {
      $nearest = $this->nearestRow($rows, $targetTimestamp);
      if ($nearest !== null) {
        return $this->detailPayloadForRow($store, $nearest);
      }
    }

    $rows = array_slice($rows, $request['offset'], $request['limit']);
    $captures = [];
    foreach ($rows as $row) {
      $captures[] = $this->collectionItem($row);
    }

    return [
      'captures' => $captures,
      'count' => count($captures),
    ];
  }

  /**
   * Übersetzt nur bekannte Eingaben und setzt dieselben Grenzen wie die alte
   * Route: maximal 200 Einträge, Standardlimit 20 und ein optionaler Pfadfilter.
   *
   * @param array<string,mixed> $query
   * @return array{
   *   slot_ts:?int,
   *   latest:bool,
   *   from_ts:?int,
   *   to_ts:?int,
   *   year:?int,
   *   month:?int,
   *   day:?int,
   *   time:?string,
   *   limit:int,
   *   offset:int,
   *   search:string
   * }
   */
  private function normalizeQuery(array $query): array
  {
    $time = isset($query['time']) && is_string($query['time'])
      ? trim($query['time'])
      : null;
    if ($time !== null && preg_match('/^\d{2}:?\d{2}(\d{2})?$/', $time)) {
      $time = str_replace(':', '', $time);
      $time = str_pad($time, 6, '0', STR_PAD_RIGHT);
    } else {
      $time = null;
    }

    $limit = isset($query['limit']) && is_numeric($query['limit'])
      ? (int) $query['limit']
      : 20;

    return [
      'slot_ts' => isset($query['slot_ts']) && is_numeric($query['slot_ts'])
        ? (int) $query['slot_ts']
        : null,
      'latest' => !empty($query['latest']),
      'from_ts' => isset($query['from_ts']) && is_numeric($query['from_ts'])
        ? (int) $query['from_ts']
        : null,
      'to_ts' => isset($query['to_ts']) && is_numeric($query['to_ts'])
        ? (int) $query['to_ts']
        : null,
      'year' => isset($query['year']) && is_numeric($query['year'])
        ? (int) $query['year']
        : null,
      'month' => isset($query['month']) && is_numeric($query['month'])
        ? (int) $query['month']
        : null,
      'day' => isset($query['day']) && is_numeric($query['day'])
        ? (int) $query['day']
        : null,
      'time' => $time,
      'limit' => max(1, min(200, $limit)),
      // Negative Offsets bleiben aus Kompatibilitätsgründen erlaubt: PHPs
      // array_slice() interpretiert sie als Abstand vom Listenende.
      'offset' => isset($query['offset']) && is_numeric($query['offset'])
        ? (int) $query['offset']
        : 0,
      'search' => isset($query['q']) && is_string($query['q'])
        ? trim($query['q'])
        : '',
    ];
  }

  /**
   * Ermittelt den DB-Zeitbereich aus from/to oder den Kalenderparametern.
   *
   * @param array<string,mixed> $request Normalisierte Query
   * @return array{0:int,1:int}
   */
  private function resolveRange(array $request, int $nowTimestamp): array
  {
    $fromTimestamp = $request['from_ts'];
    $toTimestamp = $request['to_ts'];
    $year = $request['year'];
    $month = $request['month'];
    $day = $request['day'];

    if ($fromTimestamp === null && $toTimestamp === null && $year !== null) {
      if ($month !== null && $day !== null) {
        $start = DateTimeImmutable::createFromFormat(
          '!Y-n-j H:i:s',
          sprintf('%04d-%d-%d 00:00:00', $year, $month, $day),
          $this->timezone
        );
        $end = $start instanceof DateTimeImmutable
          ? $start->setTime(23, 59, 59)
          : null;
        if ($start === false || $end === null) {
          throw new InvalidArgumentException('Invalid date');
        }
        $fromTimestamp = $start->getTimestamp();
        $toTimestamp = $end->getTimestamp();
      } elseif ($month !== null) {
        $start = DateTimeImmutable::createFromFormat(
          '!Y-n-j H:i:s',
          sprintf('%04d-%d-01 00:00:00', $year, $month),
          $this->timezone
        );
        if ($start === false) {
          throw new InvalidArgumentException('Invalid month');
        }
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);
        $fromTimestamp = $start->getTimestamp();
        $toTimestamp = $end->getTimestamp();
      } else {
        $start = DateTimeImmutable::createFromFormat(
          '!Y-m-d H:i:s',
          sprintf('%04d-01-01 00:00:00', $year),
          $this->timezone
        );
        $end = DateTimeImmutable::createFromFormat(
          '!Y-m-d H:i:s',
          sprintf('%04d-12-31 23:59:59', $year),
          $this->timezone
        );
        if ($start === false || $end === false) {
          throw new InvalidArgumentException('Invalid year');
        }
        $fromTimestamp = $start->getTimestamp();
        $toTimestamp = $end->getTimestamp();
      }
    }

    if ($fromTimestamp === null || $toTimestamp === null) {
      if ($request['latest']) {
        return [0, $nowTimestamp];
      }
      throw new InvalidArgumentException(
        'Provide slot_ts or a valid date range (from_ts/to_ts or year/month[/day])'
      );
    }

    return [(int) $fromTimestamp, (int) $toTimestamp];
  }

  /**
   * Baut den optionalen Zielzeitpunkt für die Nächstes-Bild-Auswahl.
   * Ein time-Parameter allein reicht bewusst nicht; das Datum muss vollständig
   * sein, damit keine implizite Auswahl aus dem aktuellen Tag entsteht.
   *
   * @param array<string,mixed> $request
   */
  private function resolveTargetTimestamp(array $request): ?int
  {
    if (
      $request['time'] === null
      || $request['year'] === null
      || $request['month'] === null
      || $request['day'] === null
    ) {
      return null;
    }

    $time = $request['time'];
    $date = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i:s',
      sprintf(
        '%04d-%02d-%02d %s:%s:%s',
        $request['year'],
        $request['month'],
        $request['day'],
        substr($time, 0, 2),
        substr($time, 2, 2),
        substr($time, 4, 2)
      ),
      $this->timezone
    );

    return $date instanceof DateTimeImmutable ? $date->getTimestamp() : null;
  }

  /**
   * Sucht linear den zeitlich nächsten Capture. Die DB-Zeilen sind bereits auf
   * den angefragten Zeitraum begrenzt; bei gleichem Abstand gewinnt wie bisher
   * der zuerst geladene, also ältere Slot.
   *
   * @param list<array<string,mixed>> $rows
   * @return array<string,mixed>|null
   */
  private function nearestRow(array $rows, int $targetTimestamp): ?array
  {
    $nearest = null;
    $smallestDifference = PHP_INT_MAX;
    foreach ($rows as $row) {
      $difference = abs((int) ($row['slot_ts'] ?? 0) - $targetTimestamp);
      if ($difference < $smallestDifference) {
        $nearest = $row;
        $smallestDifference = $difference;
      }
    }
    return $nearest;
  }

  /**
   * Lädt für einen ausgewählten Bereichseintrag die vollständige Detailzeile.
   * Der Fallback auf die bereits geladene Zeile bewahrt alte, unvollständige
   * Datenbanken, falls der Eintrag zwischen Bereichs- und Detailquery verschwand.
   *
   * @param array<string,mixed> $row
   * @return array<string,mixed>
   */
  private function detailPayloadForRow(CameraDataStore $store, array $row): array
  {
    $detail = isset($row['slot_ts'])
      ? $this->editor->detail($store, (int) $row['slot_ts'])
      : null;

    return $this->detailPayload($detail ?? [
      'capture' => $row,
      'variants' => $row['variants_rows'] ?? [],
      'exif' => $row['exif_rows'] ?? [],
      'weather' => $row['weather_rows'] ?? [],
      'logo' => null,
    ]);
  }

  /**
   * Erzeugt die gemeinsame Detailantwort für slot_ts, latest und Nächstes-Bild.
   *
   * @param array<string,mixed> $detail
   * @return array<string,mixed>
   */
  private function detailPayload(array $detail): array
  {
    return [
      'capture' => $this->editor->serializeCapture(
        is_array($detail['capture'] ?? null) ? $detail['capture'] : [],
        $this->timezone
      ),
      'variants' => is_array($detail['variants'] ?? null) ? $detail['variants'] : [],
      'exif' => is_array($detail['exif'] ?? null) ? $detail['exif'] : [],
      'weather' => is_array($detail['weather'] ?? null) ? $detail['weather'] : [],
      'logo' => is_array($detail['logo'] ?? null) ? $detail['logo'] : null,
    ];
  }

  /**
   * Formatiert einen Eintrag für die paginierte Collection. Logo-Daten werden
   * hier wie bisher nicht pro Zeile nachgeladen; sie gehören nur zur Detailansicht.
   *
   * @param array<string,mixed> $row
   * @return array<string,mixed>
   */
  private function collectionItem(array $row): array
  {
    return [
      'capture' => $this->editor->serializeCapture($row, $this->timezone),
      'variants' => is_array($row['variants_rows'] ?? null) ? $row['variants_rows'] : [],
      'exif' => is_array($row['exif_rows'] ?? null) ? $row['exif_rows'] : [],
      'weather' => is_array($row['weather_rows'] ?? null) ? $row['weather_rows'] : [],
    ];
  }
}
