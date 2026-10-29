<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Vereinheitlicht die historischen Archivpfade einer Kamera.
 *
 * Manche ältere Konfigurationen nennen den gemeinsamen Speicherroot, andere
 * bereits einen Pfad mit angehängtem Kamera-Slug. Diese Klasse normalisiert
 * beide Formen, damit Browser und Legacy-Cache dieselbe Pfadregel verwenden.
 */
final class ArchiveStoragePaths
{
  public function __construct(private readonly App $app)
  {
  }

  /**
   * Liefert den gemeinsamen Root oberhalb von current/archive und Kamera-Slug.
   *
   * Beispiel:
   * - Konfiguration /data/webcam ergibt /data/webcam.
   * - Altformat /data/webcam/testcam ergibt ebenfalls /data/webcam.
   *
   * Dadurch können Aufrufer anschließend einheitlich
   * archive/<slug> beziehungsweise current/<slug> anhängen.
   */
  public function baseRoot(Camera $camera): string
  {
    $storage = $camera->storage();
    $basePath = $storage['base_path'] ?? $this->app->paths()->storagePath($camera->slug());
    $basePath = rtrim(str_replace('\\', '/', (string) $basePath), '/');

    // Relative Alt-Konfigurationen beziehen sich auf den Elternordner des
    // allgemeinen Storage-Pfads. Das entspricht dem bisherigen Verhalten des
    // ArchiveBrowser und verhindert einen stillen Wechsel des Speicherorts.
    if ($basePath !== '' && !$this->isAbsolute($basePath)) {
      $root = rtrim(str_replace('\\', '/', dirname($this->app->paths()->storagePath())), '/');
      $basePath = $this->join($root !== '' ? $root : '/', $basePath);
    }

    // Eine leere Kamera-Konfiguration fällt sichtbar auf den zentralen
    // PathResolver zurück; es wird kein eigener neuer Default erfunden.
    if ($basePath === '') {
      $basePath = rtrim(str_replace('\\', '/', $this->app->paths()->storagePath($camera->slug())), '/');
    }

    // Einige historische Kameraeinträge enthielten den Slug bereits am Ende.
    // Entfernen wir ihn nicht, entstünde später versehentlich
    // archive/<slug>/<slug>.
    $slug = $camera->slug();
    $lastSlash = strrpos($basePath, '/');
    if ($lastSlash !== false && strcasecmp(substr($basePath, $lastSlash + 1), $slug) === 0) {
      $trimmed = substr($basePath, 0, $lastSlash);
      $basePath = ($trimmed === '' || $trimmed === false) ? '/' : rtrim($trimmed, '/');
      if ($basePath === '') {
        $basePath = '/';
      }
    } elseif (strcasecmp($basePath, $slug) === 0) {
      $basePath = '.';
    }

    return $basePath !== '' ? $basePath : '.';
  }

  /**
   * Verbindet normalisierte Pfadteile ohne doppelte oder fehlende Slashes.
   *
   * Die Sonderfälle Punkt und Root bleiben erhalten, weil sie für relative
   * Testkonfigurationen beziehungsweise absolute Unix-Pfade unterschiedliche
   * Bedeutung haben.
   */
  public function join(string $root, string $suffix): string
  {
    $normalizedSuffix = ltrim(str_replace('\\', '/', $suffix), '/');
    if ($normalizedSuffix === '') {
      return $root;
    }
    if ($root === '' || $root === '.') {
      return ($root === '.' ? './' : '') . $normalizedSuffix;
    }
    if ($root === '/') {
      return '/' . $normalizedSuffix;
    }
    return rtrim($root, '/') . '/' . $normalizedSuffix;
  }

  private function isAbsolute(string $path): bool
  {
    if ($path === '') {
      return false;
    }
    if (str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
      return true;
    }
    // Neben Unix- und UNC-Pfaden bleiben Windows-Laufwerke lesbar. Das ist für
    // lokale Tests nützlich, auch wenn der Produktionshost Linux verwendet.
    return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
  }
}
