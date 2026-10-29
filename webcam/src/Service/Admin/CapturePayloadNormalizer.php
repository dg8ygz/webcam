<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use InvalidArgumentException;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Prüft und vereinheitlicht Änderungen aus dem Admin-Capture-Editor.
 *
 * Diese Klasse kennt weder HTTP noch JSON-Antworten. Sie erhält bereits
 * dekodierte Browserdaten, übernimmt nur ausdrücklich erlaubte Felder und
 * liefert ein für CameraCaptureEditor geeignetes Update-Paket zurück. Bei
 * fehlerhaften Eingaben wirft sie InvalidArgumentException; erst die Route
 * übersetzt diesen Fachfehler in HTTP 400.
 *
 * Leseführung:
 * 1. Felder der Capture-Hauptzeile prüfen,
 * 2. optionale Varianten validieren,
 * 3. optionale EXIF- und Wetterzeilen begrenzen,
 * 4. ein kleines, positives Update-Paket zurückgeben.
 */
final class CapturePayloadNormalizer
{
  private const MAX_VARIANTS = 40;
  private const MAX_EXIF = 250;
  private const MAX_WEATHER = 250;
  private const MAX_VALUE_LENGTH = 2048;

  /**
   * Normalisiert einen vollständigen PUT-Body des Capture-Editors.
   *
   * Nicht gesendete Felder fehlen auch im Ergebnis. Dadurch kann der Editor
   * beispielsweise nur die Like-Zahl ändern, ohne Varianten oder Wetterdaten
   * versehentlich durch leere Listen zu ersetzen.
   *
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  public function normalizeUpdatePayload(
    array $payload,
    CameraDataStore $store,
    string $slug
  ): array {
    $normalized = $this->normalizeCaptureFields($payload, $store, $slug);

    // Die drei Listen werden nur aufgenommen, wenn der Browser sie tatsächlich
    // gesendet hat. Eine bewusst leere Liste bedeutet: vorhandene Zeilen löschen.
    if (array_key_exists('variants', $payload)) {
      $normalized['variants'] = $this->normalizeVariants($payload['variants'], $store, $slug);
    }
    if (array_key_exists('exif', $payload)) {
      $normalized['exif'] = $this->normalizeExif($payload['exif']);
    }
    if (array_key_exists('weather', $payload)) {
      $normalized['weather'] = $this->normalizeWeather($payload['weather']);
    }

    return $normalized;
  }

  /**
   * Übernimmt die einfachen Spalten der Tabelle captures.
   *
   * @param array<string,mixed> $payload
   * @return array<string,int|float|string>
   */
  private function normalizeCaptureFields(
    array $payload,
    CameraDataStore $store,
    string $slug
  ): array {
    $result = [];

    if (isset($payload['captured_ts'])) {
      $capturedTs = (int) $payload['captured_ts'];
      if ($capturedTs < 0) {
        throw new InvalidArgumentException('captured_ts must be >= 0');
      }
      $result['captured_ts'] = $capturedTs;
    }

    if (isset($payload['relative_path'])) {
      $result['relative_path'] = $this->normalizeRelativePath(
        $store,
        (string) $payload['relative_path'],
        'relative_path',
        $slug
      );
    }

    if (isset($payload['luminance'])) {
      $result['luminance'] = (float) $payload['luminance'];
    }

    if (isset($payload['hash_sha256'])) {
      $hash = trim((string) $payload['hash_sha256']);
      if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
        throw new InvalidArgumentException('hash_sha256 must be a 64 char hex string');
      }
      $result['hash_sha256'] = $hash;
    }

    if (isset($payload['likes_count'])) {
      $result['likes_count'] = max(0, (int) $payload['likes_count']);
    }

