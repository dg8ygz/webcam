#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Historische Einmalmigration von config/cameras.json in die Repo-Sample-SQLite.
 * Dieses Skript ist kein Live-Config-Editor und darf nicht auf die produktive
 * /var/lib/webcam/webcams.sqlite umgebogen werden.
 */

$root = dirname(__DIR__);
$jsonPath = $root . '/config/cameras.json';
$sqlitePath = $root . '/database/webcams.sqlite';
$schemaPath = $root . '/database/schema.config.sql';

if (!is_file($jsonPath)) {
  fwrite(STDERR, "Missing JSON config: {$jsonPath}\n");
  exit(1);
}
if (!is_file($schemaPath)) {
  fwrite(STDERR, "Missing schema file: {$schemaPath}\n");
  exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

$cameras = $data['cameras'] ?? [];
$defaults = $data['defaults'] ?? [];
$archiveCache = $data['archive_cache'] ?? null;
$sourceVersion = isset($data['version']) ? (int) $data['version'] : 0;
$sourceUpdatedAt = isset($data['updated_at']) ? (string) $data['updated_at'] : gmdate('Y-m-d\TH:i:s\Z');

$db = new PDO('sqlite:' . $sqlitePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents($schemaPath));
$db->exec('BEGIN');

$stmtCamera = $db->prepare('INSERT OR REPLACE INTO cameras (id, slug, name, title, short_title, description, mode, info_html, tags, resolutions, created_at, updated_at) VALUES (:id,:slug,:name,:title,:short_title,:description,:mode,:info_html,:tags,:resolutions,strftime("%s","now"),strftime("%s","now"))');
$stmtLocation = $db->prepare('INSERT OR REPLACE INTO location (camera_id, latitude, longitude, elevation, orientation_deg, field_of_view_deg, map_center_offset, map_center_offset_x) VALUES (:cid,:lat,:lng,:elev,:orient,:fov,:offset,:offset_x)');
$stmtCapture = $db->prepare('INSERT OR REPLACE INTO capture_rules (camera_id, interval_seconds, transport, endpoint, timeout_seconds, expected_response, extra_times, window_start, window_end, payload, script_path) VALUES (:cid,:interval,:transport,:endpoint,:timeout,:expected,:extra,:wstart,:wend,:payload,:script)');
$stmtStorage = $db->prepare('INSERT OR REPLACE INTO storage_cfg (camera_id, base_path, current_dir, archive_dir, best_of_dir) VALUES (:cid,:base,:current,:archive,:bestof)');
$stmtWatchdog = $db->prepare('INSERT OR REPLACE INTO watchdog (camera_id, enabled, max_consecutive_failures, cooldown_min_successes, cooldown_min_seconds, reset_sequence) VALUES (:cid,:enabled,:maxfail,:cool_s,:cool_t,:sequence)');
$stmtStream = $db->prepare('INSERT OR REPLACE INTO stream (camera_id, mode, playlist_url, snapshot_url) VALUES (:cid,:mode,:playlist,:snapshot)');
$stmtOverlay = $db->prepare('INSERT OR REPLACE INTO overlay (camera_id, variant, url, width, height, offset_top, offset_right, opacity) VALUES (:cid,:variant,:url,:w,:h,:top,:right,:opacity)');
$stmtWeather = $db->prepare('INSERT OR REPLACE INTO weather_commands (camera_id, commands) VALUES (:cid,:commands)');
$stmtMeta = $db->prepare('INSERT OR REPLACE INTO meta (key,value) VALUES (:k,:v)');

foreach ($cameras as $slug => $c) {
  $cid = $c['id'] ?? $slug;
  $tags = isset($c['tags']) && is_array($c['tags']) ? json_encode(array_values($c['tags'])) : json_encode([]);
  $resolutions = isset($c['resolutions']) && is_array($c['resolutions']) ? json_encode(array_values($c['resolutions'])) : json_encode([]);
  $stmtCamera->execute([
    ':id' => $cid,
    ':slug' => $c['slug'] ?? $slug,
    ':name' => $c['name'] ?? null,
    ':title' => $c['title'] ?? null,
    ':short_title' => $c['short_title'] ?? ($c['name'] ?? null),
    ':description' => $c['description'] ?? null,
    ':mode' => $c['mode'] ?? null,
    ':info_html' => $c['info_html'] ?? null,
    ':tags' => $tags,
    ':resolutions' => $resolutions,
  ]);

  $loc = $c['location'] ?? [];
  $stmtLocation->execute([
    ':cid' => $cid,
    ':lat' => $loc['latitude'] ?? null,
    ':lng' => $loc['longitude'] ?? null,
    ':elev' => $loc['elevation'] ?? null,
    ':orient' => $loc['orientation_deg'] ?? null,
    ':fov' => $loc['field_of_view_deg'] ?? null,
    ':offset' => $loc['map_center_offset'] ?? null,
    ':offset_x' => $loc['map_center_offset_x'] ?? null,
  ]);

  $cap = $c['capture'] ?? [];
  $stmtCapture->execute([
    ':cid' => $cid,
    ':interval' => $cap['interval_seconds'] ?? null,
    ':transport' => $cap['transport'] ?? 'http-get',
    ':endpoint' => $cap['endpoint'] ?? null,
    ':timeout' => $cap['timeout_seconds'] ?? null,
    ':expected' => $cap['expected_response'] ?? null,
    ':extra' => isset($cap['extra_times']) ? json_encode($cap['extra_times']) : null,
    ':wstart' => $cap['window_start'] ?? null,
    ':wend' => $cap['window_end'] ?? null,
    ':payload' => null,
    ':script' => null,
  ]);

  $stor = $c['storage'] ?? [];
  $stmtStorage->execute([
    ':cid' => $cid,
    ':base' => $stor['base_path'] ?? null,
    ':current' => $stor['current_dir'] ?? null,
    ':archive' => $stor['archive_dir'] ?? null,
    ':bestof' => $stor['best_of_dir'] ?? null,
  ]);

  $wd = $c['status']['watchdog'] ?? [];
  $cool = $wd['cooldown'] ?? [];
  $stmtWatchdog->execute([
    ':cid' => $cid,
    ':enabled' => empty($wd) ? null : (int) (!empty($wd['enabled'])),
    ':maxfail' => $wd['max_consecutive_failures'] ?? null,
    ':cool_s' => $cool['min_successes_after_reset'] ?? null,
    ':cool_t' => $cool['min_seconds_after_reset'] ?? null,
    ':sequence' => isset($wd['reset_sequence']) ? json_encode($wd['reset_sequence']) : null,
  ]);

  $stream = $c['stream'] ?? [];
  $stmtStream->execute([
    ':cid' => $cid,
    ':mode' => $stream['mode'] ?? null,
    ':playlist' => $stream['playlist_url'] ?? null,
    ':snapshot' => $stream['snapshot_url'] ?? null,
  ]);

  foreach (['light' => $c['overlay_logo'] ?? [], 'dark' => $c['overlay_logo_dark'] ?? []] as $variant => $ov) {
    if (!$ov) {
      continue;
    }
    $stmtOverlay->execute([
      ':cid' => $cid,
      ':variant' => $variant,
      ':url' => $ov['url'] ?? null,
      ':w' => $ov['width'] ?? null,
      ':h' => $ov['height'] ?? null,
      ':top' => $ov['offset_top'] ?? null,
      ':right' => $ov['offset_right'] ?? null,
      ':opacity' => $ov['opacity'] ?? null,
    ]);
  }

  $wc = $c['weather_commands'] ?? [];
  $stmtWeather->execute([
    ':cid' => $cid,
    ':commands' => $wc ? json_encode(array_values($wc)) : json_encode([]),
  ]);
}

if ($defaults) {
  $stmtMeta->execute([':k' => 'defaults', ':v' => json_encode($defaults)]);
}
if ($archiveCache) {
  $stmtMeta->execute([':k' => 'archive_cache', ':v' => json_encode($archiveCache)]);
}
$stmtMeta->execute([':k' => 'version', ':v' => (string) $sourceVersion]);
$stmtMeta->execute([':k' => 'updated_at', ':v' => $sourceUpdatedAt]);
$stmtMeta->execute([':k' => 'migrated_from_json_at', ':v' => date(DATE_ATOM)]);

$db->exec('COMMIT');

echo "Migration completed to {$sqlitePath}\n";
