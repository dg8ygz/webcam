<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Liefert Timeline, Kalendernavigation und historische Bildauswahl.
 *
 * Die bevorzugte Metadatenquelle ist die SQLite-Datenbank der Kamera. Gezielte
 * Dateisystemzugriffe ergänzen aktuelle Live-Tage oder dienen als Legacy-
 * Fallback, wenn keine brauchbaren DB-Daten existieren. Der alte Archive-Cache
 * ist kein normaler Laufzeitpfad und darf hier nicht zur Sollquelle werden.
 *
 * Leseführung:
 * 1. Jahre und Timeline laden,
 * 2. daraus eine Auswahl und Nachbarn bestimmen,
 * 3. Kalender-Metadaten bevorzugt aus SQLite ermitteln,
 * 4. einzelne Tage/nahe Einträge gezielt lesen,
 * 5. Legacy-Cache ausschließlich für explizite Diagnose verwalten.
 */
final class ArchiveBrowser
{
  private readonly TimelineMapper $timelineMapper;
  private readonly TimelineNavigator $timelineNavigator;
  private readonly ArchiveStoragePaths $storagePaths;
  private readonly ArchiveDatabaseReader $databaseReader;
  private readonly ArchiveFilesystemReader $filesystemReader;
  private readonly ArchiveEntryImageResolver $entryImageResolver;
  private readonly LegacyArchiveCache $legacyCache;
  private readonly ArchiveCalendarCatalog $calendarCatalog;
  private readonly ArchiveTimelineSelector $timelineSelector;

  public function __construct(private readonly App $app)
  {
    $this->timelineMapper = new TimelineMapper();
    $this->timelineNavigator = new TimelineNavigator();
    $this->storagePaths = new ArchiveStoragePaths($app);
    $this->databaseReader = new ArchiveDatabaseReader($app, $this->timelineMapper);
    $this->filesystemReader = new ArchiveFilesystemReader($app, $this->timelineMapper, $this->databaseReader);
    $this->entryImageResolver = new ArchiveEntryImageResolver(realpath(__DIR__ . '/../../..') ?: '',
      function (string $relative) use ($app): bool {
        $parts = DeferredImages::parse($relative);
        if ($parts === null) return false;
        try { return $app->deferredImages()->available($app->cameraOrFail($parts[0]), $relative); }
        catch (\Throwable) { return false; }
      });
    $this->legacyCache = new LegacyArchiveCache($app, $this->filesystemReader, $this->storagePaths);
    $this->calendarCatalog = new ArchiveCalendarCatalog(
      $app,
      $this->storagePaths,
      $this->databaseReader,
      $this->filesystemReader
    );
    $this->timelineSelector = new ArchiveTimelineSelector(
      $app,
      $this->timelineNavigator,
      $this->entryImageResolver,
      $this->filesystemReader,
      $this->storagePaths
    );
  }
  // -------------------------------------------------------------------------
  // Öffentliche Timeline- und Bildnavigation.
  // Diese Methoden liefern UI-fertige Einträge, ändern aber weder DB noch
  // Bildbestand.
  // -------------------------------------------------------------------------

  /**
   * Ermittelt die Jahre, die der Kamera-Navigation angeboten werden.
   *
   * Zuerst werden nur die obersten Jahresverzeichnisse betrachtet, nicht der
   * gesamte Archivbaum. Wenn dort nichts gefunden wird, liefert die Kamera-DB
   * die Jahre. current und archive bleiben für die UI getrennt.
   *
   * @return array{
   *   current:list<string>,
   *   archive:list<string>
   * }
   */
  public function listYears(Camera $camera): array
  {
    return $this->calendarCatalog->years($camera);
  }

