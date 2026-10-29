<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Scannt einen Best-Of-Ordner und erzeugt seine stabile Anzeige-Reihenfolge.
 * Varianten derselben Aufnahme und Mehrfachbilder derselben Minute werden hier
 * reduziert, bevor Pagination oder globale Zusammenführung stattfinden.
 */
final class BestOfFileCatalog
{
    public function __construct(
        private readonly App $app,
        private readonly BestOfGalleryCache $cache
    ) {
    }

    /** @return list<string> */
    public function orderedBasenames(Camera $camera, string $directory, ?int $limit = null): array
    {
        $fingerprint = $this->cache->directoryFingerprint($directory);
        $cached = $this->cache->loadBasenames($camera, $fingerprint, $limit);
        if ($cached !== null) {
            return $limit === null ? $cached : array_slice($cached, 0, $limit);
        }
        if ($limit !== null) {
            $full = $this->cache->loadBasenames($camera, $fingerprint, null);
            if ($full !== null) {
                $slice = array_slice($full, 0, $limit);
                $this->cache->writeBasenames($camera, $fingerprint, $slice, $limit);
                return $slice;
            }
        }

        $names = scandir($directory, SCANDIR_SORT_NONE);
        if (!is_array($names)) {
            $this->cache->writeBasenames($camera, $fingerprint, [], $limit);
            return [];
        }

        // Niedrige Ränge sind die bevorzugten Webvarianten. Original-/Legacy-
        // Namen bleiben als letzter Fallback erhalten.
        $rank = static function (string $name): int {
            foreach (['la' => 1, 'lm' => 2, 'sm' => 3, 'hu' => 4] as $variant => $value) {
                if (preg_match('~_' . $variant . '(\.jpe?g)$~i', $name)) {
                    return $value;
                }
            }
            return 5;
        };
        $entries = [];
        foreach ($names as $name) {
            if (!preg_match('/\.jpe?g$/i', $name) || preg_match('/_thumb\.jpe?g$/i', $name)) {
                continue;
            }
            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            $sortTs = $this->timestamp($name);
            if ($sortTs === null) {
                clearstatcache(true, $path);
                $mtime = filemtime($path);
                $sortTs = $mtime === false ? 0 : (int) $mtime;
            }
            $entries[] = ['basename' => $name, 'sort_ts' => $sortTs, 'rank' => $rank($name)];
        }
        usort($entries, self::newestFirst(...));

        // Erst Varianten derselben Aufnahme, danach Aufnahmen derselben Minute
        // reduzieren. Bei Gleichstand gewinnt deterministisch der Dateiname.
        $variants = [];
        foreach ($entries as $entry) {
            $base = preg_replace('~_(la|lm|sm|hu|full)(\.jpe?g)$~i', '', $entry['basename']);
            $existing = $variants[$base] ?? null;
            if ($existing === null
                || $entry['rank'] < $existing['rank']
                || ($entry['rank'] === $existing['rank'] && $entry['sort_ts'] > $existing['sort_ts'])
            ) {
                $variants[$base] = $entry;
            }
        }
        $minutes = [];
        $undated = [];
        foreach ($variants as $entry) {
            $slot = $this->timestamp($entry['basename']);
            if ($slot === null) {
                $undated[] = $entry;
                continue;
            }
            $key = intdiv($slot, 60);
            $existing = $minutes[$key] ?? null;
            if ($existing === null || $this->isPreferred($entry, $existing)) {
                $minutes[$key] = $entry;
            }
        }
        $reduced = array_merge(array_values($minutes), $undated);
        usort($reduced, self::newestFirst(...));
        $ordered = array_values(array_filter(
            array_map(static fn(array $item): string => (string) ($item['basename'] ?? ''), $reduced)
        ));

        $this->cache->writeBasenames($camera, $fingerprint, $ordered, null);
        if ($limit !== null) {
            $ordered = array_slice($ordered, 0, $limit);
            $this->cache->writeBasenames($camera, $fingerprint, $ordered, $limit);
        }
        return $ordered;
    }

    /** Aufnahmezeit stammt nur aus dem belastbaren YYYYMMDD_HHMMSS-Präfix. */
    public function timestamp(string $relative): ?int
    {
        if (!preg_match('/^(\d{8})_(\d{6})(?:_utc([1-9]\d{0,10}))?(?:_|\.)/', basename($relative), $match)) {
            return null;
        }
        $date = CaptureFilename::date($match[1] . $match[2],
            new DateTimeZone((string) $this->app->config('timezone', 'UTC')), $match[3] ?? '');
        return $date ? $date->getTimestamp() : null;
    }

    private static function newestFirst(array $left, array $right): int
    {
        return ((int) $right['sort_ts'] <=> (int) $left['sort_ts'])
            ?: strcmp((string) $right['basename'], (string) $left['basename']);
    }

    private function isPreferred(array $candidate, array $existing): bool
    {
        return $candidate['rank'] < $existing['rank']
            || ($candidate['rank'] === $existing['rank'] && $candidate['sort_ts'] > $existing['sort_ts'])
            || ($candidate['rank'] === $existing['rank']
                && $candidate['sort_ts'] === $existing['sort_ts']
                && strcmp($candidate['basename'], $existing['basename']) > 0);
    }
}
