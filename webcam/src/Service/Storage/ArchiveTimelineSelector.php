<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Wählt aus Timeline-Daten konkrete Bilder für Navigation und Vergleich aus.
 *
 * Die Klasse verändert weder Datenbank noch Archiv. Sie kapselt ausschließlich
 * Auswahlregeln: Datumskriterien, Uhrzeit-Slots, Zeitfenster und Bildvarianten.
 */
final class ArchiveTimelineSelector
{
    public function __construct(
        private readonly App $app,
        private readonly TimelineNavigator $navigator,
        private readonly ArchiveEntryImageResolver $imageResolver,
        private readonly ArchiveFilesystemReader $filesystem,
        private readonly ArchiveStoragePaths $paths
    ) {
    }

    /** @param array<string,mixed> $timeline @param array<string,int|string|null> $criteria */
    public function select(array $timeline, array $criteria = []): ?array
    {
        return $this->navigator->select(
            $timeline,
            $criteria,
            (string) $this->app->config('timezone', 'UTC')
        );
    }

    /**
     * Sammelt denselben Uhrzeit-Slot aus den jüngsten 30 Tagen.
     *
     * Bereits geladene Timeline-Daten kommen zuerst. Nur bei Lücken liest der
     * zweite Block gezielt das benötigte 30-Tage-Fenster aus dem Dateisystem.
     *
     * @return list<array<string,mixed>>
     */
    public function recentTimeslots(
        Camera $camera,
        array $timeline,
        string $timeValue,
        int $limit = 30,
        ?int $referenceTimestamp = null
    ): array {
        $limit = max(1, $limit);
        $normalizedTime = $this->navigator->normalizeTime($timeValue);
        if ($normalizedTime === null) {
            return [];
        }
        $referenceTimestamp ??= time();
        $earliest = $referenceTimestamp - (30 * 86400);
        $results = [];
        $seen = [];

        // Eine lokale Sammelfunktion hält Filter und Variantenauswahl für DB-
        // und Dateisystemeinträge exakt gleich.
        $collect = function (array $entries) use (
            &$results,
            &$seen,
            $referenceTimestamp,
            $earliest,
            $normalizedTime,
            $limit
        ): void {
            for ($index = count($entries) - 1; $index >= 0 && count($results) < $limit; $index--) {
                $entry = $entries[$index];
                $timestamp = (int) ($entry['timestamp'] ?? 0);
                if ($timestamp <= 0 || $timestamp > $referenceTimestamp) {
                    continue;
                }
                if ($timestamp < $earliest) {
                    break;
                }
                if ((string) ($entry['time'] ?? '') !== $normalizedTime || isset($seen[$timestamp])) {
                    continue;
                }
                $display = $this->imageResolver->resolve($entry, ['sm', 'la', 'lm', 'original', 'full', 'hu']);
                if ($display === null) {
                    continue;
                }
                $large = $this->imageResolver->resolve($entry, ['la', 'lm', 'hu', 'full', 'original', 'sm']);
                $seen[$timestamp] = true;
                $results[] = [
                    'timestamp' => $timestamp,
                    'date_label' => $entry['date_label'] ?? date('d.m.Y', $timestamp),
                    'time_label' => $entry['time_label'] ?? substr($normalizedTime, 0, 2) . ':' . substr($normalizedTime, 2, 2),
                    'url' => $display['url'],
                    'variant' => $display['variant'],
                    'relative_path' => $display['relative_path'],
                    'hi_res_url' => $large['url'] ?? $display['url'],
                ];
            }
        };

        $collect($timeline['entries'] ?? []);
        if (count($results) < $limit) {
            [$cache, $current, $archive] = $this->roots($camera);
            $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
            $startYear = (int) (new DateTimeImmutable('@' . $earliest))->setTimezone($timezone)->format('Y');
            $endYear = (int) (new DateTimeImmutable('@' . $referenceTimestamp))->setTimezone($timezone)->format('Y');
            for ($year = $endYear; $year >= $startYear && count($results) < $limit; $year--) {
                $collect($this->filesystem->entriesForYear(
                    $camera,
                    $year,
                    $cache,
                    $current,
                    $archive,
                    null,
                    $earliest,
                    $referenceTimestamp
                ));
            }
        }

        usort($results, static fn(array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);
        return array_slice($results, 0, $limit);
    }

    /** Ermittelt Cache-, Legacy-current- und Archivwurzel ohne Fehlerdurchgriff. */
    private function roots(Camera $camera): array
    {
        $cache = $current = $archive = '';
        try {
            $cache = $this->app->paths()->cachePath($camera->slug());
        } catch (Throwable) {
        }
        try {
            $base = $this->paths->baseRoot($camera);
            $current = $this->paths->join($base, $camera->storageCurrentDir() . '/' . $camera->slug());
            $archive = $this->paths->join($base, $camera->storageArchiveDir() . '/' . $camera->slug());
        } catch (Throwable) {
        }
        return [$cache, $current, $archive];
    }
}
