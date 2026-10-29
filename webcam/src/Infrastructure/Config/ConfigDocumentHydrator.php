<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Config;

use PDO;
use Webcam\Service\Maintenance\MaintenanceSchedule;

/**
 * Übersetzt die normalisierten SQLite-Tabellen in das fachliche Config-Dokument.
 *
 * Die Datenbank speichert jede Kamerasektion in einer eigenen Tabelle. Der Rest
 * der Anwendung arbeitet dagegen bewusst mit einem zusammenhängenden Array. Diese
 * Klasse ist die einzige Leseschicht, die beide Darstellungen miteinander kennt.
 */
final class ConfigDocumentHydrator
{
    /** Baut Meta-Einstellungen und sämtliche Kameras zu einem Dokument zusammen. */
    public function hydrate(PDO $pdo): array
    {
        $meta = $this->metaMap($pdo);
        $archiveCache = $this->decodeAssoc($meta['archive_cache'] ?? null, [
            'enabled' => false,
            'last_built_at' => null,
        ]);

        // Ein Betreiber kann den Cache für Diagnosezwecke erzwingen, ohne die
        // persistierte Konfiguration verändern zu müssen.
        $forceCache = getenv('WEBCAM_ARCHIVE_CACHE_ENABLE');
        if ($forceCache !== false && trim((string) $forceCache) !== '') {
            $archiveCache['enabled'] = true;
        }

        return [
            'cameras' => $this->cameraMap($pdo),
            'defaults' => $this->decodeAssoc($meta['defaults'] ?? null, ['resolutions' => []]),
            'archive_cache' => $archiveCache,
            'public_rate_limit' => $this->decodeAssoc($meta['public_rate_limit'] ?? null, [
                'limit' => 120,
                'window_seconds' => 60,
                'ttl_days' => 7,
            ]),
            'timeline' => $this->decodeAssoc($meta['timeline'] ?? null, ['max_entries' => 100]),
            'runtime' => $this->decodeAssoc($meta['runtime'] ?? null, [
                'weather_command_timeout_seconds' => 20,
            ]),
            'maintenance_scripts' => MaintenanceSchedule::normalize(
                $this->decodeAssoc($meta['maintenance_scripts'] ?? null, [])
            ),
            'overview_map' => $this->decodeAssoc($meta['overview_map'] ?? null, [
                'lat' => 51.96,
                'lng' => 8.53,
                'zoom' => 11,
                'height_px' => 220,
            ]),
            'overview_hero' => isset($meta['overview_hero'])
                ? (string) $meta['overview_hero']
                : 'Schnelle Kachelübersicht mit Filter & Sortierung – ähnlich foto-webcam.eu, aber leichtgewichtig.',
            'version' => isset($meta['version']) ? (int) $meta['version'] : 0,
            'updated_at' => $meta['updated_at'] ?? null,
        ];
    }

    /** Gruppiert die Hauptzeilen und alle optionalen Untertabellen nach Kamera-ID. */
    private function cameraMap(PDO $pdo): array
    {
        $cameraRows = $pdo->query('SELECT * FROM cameras ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC);
        $locations = $this->rowsByCamera($pdo, 'SELECT * FROM location');
        $captureRules = $this->rowsByCamera($pdo, 'SELECT * FROM capture_rules');
        $storage = $this->rowsByCamera($pdo, 'SELECT * FROM storage_cfg');
        $watchdogs = $this->rowsByCamera($pdo, 'SELECT * FROM watchdog');
        $streams = $this->rowsByCamera($pdo, 'SELECT * FROM stream');
        $weather = $this->rowsByCamera($pdo, 'SELECT * FROM weather_commands');
        $overlays = $this->overlayRows($pdo);
        $labels = $this->labelRows($pdo);

        $result = [];
        foreach ($cameraRows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $id = (string) ($row['id'] ?? $slug);
            $result[$slug] = [
                'id' => $id,
                'slug' => $slug,
                'name' => (string) ($row['name'] ?? $row['title'] ?? $slug),
                'title' => (string) ($row['title'] ?? $row['name'] ?? $slug),
                'short_title' => (string) ($row['short_title'] ?? $row['name'] ?? $slug),
                'description' => (string) ($row['description'] ?? ''),
                'info_html' => (string) ($row['info_html'] ?? ''),
                'location' => $this->location($locations[$id] ?? []),
                'tags' => $this->decodeList($row['tags'] ?? null, false),
                'mode' => (string) ($row['mode'] ?? 'pull-snapshot'),
                'capture' => $this->capture($captureRules[$id] ?? []),
                'storage' => $this->storage($storage[$id] ?? []),
                'status' => $this->status($watchdogs[$id] ?? []),
                'stream' => $this->stream($streams[$id] ?? []),
                'resolutions' => $this->decodeList($row['resolutions'] ?? null, true),
                'weather_commands' => $this->decodeList($weather[$id]['commands'] ?? null, false),
                'overlay_logo' => $this->overlay($overlays[$id]['light'] ?? null),
                'overlay_logo_dark' => $this->overlay($overlays[$id]['dark'] ?? null),
                'labels' => $this->labels($labels[$id] ?? []),
            ];
        }
        return $result;
    }

    /** Liest die Schlüssel/Wert-Metadaten in eine schneller verwendbare Map. */
    private function metaMap(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_ASSOC);
        $meta = [];
        foreach ($rows as $row) {
            if (isset($row['key'])) {
                $meta[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }
        return $meta;
    }

    /** Indexiert eine Tabelle mit höchstens einer Zeile je Kamera. */
    private function rowsByCamera(PDO $pdo, string $sql): array
    {
        $result = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cameraId = (string) ($row['camera_id'] ?? '');
            if ($cameraId !== '') {
                $result[$cameraId] = $row;
            }
        }
        return $result;
    }

    /** Indexiert eine Tabelle mit mehreren geordneten Zeilen je Kamera. */
    private function rowsByCameraList(PDO $pdo, string $sql): array
    {
        $result = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cameraId = (string) ($row['camera_id'] ?? '');
            if ($cameraId !== '') {
                $result[$cameraId][] = $row;
            }
        }
        return $result;
    }

