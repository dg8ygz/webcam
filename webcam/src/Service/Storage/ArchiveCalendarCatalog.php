<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use DirectoryIterator;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Liefert die schlanken Jahres-, Monats- und Tageslisten der Archivnavigation.
 *
 * Diese Klasse lädt keine Bilder. Sie fragt bevorzugt SQLite ab und betrachtet
 * im Dateisystem nur genau die für einen Kalenderwert nötige Verzeichnisebene.
 * Damit bleibt der Aufruf auch auf langsamen Archiv-Mounts berechenbar.
 */
final class ArchiveCalendarCatalog
{
    /** @var array<string,array{months:array<int,list<int>>,days:array<int,array<int,list<int>>>}> */
    private array $memo = [];

    public function __construct(
        private readonly App $app,
        private readonly ArchiveStoragePaths $paths,
        private readonly ArchiveDatabaseReader $database,
        private readonly ArchiveFilesystemReader $filesystem
    ) {
    }

    /** @return array{current:list<string>,archive:list<string>} */
    public function years(Camera $camera): array
    {
        $base = $this->paths->baseRoot($camera);
        $roots = ['current' => [], 'archive' => []];
        try {
            $roots['current'][] = $this->app->paths()->cachePath($camera->slug());
        } catch (Throwable) {
        }
        $roots['current'][] = $this->paths->join($base, $camera->storageCurrentDir() . '/' . $camera->slug());
        $roots['archive'][] = $this->paths->join($base, $camera->storageArchiveDir() . '/' . $camera->slug());

        $years = ['current' => [], 'archive' => []];
        foreach ($roots as $group => $groupRoots) {
            foreach ($groupRoots as $root) {
                if (!is_dir($root)) {
                    continue;
                }
                foreach (new DirectoryIterator($root) as $entry) {
                    if (!$entry->isDot() && $entry->isDir() && preg_match('/^\d{4}$/', $entry->getFilename())) {
                        $years[$group][] = $entry->getFilename();
                    }
                }
            }
            $years[$group] = array_values(array_unique($years[$group]));
        }

        // Vollständig DB-basierte Installationen besitzen eventuell keine
        // sichtbaren Jahresordner. Dann bleibt die Navigation trotzdem nutzbar.
        if (($years['current'] === [] && $years['archive'] === [])
            || ($camera->slug() === 'bielefeld' && $this->app->config('deferred_image_root'))) {
            $databaseYears = $this->database->years($camera) ?? [];
            $years['current'] = array_values(array_unique(array_merge($years['current'], array_map('strval', array_values($databaseYears)))));
            $currentYear = (int) (new DateTimeImmutable('now', $this->timezone()))->format('Y');
            $years['archive'] = array_values(array_unique(array_merge($years['archive'], array_map('strval', array_values(array_filter(
                array_map('intval', $databaseYears),
                static fn(int $year): bool => $year < $currentYear
            ))))));
        }
        rsort($years['current']);
        rsort($years['archive']);
        return $years;
    }

    /** @return array<int,list<int>> */
    public function months(Camera $camera, ?array $yearsFilter = null): array
    {
        $base = $this->paths->baseRoot($camera);
        $archive = $this->paths->join($base, $camera->storageArchiveDir() . '/' . $camera->slug());
        $current = $this->app->paths()->cachePath($camera->slug());
        $pilot = $camera->slug() === 'bielefeld' && $this->app->config('deferred_image_root');
        $years = $yearsFilter ?? $this->filesystem->availableYears([$current, $archive]);
        if ($yearsFilter === null && $pilot) {
            $years = array_unique(array_merge($years, $this->database->years($camera) ?? []));
        }
        $result = [];
        foreach ($years as $yearValue) {
            $year = (int) $yearValue;
            $result[$year] = $this->filesystem->availableMonths([$current, $archive], $year);
            if ($result[$year] === [] || $pilot) {
                $result[$year] = array_values(array_unique(array_merge($result[$year], $this->monthsFromDatabase($camera, $year))));
            }
            sort($result[$year]);
        }
        return $result;
    }

    /** @param list<int> $yearsFilter */
    public function calendar(Camera $camera, array $yearsFilter): array
    {
        $years = array_values(array_unique(array_map('intval', $yearsFilter)));
        sort($years);
        if ($years === []) {
            return ['months' => [], 'days' => []];
        }
        $key = $camera->slug() . ':' . implode(',', $years);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $calendar = $this->calendarFromDatabase($camera, $years);
        if ($calendar === null) {
            $months = $this->months($camera, $years);
            $days = [];
            foreach ($years as $year) {
                foreach ($months[$year] ?? [] as $month) {
                    $days[$year][$month] = $this->days($camera, $year, $month);
                }
            }
            $calendar = ['months' => $months, 'days' => $days];
        }
        return $this->memo[$key] = $calendar;
    }

    /** @return list<int> */
    public function days(Camera $camera, int $year, int $month): array
    {
        $base = $this->paths->baseRoot($camera);
        $roots = [];
        try {
            $roots[] = $this->app->paths()->cachePath($camera->slug());
        } catch (Throwable) {
        }
        $roots[] = $this->paths->join($base, $camera->storageCurrentDir() . '/' . $camera->slug());
        $roots[] = $this->paths->join($base, $camera->storageArchiveDir() . '/' . $camera->slug());
        $days = [];
        foreach ($roots as $root) {
            $monthPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . sprintf('%04d/%02d', $year, $month);
            if (!is_dir($monthPath)) {
                continue;
            }
            foreach (new DirectoryIterator($monthPath) as $entry) {
                if (!$entry->isDot() && $entry->isDir() && preg_match('/^(\d{2})$/', $entry->getFilename(), $match)) {
                    $day = (int) $match[1];
                    if ($day >= 1 && $day <= 31) {
                        $days[$day] = true;
                    }
                }
            }
        }
        $result = array_map('intval', array_keys($days));
        if ($result === []) {
            $result = $this->daysFromDatabase($camera, $year, $month);
        }
        sort($result);
        return $result;
    }

