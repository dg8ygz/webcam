<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use InvalidArgumentException;
use Webcam\Service\Status\WatchdogConfigNormalizer;

/**
 * Übersetzt Admin-Eingaben in eine begrenzte Kamera-Konfiguration.
 *
 * Leseführung:
 * 1. normalizePayload() ergänzt sichere Defaults und prüft gemeinsame Grenzen.
 * 2. applySectionUpdate() übernimmt nur Felder des ausdrücklich gewählten Tabs.
 * 3. Kleine private Helfer prüfen Objekt-/Listenformen ohne stille Fallbacks.
 * 4. Overlay- und Labelnormalisierung begrenzen öffentliche Darstellungsdaten.
 *
 * Die Klasse kennt weder HTTP noch ConfigStore oder Dateisystem. Fehler werden
 * als InvalidArgumentException zurückgegeben. Der aufrufende Admin-Router
 * entscheidet anschließend, wie daraus eine HTTP-Antwort entsteht.
 */
final class CameraConfigNormalizer
{
  /** @var list<string> */
  private const STORAGE_KEYS = [
    'base_path',
    'current_dir',
    'archive_dir',
    'best_of_dir',
  ];

  /** @var list<string> */
  private const MAP_KEYS = [
    'latitude',
    'longitude',
    'elevation',
    'orientation_deg',
    'field_of_view_deg',
    'map_center_offset',
    'map_center_offset_x',
    'map_height_px',
    'orientation_visualization_km',
  ];

  /** @var list<string> */
  private const CAPTURE_KEYS = [
    'interval_seconds',
    'endpoint',
    'timeout_seconds',
    'expected_response',
    'upload_key',
    'helper_upload_mode',
    'auto_iso',
    'transport',
    'window_start',
    'window_end',
    'active_upload_fallback_failures',
  ];

  /** @var list<string> */
  private const STREAM_KEYS = [
    'mode',
    'playlist_url',
    'snapshot_url',
    'fps',
    'quality',
    'max_clients',
  ];

  private readonly CameraOverlayNormalizer $overlays;
  private readonly CameraLabelNormalizer $labels;

  public function __construct(
    ?CameraOverlayNormalizer $overlays = null,
    ?CameraLabelNormalizer $labels = null
  ) {
    $this->overlays = $overlays ?? new CameraOverlayNormalizer();
    $this->labels = $labels ?? new CameraLabelNormalizer();
  }

  /**
   * Baut aus einem vollständigen Browserpayload die speicherbare Kamera.
   *
   * Vorhandene, nicht von dieser Sicherheitsgrenze behandelte Felder bleiben
   * erhalten. Felder mit Pfad-, Protokoll- oder Typrelevanz werden dagegen
   * explizit normalisiert und niemals ungeprüft durchgereicht.
   *
   * @param array<string,mixed> $payload
   * @param array<string,mixed> $defaults
   * @return array<string,mixed>
   */
  public function normalizePayload(array $payload, string $slug, array $defaults): array
  {
    $payload['slug'] = $slug;
    $payload['id'] = $payload['id'] ?? $slug;
    $payload['short_title'] = $payload['short_title']
      ?? ($payload['name'] ?? $payload['title'] ?? $slug);
    $payload['description'] = $payload['description'] ?? '';
    $payload['tags'] = $this->filterStringListOrDefault($payload['tags'] ?? null);
    $payload['resolutions'] = isset($payload['resolutions']) && is_array($payload['resolutions'])
      ? array_values(array_filter($payload['resolutions'], 'is_numeric'))
      : ($defaults['resolutions'] ?? []);

    $payload['capture'] = $this->normalizeCapture($payload['capture'] ?? null);
    $payload['storage'] = $this->normalizeStorage($payload['storage'] ?? null);
    $payload['location'] = $this->normalizeLocation($payload['location'] ?? null);
    $this->assertCaptureEndpoint((string) ($payload['capture']['endpoint'] ?? ''));

    if (!isset($payload['status']) || !is_array($payload['status'])) {
      $payload['status'] = [];
    }
    if (array_key_exists('watchdog', $payload['status'])) {
      $payload['status']['watchdog'] = (new WatchdogConfigNormalizer())->normalize($payload['status']['watchdog']);
    }

    $payload['overlay_logo'] = $this->overlays->normalize(
      $payload['overlay_logo'] ?? null,
      $slug
    );
    $payload['overlay_logo_dark'] = $this->overlays->normalize(
      $payload['overlay_logo_dark'] ?? null,
      $slug
    );
    if (array_key_exists('labels', $payload)) {
      $payload['labels'] = $this->labels->normalize($payload['labels']);
    }

    return $payload;
  }

