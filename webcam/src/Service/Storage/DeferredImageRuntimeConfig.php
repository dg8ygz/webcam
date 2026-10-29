<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use RuntimeException;

/**
 * Webrequests und CLI-Jobs lesen dieselbe lokale Betreiberentscheidung.
 * Projekt- UND Datenbankpfade binden sie an genau eine Installation;
 * eine Testkopie oder ein PROD-Rollout aktiviert damit nicht denselben Piloten.
 */
final class DeferredImageRuntimeConfig
{
  public static function load(string $project, string $configDb, string $cameraDbRoot): array
  {
    $file = '/etc/webcam-deferred/' . basename($project) . '-runtime.json';
    if (!file_exists($file) && !is_link($file)) return [];
    DeferredStorageGuard::assertRootOwnedFile($file);
    $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid deferred runtime configuration');
    return self::forInstallation($data, $project, $configDb, $cameraDbRoot);
  }

  public static function forInstallation(array $data, string $project, string $configDb, string $cameraDbRoot): array
  {
    if (($data['version'] ?? null) !== 1) throw new RuntimeException('Invalid deferred runtime version');
    foreach (['project_root' => $project, 'config_db' => $configDb, 'camera_db_root' => $cameraDbRoot] as $key => $path) {
      if (($data[$key] ?? null) !== $path) return [];
    }
    $cameras = $data['cameras'] ?? null;
    if ($cameras !== [] && $cameras !== ['bielefeld']) throw new RuntimeException('Only the Bielefeld pilot is supported');
    $result = ['deferred_image_cameras' => $cameras];
    foreach (['root', 'backup_root', 'guard'] as $key) {
      $value = $data[$key] ?? null;
      if (!is_string($value) || !str_starts_with($value, '/') || str_contains($value, "\0")) {
        throw new RuntimeException('Invalid deferred runtime path');
      }
      $result['deferred_image_' . $key] = $value;
    }
    return $result;
  }
}
