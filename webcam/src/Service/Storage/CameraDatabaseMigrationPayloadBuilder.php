<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Übersetzt einen gefundenen Archivslot in das Schreibformat der Kamera-DB.
 *
 * Der Scanner kennt Dateinamen und relative Pfade. Diese Klasse besitzt die
 * zweite Grenze: Nur tatsächlich vorhandene Bildvarianten mit positiver Größe
 * werden übernommen; anschließend werden Primärbild, EXIF und Wetter ergänzt.
 * Dadurch bleibt die CLI frei von Datei- und Payload-Details.
 */
final class CameraDatabaseMigrationPayloadBuilder
{
  public function __construct(private readonly App $app)
  {
  }

  /**
   * @param array<string,mixed> $entry
   * @return array{
   *   slot_timestamp:int,
   *   captured_timestamp:int,
   *   relative_path:string,
   *   luminance:mixed,
   *   hash:mixed,
   *   variants:list<array{variant:string,relative_path:string,width:?int,height:?int,size:?int,mtime:?int}>,
   *   exif:array<string,string>,
   *   weather_lines:list<string>
   * }|null
   */
  public function build(Camera $camera, array $entry, mixed $primaryRelativePath, int $slotTimestamp): ?array
  {
    $variants = $this->buildVariants($camera, $entry['variants'] ?? []);
    if ($variants === []) {
      return null;
    }

    $primaryRelativePath = $this->resolvePrimaryRelativePath(
      $camera,
      $primaryRelativePath,
      $variants
    );
    if ($primaryRelativePath === null) {
      return null;
    }

    $exifRelativePath = $entry['exif_relative'] ?? null;
    $weatherRelativePath = $entry['weather_relative'] ?? ($entry['weather_path'] ?? null);
    $exif = is_array($entry['exif'] ?? null)
      ? $entry['exif']
      : $this->readExifPayload($camera, $exifRelativePath);
    $weatherLines = is_array($entry['weather_lines'] ?? null)
      ? array_values($entry['weather_lines'])
      : $this->readWeatherPayload($camera, $weatherRelativePath);

    return [
      'slot_timestamp' => $slotTimestamp,
      'captured_timestamp' => $slotTimestamp,
      'relative_path' => $primaryRelativePath,
      'luminance' => $entry['luminance'] ?? null,
      'hash' => $entry['hash'] ?? null,
      'variants' => $variants,
      'exif' => $exif,
      'weather_lines' => $weatherLines,
    ];
  }

  /**
   * Liest das historische Pipe-Format `Schlüssel|Wert` ohne HTML-Auswertung.
   *
   * @return array<string,string>
   */
  public function parseExifFile(string $path): array
  {
    if (!is_file($path)) {
      return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $result = [];
    foreach ($lines as $line) {
      if (!str_contains($line, '|')) {
        continue;
      }
      [$key, $value] = array_pad(explode('|', $line, 2), 2, '');
      if ($key !== '') {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  /** @return list<string> */
  public function parseWeatherFile(string $path): array
  {
    if (!is_file($path)) {
      return [];
    }

    return array_values(array_filter(
      array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []),
      static fn(string $line): bool => $line !== ''
    ));
  }

  /**
   * @param list<array{variant:string,relative_path:string,width:?int,height:?int,size:?int,mtime:?int}> $variants
   */
  private function resolvePrimaryRelativePath(
    Camera $camera,
    mixed $primaryRelativePath,
    array $variants
  ): ?string {
    if (is_string($primaryRelativePath) && $primaryRelativePath !== '') {
      $metadata = $this->readFileMetadata(
        $this->app->resolveCameraStoragePath($camera, $primaryRelativePath)
      );
      if (($metadata['size'] ?? null) !== null && (int) $metadata['size'] > 0) {
        return $primaryRelativePath;
      }
    }

    // Historische Bestände besitzen nicht immer `full`. Die Reihenfolge bildet
    // deshalb die bisherige Anzeigepräferenz ab und bleibt rückwärtskompatibel.
    foreach (['la', 'lm', 'full', 'sm', 'hu'] as $preferred) {
      foreach ($variants as $variant) {
        if (($variant['variant'] ?? null) === $preferred) {
          return (string) $variant['relative_path'];
        }
      }
    }

    foreach ($variants as $variant) {
      if (is_string($variant['relative_path'] ?? null) && $variant['relative_path'] !== '') {
        return (string) $variant['relative_path'];
      }
    }
    return null;
  }

  /**
   * @param mixed $variantMap
   * @return list<array{variant:string,relative_path:string,width:?int,height:?int,size:?int,mtime:?int}>
   */
  private function buildVariants(Camera $camera, mixed $variantMap): array
  {
    if (!is_array($variantMap)) {
      return [];
    }

    $variants = [];
    foreach ($variantMap as $key => $info) {
      if (!is_array($info)) {
        continue;
      }
      $relativePath = (string) ($info['relative_path'] ?? '');
      if ($relativePath === '') {
        continue;
      }

      $metadata = $this->readFileMetadata(
        $this->app->resolveCameraStoragePath($camera, $relativePath)
      );
      if (($metadata['size'] ?? null) === null || (int) ($metadata['size'] ?? 0) <= 0) {
        continue;
      }
      $variants[] = [
        'variant' => (string) $key,
        'relative_path' => $relativePath,
        'width' => ctype_digit((string) $key) ? (int) $key : null,
        'height' => null,
        'size' => $metadata['size'],
        'mtime' => $metadata['mtime'],
      ];
    }
    return $variants;
  }

  /** @return array{size:?int,mtime:?int} */
  private function readFileMetadata(?string $path): array
  {
    if ($path === null || !is_file($path)) {
      return ['size' => null, 'mtime' => null];
    }

    $stat = stat($path);
    if ($stat === false) {
      return ['size' => null, 'mtime' => null];
    }
    return [
      'size' => isset($stat['size']) ? (int) $stat['size'] : null,
      'mtime' => isset($stat['mtime']) ? (int) $stat['mtime'] : null,
    ];
  }

  /** @return array<string,string> */
  private function readExifPayload(Camera $camera, mixed $relativePath): array
  {
    if (!is_string($relativePath) || $relativePath === '') {
      return [];
    }
    $path = $this->app->resolveCameraStoragePath($camera, $relativePath);
    return $path === null ? [] : $this->parseExifFile($path);
  }

  /** @return list<string> */
  private function readWeatherPayload(Camera $camera, mixed $relativePath): array
  {
    if (!is_string($relativePath) || $relativePath === '') {
      return [];
    }
    $path = $this->app->resolveCameraStoragePath($camera, $relativePath);
    return $path === null ? [] : $this->parseWeatherFile($path);
  }
}
