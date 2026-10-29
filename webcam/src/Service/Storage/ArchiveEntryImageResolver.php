<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

/**
 * Wählt aus einem Timeline-Eintrag eine tatsächlich auslieferbare Bildvariante.
 *
 * ArchiveBrowser bestimmt nur noch die fachliche Reihenfolge der gewünschten
 * Größen. Diese Klasse prüft die Varianten und löst lokale URLs innerhalb des
 * Projektverzeichnisses auf. Externe URLs bleiben wie bisher zulässig und
 * werden nicht als lokale Dateien interpretiert.
 */
final class ArchiveEntryImageResolver
{
  public function __construct(private readonly string $projectRoot, private readonly ?\Closure $virtualAvailable = null)
  {
  }

  /**
   * @param array<string,mixed> $entry
   * @param list<string> $preferredOrder
   * @return array{url:string,relative_path:string,variant:string}|null
   */
  public function resolve(array $entry, array $preferredOrder): ?array
  {
    if (empty($entry['variants']) || !is_array($entry['variants'])) {
      return null;
    }

    foreach ($preferredOrder as $candidate) {
      if (!isset($entry['variants'][$candidate]) || empty($entry['variants'][$candidate]['url'])) {
        continue;
      }
      $resolved = $this->resolveVariant((string) $candidate, $entry['variants'][$candidate]);
      if ($resolved !== null) {
        return $resolved;
      }
    }

    // Unbekannte, aber gültige Legacy-Varianten bleiben als letzter Fallback
    // erreichbar. Die konfigurierte Reihenfolge gewinnt immer zuerst.
    foreach ($entry['variants'] as $variant => $info) {
      if (!is_array($info) || empty($info['url'])) {
        continue;
      }
      $resolved = $this->resolveVariant((string) $variant, $info);
      if ($resolved !== null) {
        return $resolved;
      }
    }
    return null;
  }

  /**
   * @param array<string,mixed> $info
   * @return array{url:string,relative_path:string,variant:string}|null
   */
  private function resolveVariant(string $variant, array $info): ?array
  {
    $url = (string) ($info['url'] ?? '');
    $relativePath = (string) ($info['relative_path'] ?? '');
    if ($url === '' || !$this->isResolvableFile($url, $relativePath)) {
      return null;
    }
    return [
      'url' => $url,
      'relative_path' => $relativePath,
      'variant' => $variant,
    ];
  }

  private function isResolvableFile(string $url, string $relativePath): bool
  {
    if (DeferredImages::isPath($relativePath)) {
      return $this->virtualAvailable !== null && ($this->virtualAvailable)($relativePath);
    }
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url)) {
      return true;
    }
    if ($this->projectRoot === '') {
      return true;
    }

    $normalize = static function (string $path): string {
      $path = preg_replace('#[?\\#].*$#', '', $path) ?? $path;
      return ltrim($path, '/');
    };

    $candidates = [];
    if ($relativePath !== '') {
      $candidates[] = $normalize($relativePath);
    }
    $candidates[] = $normalize($url);

    // Manche ältere Datensätze enthalten zusätzlich den Deployment-Ordner
    // „webcam/“. Beide Schreibweisen werden gegen denselben Projektroot geprüft.
    $uniqueCandidates = [];
    foreach ($candidates as $candidate) {
      if ($candidate === '') {
        continue;
      }
      $uniqueCandidates[$candidate] = true;
      if (str_starts_with($candidate, 'webcam/')) {
        $uniqueCandidates[substr($candidate, strlen('webcam/'))] = true;
      }
    }

    foreach (array_keys($uniqueCandidates) as $candidate) {
      $file = rtrim($this->projectRoot, '/') . '/' . $candidate;
      if (is_file($file) && is_readable($file)) {
        return true;
      }
    }
    return false;
  }
}
