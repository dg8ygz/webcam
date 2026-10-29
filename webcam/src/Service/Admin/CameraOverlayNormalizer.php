<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use InvalidArgumentException;

/**
 * Begrenzt Logo-Overlaydaten auf kontrollierte URL- und Darstellungswerte.
 *
 * Lokale Dateien werden auf overlay.php abgebildet. Absolute HTTP(S)-URLs
 * bleiben aus Kompatibilitätsgründen möglich; Traversal und Backslashes werden
 * vor jeder weiteren Auswertung abgewiesen.
 */
final class CameraOverlayNormalizer
{
  /** @return array<string,mixed>|null */
  public function normalize(mixed $config, string $slug): ?array
  {
    if ($config === null || $config === []) {
      return null;
    }
    if (!is_array($config)) {
      throw new InvalidArgumentException('overlay config must be an object or null');
    }
    $url = isset($config['url']) ? trim((string) $config['url']) : '';
    if ($url === '') {
      return null;
    }
    if (str_contains($url, '..') || str_contains($url, '\\')) {
      throw new InvalidArgumentException('overlay url must not contain path traversal');
    }

    $width = isset($config['width']) ? (int) $config['width'] : null;
    $height = isset($config['height']) ? (int) $config['height'] : null;
    return [
      'url' => $this->normalizeUrl($url, $slug),
      'width' => $width && $width > 0 ? $width : null,
      'height' => $height && $height > 0 ? $height : null,
      'offset_top' => isset($config['offset_top']) ? (int) $config['offset_top'] : 0,
      'offset_right' => isset($config['offset_right']) ? (int) $config['offset_right'] : 0,
      'opacity' => isset($config['opacity']) ? (float) $config['opacity'] : 1.0,
    ];
  }

  /** Erlaubt nur die bisherigen dokumentrelativen Overlay-URL-Formen. */
  private function normalizeUrl(string $url, string $slug): string
  {
    $trimmed = trim($url);
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $trimmed)) {
      return $trimmed;
    }
    $trimmed = preg_replace('#^\\./#', '', $trimmed) ?? $trimmed;

    if (stripos($trimmed, 'overlay.php') !== false) {
      $parts = parse_url($trimmed);
      $query = isset($parts['query']) ? (string) $parts['query'] : '';
      parse_str($query, $params);
      $file = isset($params['file']) ? (string) $params['file'] : '';
      $targetSlug = isset($params['slug']) ? (string) $params['slug'] : $slug;
      if ($file !== '') {
        return 'overlay.php?slug=' . rawurlencode($targetSlug) . '&file=' . rawurlencode($file);
      }
      return $query !== '' ? 'overlay.php?' . $query : 'overlay.php';
    }
    if (preg_match('~cache/([^/]+)/overlays/([^/?#]+)~i', $trimmed, $match)) {
      return 'overlay.php?slug=' . rawurlencode($match[1]) . '&file=' . rawurlencode($match[2]);
    }
    if (preg_match('#^[^/]+\\.(?:png|jpe?g)$#i', $trimmed)) {
      return 'overlay.php?slug=' . rawurlencode($slug) . '&file=' . rawurlencode($trimmed);
    }
    return ltrim($trimmed, '/');
  }
}