  /** Select before path resolution; never silently clip a long timelapse. */
  public function timelapseEntries(Camera $camera, int $from, int $to, int $limit): array
  {
    $limit = max(2, min(600, $limit));
    $entries = $this->databaseReader->timelapseEntries($camera, $from, $to, $limit);
    if ($entries !== null) { return $entries; }
    // Preserve the bounded legacy filesystem fallback for short windows only.
    // A missing index must not turn an arbitrary multi-year request into a scan.
    if ($to - $from > 72 * 3600) {
      throw new \RuntimeException('Timelapse index unavailable');
    }
    $entries = $this->timeline($camera, null, $from, $to)['entries'] ?? [];
    $total = count($entries);
    if ($total <= $limit) { return $entries; }
    $sample = [];
    for ($i = 0; $i < $limit; $i++) {
      $sample[] = $entries[(int) floor($i * ($total - 1) / ($limit - 1))];
    }
    return $sample;
  }

  /**
   * Baut die vollständige Timeline-Struktur für einen Zeitraum.
   *
   * Reihenfolge: Kamera-DB lesen, einen aktuellen Einzeltag bei Bedarf mit dem
   * SSD-Cache ergänzen und erst bei unbrauchbaren DB-Daten den enger begrenzten
   * Dateisystem-Fallback verwenden. Die Rückgabe enthält neben Bildern auch die
   * Kalender-Maps, damit camera.php nicht erneut Verzeichnisse durchsuchen muss.
   *
   * @return array{
   *   entries:list<array{
   *     timestamp:int,
   *     year:int,
   *     month:int,
   *     day:int,
   *     time:string,
   *     time_label:string,
   *     relative_path:?string,
   *     url:?string,
   *     preview_url:?string,
   *     source:string,
   *     datetime_label:string,
   *     datetime_iso:string,
   *     variants:array<string,array{url:string,relative_path:string,filename:string}>,
   *     exif_path:?string,
   *     exif_relative:?string,
   *     primary_variant:?string
   *   }>,
   *   years:list<int>,
   *   months:array<int,list<int>>,
   *   days:array<int,array<int,list<int>>>,
   *   times:array<int,array<int,array<int,list<array<string,mixed>>>>>,
   *   latest:?array
   * }
   */
  public function timeline(Camera $camera, ?int $yearFilter = null, ?int $fromTs = null, ?int $toTs = null): array
  {
    // Strategie in Kurzform:
    // - erst DB/Metadaten verwenden, weil das auf SSD/SQLite deutlich günstiger
    //   ist als Monats-/Tages-Scans auf dem Dateisystem
    // - für heutige Live-Tage fehlende Einträge bei Bedarf aus dem Cache-Dateibaum
    //   nachziehen
    // - nur wenn DB nichts Brauchbares liefern kann, auf Dateisystem-Fallback gehen
    // Pfade bestimmen: aktuelles Jahr aus cache/<slug>, Archiv aus storage/archive/<slug>
    $cacheRoot = '';
    try {
      $cacheRoot = $this->app->paths()->cachePath($camera->slug());
    } catch (\Throwable) {
      $cacheRoot = '';
    }
    $currentRootLegacy = ''; // legacy current/<slug> nicht mehr genutzt
    try {
      $archiveRoot = $this->app->paths()->storagePath('archive/' . $camera->slug());
    } catch (\Throwable) {
      $archiveRoot = '';
    }

    $prefilledMonths = [];

    $dayRange = $this->timelineNavigator->singleDayRange(
      $yearFilter,
      $fromTs,
      $toTs,
      (string) $this->app->config('timezone', 'UTC')
    );

    $fullYearRange = false;
    if ($yearFilter !== null && $fromTs !== null && $toTs !== null) {
      $yearStart = strtotime(sprintf('%04d-01-01 00:00:00', $yearFilter));
      $yearEnd = strtotime(sprintf('%04d-12-31 23:59:59', $yearFilter));
      if ($yearStart === $fromTs && $yearEnd === $toTs) {
        $fullYearRange = true;
      }
    }

    // DB bevorzugen, wenn die Capture-Zeilen vollständig auf existente Dateien aufgelöst werden können.
    $dbTimeline = $this->databaseReader->timeline($camera, $yearFilter, $fromTs, $toTs, $prefilledMonths);
    if ($dbTimeline !== null) {
      if ($dayRange !== null && $dayRange['year'] === (int) (new \DateTimeImmutable('now', new \DateTimeZone($this->app->config('timezone', 'UTC'))))->format('Y')) {
        $fsDayEntries = $this->filesystemReader->entriesForDay(
          $camera,
          $dayRange['year'],
          $dayRange['month'],
          $dayRange['day'],
          $cacheRoot,
          $currentRootLegacy,
          $archiveRoot
        );
        $fsDayEntries = $this->databaseReader->excludeDuplicates($camera, $fsDayEntries);
        if ($fsDayEntries !== []) {
          $dbTimeline = $this->timelineMapper->merge($dbTimeline, $fsDayEntries, $yearFilter);
        }
      }

      // Hard-limit entries only for unbounded queries; preserve full slices when a time window is requested
      $meta = $this->app->camerasMeta();
      $timelineCfg = is_array($meta['timeline'] ?? null) ? $meta['timeline'] : [];
      $maxEntries = (int) ($timelineCfg['max_entries'] ?? (getenv('WEBCAM_TIMELINE_MAX_ENTRIES') ?: 300));
      $shouldLimit = $maxEntries > 0 && $fromTs === null && $toTs === null;
      if ($shouldLimit && isset($dbTimeline['entries']) && count($dbTimeline['entries']) > $maxEntries) {
        $dbTimeline['entries'] = array_slice($dbTimeline['entries'], -1 * $maxEntries);
      }
      return $dbTimeline; // avoid expensive filesystem scan when DB data available
    }

    // Ein DB-Zeitraum, der ausschließlich als Duplikat markierte Captures
    // enthält, ist absichtlich leer. Die Bilder dürfen weder über Cache- noch
    // Archivscans wieder in Suche oder Navigation gelangen.
    if ($fromTs !== null && $toTs !== null && $this->databaseReader->rangeContainsOnlyDuplicates($camera, $fromTs, $toTs)) {
      $availableYears = $this->databaseReader->years($camera) ?? [];
      sort($availableYears);
      return $this->timelineMapper->assemble([], $availableYears, $yearFilter, $prefilledMonths);
    }

    if ($dayRange !== null) {
      $fsDayEntries = $this->filesystemReader->entriesForDay(
        $camera,
        $dayRange['year'],
        $dayRange['month'],
        $dayRange['day'],
        $cacheRoot,
        $currentRootLegacy,
        $archiveRoot
      );
      $fsDayEntries = $this->databaseReader->excludeDuplicates($camera, $fsDayEntries);
      if ($fsDayEntries !== []) {
        $availableYears = $this->databaseReader->years($camera) ?? [];
        if ($availableYears === []) {
          $availableYears = [$dayRange['year']];
        }
        sort($availableYears);

        return $this->timelineMapper->assemble($fsDayEntries, $availableYears, $yearFilter, $prefilledMonths);
      }
    }

    $availableYears = $this->filesystemReader->availableYears([$cacheRoot, $currentRootLegacy, $archiveRoot]);
    if ($availableYears) {
      sort($availableYears);
    }
    if ($yearFilter === null && $fromTs !== null) {
      $yearFilter = (int) gmdate('Y', $fromTs);
    } elseif ($yearFilter === null && $availableYears) {
      $yearFilter = (int) max($availableYears);
    }

    $prefilledMonths = [];
    if ($yearFilter !== null) {
      $prefilledMonths[$yearFilter] = $this->filesystemReader->availableMonths([$cacheRoot, $currentRootLegacy, $archiveRoot], $yearFilter);
    }

    $entries = [];
    if ($yearFilter !== null) {
      $skipCache = ($fromTs !== null || $toTs !== null) && !$fullYearRange;
      $useCache = !$skipCache && $this->legacyCache->isFresh($camera, $yearFilter);
      $entries = $useCache ? ($this->legacyCache->load($camera, $yearFilter) ?? []) : [];
      if (!$entries) {
        $entries = $this->filesystemReader->entriesForYear(
          $camera,
          $yearFilter,
          (string) ($cacheRoot ?? ''),
          (string) ($currentRootLegacy ?? ''),
          (string) ($archiveRoot ?? ''),
          null,
          $fromTs,
          $toTs
        );
        if ($this->legacyCache->isEnabled()) {
          $this->legacyCache->write($camera, $yearFilter, $entries);
        }
      }
    }

    // Apply range filter even when loaded from cache
    if ($entries && ($fromTs !== null || $toTs !== null)) {
      $entries = array_values(array_filter($entries, static function (array $entry) use ($fromTs, $toTs): bool {
        $ts = (int) ($entry["timestamp"] ?? 0);
        if ($fromTs !== null && $ts < $fromTs) {
          return false;
        }
        if ($toTs !== null && $ts > $toTs) {
          return false;
        }
        return true;
      }));
    }
    if (!$entries && ($fromTs !== null || $toTs !== null) && $yearFilter !== null) {
      $entries = $this->filesystemReader->entriesForYear(
        $camera,
        $yearFilter,
        (string) ($cacheRoot ?? ''),
        (string) ($currentRootLegacy ?? ''),
        (string) ($archiveRoot ?? ''),
        null,
        $fromTs,
        $toTs
      );
    }
    $entries = $this->databaseReader->excludeDuplicates($camera, $entries);
    $entries = $this->databaseReader->suppressFlaggedWeather($camera, $entries);
    return $this->timelineMapper->assemble($entries, $availableYears, $yearFilter, $prefilledMonths);
  }

