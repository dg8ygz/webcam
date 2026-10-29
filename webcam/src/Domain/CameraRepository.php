<?php
declare(strict_types=1);

namespace Webcam\Domain;

use RuntimeException;

/**
 * Geladene Kamera-Konfiguration, geordnet nach dem eindeutigen Slug.
 *
 * config/cameras.php liefert die aus der Config-DB gelesenen Rohdaten. Das
 * Repository wandelt jeden Eintrag einmal in ein typisiertes Camera-Objekt um
 * und trennt interne Vollstaendigkeit von oeffentlicher Auffindbarkeit.
 */
final class CameraRepository
{
  /**
   * @var array<string, Camera>
   */
  private array $cameras = [];
  /**
   * @var array<string,mixed>
   */
  private array $meta = [];

  public function __construct(private readonly string $configPath)
  {
    if (!is_file($this->configPath)) {
      throw new RuntimeException("Camera config not found: {$this->configPath}");
    }

    $raw = require $this->configPath;
    if (!is_array($raw)) {
      throw new RuntimeException('Camera config file must return an array');
    }

    foreach ($raw as $config) {
      if (!is_array($config)) {
        continue;
      }
      $camera = new Camera($config);
      $this->cameras[$camera->slug()] = $camera;
    }

    $meta = $GLOBALS['webcam_cameras_meta'] ?? null;
    if (is_array($meta)) {
      $this->meta = $meta;
    }
  }

  /**
   * @return list<Camera>
   */
  public function all(): array
  {
    return array_values($this->cameras);
  }

  /**
   * Oeffentliche Sammlungen verwenden nur diese Liste. all()/find() bleiben
   * fuer Admin, Capture, Upload, Wartung und direkte Kameraansichten komplett.
   * @return list<Camera>
   */
  public function listed(): array
  {
    return array_values(array_filter($this->cameras, static fn(Camera $camera): bool => !$camera->isTest()));
  }

  /** Einzelzugriff fuer oeffentliche Sammlungen; direkte Kamerarouten nutzen find(). */
  public function findListed(string $slug): ?Camera
  {
    $camera = $this->find($slug);
    return $camera !== null && !$camera->isTest() ? $camera : null;
  }

  public function find(string $slug): ?Camera
  {
    return $this->cameras[$slug] ?? null;
  }

  /**
   * @return array<string,mixed>
   */
  public function meta(): array
  {
    return $this->meta;
  }
}
