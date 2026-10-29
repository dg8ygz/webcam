<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/** Optional authenticated image metadata. Invalid weather never rejects an image. */
final class LocalSensorWeather
{
  /**
   * Akzeptiert nur begrenzte DHT-Metadaten mit plausiblen Werten nahe der Aufnahme.
   * null verwirft nur das optionale Wetter, nicht den authentifizierten Bild-Upload.
   */
  public static function parse(mixed $raw, int $captureTimestamp, ?int $now = null): ?array
  {
    if (!is_string($raw) || strlen($raw) > 512) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $keys = array_keys($data);
    sort($keys);
    if ($keys !== ['captured_at', 'humidity_percent', 'measured_at', 'source', 'temperature_c']
      || $data['source'] !== 'dht22' || !is_int($data['measured_at']) || !is_int($data['captured_at'])) return null;
    $now ??= time();
    if ($data['measured_at'] < 946684800 || $data['captured_at'] - $data['measured_at'] < 0
      || $data['captured_at'] - $data['measured_at'] > 120
      || abs($data['captured_at'] - $captureTimestamp) > 900 || $data['captured_at'] > $now + 30) return null;
    foreach (['temperature_c' => [-40, 80], 'humidity_percent' => [0, 100]] as $key => [$min, $max]) {
      if ((!is_int($data[$key]) && !is_float($data[$key])) || !is_finite((float) $data[$key])
        || $data[$key] < $min || $data[$key] > $max) return null;
    }
    return $data;
  }

  /**
   * Ohne konfiguriertes Wetterscript atomar als normales WX-Sidecar ablegen.
   * Rueckgabe versorgt den vorhandenen DB-Ingest; Schreibfehler bleiben sichtbar.
   */
  public static function store(Camera $camera, mixed $raw, int $timestamp, string $dir, string $file): ?array
  {
    // Configured weather scripts always win, including their error/empty case.
    if (array_filter($camera->weatherCommands(), static fn($v) => is_string($v) && trim($v) !== '')) return null;
    $data = self::parse($raw, $timestamp);
    if ($data === null) return null;
    $payload = sprintf("Temp: %.1f C\nHumidity: %.1f %%\nSource: local DHT22\nMeasured at: %s",
      $data['temperature_c'], $data['humidity_percent'], gmdate('c', $data['measured_at']));
    $path = $dir . DIRECTORY_SEPARATOR . $file . '_WX.txt';
    if (!AtomicFileWriter::write($path, $payload . "\n")) throw new \RuntimeException('Local weather sidecar write failed');
    return ['path' => $path, 'data' => $payload];
  }
}
