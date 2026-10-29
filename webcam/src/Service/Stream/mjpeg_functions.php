<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Service\Stream\StreamState;

// MJPEG-Viewer belegen kurzlebige flock-Slots. Die Funktionen in dieser Datei
// zählen, aktualisieren und räumen diese Slots sowie den ffmpeg-Prozess auf.

function count_active_stream_slots(App $app, string $slug, int $maxClients): int
{
  $dir = $app->paths()->statePath('stream/' . $slug);
  if (!is_dir($dir)) {
    return 0;
  }

  $active = 0;
  for ($slot = 1; $slot <= $maxClients; $slot++) {
    $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slot . '.lock';
    if (!is_file($path)) {
      continue;
    }
    $handle = @fopen($path, 'c');
    if (!is_resource($handle)) {
      continue;
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
      $active++;
      fclose($handle);
      continue;
    }
    @flock($handle, LOCK_UN);
    fclose($handle);
  }

  return $active;
}

/**
 * Belegt den ersten freien MJPEG-Slot nichtblockierend und schreibt Metadaten in
 * dieselbe Lock-Datei. Ohne freien Slot erhält der Client sichtbar 503.
 *
 * @return array{path:string,handle:resource}|null
 */
function acquire_stream_slot(
  App $app,
  StreamState $streamState,
  string $slug,
  \Webcam\Infrastructure\Logging\Logger $logger,
  int $maxClients,
  array $server
): ?array {
  $dir = $app->paths()->statePath('stream/' . $slug);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    $logger->error('stream state dir create failed', ['camera' => $slug, 'dir' => $dir]);
    return null;
  }

  for ($slot = 1; $slot <= $maxClients; $slot++) {
    $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slot . '.lock';
    $handle = fopen($path, 'c');
    if (!is_resource($handle)) {
      $logger->warning('stream lock open failed', ['camera' => $slug, 'path' => $path]);
      continue;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      continue;
    }
    $metadata = $streamState->mjpegMetadata($slug, $slot, $maxClients, $server);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    return ['path' => $path, 'handle' => $handle, 'metadata' => $metadata];
  }

  return null;
}

/**
 * Aktualisiert den Heartbeat eines weiterhin gehaltenen MJPEG-Slots.
 *
 * @param array{path:string,handle:resource,metadata?:array<string,mixed>}|null $slot
 */
function touch_stream_slot(?array &$slot): void
{
  if ($slot === null) {
    return;
  }
  $handle = $slot['handle'] ?? null;
  if (!is_resource($handle)) {
    return;
  }
  $metadata = is_array($slot['metadata'] ?? null) ? $slot['metadata'] : [];
  $metadata['last_seen_at'] = time();
  $slot['metadata'] = $metadata;
  rewind($handle);
  ftruncate($handle, 0);
  fwrite($handle, json_encode($metadata, JSON_UNESCAPED_SLASHES) . "\n");
  fflush($handle);
}

/**
 * Leert Metadaten, löst flock und schließt das Slot-Handle genau einmal.
 *
 * @param array{path:string,handle:resource,metadata?:array<string,mixed>}|null $slot
 */
function release_stream_slot(?array &$slot): void
{
  if ($slot === null) {
    return;
  }
  $handle = $slot['handle'] ?? null;
  if (is_resource($handle)) {
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode(['released_at' => time()], JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
  }
  if (isset($slot['metadata']) && is_array($slot['metadata'])) {
    $token = (string) ($slot['metadata']['token'] ?? '');
    global $streamState;
    if ($token !== '' && $streamState instanceof StreamState) {
      try {
        $streamState->clearDisconnect($token);
      } catch (\Throwable) {
        // Ignore cleanup failures during shutdown.
      }
    }
  }
  $slot = null;
}

/**
 * Schließt ffmpeg-Pipes und beendet den Prozess bei Bedarf stufenweise. Die
 * Funktion ist auch für bereits beendete Prozesse und Client-Abbrüche sicher.
 *
 * @param mixed $process proc_open resource
 * @param array<int, mixed> $pipes
 */
function close_stream_process(&$process, array &$pipes, bool $terminate): ?int
{
  if (!is_resource($process)) {
    return null;
  }

  if ($terminate) {
    $status = proc_get_status($process);
    if (($status['running'] ?? false) === true) {
      proc_terminate($process);
      for ($i = 0; $i < 10; $i++) {
        usleep(100000);
        $status = proc_get_status($process);
        if (($status['running'] ?? false) !== true) {
          break;
        }
      }
      if (($status['running'] ?? false) === true) {
        proc_terminate($process, 9);
      }
    }
  }

  foreach ($pipes as $idx => $pipe) {
    if (is_resource($pipe)) {
      fclose($pipe);
    }
    $pipes[$idx] = null;
  }

  $exitCode = proc_close($process);
  $process = null;

  return is_int($exitCode) ? $exitCode : null;
}
