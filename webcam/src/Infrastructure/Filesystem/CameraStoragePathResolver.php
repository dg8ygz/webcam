<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Filesystem;

use RuntimeException;
use Webcam\Domain\Camera;

/**
 * Löst die historisch unterschiedlich gespeicherten Kamera-Basispfade auf.
 *
 * Leseführung
 * -----------
 * 1. `configuredPath()` normalisiert den rohen Konfigurationswert und macht
 *    relative Werte unabhängig von Apache-/CLI-Arbeitsverzeichnissen.
 * 2. `baseRoot()` entfernt einen historisch angehängten Kameraslug. Unter
 *    diesem Root liegen anschließend Current, Archive und BestOf.
 * 3. `resolveStorageRelative()` prüft einen gespeicherten relativen Pfad und
 *    behandelt den außerhalb von `storage/` liegenden Cache ausdrücklich.
 * 4. `join()` ist die einzige betriebssystemneutrale Pfadverkettung für die
 *    wenigen Legacy-Abläufe, die noch Unterverzeichnisse zusammensetzen.
 *
 * `storage.base_path` existiert historisch sowohl als gemeinsamer Root als
 * auch als Root inklusive Kameraslug. Diese Klasse erhält beide Formate, legt
 * aber eine verbindliche Regel für relative Angaben fest: Sie beziehen sich
 * immer auf die Projektwurzel, also den Parent von `storage_path`.
 */
final class CameraStoragePathResolver
{
  public function __construct(private readonly PathResolver $paths) {}

  /** Liefert den normalisierten Konfigurationspfad vor der Slug-Korrektur. */
  public function configuredPath(Camera $camera): string
  {
    $storage = $camera->storage();
    $fallback = $this->normalize($this->paths->storagePath($camera->slug()));
    $configured = $storage['base_path'] ?? $fallback;
    $path = $this->normalize(is_string($configured) ? $configured : '');
    if ($path === '') {
      $path = $fallback;
    }

    if ($this->isAbsolute($path)) {
      return $path;
    }

    return $this->join($this->projectRoot(), $path);
  }

  /**
   * Liefert den gemeinsamen Root, unter dem die konfigurierten Storage-Bäume
   * und deren Kameraslug angelegt werden.
   */
  public function baseRoot(Camera $camera): string
  {
    $basePath = $this->configuredPath($camera);
    $slug = $camera->slug();
    $lastSlash = strrpos($basePath, '/');

    if ($lastSlash !== false) {
      $lastSegment = substr($basePath, $lastSlash + 1);
      if (strcasecmp($lastSegment, $slug) === 0) {
        $trimmed = rtrim((string) substr($basePath, 0, $lastSlash), '/');
        return $trimmed !== '' ? $trimmed : '/';
      }
    } elseif (strcasecmp($basePath, $slug) === 0) {
      // Dieser Zweig ist bei `configuredPath()` praktisch nur für exotische
      // Windows-Laufwerksangaben relevant, bleibt aber zur Legacy-Kompatibilität.
      return $this->projectRoot();
    }

    return $basePath !== '' ? $basePath : $this->projectRoot();
  }

  /**
   * Wandelt einen DB-/Sidecar-Pfad in einen absoluten Dateisystempfad um.
   * Traversal wird vor jeder Verkettung fail-closed abgelehnt.
   */
  public function resolveStorageRelative(Camera $camera, string $relativePath): ?string
  {
    $normalized = preg_replace('#/+#', '/', str_replace('\\', '/', $relativePath));
    $normalized = ltrim((string) $normalized, '/');
    if ($normalized === '' || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1) {
      return null;
    }

    // `cache/` ist bewusst ein Geschwisterverzeichnis von `storage/` und darf
    // deshalb nicht unter den Kamera-Storage-Root gehängt werden.
    if (str_starts_with($normalized, 'cache/')) {
      try {
        return $this->paths->cachePath(substr($normalized, strlen('cache/')));
      } catch (RuntimeException) {
        // Alte Minimalinstallationen hatten keinen eigenen Cache-Root. Für sie
        // bleibt der bisherige kontrollierte Storage-Fallback erhalten.
      }
    }

    return $this->join($this->baseRoot($camera), $normalized);
  }

  /** Verknüpft bereits geprüfte Root-/Suffixwerte ohne doppelte Separatoren. */
  public function join(string $root, string $suffix): string
  {
    $normalizedRoot = $this->normalize($root);
    $normalizedSuffix = ltrim(str_replace('\\', '/', $suffix), '/');
    if ($normalizedSuffix === '') {
      return $normalizedRoot;
    }
    if ($normalizedRoot === '' || $normalizedRoot === '.') {
      return ($normalizedRoot === '.' ? './' : '') . $normalizedSuffix;
    }
    if ($normalizedRoot === '/') {
      return '/' . $normalizedSuffix;
    }
    return rtrim($normalizedRoot, '/') . '/' . $normalizedSuffix;
  }

  private function projectRoot(): string
  {
    $root = $this->normalize(dirname($this->paths->storagePath()));
    return $root !== '' ? $root : '/';
  }

  private function normalize(string $path): string
  {
    $normalized = str_replace('\\', '/', trim($path));
    if ($normalized === '/') {
      return '/';
    }
    return rtrim($normalized, '/');
  }

  private function isAbsolute(string $path): bool
  {
    if ($path === '') {
      return false;
    }
    return $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
  }
}