    return $result;
  }

  /**
   * Prüft Anzahl, Namen, Dimensionen, Größen und Pfade aller Bildvarianten.
   *
   * @return list<array{variant:string,relative_path:string,width:?int,height:?int,size_bytes:?int,mtime:?int}>
   */
  private function normalizeVariants(
    mixed $raw,
    CameraDataStore $store,
    string $slug
  ): array {
    if (!is_array($raw)) {
      throw new InvalidArgumentException('variants must be an array');
    }
    if (count($raw) > self::MAX_VARIANTS) {
      throw new InvalidArgumentException('variants exceeds max items');
    }

    $variants = [];
    foreach ($raw as $index => $variant) {
      if (!is_array($variant)) {
        throw new InvalidArgumentException("variants[$index] must be an object");
      }

      $name = trim((string) ($variant['variant'] ?? ''));
      if ($name === '') {
        throw new InvalidArgumentException("variants[$index].variant is required");
      }
      if (preg_match('/^[a-z0-9-]+$/i', $name) !== 1) {
        throw new InvalidArgumentException("variants[$index].variant has invalid characters");
      }

      $pathInput = isset($variant['relative_path'])
        ? (string) $variant['relative_path']
        : '';
      if (trim($pathInput) === '') {
        throw new InvalidArgumentException("variants[$index].relative_path is required");
      }

      $variants[] = [
        'variant' => $name,
        'width' => $this->optionalNonNegativeInteger($variant, 'width', "variants[$index].width"),
        'height' => $this->optionalNonNegativeInteger($variant, 'height', "variants[$index].height"),
        'relative_path' => $this->normalizeRelativePath(
          $store,
          $pathInput,
          "variants[$index].relative_path",
          $slug
        ),
        'size_bytes' => $this->optionalNonNegativeInteger(
          $variant,
          'size_bytes',
          "variants[$index].size_bytes"
        ),
        'mtime' => $this->optionalNonNegativeInteger($variant, 'mtime', "variants[$index].mtime"),
      ];
    }

    return $variants;
  }

  /**
   * Begrenzt EXIF-Einträge auf einfache Tag-/Wert-Paare mit Sortiernummer.
   *
   * @return list<array{tag:string,value:string,sort_order:?int}>
   */
  private function normalizeExif(mixed $raw): array
  {
    if (!is_array($raw)) {
      throw new InvalidArgumentException('exif must be an array');
    }
    if (count($raw) > self::MAX_EXIF) {
      throw new InvalidArgumentException('exif exceeds max items');
    }

    $exif = [];
    foreach ($raw as $index => $entry) {
      if (!is_array($entry)) {
        throw new InvalidArgumentException("exif[$index] must be an object");
      }
      $tag = trim((string) ($entry['tag'] ?? ''));
      if ($tag === '') {
        throw new InvalidArgumentException("exif[$index].tag is required");
      }
      $value = (string) ($entry['value'] ?? '');
      $this->assertValueLength($value, "exif[$index].value");

      $exif[] = [
        'tag' => $tag,
        'value' => $value,
        'sort_order' => $this->optionalNonNegativeInteger(
          $entry,
          'sort_order',
          "exif[$index].sort_order"
        ),
      ];
    }

    return $exif;
  }

  /**
   * Prüft die geordneten Textzeilen der zum Capture gehörenden Wetterdaten.
   *
   * @return list<array{key:string,value:string,position:?int}>
   */
  private function normalizeWeather(mixed $raw): array
  {
    if (!is_array($raw)) {
      throw new InvalidArgumentException('weather must be an array');
    }
    if (count($raw) > self::MAX_WEATHER) {
      throw new InvalidArgumentException('weather exceeds max items');
    }

    $weather = [];
    foreach ($raw as $index => $entry) {
      if (!is_array($entry)) {
        throw new InvalidArgumentException("weather[$index] must be an object");
      }
      $value = trim((string) ($entry['value'] ?? ''));
      if ($value === '') {
        throw new InvalidArgumentException("weather[$index].value is required");
      }
      $this->assertValueLength($value, "weather[$index].value");

      $weather[] = [
        'key' => trim((string) ($entry['key'] ?? '')),
        'value' => $value,
        'position' => $this->optionalNonNegativeInteger(
          $entry,
          'position',
          "weather[$index].position"
        ),
      ];
    }

    return $weather;
  }

  /**
   * Vereinheitlicht einen relativen DB-Pfad und bindet ihn an die Kamera-ID.
   */
  private function normalizeRelativePath(
    CameraDataStore $store,
    string $path,
    string $label,
    string $slug
  ): string {
    $raw = trim($path);
    if ($raw === '') {
      throw new InvalidArgumentException("{$label} must not be empty");
    }
    if (preg_match('#^([a-zA-Z]:|/)#', $raw) === 1) {
      throw new InvalidArgumentException("{$label} must be a relative path");
    }

    $normalized = $store->normalizeRelativePath($raw);
    // Bewusst strenger als eine reine Segmentprüfung: Auch ungewöhnliche
    // Dateinamen mit ".." bleiben im Admin-Editor verboten. Das erhält den
    // bisherigen Vertrag und macht Traversal-Prüfungen leicht nachvollziehbar.
    if ($normalized === '' || str_contains($normalized, '..')) {
      throw new InvalidArgumentException("{$label} must not contain '..'");
    }
    if (!$this->pathIncludesSlug($normalized, $slug)) {
      throw new InvalidArgumentException("{$label} must include camera slug");
    }

    return $normalized;
  }

  /** Prüft eine optionale Ganzzahl, ohne negative Metadaten zuzulassen. */
  private function optionalNonNegativeInteger(array $entry, string $key, string $label): ?int
  {
    $value = isset($entry[$key]) ? (int) $entry[$key] : null;
    if ($value !== null && $value < 0) {
      throw new InvalidArgumentException("{$label} must be >= 0");
    }
    return $value;
  }

  /** Begrenzt frei eingegebenen Text, bevor er in SQLite landet. */
  private function assertValueLength(string $value, string $label): void
  {
    if (strlen($value) > self::MAX_VALUE_LENGTH) {
      throw new InvalidArgumentException("{$label} exceeds max length");
    }
  }

  /** Der Slug muss ein vollständiges Pfadsegment sein, kein Teiltreffer. */
  private function pathIncludesSlug(string $path, string $slug): bool
  {
    return in_array(strtolower($slug), explode('/', strtolower($path)), true);
  }
}
