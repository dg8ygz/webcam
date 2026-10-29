<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Config;

use RuntimeException;
use Webcam\App;

require_once __DIR__ . '/../../..' . '/config/camera_store.php';

/**
 * Kleine objektorientierte Schnittstelle zur zentralen Config-SQLite.
 *
 * Die eigentliche SQLite-Absicherung steckt aus historischen Gründen in
 * config/camera_store.php. Nach einem erfolgreichen Schreiben legt diese Klasse
 * zusätzlich config.reload ab, damit andere Prozesse die neue Version erkennen.
 */
final class ConfigStore
{
  public function __construct(private readonly App $app)
  {
    $this->ensureConfigPath();
  }

  /**
   * Load the full configuration document from SQLite as associative array.
   *
   * @return array<string,mixed>
   */
  public function load(): array
  {
    return \webcam_camera_store_load();
  }

  /**
   * @param array<string,mixed> $document
   * @return array<string,mixed>
   */
  public function save(array $document, ?string $actor = null, string $action = 'update', bool $bumpVersion = true): array
  {
    $saved = \webcam_camera_store_save($document, $bumpVersion);
    $this->writeReloadFlag($saved, $actor, $action);
    return $saved;
  }

  /**
   * @param array<string,mixed> $document
   */
  private function writeReloadFlag(array $document, ?string $actor, string $action): void
  {
    $payload = [
      'version' => $document['version'] ?? null,
      'updated_at' => $document['updated_at'] ?? null,
      'actor' => $actor,
      'action' => $action,
    ];
    $path = $this->app->paths()->statePath('config.reload');
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      $this->app->logger()->warning('config reload dir unavailable', ['dir' => $dir]);
      return;
    }
    if (!\webcam_atomic_write_json($path, $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
      $this->app->logger()->warning('config reload write failed', ['path' => $path]);
    }
  }

  private function ensureConfigPath(): void
  {
    $path = $this->app->config('camera_config_db');
    if (!is_string($path) || $path === '') {
      throw new RuntimeException('camera_config_db is not configured');
    }
    if (!is_file($path)) {
      throw new RuntimeException("SQLite config database not found: {$path}");
    }
  }
}