  /**
   * Aktualisiert genau einen Kameraeditor-Abschnitt.
   *
   * Ein Teilrequest darf keine Felder benachbarter Tabs überschreiben. Deshalb
   * besitzt jeder Abschnitt eine Positivliste. Die vollständige gemeinsame
   * Normalisierung erfolgt anschließend über normalizeSectionUpdate().
   *
   * @param array<string,mixed> $camera
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  public function applySectionUpdate(array $camera, array $payload, string $section): array
  {
    $section = strtolower(trim($section));
    if ($section === '') {
      throw new InvalidArgumentException('Section is required');
    }

    return match ($section) {
      'general' => $this->applyGeneralSection($camera, $payload),
      'info' => $this->applyInfoSection($camera, $payload),
      'liveview' => $this->applyLiveViewSection($camera, $payload),
      'map' => $this->applyMapSection($camera, $payload),
      'image-processing' => $this->applyImageProcessingSection($camera, $payload),
      'capture' => $this->applyCaptureSection($camera, $payload),
      'storage' => $this->applyStorageSection($camera, $payload),
      'overlay' => $this->applyOverlaySection($camera, $payload),
      'labels' => $this->applyLabelsSection($camera, $payload),
      'weather' => $this->applyWeatherSection($camera, $payload),
      'watchdog' => $this->applyWatchdogSection($camera, $payload),
      default => throw new InvalidArgumentException('Unknown section'),
    };
  }

  /**
   * Validate the complete document without migrating unrelated capture settings.
   * Creation/full replacement and capture edits still materialize capture defaults.
   */
  public function normalizeSectionUpdate(array $camera, array $payload, string $section, string $slug, array $defaults): array
  {
    $section = strtolower(trim($section));
    $normalized = $this->normalizePayload(
      $this->applySectionUpdate($camera, $payload, $section), $slug, $defaults
    );
    $processing = $normalized['capture']['image_processing'] ?? null;
    if ($section !== 'capture') {
      if (array_key_exists('capture', $camera)) {
        $normalized['capture'] = $camera['capture'];
      } else {
        unset($normalized['capture']);
      }
    }
    if ($section === 'image-processing') {
      $normalized['capture']['image_processing'] = $processing;
    }
    return $normalized;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyGeneralSection(array $camera, array $payload): array
  {
    foreach (['name', 'title', 'short_title'] as $key) {
      if (!array_key_exists($key, $payload)) {
        continue;
      }
      if (!is_string($payload[$key])) {
        throw new InvalidArgumentException("$key must be a string");
      }
      $camera[$key] = trim($payload[$key]);
    }
    if (array_key_exists('tags', $payload)) {
      $camera['tags'] = $this->requireStringList($payload['tags'], 'tags');
    }
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyInfoSection(array $camera, array $payload): array
  {
    foreach (['description', 'info_html'] as $key) {
      if (!array_key_exists($key, $payload)) {
        continue;
      }
      if (!is_string($payload[$key])) {
        throw new InvalidArgumentException("$key must be a string");
      }
      $camera[$key] = $payload[$key];
    }
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyLiveViewSection(array $camera, array $payload): array
  {
    if (array_key_exists('tags', $payload)) {
      $camera['tags'] = $this->requireStringList($payload['tags'], 'tags');
    }
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyMapSection(array $camera, array $payload): array
  {
    $input = $this->requireArray($payload['location'] ?? null, 'location');
    $location = is_array($camera['location'] ?? null) ? $camera['location'] : [];
    $camera['location'] = $this->copyAllowedKeys($location, $input, self::MAP_KEYS);
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyCaptureSection(array $camera, array $payload): array
  {
    if (array_key_exists('mode', $payload)) {
      if (!is_string($payload['mode'])) {
        throw new InvalidArgumentException('mode must be a string');
      }
      $camera['mode'] = trim($payload['mode']);
    }

    if (array_key_exists('capture', $payload)) {
      $input = $this->requireArray($payload['capture'], 'capture');
      $capture = is_array($camera['capture'] ?? null) ? $camera['capture'] : [];
      $capture = $this->copyAllowedKeys($capture, $input, self::CAPTURE_KEYS);
      if (array_key_exists('extra_times', $input)) {
        $capture['extra_times'] = $this->requireStringList(
          $input['extra_times'],
          'capture.extra_times'
        );
      }
      $camera['capture'] = $capture;
    }

    if (array_key_exists('stream', $payload)) {
      if ($payload['stream'] === null) {
        $camera['stream'] = null;
      } else {
        $input = $this->requireArray($payload['stream'], 'stream');
        $stream = is_array($camera['stream'] ?? null) ? $camera['stream'] : [];
        $camera['stream'] = $this->copyAllowedKeys($stream, $input, self::STREAM_KEYS);
      }
    }
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyStorageSection(array $camera, array $payload): array
  {
    $input = $this->requireArray($payload['storage'] ?? null, 'storage');
    $storage = is_array($camera['storage'] ?? null) ? $camera['storage'] : [];
    foreach (self::STORAGE_KEYS as $key) {
      if (!array_key_exists($key, $input)) {
        continue;
      }
      if (!is_string($input[$key])) {
        throw new InvalidArgumentException("storage.$key must be a string");
      }
      $storage[$key] = trim($input[$key]);
    }
    $camera['storage'] = $storage;
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyOverlaySection(array $camera, array $payload): array
  {
    foreach (['overlay_logo', 'overlay_logo_dark'] as $key) {
      if (!array_key_exists($key, $payload)) {
        continue;
      }
      if ($payload[$key] !== null && !is_array($payload[$key])) {
        throw new InvalidArgumentException("$key must be an object or null");
      }
      $camera[$key] = $payload[$key];
    }
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyLabelsSection(array $camera, array $payload): array
  {
    if (!array_key_exists('labels', $payload)) {
      throw new InvalidArgumentException('labels missing');
    }
    if ($payload['labels'] !== null && !is_array($payload['labels'])) {
      throw new InvalidArgumentException('labels must be an array');
    }
    $camera['labels'] = $payload['labels'];
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyWeatherSection(array $camera, array $payload): array
  {
    if (!array_key_exists('weather_commands', $payload)) {
      throw new InvalidArgumentException('weather_commands missing');
    }
    $camera['weather_commands'] = $this->requireStringList(
      $payload['weather_commands'],
      'weather_commands'
    );
    return $camera;
  }

  /** @param array<string,mixed> $camera @param array<string,mixed> $payload */
  private function applyWatchdogSection(array $camera, array $payload): array
  {
    $watchdog = null;
    if (array_key_exists('status', $payload)) {
      $statusInput = $this->requireArray($payload['status'], 'status');
      $watchdog = $statusInput['watchdog'] ?? null;
    } elseif (array_key_exists('watchdog', $payload)) {
      $watchdog = $payload['watchdog'];
    }
    if (!is_array($watchdog)) {
      throw new InvalidArgumentException('watchdog must be an object');
    }

    $status = is_array($camera['status'] ?? null) ? $camera['status'] : [];
    $status['watchdog'] = (new WatchdogConfigNormalizer())->normalize($watchdog);
    $camera['status'] = $status;
    return $camera;
  }

  /** Bildbearbeitung besitzt einen eigenen Tab und veraendert keine Aufnahmeparameter. */
  private function applyImageProcessingSection(array $camera, array $payload): array
  {
    $value = $payload['capture']['image_processing'] ?? null;
    if (!is_array($value)) { throw new InvalidArgumentException('Bildbearbeitung fehlt'); }
    $camera['capture']['image_processing'] = \Webcam\Service\Storage\ImageProcessingConfig::normalize($value);
    return $camera;
  }

  private function normalizeCapture(mixed $capture): array
  {
    $capture = is_array($capture) ? $capture : [];
    if (array_key_exists('image_processing', $capture)) {
      $capture['image_processing'] = \Webcam\Service\Storage\ImageProcessingConfig::normalize($capture['image_processing']);
    }
    if (array_key_exists('helper_upload_mode', $capture)
      && !in_array($capture['helper_upload_mode'], ['local', 'legacy', 'chunked'], true)) {
      throw new InvalidArgumentException('capture.helper_upload_mode must be local, legacy or chunked');
    }
    $capture['auto_iso'] = \Webcam\Service\Capture\AutoIsoPolicy::normalize($capture['auto_iso'] ?? null);
    $capture['interval_seconds'] = isset($capture['interval_seconds'])
      ? (int) $capture['interval_seconds']
      : 600;

    $transport = $capture['transport'] ?? 'http-get';
    if (!is_string($transport)) {
      throw new InvalidArgumentException('capture.transport must be a string');
    }
    $transport = trim($transport) ?: 'http-get';
    if (!in_array($transport, ['http-get', 'rtsp'], true)) {
      throw new InvalidArgumentException('capture.transport must be http-get or rtsp');
    }
    $capture['transport'] = $transport;
    $capture['upload_key'] = isset($capture['upload_key']) && is_string($capture['upload_key'])
      ? trim($capture['upload_key'])
      : '';
    $fallbackFailures = $capture['active_upload_fallback_failures'] ?? 0;
    $isIntegerString = is_string($fallbackFailures)
      && preg_match('/^\d+$/', trim($fallbackFailures)) === 1;
    if (!is_int($fallbackFailures) && !$isIntegerString) {
      throw new InvalidArgumentException('capture.active_upload_fallback_failures must be an integer');
    }
    $fallbackFailures = (int) $fallbackFailures;
    if ($fallbackFailures < 0 || $fallbackFailures > 100) {
      throw new InvalidArgumentException('capture.active_upload_fallback_failures must be between 0 and 100');
    }
    $capture['active_upload_fallback_failures'] = $fallbackFailures;
    $capture['extra_times'] = $this->filterStringListOrDefault($capture['extra_times'] ?? null);
    return $capture;
  }

  /** @return array<string,mixed> */
  private function normalizeStorage(mixed $storage): array
  {
    $storage = is_array($storage) ? $storage : [];
    $defaults = [
      'base_path' => 'storage/',
      'current_dir' => 'Current',
      'archive_dir' => 'Archive',
      'best_of_dir' => 'BestOf',
    ];
    foreach ($defaults as $key => $default) {
      // `null` bedeutet wie bisher „nicht gesetzt“ und erhält den Default.
      // Andere falsche Typen werden unten sichtbar zurückgewiesen.
      $storage[$key] = $storage[$key] ?? $default;
    }

    foreach (self::STORAGE_KEYS as $key) {
      $value = $storage[$key] ?? '';
      if (!is_string($value)) {
        throw new InvalidArgumentException("Invalid storage $key");
      }
      if (str_contains($value, '..')) {
        throw new InvalidArgumentException("storage.$key must not contain '..'");
      }
      if ($key === 'base_path' && preg_match('#^([a-zA-Z]:[\\/]|/)#', $value)) {
        throw new InvalidArgumentException('storage.base_path must be relative');
      }
    }
    return $storage;
  }

  /** @return array<string,mixed> */
  private function normalizeLocation(mixed $location): array
  {
    $location = is_array($location) ? $location : [];
    $location['map_center_offset'] = $location['map_center_offset'] ?? 0;
    $location['map_center_offset_x'] = $location['map_center_offset_x'] ?? 0;
    $height = isset($location['map_height_px']) ? (float) $location['map_height_px'] : 300.0;
    if ($height < 100 || $height > 1200) {
      throw new InvalidArgumentException('location.map_height_px must be between 100 and 1200');
    }
    $location['map_height_px'] = $height;
    return $location;
  }

  private function assertCaptureEndpoint(string $endpoint): void
  {
    if (str_starts_with($endpoint, 'file://')) {
      throw new InvalidArgumentException('Capture endpoint must not be file://');
    }
    if (str_contains($endpoint, '..')) {
      throw new InvalidArgumentException('Capture endpoint contains invalid path traversal');
    }
  }

  /** @return array<string,mixed> */
  private function requireArray(mixed $value, string $label): array
  {
    if (!is_array($value)) {
      throw new InvalidArgumentException("$label must be an object");
    }
    return $value;
  }

  /** @return list<string> */
  private function requireStringList(mixed $value, string $label): array
  {
    if (!is_array($value)) {
      throw new InvalidArgumentException("$label must be an array");
    }
    return array_values(array_filter($value, 'is_string'));
  }

  /** @return list<string> */
  private function filterStringListOrDefault(mixed $value): array
  {
    return is_array($value)
      ? array_values(array_filter($value, 'is_string'))
      : [];
  }

  /**
   * Kopiert nur ausdrücklich erlaubte Schlüssel in ein bestehendes Teilobjekt.
   *
   * @param array<string,mixed> $target
   * @param array<string,mixed> $source
   * @param list<string> $keys
   * @return array<string,mixed>
   */
  private function copyAllowedKeys(array $target, array $source, array $keys): array
  {
    foreach ($keys as $key) {
      if (array_key_exists($key, $source)) {
        $target[$key] = $source[$key];
      }
    }
    return $target;
  }
}
