<?php
declare(strict_types=1);

// Kompatibilitätsbrücke für CameraRepository: lädt das normalisierte Dokument
// aus der Config-SQLite, legt globale Meta-/Defaultwerte ab und gibt nur die
// Kamera-Liste zurück. Die Datei enthält selbst keine echte Kamerakonfiguration.

require_once __DIR__ . '/camera_store.php';

return (static function (): array {
  $document = webcam_camera_store_load();
  $GLOBALS['webcam_cameras_meta'] = [
    'version' => (int) ($document['version'] ?? 0),
    'defaults' => $document['defaults'] ?? [],
    'archive_cache' => $document['archive_cache'] ?? [
      'enabled' => false,
      'last_built_at' => null,
    ],
    'public_rate_limit' => $document['public_rate_limit'] ?? [
      'limit' => 120,
      'window_seconds' => 60,
      'ttl_days' => 7,
    ],
    'timeline' => $document['timeline'] ?? [
      'max_entries' => 100,
    ],
    'runtime' => $document['runtime'] ?? [
      'weather_command_timeout_seconds' => 20,
    ],
    'maintenance_scripts' => $document['maintenance_scripts']
      ?? \Webcam\Service\Maintenance\MaintenanceSchedule::defaults(),
    'overview_hero' => $document['overview_hero'] ?? 'Schnelle Kachelübersicht mit Filter & Sortierung – ähnlich foto-webcam.eu, aber leichtgewichtig.',
    'overview_map' => $document['overview_map'] ?? [
      'lat' => 51.96,
      'lng' => 8.53,
      'zoom' => 11,
      'height_px' => 220,
    ],
  ];
  return $document['cameras'];
})();