    private function labelRows(PDO $pdo): array
    {
        if (!\webcam_camera_store_table_exists($pdo, 'labels')) {
            \webcam_camera_store_log_missing_table('labels');
            return [];
        }
        return $this->rowsByCameraList($pdo, 'SELECT * FROM labels ORDER BY camera_id, id');
    }

    private function overlayRows(PDO $pdo): array
    {
        $result = [];
        foreach ($pdo->query('SELECT * FROM overlay')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cameraId = (string) ($row['camera_id'] ?? '');
            $variant = strtolower((string) ($row['variant'] ?? ''));
            if ($cameraId !== '' && $variant !== '') {
                $result[$cameraId][$variant] = $row;
            }
        }
        return $result;
    }

    /** Übersetzt flache Ortsfelder in den öffentlichen location-Abschnitt. */
    private function location(array $row): array
    {
        return [
            'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : null,
            'elevation' => isset($row['elevation']) ? (float) $row['elevation'] : null,
            'orientation_deg' => isset($row['orientation_deg']) ? (float) $row['orientation_deg'] : null,
            'field_of_view_deg' => isset($row['field_of_view_deg']) ? (float) $row['field_of_view_deg'] : null,
            'map_center_offset' => isset($row['map_center_offset']) ? (float) $row['map_center_offset'] : 0,
            'map_center_offset_x' => isset($row['map_center_offset_x']) ? (float) $row['map_center_offset_x'] : 0,
            'orientation_visualization_km' => isset($row['orientation_visualization_km'])
                ? (float) $row['orientation_visualization_km'] : null,
            'map_height_px' => isset($row['map_height_px']) ? (float) $row['map_height_px'] : 300,
        ];
    }

    /** Übersetzt Intervall, Transport, Fenster und Active-Upload-Fallback. */
    private function capture(array $row): array
    {
        $capture = [
            'interval_seconds' => isset($row['interval_seconds']) ? (int) $row['interval_seconds'] : 600,
            'transport' => ($row['transport'] ?? '') !== '' ? (string) $row['transport'] : 'http-get',
            'endpoint' => (string) ($row['endpoint'] ?? ''),
            'timeout_seconds' => isset($row['timeout_seconds']) ? (int) $row['timeout_seconds'] : 10,
            'expected_response' => isset($row['expected_response']) ? (int) $row['expected_response'] : 200,
            'extra_times' => $this->decodeList($row['extra_times'] ?? null, false),
            'upload_key' => (string) ($row['upload_key'] ?? ''),
            'active_upload_fallback_failures' => isset($row['active_upload_fallback_failures'])
                ? (int) $row['active_upload_fallback_failures'] : 0,
        ];
        // Optionale Helper-Wahl im bestehenden Payload, keine eigene Schema-Spalte.
        $helperOptions = json_decode((string) ($row['payload'] ?? ''), true);
        if (is_array($helperOptions) && isset($helperOptions['helper_upload_mode'])) {
            $capture['helper_upload_mode'] = $helperOptions['helper_upload_mode'];
        }
        if (is_array($helperOptions) && array_key_exists('auto_iso', $helperOptions)) {
            $capture['auto_iso'] = $helperOptions['auto_iso'];
        }
        if (is_array($helperOptions) && array_key_exists('image_processing', $helperOptions)) {
            $capture['image_processing'] = $helperOptions['image_processing'];
        }
        if (!empty($row['window_start']) || !empty($row['window_end'])) {
            $capture['window'] = [
                'start' => (string) ($row['window_start'] ?? ''),
                'end' => (string) ($row['window_end'] ?? ''),
            ];
        }
        return $capture;
    }