  /**
   * Wählt aus einer bereits geladenen Timeline das passendste Bild.
   *
   * Vollständige Datum-/Zeitangaben suchen den zeitlich nächsten Eintrag am
   * selben Tag. Unvollständige Kriterien werden stufenweise bis zum Jahr
   * reduziert; ohne Auswahl gewinnt das neueste Bild.
   *
   * @param array<string,mixed> $timeline
   * @param array<string,int|string|null> $criteria
   * @return array<string,mixed>|null
   */
  public function selectFromTimeline(array $timeline, array $criteria = []): ?array
  {
    return $this->timelineSelector->select($timeline, $criteria);
  }

  /**
   * Sucht an einem Tag das Bild, das einer gewünschten Uhrzeit am nächsten ist.
   * Die schlanke DB-Suche läuft zuerst; nur bei fehlenden DB-Daten wird der
   * komplette Tag geladen. fallbackEdge bestimmt dann erstes oder letztes Bild.
   */
  public function dayNearestEntry(Camera $camera, int $year, int $month, int $day, string $timeValue, string $fallbackEdge = 'last'): ?array
  {
    $normalizedTime = $this->timelineNavigator->normalizeTime($timeValue);
    if ($normalizedTime === null) {
      return $this->dayEdgeEntry($camera, $year, $month, $day, $fallbackEdge);
    }

    $tzName = $this->app->config('timezone', 'UTC');
    $targetTs = $this->timelineNavigator->criteriaToTimestamp([
      'year' => $year,
      'month' => $month,
      'day' => $day,
      'time' => $normalizedTime,
    ], $tzName);
    if ($targetTs === null) {
      return $this->dayEdgeEntry($camera, $year, $month, $day, $fallbackEdge);
    }

    $tz = new \DateTimeZone($tzName);
    $start = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $tz))->getTimestamp();
    $end = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day), $tz))->getTimestamp();

    $entry = $this->nearestEntryFromDatabase($camera, $targetTs, $start, $end);
    if ($entry !== null) {
      return $entry;
    }

    $dayData = $this->entriesForDay($camera, $year, $month, $day);
    $entries = $dayData['entries'] ?? [];
    $closest = $this->timelineNavigator->closestEntry($entries, $targetTs);
    if ($closest !== null) {
      return $closest;
    }

    return $this->dayEdgeEntry($camera, $year, $month, $day, $fallbackEdge);
  }

  /**
   * Sucht die Bilder möglichst nahe eine Stunde vor und nach einem Capture.
   * Die Zielzeitpunkte werden als absolute Unix-Zeit berechnet und erst dann
   * in den jeweiligen lokalen Kalendertag aufgelöst. Dadurch bleibt die Minute
   * beim Wechsel über Mitternacht erhalten (z.B. 00:50 -> 23:50 am Vortag).
   *
   * @return array{prev:?array<string,mixed>,next:?array<string,mixed>}
   */
  public function hourlyAdjacentEntries(Camera $camera, int $selectedTs): array
  {
    if ($selectedTs <= 0) {
      return ['prev' => null, 'next' => null];
    }

    $tz = new \DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $resolve = function (int $targetTs, int $direction) use ($camera, $selectedTs, $tz): ?array {
      $target = (new \DateTimeImmutable('@' . $targetTs))->setTimezone($tz);
      $dayStart = $target->setTime(0, 0, 0)->getTimestamp();
      $dayEnd = $target->setTime(23, 59, 59)->getTimestamp();
      $rangeStart = $direction < 0 ? $dayStart : max($dayStart, $selectedTs + 1);
      $rangeEnd = $direction < 0 ? min($dayEnd, $selectedTs - 1) : $dayEnd;
      if ($rangeEnd < $rangeStart) {
        return null;
      }

      $entry = $this->nearestEntryFromDatabase($camera, $targetTs, $rangeStart, $rangeEnd);
      if ($entry !== null) {
        return $entry;
      }

      $dayData = $this->entriesForDay(
        $camera,
        (int) $target->format('Y'),
        (int) $target->format('n'),
        (int) $target->format('j')
      );
      $entries = array_values(array_filter(
        $dayData['entries'] ?? [],
        static function (array $candidate) use ($rangeStart, $rangeEnd): bool {
          $timestamp = (int) ($candidate['timestamp'] ?? 0);
          return $timestamp >= $rangeStart && $timestamp <= $rangeEnd;
        }
      ));

      return $this->timelineNavigator->closestEntry($entries, $targetTs);
    };

    $prev = $resolve($selectedTs - 3600, -1);
    $next = $resolve($selectedTs + 3600, 1);

    return ['prev' => $prev, 'next' => $next];
  }

  /**
   * Sammelt für die Vergleichsansicht denselben Uhrzeit-Slot aus jüngeren Tagen.
   * Doppelte Zeitstempel und Einträge außerhalb des 30-Tage-Fensters werden
   * verworfen; Varianten werden auf eine tatsächlich auslieferbare URL geprüft.
   *
   * @return list<array{timestamp:int,date_label:string,time_label:string,url:string,variant:string,relative_path:string}>
   */
  public function recentTimeslotEntries(
    \Webcam\Domain\Camera $camera,
    array $timeline,
    string $timeValue,
    int $limit = 30,
    ?int $referenceTimestamp = null
  ): array {
    return $this->timelineSelector->recentTimeslots(
      $camera,
      $timeline,
      $timeValue,
      $limit,
      $referenceTimestamp
    );
  }

  // -------------------------------------------------------------------------
  // Kalender-Metadaten.
  // SQLite ist die Sollquelle. Nur fehlende Legacy-Bestände werden mit eng
  // begrenzten Verzeichnisabfragen ergänzt; vollständige Bildbäume werden hier
  // nicht rekursiv gescannt.
  // -------------------------------------------------------------------------
  /**
   * Liefert die verfügbaren Monate je Jahr.
   *
   * Die Methode prüft nur die unmittelbaren Monatsverzeichnisse. Fehlen diese,
   * fragt sie die Kamera-DB ab; tiefe Archivscans werden vermieden.
   *
   * @return array<int,list<int>> months[year] = [1,2,...]
   */
  public function availableMonths(Camera $camera, ?array $yearsFilter = null): array
  {
    return $this->calendarCatalog->months($camera, $yearsFilter);
  }

  /**
   * Liefert Monats-/Tages-Metadaten für die angefragten Jahre.
   *
   * Bevorzugt eine DB-Abfrage über `captures`, damit Timeline-Picker nicht
   * monatsweise Verzeichnis-Scans auf langsamen Network-Mounts auslösen.
   *
   * @param list<int> $yearsFilter
   * @return array{
   *   months: array<int,list<int>>,
   *   days: array<int,array<int,list<int>>>
   * }
   */
  public function availableCalendar(Camera $camera, array $yearsFilter): array
  {
    return $this->calendarCatalog->calendar($camera, $yearsFilter);
  }

  /**
   * Liefert alle belegten Tage eines Monats.
   *
   * Cache, Legacy-current und Archiv werden nur auf Monatsebene geprüft. Wenn
   * dort nichts erkennbar ist, liefert die Kamera-DB die Tage.
   *
   * @return list<int>
   */
  public function availableDays(Camera $camera, int $year, int $month): array
  {
    return $this->calendarCatalog->days($camera, $year, $month);
  }

  /**
   * Liefert das neueste auflösbare Bild der Kamera.
   *
   * Die DB bestimmt zuerst nur den jüngsten Slot und lädt genau diesen Datensatz.
   * Erst wenn das scheitert, wird die allgemeinere Timeline verwendet.
   */
  public function latestEntry(Camera $camera): ?array
  {
    try {
      $store = new \Webcam\Infrastructure\Storage\CameraDataStore($this->app, $camera, null, true);
      $pdo = $store->pdo();
      $stmt = $pdo->query('SELECT slot_ts FROM captures WHERE is_duplicate = 0 ORDER BY slot_ts DESC LIMIT 1');
      $value = $stmt ? $stmt->fetchColumn() : null;
      $latestTs = ($value !== false && $value !== null) ? (int) $value : null;
      if ($latestTs !== null && $latestTs > 0) {
        $rows = $store->capturesForRange($latestTs, $latestTs);
        if ($rows) {
          $tz = new \DateTimeZone($this->app->config('timezone', 'UTC'));
          $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
          // Cache-Varianten bleiben dokumentrelativ und funktionieren damit
          // unabhängig vom Installationspräfix (/webcam, /testx, Proxy-Prefix).
          $publicBaseCache = '';
          foreach ($rows as $row) {
            $entry = $this->databaseReader->entryFromRow($camera, $row, $tz, $publicBaseArchive, $publicBaseCache);
            if ($entry !== null) {
              return $entry;
            }
          }
        }
      }
    } catch (\Throwable) {
      // fallback below
    }

    $timeline = $this->timeline($camera, null, null, null);
    $latest = $timeline['latest'] ?? null;
    return is_array($latest) ? $latest : null;
  }

  /**
   * Liefert nur den ersten oder letzten Navigationspunkt eines Tages.
   *
   * Das ist absichtlich leichter als entriesForDay(): Navigationslinks benötigen
   * nur den Zielzeitpunkt, nicht sämtliche Varianten und Wetterdaten.
   */
  public function dayEdgeEntry(Camera $camera, int $year, int $month, int $day, string $edge = 'first'): ?array
  {
    $edge = strtolower($edge) === 'last' ? 'last' : 'first';
    $tz = new \DateTimeZone($this->app->config('timezone', 'UTC'));
    $start = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $tz))->getTimestamp();
    $end = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day), $tz))->getTimestamp();

    try {
      $store = new \Webcam\Infrastructure\Storage\CameraDataStore($this->app, $camera, null, true);
      $pdo = $store->pdo();
      $order = $edge === 'last' ? 'DESC' : 'ASC';
      $stmt = $pdo->prepare(
        "SELECT slot_ts
          FROM captures
          WHERE slot_ts BETWEEN :start AND :end
            AND is_duplicate = 0
          ORDER BY slot_ts {$order}
          LIMIT 1"
      );
      $stmt->execute([
        ':start' => $start,
        ':end' => $end,
      ]);
      $value = $stmt->fetchColumn();
      $slotTs = ($value !== false && $value !== null) ? (int) $value : null;
      if ($slotTs !== null && $slotTs > 0) {
        return $this->timelineNavigator->navigationEntry($slotTs, $tz);
      }
    } catch (\Throwable) {
      // Fall back to the heavier per-day loading path below.
    }

    $dayData = $this->entriesForDay($camera, $year, $month, $day);
    $entries = $dayData['entries'] ?? [];
    if (!$entries) {
      return null;
    }

    return $edge === 'last' ? $entries[count($entries) - 1] : $entries[0];
  }
  // -------------------------------------------------------------------------
  // Gezielte Tages- und Nächster-Eintrag-Abfragen.
  // Sie werden von History, Stunden-Sprüngen und Lightbox genutzt und vermeiden
  // dafür das Laden einer vollständigen Jahres-Timeline.
  // -------------------------------------------------------------------------
  /**
   * Liefert alle Einträge eines lokalen Kalendertages inklusive Varianten.
   *
   * Standardmäßig wird zuerst die Kamera-DB gelesen. preferFilesystem ist ein
   * bewusster Diagnose-/Fallbackschalter; gefundene Dateien werden anschließend
   * um DB-Wetter ergänzt und auf Qualitätsflags geprüft.
   *
   * @return array{entries:list<array<string,mixed>>,latest:?array}
   */
  public function entriesForDay(Camera $camera, int $year, int $month, int $day, bool $preferFilesystem = false): array
  {
    $tz = new \DateTimeZone($this->app->config('timezone', 'UTC'));
    $start = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $tz))->getTimestamp();
    $end = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day), $tz))->getTimestamp();
    $cacheRoot = '';
    $currentRootLegacy = '';
    $archiveRoot = '';
    try {
      $cacheRoot = $this->app->paths()->cachePath($camera->slug());
    } catch (\Throwable) {
      $cacheRoot = '';
    }
    try {
      $basePath = $this->storagePaths->baseRoot($camera);
      $currentRootLegacy = $this->storagePaths->join($basePath, $camera->storageCurrentDir() . '/' . $camera->slug());
      $archiveRoot = $this->storagePaths->join($basePath, $camera->storageArchiveDir() . '/' . $camera->slug());
    } catch (\Throwable) {
      $currentRootLegacy = '';
      $archiveRoot = '';
    }

    if ($preferFilesystem) {
      $entries = $this->filesystemReader->entriesForDay($camera, $year, $month, $day, $cacheRoot, $currentRootLegacy, $archiveRoot);
      $entries = $this->databaseReader->excludeDuplicates($camera, $entries);
      if ($entries !== []) {
        $entries = $this->databaseReader->enrichWithWeather($camera, $entries);
        return [
          'entries' => $entries,
          'latest' => $entries[count($entries) - 1] ?? null,
        ];
      }
    }

    // Prefer DB slice for single-day lookups to avoid expensive filesystem scans,
    // but fall back to filesystem when the DB returns no usable entries.
    $dbTimeline = $this->databaseReader->timeline($camera, $year, $start, $end);
    if ($dbTimeline !== null) {
      if ($year === (int) (new \DateTimeImmutable('now', $tz))->format('Y')) {
        $fsDayEntries = $this->filesystemReader->entriesForDay(
          $camera,
          $year,
          $month,
          $day,
          $cacheRoot,
          $currentRootLegacy,
          $archiveRoot
        );
        $fsDayEntries = $this->databaseReader->excludeDuplicates($camera, $fsDayEntries);
        if ($fsDayEntries !== []) {
          $dbTimeline = $this->timelineMapper->merge($dbTimeline, $fsDayEntries, $year);
        }
      }

      $entries = $dbTimeline['entries'] ?? [];
      if ($entries) {
        return [
          'entries' => $entries,
          'latest' => $dbTimeline['latest'] ?? null,
        ];
      }
    }

    $entries = $this->filesystemReader->entriesForDay($camera, $year, $month, $day, $cacheRoot, $currentRootLegacy, $archiveRoot);
    $entries = $this->databaseReader->excludeDuplicates($camera, $entries);
    $entries = $this->databaseReader->enrichWithWeather($camera, $entries);
    return [
      'entries' => $entries,
      'latest' => $entries ? $entries[count($entries) - 1] : null,
    ];
  }

  /**
   * Sucht ausschließlich in der DB den Capture vor und nach einem Zielzeitpunkt.
   * Der kleinere Abstand gewinnt; bei Gleichstand wird der frühere Capture
   * gewählt. null signalisiert, dass der Aufrufer einen Fallback versuchen darf.
   */
  public function nearestEntryFromDatabase(
    Camera $camera,
    int $targetTs,
    int $startTs,
    int $endTs,
    ?\Webcam\Infrastructure\Storage\CameraDataStore $store = null,
    ?int $singleWidth = null
  ): ?array
  {
    if ($targetTs <= 0 || $endTs < $startTs) {
      return null;
    }
    try {
      $store ??= new \Webcam\Infrastructure\Storage\CameraDataStore($this->app, $camera, null, true);
      $store->pdo();
    } catch (\Throwable) {
      return null;
    }
    try {
      $bestTs = $store->nearestCaptureSlot($targetTs, $startTs, $endTs);
      if ($bestTs === null) {
        return null;
      }
      $rows = $store->capturesForRange($bestTs, $bestTs);
      if (!$rows) {
        return null;
      }
      $tz = new \DateTimeZone($this->app->config('timezone', 'UTC'));
      foreach ($rows as $row) {
        $entry = $this->databaseReader->entryFromRow($camera, $row, $tz, null, null, $singleWidth);
        if ($entry !== null) {
          return $entry;
        }
      }
    } catch (\Throwable) {
      return null;
    }
    return null;
  }

  /**
   * Legacy-/Optionalpfad: sucht im alten Archive-Cache den zeitlich nächsten
   * Eintrag. Die öffentliche Methode bleibt aus Kompatibilitätsgründen hier;
   * Dateiformat und Suchalgorithmus gehören vollständig der Cache-Komponente.
   */
  public function nearestEntryFromCache(Camera $camera, int $year, int $targetTs): ?array
  {
    return $this->legacyCache->nearest($camera, $year, $targetTs);
  }

  /**
   * Legacy-Werkzeug: baut den optionalen Archive-Cache nur nach ausdrücklichem
   * Admin-/CLI-Aufruf neu. Normale Timeline-Requests erreichen diesen Pfad nicht.
   *
   * @param list<int>|null $years
   * @param callable|null $progress function(string $slug, int $year, string $stage, ?string $context = null): void
   * @return array<string,mixed>
   */
  public function rebuildCache(Camera $camera, ?array $years = null, ?callable $progress = null): array
  {
    return $this->legacyCache->rebuild($camera, $years, $progress);
  }

  /**
   * Liefert den Zustand des optionalen Cache-Werkzeugs für die Admin-Anzeige.
   *
   * @return array<string,mixed>
   */
  public function cacheStatus(?Camera $camera = null): array
  {
    return $this->legacyCache->status($camera);
  }
}
