<?php
declare(strict_types=1);

namespace Webcam\Service\Status;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Persistiert die kurzfristige JSON-Momentaufnahme einer Kamera.
 *
 * Diese Klasse enthält ausschließlich Datei-, Lock- und Ereignislogik. Sie
 * entscheidet weder über Watchdog-Resets noch über die Bedeutung eines
 * Capture-Erfolgs. Schreibvorgänge ersetzen eine vollständig geschriebene
 * temporäre Datei atomar, damit parallele Leser niemals halbes JSON sehen.
 */
final class CameraStatusStore
{
  public function __construct(private readonly App $app) {}

  /** Liest und defensiv normalisiert die gespeicherte Momentaufnahme. */
  public function read(Camera $camera): ?array
  {
    $path = $this->stateFile($camera);
    if (!is_file($path)) {
      return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
      $this->app->logger()->warning('status read failed', ['path' => $path, 'camera' => $camera->slug()]);
      return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return null;
    }

    $state = array_merge($this->defaultState($camera), $decoded);
    if (!is_array($state['events'] ?? null)) {
      $state['events'] = [];
    } else {
      usort($state['events'], static fn(array $left, array $right): int =>
        ($right['timestamp'] ?? 0) <=> ($left['timestamp'] ?? 0));
      $state['events'] = array_slice($state['events'], 0, 100);
    }
    $state['last_exif'] = $this->normalizeExif($state['last_exif'] ?? null);
    $state['last_exif_path'] = is_string($state['last_exif_path'] ?? null)
      ? $state['last_exif_path'] : null;
    $state['last_exif_relative'] = is_string($state['last_exif_relative'] ?? null)
      ? $state['last_exif_relative'] : null;
    $state['last_luminance'] = is_numeric($state['last_luminance'] ?? null)
      ? (float) $state['last_luminance'] : null;
    $state['last_weather_path'] = is_string($state['last_weather_path'] ?? null)
      ? $state['last_weather_path'] : null;
    $state['last_weather_at'] = is_numeric($state['last_weather_at'] ?? null)
      ? (int) $state['last_weather_at'] : null;
    $state['last_weather_data'] = is_string($state['last_weather_data'] ?? null)
      ? $this->truncateText($state['last_weather_data']) : '';
    return $state;
  }

  public function defaultState(Camera $camera): array
  {
    return [
      'camera_id' => $camera->id(),
      'last_capture_at' => null,
      'last_success_at' => null,
      'last_failure_at' => null,
      'last_duplicate_at' => null,
      'last_duplicate_note' => null,
      'duplicate_active' => false,
      'last_reset_at' => null,
      'consecutive_failures' => 0,
      'successes_since_reset' => 0,
      'total_captures' => 0,
      'total_failures' => 0,
      'resets_total' => 0,
      'events' => [],
      'last_exif' => [],
      'last_exif_path' => null,
      'last_exif_relative' => null,
      'last_luminance' => null,
      'last_weather_path' => null,
      'last_weather_at' => null,
      'last_weather_data' => '',
      'weather_quality' => ['status' => 'ok', 'flags_total' => 0, 'counts' => []],
    ];
  }

  public function write(Camera $camera, array $state): void
  {
    $file = $this->stateFile($camera);
    $json = json_encode(SecretRedactor::redactValue($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $temporary = tempnam(dirname($file), basename($file) . '.tmp.');
    if (
      $json === false
      || $temporary === false
      || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false
      || !chmod($temporary, 0664)
      || !rename($temporary, $file)
    ) {
      if (is_string($temporary)) @unlink($temporary);
      $this->app->logger()->warning('status write failed', ['path' => $file, 'camera' => $camera->slug()]);
    }
  }

  /** Serialisiert den vollständigen Read-Modify-Write-Zyklus einer Kamera. */
  public function withLock(Camera $camera, callable $callback): void
  {
    $lockPath = $this->stateFile($camera) . '.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) throw new RuntimeException("Unable to open status lock: {$lockPath}");
    if (!flock($handle, LOCK_EX)) {
      fclose($handle);
      throw new RuntimeException("Unable to acquire status lock: {$lockPath}");
    }
    try {
      $callback();
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /** Ergänzt State-Historie und zeilenorientiertes Capture-Log gemeinsam. */
  public function appendEvent(
    Camera $camera,
    array &$state,
    string $type,
    string $message = '',
    ?string $path = null
  ): void {
    if (!is_array($state['events'] ?? null)) $state['events'] = [];
    $entry = array_filter([
      'timestamp' => time(),
      'type' => $type,
      'message' => $message !== '' ? $message : null,
      'path' => $path,
    ]);
    $state['events'][] = $entry;
    if (count($state['events']) > 200) {
      $state['events'] = array_slice($state['events'], -200);
    }
    $this->appendLogLine($camera, $entry);
  }

  /** Normalisiert einen Pfad ohne zu verlangen, dass eine neue Datei schon existiert. */
  public function normalizePath(?string $path): ?string
  {
    if ($path === null || $path === '') return $path;
    $candidate = str_replace('\\', '/', $path);
    $real = realpath($candidate);
    if (is_string($real) && $real !== '') return str_replace('\\', '/', $real);
    $collapsed = preg_replace('#/(?:[^/]+/\.\.)#', '/', $candidate);
    return $collapsed ?: $candidate;
  }

  private function stateFile(Camera $camera): string
  {
    $directory = $this->app->paths()->statePath('cameras');
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException("Unable to create state directory: {$directory}");
    }
    return $directory . '/' . $camera->slug() . '.json';
  }

  private function normalizeExif(mixed $raw): array
  {
    if (!is_array($raw)) return [];
    $filtered = [];
    foreach ($raw as $key => $value) {
      if (!is_string($key)) continue;
      if (is_array($value)) $value = implode(', ', array_map('strval', $value));
      elseif (!is_scalar($value)) continue;
      $filtered[$key] = (string) $value;
    }
    return $filtered;
  }

  private function truncateText(string $value): string
  {
    $trimmed = trim($value);
    return strlen($trimmed) > 4000 ? substr($trimmed, 0, 4000) . '…' : $trimmed;
  }

  private function appendLogLine(Camera $camera, array $entry): void
  {
    $directory = $this->app->paths()->logPath('capture');
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      $this->app->logger()->warning('capture log dir unavailable', [
        'dir' => $directory,
        'camera' => $camera->slug(),
      ]);
      return;
    }
    $file = $directory . DIRECTORY_SEPARATOR . $camera->slug() . '.log';
    $payload = SecretRedactor::redactValue([
      'timestamp' => $entry['timestamp'] ?? time(),
      'camera' => $camera->slug(),
      'type' => $entry['type'] ?? 'event',
      'message' => $entry['message'] ?? null,
      'path' => $entry['path'] ?? null,
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
      $this->app->logger()->warning('capture log write failed', ['path' => $file, 'camera' => $camera->slug()]);
    }
  }
}
