<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Filesystem;

use RuntimeException;

/**
 * Baut absolute Dateisystempfade aus zentral konfigurierten Basisverzeichnissen.
 *
 * Fachcode soll dadurch nicht wissen müssen, wo Cache, Archiv, Status oder Logs
 * auf einem bestimmten Host liegen. Fehlt ein benötigter Basispfad, wird ein
 * sichtbarer Fehler ausgelöst statt unbemerkt in ein anderes Verzeichnis zu
 * schreiben.
 */
final class PathResolver
{
  public function __construct(
    private readonly ?string $storagePath,
    private readonly ?string $archivePath,
    private readonly ?string $statePath,
    private readonly ?string $logPath,
    private readonly ?string $infoPath = null,
    private readonly ?string $cachePath = null,
    private readonly ?string $cameraDbPath = null,
  ) {}

  public function ensureBaseDirectories(): void
  {
    foreach ([$this->storagePath, $this->archivePath, $this->statePath, $this->logPath, $this->infoPath, $this->cachePath, $this->cameraDbPath] as $path) {
      if (!$path) {
        continue;
      }
      if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Failed to create directory: {$path}");
      }
    }
  }

  public function storagePath(?string $suffix = null): string
  {
    return $this->build($this->storagePath, $suffix);
  }

  public function archivePath(?string $suffix = null): string
  {
    return $this->build($this->archivePath, $suffix);
  }

  public function statePath(?string $suffix = null): string
  {
    return $this->build($this->statePath, $suffix);
  }

  public function logPath(?string $suffix = null): string
  {
    return $this->build($this->logPath, $suffix);
  }

  public function infoPath(?string $suffix = null): string
  {
    return $this->build($this->infoPath, $suffix);
  }

  public function cachePath(?string $suffix = null): string
  {
    return $this->build($this->cachePath, $suffix);
  }

  public function cameraDbPath(?string $suffix = null): string
  {
    return $this->build($this->cameraDbPath ?? $this->cachePath, $suffix);
  }

  private function build(?string $base, ?string $suffix): string
  {
    if (!$base) {
      throw new RuntimeException('Base path is not configured');
    }
    if ($suffix === null || $suffix === '') {
      return $base;
    }
    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR);
  }
}