    /** @return list<int> */
    private function monthsFromDatabase(Camera $camera, int $year): array
    {
        $timezone = $this->timezone();
        $start = (new DateTimeImmutable(sprintf('%04d-01-01', $year), $timezone))->getTimestamp();
        $end = (new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year), $timezone))->getTimestamp();
        $dates = $this->databaseDates($camera, $start, $end);
        if ($dates === null) {
            return [];
        }

        $months = [];
        foreach ($dates as $date) {
            if ((int) $date->format('Y') === $year) {
                $months[(int) $date->format('n')] = true;
            }
        }
        $result = array_map('intval', array_keys($months));
        sort($result);
        return $result;
    }

    /** @return list<int> */
    private function daysFromDatabase(Camera $camera, int $year, int $month): array
    {
        $timezone = $this->timezone();
        $startDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone);
        $endDate = $startDate->modify('last day of this month 23:59:59');
        $dates = $this->databaseDates($camera, $startDate->getTimestamp(), $endDate->getTimestamp());
        if ($dates === null) {
            return [];
        }

        $days = [];
        foreach ($dates as $date) {
            if ((int) $date->format('Y') === $year && (int) $date->format('n') === $month) {
                $days[(int) $date->format('j')] = true;
            }
        }
        $result = array_map('intval', array_keys($days));
        sort($result);
        return $result;
    }

    /** @param list<int> $years */
    private function calendarFromDatabase(Camera $camera, array $years): ?array
    {
        $monthSets = $daySets = [];
        $hadRows = false;
        foreach ($years as $year) {
            $start = (new DateTimeImmutable(sprintf('%04d-01-01', $year), $this->timezone()))->getTimestamp();
            $end = (new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year), $this->timezone()))->getTimestamp();
            $dates = $this->databaseDates($camera, $start, $end);
            if ($dates === null) {
                return null;
            }
            $hadRows = $hadRows || $dates !== [];
            foreach ($dates as $date) {
                // Die SQL-Grenzen sind bereits lokale Jahresgrenzen. Die
                // zusätzliche Prüfung verhindert dennoch, dass ein später
                // geänderter oder ungenauer Bereich ein Nachbarjahr einmischt.
                if ((int) $date->format('Y') !== $year) {
                    continue;
                }
                $month = (int) $date->format('n');
                $day = (int) $date->format('j');
                $monthSets[$year][$month] = true;
                $daySets[$year][$month][$day] = true;
            }
        }
        if (!$hadRows) {
            return null;
        }
        $months = $days = [];
        foreach ($years as $year) {
            $months[$year] = array_map('intval', array_keys($monthSets[$year] ?? []));
            sort($months[$year]);
            foreach ($months[$year] as $month) {
                $days[$year][$month] = array_map('intval', array_keys($daySets[$year][$month] ?? []));
                sort($days[$year][$month]);
            }
        }
        return ['months' => $months, 'days' => $days];
    }

    /**
     * Ermittelt belegte lokale Kalendertage mit höchstens einer SQL-Zeile je
     * UTC-Tag.
     *
     * SQLite formatiert Unix-Timestamps ohne Kenntnis der in der Anwendung
     * konfigurierten Zeitzone. Eine direkte strftime-Gruppierung hatte deshalb
     * beispielsweise 01.01.2026 00:00 Uhr Europe/Berlin als 31.12.2025 UTC
     * gelesen und fälschlich „Dezember“ in das Jahr 2026 einsortiert.
     *
     * Pro UTC-Tag reichen erster und letzter Capture: Liegen sie lokal auf
     * verschiedenen Tagen, beweist jeder Rand einen belegten Kalendertag. So
     * bleiben die Resultate zeitzonenkorrekt, ohne alle Capture-Zeilen aus der
     * Datenbank in PHP laden zu müssen.
     *
     * @return list<DateTimeImmutable>|null Null bedeutet DB-Fehler.
     */
    private function databaseDates(Camera $camera, int $start, int $end): ?array
    {
        $store = null;
        try {
            $store = new CameraDataStore($this->app, $camera, null, true);
            $statement = $store->pdo()->prepare(
                "SELECT MIN(slot_ts) AS first_ts, MAX(slot_ts) AS last_ts
                   FROM captures
                  WHERE slot_ts BETWEEN :start AND :end AND is_duplicate=0
                  GROUP BY strftime('%Y-%m-%d', datetime(slot_ts, 'unixepoch'))
                  ORDER BY first_ts"
            );
            $statement->execute([':start' => $start, ':end' => $end]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return null;
        } finally {
            $store?->close();
        }

        $timezone = $this->timezone();
        $dates = [];
        foreach ($rows as $row) {
            foreach (['first_ts', 'last_ts'] as $column) {
                $timestamp = (int) ($row[$column] ?? 0);
                if ($timestamp <= 0) {
                    continue;
                }
                $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                $dates[$date->format('Y-m-d')] = $date;
            }
        }
        ksort($dates);
        return array_values($dates);
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    }
}