    private function storage(array $row): array
    {
        return [
            'base_path' => (string) ($row['base_path'] ?? 'storage/'),
            'current_dir' => (string) ($row['current_dir'] ?? 'Current'),
            'archive_dir' => (string) ($row['archive_dir'] ?? 'Archive'),
            'best_of_dir' => (string) ($row['best_of_dir'] ?? 'BestOf'),
        ];
    }

    private function status(array $row): array
    {
        if ($row === []) {
            return [];
        }
        return ['watchdog' => [
            'enabled' => !empty($row['enabled']),
            'max_consecutive_failures' => isset($row['max_consecutive_failures'])
                ? (int) $row['max_consecutive_failures'] : 3,
            'reset_sequence' => $this->decodeAssoc($row['reset_sequence'] ?? null, []),
            'cooldown' => [
                'min_successes_after_reset' => isset($row['cooldown_min_successes'])
                    ? (int) $row['cooldown_min_successes'] : 0,
                'min_seconds_after_reset' => isset($row['cooldown_min_seconds'])
                    ? (int) $row['cooldown_min_seconds'] : 0,
            ],
        ]];
    }

    private function stream(array $row): array
    {
        if ($row === []) {
            return ['mode' => 'none'];
        }
        $stream = [
            'mode' => (string) ($row['mode'] ?? 'none'),
            'playlist_url' => $row['playlist_url'] ?? null,
            'snapshot_url' => $row['snapshot_url'] ?? null,
        ];
        foreach (['fps' => [1, 30], 'quality' => [2, 31], 'max_clients' => [1, 20]] as $key => [$min, $max]) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $value = (int) $row[$key];
                if ($value >= $min && $value <= $max) {
                    $stream[$key] = $value;
                }
            }
        }
        return $stream;
    }

    private function overlay(?array $row): ?array
    {
        $url = trim((string) ($row['url'] ?? ''));
        if ($row === null || $url === '') {
            return null;
        }
        return [
            'url' => $url,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'offset_top' => isset($row['offset_top']) ? (int) $row['offset_top'] : 0,
            'offset_right' => isset($row['offset_right']) ? (int) $row['offset_right'] : 0,
            'opacity' => isset($row['opacity']) ? (float) $row['opacity'] : 1.0,
        ];
    }

    /** Verwirft unvollständige Labels statt fehlerhafte Marker auszuliefern. */
    private function labels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $x = isset($row['x']) && is_numeric($row['x']) ? (float) $row['x'] : null;
            $y = isset($row['y']) && is_numeric($row['y']) ? (float) $row['y'] : null;
            $text = trim((string) ($row['text'] ?? ''));
            if ($x === null || $y === null || $text === '') {
                continue;
            }
            $entry = [
                'x' => $x,
                'y' => $y,
                'text' => $text,
                'href' => (string) ($row['href'] ?? ''),
                'type' => isset($row['type']) ? (int) $row['type'] : 0,
                'resolution' => isset($row['resolution']) ? (int) $row['resolution'] : 0,
                'valid_from' => (string) ($row['valid_from'] ?? ''),
                'valid_to' => (string) ($row['valid_to'] ?? ''),
            ];
            foreach (['color', 'text_tone'] as $optional) {
                $value = trim((string) ($row[$optional] ?? ''));
                if ($value !== '' && ($optional !== 'text_tone' || in_array(strtolower($value), ['light', 'dark'], true))) {
                    $entry[$optional] = $optional === 'text_tone' ? strtolower($value) : $value;
                }
            }
            if (isset($row['id']) && is_numeric($row['id'])) {
                $entry['id'] = (int) $row['id'];
            }
            $labels[] = $entry;
        }
        return $labels;
    }

    private function decodeList(?string $value, bool $castToInt): array
    {
        $decoded = $value === null || $value === '' ? [] : json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $entry) {
            if ($castToInt && is_numeric($entry)) {
                $result[] = (int) $entry;
            } elseif (!$castToInt && is_scalar($entry)) {
                $result[] = (string) $entry;
            }
        }
        return $result;
    }

    private function decodeAssoc(?string $value, array $default): array
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
