<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Config;

use PDO;
use Throwable;
use Webcam\Service\Maintenance\MaintenanceSchedule;

/**
 * Speichert ein vollständiges Kamera-Konfigurationsdokument atomar in SQLite.
 *
 * Jede private Methode besitzt genau eine fachliche Untertabelle. Dadurch sind
 * Spaltenänderungen künftig lokal nachvollziehbar und die öffentliche
 * camera_store.php bleibt eine kleine Kompatibilitätsschicht.
 */
final class ConfigDocumentPersister
{
    /** Normalisiert Meta-Werte, ersetzt Kamerazeilen und committed alles gemeinsam. */
    public function persist(PDO $pdo, array $document, bool $bumpVersion = true): array
    {
        \webcam_camera_store_guard_write($pdo, $document);
        $pdo->beginTransaction();

        try {
            $meta = $this->metaMap($pdo);
            $initialized = array_key_exists('version', $meta);
            if ($initialized && (!is_int($document['version'] ?? null)
                || $document['version'] !== (int) $meta['version'])) {
                throw new ConfigConflictException('Configuration changed; reload before saving again');
            }
            $version = $initialized ? (int) $meta['version'] : (int) ($document['version'] ?? 0);
            // bumpVersion=false ist nur für die Erstinitialisierung gedacht.
            // Jede Änderung eines bestehenden Dokuments braucht eine neue CAS-Version.
            if ($bumpVersion || $initialized) {
                $version++;
            }
            $document['version'] = $version;
            $document['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

            // Fehlende globale Abschnitte erhalten dieselben sicheren Defaults
            // wie beim Lesen. So bleibt auch ein älterer Admin-Client kompatibel.
            $global = [
                'defaults' => is_array($document['defaults'] ?? null)
                    ? $document['defaults'] : ['resolutions' => []],
                'archive_cache' => is_array($document['archive_cache'] ?? null)
                    ? $document['archive_cache'] : ['enabled' => false, 'last_built_at' => null],
                'public_rate_limit' => is_array($document['public_rate_limit'] ?? null)
                    ? $document['public_rate_limit'] : ['limit' => 120, 'window_seconds' => 60, 'ttl_days' => 7],
                'timeline' => is_array($document['timeline'] ?? null)
                    ? $document['timeline'] : ['max_entries' => 100],
                'runtime' => is_array($document['runtime'] ?? null)
                    ? $document['runtime'] : ['weather_command_timeout_seconds' => 20],
                'maintenance_scripts' => MaintenanceSchedule::normalize($document['maintenance_scripts'] ?? null),
                'overview_map' => is_array($document['overview_map'] ?? null)
                    ? $document['overview_map'] : ['lat' => 51.96, 'lng' => 8.53, 'zoom' => 11, 'height_px' => 220],
            ];
            foreach ($global as $key => $value) {
                $this->writeMeta($pdo, $key, $this->json($value));
            }
            $this->writeMeta(
                $pdo,
                'overview_hero',
                isset($document['overview_hero'])
                    ? (string) $document['overview_hero']
                    : 'Schnelle Kachelübersicht mit Filter & Sortierung – ähnlich foto-webcam.eu, aber leichtgewichtig.'
            );
            $this->writeMeta($pdo, 'version', (string) $version);
            $this->writeMeta($pdo, 'updated_at', (string) $document['updated_at']);

            $cameras = is_array($document['cameras'] ?? null) ? $document['cameras'] : [];
            $this->removeMissingCameras($pdo, array_keys($cameras));
            foreach ($cameras as $slug => $config) {
                if (is_array($config)) {
                    $this->upsertCamera($pdo, (string) $slug, $config);
                }
            }

            $pdo->commit();
            return $document;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function metaMap(PDO $pdo): array
    {
        $meta = [];
        foreach ($pdo->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['key'])) {
                $meta[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }
        return $meta;
    }

    private function writeMeta(PDO $pdo, string $key, string $value): void
    {
        $statement = $pdo->prepare('REPLACE INTO meta (key, value) VALUES (:key, :value)');
        $statement->execute([':key' => $key, ':value' => $value]);
    }

    /** ON DELETE CASCADE räumt die Untertabellen entfernter Kameras mit auf. */
    private function removeMissingCameras(PDO $pdo, array $wantedSlugs): void
    {
        $existing = $pdo->query('SELECT slug FROM cameras')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $delete = $pdo->prepare('DELETE FROM cameras WHERE slug = :slug');
        foreach (array_diff($existing, $wantedSlugs) as $slug) {
            $delete->execute([':slug' => $slug]);
        }
    }

    /** Schreibt die Hauptzeile und delegiert anschließend ihre Unterabschnitte. */
    private function upsertCamera(PDO $pdo, string $slug, array $config): void
    {
        $id = (string) ($config['id'] ?? $slug);
        $name = (string) ($config['name'] ?? $config['title'] ?? $slug);
        $statement = $pdo->prepare(
            'INSERT INTO cameras (id, slug, name, title, short_title, description, mode, info_html, tags, resolutions)
             VALUES (:id, :slug, :name, :title, :short, :description, :mode, :info, :tags, :res)
             ON CONFLICT(slug) DO UPDATE SET
               id = excluded.id, name = excluded.name, title = excluded.title,
               short_title = excluded.short_title, description = excluded.description,
               mode = excluded.mode, info_html = excluded.info_html,
               tags = excluded.tags, resolutions = excluded.resolutions'
        );
        $statement->execute([
            ':id' => $id,
            ':slug' => $slug,
            ':name' => $name,
            ':title' => (string) ($config['title'] ?? $name),
            ':short' => (string) ($config['short_title'] ?? $name),
            ':description' => (string) ($config['description'] ?? ''),
            ':mode' => (string) ($config['mode'] ?? 'pull-snapshot'),
            ':info' => (string) ($config['info_html'] ?? ''),
            ':tags' => $this->json(array_values(is_array($config['tags'] ?? null) ? $config['tags'] : [])),
            ':res' => $this->json(array_values(is_array($config['resolutions'] ?? null) ? $config['resolutions'] : [])),
        ]);

        $this->upsertLocation($pdo, $id, is_array($config['location'] ?? null) ? $config['location'] : []);
        $this->upsertCapture($pdo, $id, is_array($config['capture'] ?? null) ? $config['capture'] : []);
        $this->upsertStorage($pdo, $id, is_array($config['storage'] ?? null) ? $config['storage'] : []);
        $this->upsertWatchdog($pdo, $id, is_array($config['status']['watchdog'] ?? null) ? $config['status']['watchdog'] : []);
        $this->upsertStream($pdo, $id, is_array($config['stream'] ?? null) ? $config['stream'] : []);
        $this->upsertWeather($pdo, $id, is_array($config['weather_commands'] ?? null) ? $config['weather_commands'] : []);
        $this->upsertOverlay($pdo, $id, 'light', is_array($config['overlay_logo'] ?? null) ? $config['overlay_logo'] : null);
        $this->upsertOverlay($pdo, $id, 'dark', is_array($config['overlay_logo_dark'] ?? null) ? $config['overlay_logo_dark'] : null);
        if (array_key_exists('labels', $config)) {
            $this->upsertLabels($pdo, $id, $config['labels']);
        }
    }

    private function upsertLocation(PDO $pdo, string $cameraId, array $location): void
    {
        if ($location === []) {
            $this->deleteCameraRow($pdo, 'location', $cameraId);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO location (camera_id, latitude, longitude, elevation, orientation_deg, field_of_view_deg, map_center_offset, map_center_offset_x, orientation_visualization_km, map_height_px)
             VALUES (:id,:lat,:lng,:elev,:orient,:fov,:offset,:offset_x,:orient_km,:map_height)
             ON CONFLICT(camera_id) DO UPDATE SET
               latitude=excluded.latitude, longitude=excluded.longitude, elevation=excluded.elevation,
               orientation_deg=excluded.orientation_deg, field_of_view_deg=excluded.field_of_view_deg,
               map_center_offset=excluded.map_center_offset, map_center_offset_x=excluded.map_center_offset_x,
               orientation_visualization_km=excluded.orientation_visualization_km, map_height_px=excluded.map_height_px'
        );
        $statement->execute([
            ':id' => $cameraId,
            ':lat' => $location['latitude'] ?? null,
            ':lng' => $location['longitude'] ?? null,
            ':elev' => $location['elevation'] ?? null,
            ':orient' => $location['orientation_deg'] ?? null,
            ':fov' => $location['field_of_view_deg'] ?? null,
            ':offset' => $location['map_center_offset'] ?? 0,
            ':offset_x' => $location['map_center_offset_x'] ?? 0,
            ':orient_km' => $location['orientation_visualization_km'] ?? null,
            ':map_height' => $location['map_height_px'] ?? null,
        ]);
    }

    private function upsertCapture(PDO $pdo, string $cameraId, array $capture): void
    {
        if ($capture === []) {
            $this->deleteCameraRow($pdo, 'capture_rules', $cameraId);
            return;
        }
        $window = is_array($capture['window'] ?? null)
            ? $capture['window'] : (is_array($capture['capture_window'] ?? null) ? $capture['capture_window'] : []);
        $statement = $pdo->prepare(
            'INSERT INTO capture_rules (camera_id, interval_seconds, transport, endpoint, timeout_seconds, expected_response, extra_times, window_start, window_end, payload, script_path, upload_key, active_upload_fallback_failures)
             VALUES (:id,:interval,:transport,:endpoint,:timeout,:expected,:extra,:window_start,:window_end,:payload,NULL,:upload_key,:fallback)
             ON CONFLICT(camera_id) DO UPDATE SET
               interval_seconds=excluded.interval_seconds, transport=excluded.transport,
               endpoint=excluded.endpoint, timeout_seconds=excluded.timeout_seconds,
               expected_response=excluded.expected_response, extra_times=excluded.extra_times,
               window_start=excluded.window_start, window_end=excluded.window_end,
               payload=excluded.payload, script_path=excluded.script_path,
               upload_key=excluded.upload_key, active_upload_fallback_failures=excluded.active_upload_fallback_failures'
        );
        $statement->execute([
            ':id' => $cameraId,
            ':interval' => $capture['interval_seconds'] ?? 600,
            ':transport' => $capture['transport'] ?? 'http-get',
            ':endpoint' => $capture['endpoint'] ?? '',
            ':timeout' => $capture['timeout_seconds'] ?? 10,
            ':expected' => $capture['expected_response'] ?? 200,
            ':extra' => $this->json(array_values(is_array($capture['extra_times'] ?? null) ? $capture['extra_times'] : [])),
            ':window_start' => $window['start'] ?? null,
            ':window_end' => $window['end'] ?? null,
            ':payload' => $this->json(array_intersect_key($capture, array_flip(['helper_upload_mode', 'auto_iso', 'image_processing']))),
            ':upload_key' => $capture['upload_key'] ?? '',
            ':fallback' => $capture['active_upload_fallback_failures'] ?? 0,
        ]);
    }

    private function upsertStorage(PDO $pdo, string $cameraId, array $storage): void
    {
        if ($storage === []) {
            $this->deleteCameraRow($pdo, 'storage_cfg', $cameraId);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO storage_cfg (camera_id, base_path, current_dir, archive_dir, best_of_dir)
             VALUES (:id,:base,:current,:archive,:best)
             ON CONFLICT(camera_id) DO UPDATE SET base_path=excluded.base_path,
               current_dir=excluded.current_dir, archive_dir=excluded.archive_dir,
               best_of_dir=excluded.best_of_dir'
        );
        $statement->execute([
            ':id' => $cameraId,
            ':base' => $storage['base_path'] ?? 'storage/',
            ':current' => $storage['current_dir'] ?? 'Current',
            ':archive' => $storage['archive_dir'] ?? 'Archive',
            ':best' => $storage['best_of_dir'] ?? 'BestOf',
        ]);
    }

    private function upsertWatchdog(PDO $pdo, string $cameraId, array $watchdog): void
    {
        if ($watchdog === []) {
            $this->deleteCameraRow($pdo, 'watchdog', $cameraId);
            return;
        }
        $cooldown = is_array($watchdog['cooldown'] ?? null) ? $watchdog['cooldown'] : [];
        $statement = $pdo->prepare(
            'INSERT INTO watchdog (camera_id, enabled, max_consecutive_failures, cooldown_min_successes, cooldown_min_seconds, reset_sequence)
             VALUES (:id,:enabled,:max,:successes,:seconds,:sequence)
             ON CONFLICT(camera_id) DO UPDATE SET enabled=excluded.enabled,
               max_consecutive_failures=excluded.max_consecutive_failures,
               cooldown_min_successes=excluded.cooldown_min_successes,
               cooldown_min_seconds=excluded.cooldown_min_seconds, reset_sequence=excluded.reset_sequence'
        );
        $statement->execute([
            ':id' => $cameraId,
            ':enabled' => empty($watchdog['enabled']) ? 0 : 1,
            ':max' => $watchdog['max_consecutive_failures'] ?? 3,
            ':successes' => $cooldown['min_successes_after_reset'] ?? 0,
            ':seconds' => $cooldown['min_seconds_after_reset'] ?? 0,
            ':sequence' => isset($watchdog['reset_sequence']) ? $this->json($watchdog['reset_sequence']) : null,
        ]);
    }

    /** Stream-Spalten wurden schrittweise eingeführt und werden daher erkannt. */
    private function upsertStream(PDO $pdo, string $cameraId, array $stream): void
    {
        if ($stream === []) {
            $this->deleteCameraRow($pdo, 'stream', $cameraId);
            return;
        }
        $columns = ['camera_id', 'mode', 'playlist_url', 'snapshot_url'];
        $values = [':id', ':mode', ':playlist', ':snapshot'];
        $updates = ['mode=excluded.mode', 'playlist_url=excluded.playlist_url', 'snapshot_url=excluded.snapshot_url'];
        $params = [
            ':id' => $cameraId,
            ':mode' => $stream['mode'] ?? 'none',
            ':playlist' => $stream['playlist_url'] ?? null,
            ':snapshot' => $stream['snapshot_url'] ?? null,
        ];
        foreach (['fps' => [1, 30], 'quality' => [2, 31], 'max_clients' => [1, 20]] as $column => [$min, $max]) {
            if (\webcam_camera_store_column_exists($pdo, 'stream', $column)) {
                $value = isset($stream[$column]) ? (int) $stream[$column] : null;
                $columns[] = $column;
                $values[] = ':' . $column;
                $updates[] = $column . '=excluded.' . $column;
                $params[':' . $column] = $value !== null && $value >= $min && $value <= $max ? $value : null;
            } elseif (isset($stream[$column])) {
                \webcam_camera_store_log_missing_column('stream', $column);
            }
        }
        $statement = $pdo->prepare(
            'INSERT INTO stream (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')
             ON CONFLICT(camera_id) DO UPDATE SET ' . implode(', ', $updates)
        );
        $statement->execute($params);
    }

    private function upsertWeather(PDO $pdo, string $cameraId, array $commands): void
    {
        $commands = array_values(array_filter(
            $commands,
            static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
        ));
        if ($commands === []) {
            $this->deleteCameraRow($pdo, 'weather_commands', $cameraId);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO weather_commands (camera_id, commands) VALUES (:id,:commands)
             ON CONFLICT(camera_id) DO UPDATE SET commands=excluded.commands'
        );
        $statement->execute([':id' => $cameraId, ':commands' => $this->json($commands)]);
    }

    private function upsertOverlay(PDO $pdo, string $cameraId, string $variant, ?array $overlay): void
    {
        if ($overlay === null || empty($overlay['url'])) {
            $pdo->prepare('DELETE FROM overlay WHERE camera_id=:id AND variant=:variant')
                ->execute([':id' => $cameraId, ':variant' => $variant]);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO overlay (camera_id, variant, url, width, height, offset_top, offset_right, opacity)
             VALUES (:id,:variant,:url,:width,:height,:top,:right,:opacity)
             ON CONFLICT(camera_id, variant) DO UPDATE SET url=excluded.url,
               width=excluded.width, height=excluded.height, offset_top=excluded.offset_top,
               offset_right=excluded.offset_right, opacity=excluded.opacity'
        );
        $statement->execute([
            ':id' => $cameraId,
            ':variant' => $variant,
            ':url' => $overlay['url'],
            ':width' => $overlay['width'] ?? null,
            ':height' => $overlay['height'] ?? null,
            ':top' => $overlay['offset_top'] ?? 0,
            ':right' => $overlay['offset_right'] ?? 0,
            ':opacity' => $overlay['opacity'] ?? 1.0,
        ]);
    }

    /** Labels werden als geordnete Gesamtliste ersetzt, damit keine Altzeilen bleiben. */
    private function upsertLabels(PDO $pdo, string $cameraId, mixed $labels): void
    {
        if (!\webcam_camera_store_table_exists($pdo, 'labels')) {
            \webcam_camera_store_log_missing_table('labels');
            return;
        }
        $pdo->prepare('DELETE FROM labels WHERE camera_id=:id')->execute([':id' => $cameraId]);
        if (!is_array($labels) || $labels === []) {
            return;
        }

        $hasColor = \webcam_camera_store_column_exists($pdo, 'labels', 'color');
        $hasTextTone = \webcam_camera_store_column_exists($pdo, 'labels', 'text_tone');
        foreach ([['color', $hasColor], ['text_tone', $hasTextTone]] as [$column, $available]) {
            if (!$available && array_filter($labels, static fn($label): bool =>
                is_array($label) && trim((string) ($label[$column] ?? '')) !== '')) {
                \webcam_camera_store_log_missing_column('labels', $column);
            }
        }

        $extraColumns = ($hasColor ? ', color' : '') . ($hasTextTone ? ', text_tone' : '');
        $extraValues = ($hasColor ? ', :color' : '') . ($hasTextTone ? ', :text_tone' : '');
        $baseColumns = 'camera_id, x, y, text, href, type, resolution' . $extraColumns . ', valid_from, valid_to, created_at, updated_at';
        $baseValues = ':camera_id,:x,:y,:text,:href,:type,:resolution' . $extraValues
            . ',:valid_from,:valid_to,strftime(\'%s\',\'now\'),strftime(\'%s\',\'now\')';
        $withId = $pdo->prepare('INSERT INTO labels (id, ' . $baseColumns . ') VALUES (:id,' . $baseValues . ')');
        $withoutId = $pdo->prepare('INSERT INTO labels (' . $baseColumns . ') VALUES (' . $baseValues . ')');

        foreach ($labels as $label) {
            if (!is_array($label) || !is_numeric($label['x'] ?? null) || !is_numeric($label['y'] ?? null)) {
                continue;
            }
            $text = trim((string) ($label['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $params = [
                ':camera_id' => $cameraId,
                ':x' => (float) $label['x'],
                ':y' => (float) $label['y'],
                ':text' => $text,
                ':href' => isset($label['href']) ? (string) $label['href'] : null,
                ':type' => is_numeric($label['type'] ?? null) ? (int) $label['type'] : 0,
                ':resolution' => is_numeric($label['resolution'] ?? null) ? (int) $label['resolution'] : 0,
                ':valid_from' => isset($label['valid_from']) ? (string) $label['valid_from'] : null,
                ':valid_to' => isset($label['valid_to']) ? (string) $label['valid_to'] : null,
            ];
            if ($hasColor) {
                $params[':color'] = (string) ($label['color'] ?? '');
            }
            if ($hasTextTone) {
                $params[':text_tone'] = (string) ($label['text_tone'] ?? 'auto');
            }
            if (isset($label['id']) && is_numeric($label['id'])) {
                $params[':id'] = (int) $label['id'];
                $withId->execute($params);
            } else {
                $withoutId->execute($params);
            }
        }
    }

    /** Löscht die einzelne Unterzeile einer Kamera ohne dynamische Nutzdaten. */
    private function deleteCameraRow(PDO $pdo, string $table, string $cameraId): void
    {
        // Die Tabellennamen stammen ausschließlich aus festen Aufrufen dieser Klasse.
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE camera_id=:id')->execute([':id' => $cameraId]);
    }

    /** JSON-Fehler werden nicht als leere Strings in der Konfiguration versteckt. */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
