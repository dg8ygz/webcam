<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\App;

/**
 * Kleine Dateisystemgrenze für den kurzlebigen Streamzustand.
 *
 * Alle Streamkomponenten verwenden dadurch dieselbe Pfadauflösung, atomare
 * JSON-Schreibweise und Verzeichniserzeugung. Fachliche Entscheidungen wie
 * „Viewer aktiv“ oder „IP gesperrt“ gehören ausdrücklich nicht hierher.
 */
final class StreamRuntimeStore
{
  public function __construct(private readonly App $app) {}

  public function path(string $suffix): string
  {
    $path = $this->app->paths()->statePath($suffix);
    $directory = str_ends_with($suffix, '/') ? $path : dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new \RuntimeException('Unable to create stream state directory: ' . $directory);
    }
    return $path;
  }

  /** @return array<string,mixed>|null */
  public function readJson(string $path): ?array
  {
    if (!is_file($path)) {
      return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
      return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }

  /** @param array<string,mixed> $data */
  public function writeJson(string $path, array $data): void
  {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new \RuntimeException('Unable to create stream state directory: ' . $directory);
    }
    // HLS playlist cleanup and client heartbeats can touch the same NFS-backed
    // state tree concurrently. Retry short-lived rename/open races so one
    // heartbeat cannot turn into an HTTP 500 for an otherwise healthy stream.
    for ($attempt = 0; $attempt < 3; $attempt++) {
      if (\webcam_atomic_write_json($path, $data, JSON_UNESCAPED_SLASHES)) {
        return;
      }
      usleep(20000);
      clearstatcache(true, $path);
    }
    throw new \RuntimeException('Unable to write stream state: ' . $path);
  }

  /**
   * Führt ein Read-modify-write unter einem stabilen Datei-Lock aus.
   *
   * @param callable(array<string,mixed>):array<string,mixed> $mutator
   */
  public function updateJsonUnderLock(string $path, callable $mutator): void
  {
    $handle = fopen($path, 'c+');
    if (!is_resource($handle)) {
      throw new \RuntimeException('Unable to open stream state: ' . $path);
    }
    try {
      if (!flock($handle, LOCK_EX)) {
        throw new \RuntimeException('Unable to lock stream state: ' . $path);
      }
      $raw = stream_get_contents($handle);
      $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
      $data = is_array($data) ? $data : [];
      $data = $mutator($data);
      rewind($handle);
      ftruncate($handle, 0);
      fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
      fflush($handle);
      flock($handle, LOCK_UN);
    } finally {
      fclose($handle);
    }
  }
}
