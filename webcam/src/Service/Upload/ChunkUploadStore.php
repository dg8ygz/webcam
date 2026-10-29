<?php
declare(strict_types=1);

namespace Webcam\Service\Upload;

use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Serverseitiger, wiederholbarer Zwischenspeicher fuer kleine Uploadteile.
 *
 * Ein Chunk ist ein Teilstueck des JPEGs. Bei einem Netzabbruch kann der
 * Sender fehlende Teile einzeln wiederholen, statt das ganze Bild neu zu senden.
 * Eine feste Upload-ID ordnet die Teile demselben Bild zu. Das Manifest ist
 * dessen Beschreibung mit Groesse, Zeit und Pruefsummen (SHA256-Fingerabdruecken).
 * Dateisperren und Vergleiche verhindern, dass gleichzeitige oder widerspruechliche
 * Anfragen Teile verschiedener Bilder vermischen.
 *
 * acceptChunk nimmt einzelne Teile an; finalize setzt sie zusammen und uebergibt
 * die Datei an UploadIngestor. Ein bestaetigter Teil ist noch kein bestaetigtes
 * Kamerabild! Erst die erfolgreiche abschliessende Verarbeitung liefert das
 * Bild-ACK und erlaubt, den temporaeren Upload-Zwischenspeicher zu entfernen.
 */
final class ChunkUploadStore
{
  public const PROTOCOL = 'camera-rpi-chunk-v1';
  public const MIN_CHUNK_BYTES = 65536;
  public const MAX_CHUNK_BYTES = 1048576;
  public const PREFERRED_CHUNK_BYTES = 262144;
  private const MAX_ACTIVE_UPLOADS_PER_CAMERA = 32;
  private const STALE_SECONDS = 21600;
  private const FREE_SPACE_RESERVE = 52428800;

  public function __construct(
    private readonly App $app,
    private readonly UploadIngestor $ingestor
  ) {}

  /** @return array{status:int,payload:array<string,mixed>} */
  public function capabilities(): array
  {
    return [
      'status' => 200,
      'payload' => [
        'ok' => true,
        'protocol' => self::PROTOCOL,
        'preferred_chunk_bytes' => self::PREFERRED_CHUNK_BYTES,
        'min_chunk_bytes' => self::MIN_CHUNK_BYTES,
        'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
        'max_upload_bytes' => $this->ingestor->maxUploadBytes(),
      ],
    ];
  }

  /**
   * @param array<string,mixed> $post
   * @param array<string,mixed>|null $upload
   * @return array{status:int,payload:array<string,mixed>}
   */
  public function acceptChunk(Camera $camera, array $post, ?array $upload): array
  {
    $metadataResult = $this->parseMetadata($camera, $post);
    if (isset($metadataResult['status'])) {
      return $metadataResult;
    }
    $metadata = $metadataResult;
    $indexRaw = $post['chunk_index'] ?? null;
    $chunkHash = $post['chunk_sha256'] ?? null;
    if (!is_string($indexRaw) || !preg_match('/^[0-9]{1,4}$/D', $indexRaw)) {
      return $this->error(400, 'invalid chunk index');
    }
    $index = (int) $indexRaw;
    if ($index < 0 || $index >= $metadata['total_chunks']) {
      return $this->error(400, 'chunk index out of range');
    }
    if (!is_string($chunkHash) || !preg_match('/^[a-f0-9]{64}$/D', $chunkHash)) {
      return $this->error(400, 'invalid chunk hash');
    }
    if ($upload === null) {
      return $this->error(400, 'missing chunk');
    }
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
      return $this->error(400, UploadIngestor::uploadErrorMessage($uploadError));
    }
    $temporaryName = $upload['tmp_name'] ?? null;
    if (!is_string($temporaryName) || !is_uploaded_file($temporaryName)) {
      return $this->error(400, 'missing chunk');
    }

    $expectedSize = $index === $metadata['total_chunks'] - 1
      ? $metadata['total_size'] - ($index * $metadata['chunk_size'])
      : $metadata['chunk_size'];
    $actualSize = filesize($temporaryName);
    if ($actualSize === false || $actualSize !== $expectedSize) {
      return $this->error(400, 'chunk size mismatch');
    }
    $actualHash = hash_file('sha256', $temporaryName);
    if (!is_string($actualHash) || !hash_equals($chunkHash, $actualHash)) {
      return $this->error(422, 'chunk hash mismatch');
    }

    $base = $this->cameraBase($camera);
    $this->cleanupStale($base);
    $free = disk_free_space($base);
    if ($free !== false && $free < $expectedSize + self::FREE_SPACE_RESERVE) {
      return $this->error(507, 'chunk storage full');
    }
    $lock = $this->lockUpload($base, $metadata['upload_id']);
    if ($lock === null) {
      return $this->error(503, 'chunk lock unavailable');
    }

    try {
      $dir = $base . DIRECTORY_SEPARATOR . $metadata['upload_id'];
      if (!is_dir($dir)) {
        // Upload-Sperren schuetzen einzelne IDs; erst diese gemeinsame Sperre
        // macht Zaehlen und Anlegen gegen parallele neue IDs atomar.
        $admission = $this->lockFile($base . '/.admission.lock');
        if ($admission === null) {
          return $this->error(503, 'chunk admission busy');
        }
        try {
          if ($this->activeUploadCount($base) >= self::MAX_ACTIVE_UPLOADS_PER_CAMERA) {
            return $this->error(429, 'too many active chunk uploads');
          }
          if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
            return $this->error(500, 'chunk directory unavailable');
          }
        } finally {
          $this->unlock($admission);
        }
      }
      $manifestError = $this->ensureManifest($dir, $metadata);
      if ($manifestError !== null) {
        return $manifestError;
      }

      $target = $dir . DIRECTORY_SEPARATOR . sprintf('%06d.part', $index);
      if (is_file($target)) {
        $existingHash = hash_file('sha256', $target);
        $existingSize = filesize($target);
        if (is_string($existingHash) && hash_equals($chunkHash, $existingHash) && $existingSize === $expectedSize) {
          touch($dir);
          return $this->chunkAccepted($metadata['upload_id'], $index, true);
        }
        return $this->error(409, 'existing chunk conflicts');
      }

      $partial = $target . '.upload';
      if (!move_uploaded_file($temporaryName, $partial) || !rename($partial, $target)) {
        @unlink($partial);
        return $this->error(500, 'chunk store failed');
      }
      chmod($target, 0660);
      touch($dir);
      return $this->chunkAccepted($metadata['upload_id'], $index, false);
    } finally {
      $this->unlock($lock);
    }
  }

  /**
   * @param array<string,mixed> $post
   * @return array{status:int,payload:array<string,mixed>}
   */
  public function finalize(Camera $camera, array $post): array
  {
    $metadataResult = $this->parseMetadata($camera, $post);
    if (isset($metadataResult['status'])) {
      return $metadataResult;
    }
    $metadata = $metadataResult;
    $base = $this->cameraBase($camera);
    $lock = $this->lockUpload($base, $metadata['upload_id']);
    if ($lock === null) {
      return $this->error(503, 'chunk lock unavailable');
    }

    try {
      $dir = $base . DIRECTORY_SEPARATOR . $metadata['upload_id'];
      if (!is_dir($dir)) {
        return $this->error(409, 'chunk upload not started');
      }
      $manifestError = $this->ensureManifest($dir, $metadata, false);
      if ($manifestError !== null) {
        return $manifestError;
      }
      $free = disk_free_space($base);
      if ($free !== false && $free < $metadata['total_size'] + self::FREE_SPACE_RESERVE) {
        return $this->error(507, 'chunk storage full');
      }

      $assembled = $dir . DIRECTORY_SEPARATOR . 'assembled.upload';
      $output = @fopen($assembled, 'wb');
      if ($output === false) {
        return $this->error(500, 'assembly unavailable');
      }
      $assemblyOk = true;
      try {
        for ($index = 0; $index < $metadata['total_chunks']; $index++) {
          $part = $dir . DIRECTORY_SEPARATOR . sprintf('%06d.part', $index);
          $expectedSize = $index === $metadata['total_chunks'] - 1
            ? $metadata['total_size'] - ($index * $metadata['chunk_size'])
            : $metadata['chunk_size'];
          if (!is_file($part) || filesize($part) !== $expectedSize) {
            $assemblyOk = false;
            break;
          }
          $input = @fopen($part, 'rb');
          if ($input === false || stream_copy_to_stream($input, $output) !== $expectedSize) {
            if (is_resource($input)) {
              fclose($input);
            }
            $assemblyOk = false;
            break;
          }
          fclose($input);
        }
      } finally {
        fclose($output);
      }
      if (!$assemblyOk) {
        @unlink($assembled);
        return $this->error(409, 'chunks incomplete');
      }
      $assembledSize = filesize($assembled);
      $assembledHash = hash_file('sha256', $assembled);
      if (
        $assembledSize !== $metadata['total_size']
        || !is_string($assembledHash)
        || !hash_equals($metadata['sha256'], $assembledHash)
      ) {
        @unlink($assembled);
        return $this->error(422, 'assembled hash mismatch');
      }
      // Der gemeinsame Ingest-Weg arbeitet direkt mit der fertigen Datei. So
      // bleibt nicht zusätzlich zum GD-Bild ein vollständiger JPEG-String im
      // PHP-Speicher. Die geprüften Parts bleiben bei Fehlern wiederholbar.
      $result = $this->ingestor->ingestFile($camera, $assembled, $metadata['timestamp'], $post['local_weather'] ?? null, $metadata['captured_at'] ?? null);
      @unlink($assembled);
      if ($result['status'] >= 200 && $result['status'] < 300 && ($result['payload']['ok'] ?? false) === true) {
        $result['payload']['protocol'] = self::PROTOCOL;
        $result['payload']['upload_id'] = $metadata['upload_id'];
        $result['payload']['chunks'] = $metadata['total_chunks'];
        $this->removeTree($dir);
      }
      return $result;
    } finally {
      $this->unlock($lock);
    }
  }

  /**
   * @param array<string,mixed> $post
   * @return array{upload_id:string,timestamp:int,total_size:int,chunk_size:int,total_chunks:int,sha256:string}|array{status:int,payload:array<string,mixed>}
   */
  private function parseMetadata(Camera $camera, array $post): array
  {
    $uploadId = $post['upload_id'] ?? null;
    $timestampRaw = $post['now'] ?? null;
    $totalSizeRaw = $post['total_size'] ?? null;
    $chunkSizeRaw = $post['chunk_size'] ?? null;
    $totalChunksRaw = $post['total_chunks'] ?? null;
    $sha256 = $post['sha256'] ?? null;
    if (!is_string($uploadId) || !preg_match('/^[a-f0-9]{32}$/D', $uploadId)) {
      return $this->error(400, 'invalid upload id');
    }
    if (!is_string($timestampRaw) || !preg_match('/^[0-9]{9,12}$/D', $timestampRaw)) {
      return $this->error(400, 'invalid timestamp');
    }
    foreach ([$totalSizeRaw, $chunkSizeRaw, $totalChunksRaw] as $number) {
      if (!is_string($number) || !preg_match('/^[0-9]{1,10}$/D', $number)) {
        return $this->error(400, 'invalid chunk metadata');
      }
    }
    if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
      return $this->error(400, 'invalid upload hash');
    }
    $expectedUploadId = substr(hash('sha256', $camera->slug() . ':' . $timestampRaw . ':' . $sha256), 0, 32);
    if (!hash_equals($expectedUploadId, $uploadId)) {
      return $this->error(400, 'upload id mismatch');
    }
    $totalSize = (int) $totalSizeRaw;
    $chunkSize = (int) $chunkSizeRaw;
    $totalChunks = (int) $totalChunksRaw;
    $timestamp = (int) $timestampRaw;
    if ($timestamp < 946684800 || $timestamp > time() + 86400) {
      return $this->error(400, 'timestamp out of range');
    }
    if ($totalSize < 128 || $totalSize > $this->ingestor->maxUploadBytes()) {
      return $this->error(413, 'upload size out of range');
    }
    if ($chunkSize < self::MIN_CHUNK_BYTES || $chunkSize > self::MAX_CHUNK_BYTES) {
      return $this->error(400, 'chunk size out of range');
    }
    $expectedChunks = (int) ceil($totalSize / $chunkSize);
    if ($totalChunks !== $expectedChunks || $totalChunks < 1 || $totalChunks > 512) {
      return $this->error(400, 'chunk count mismatch');
    }
    $metadata = [
      'upload_id' => $uploadId,
      'timestamp' => $timestamp,
      'total_size' => $totalSize,
      'chunk_size' => $chunkSize,
      'total_chunks' => $totalChunks,
      'sha256' => $sha256,
    ];
    if (array_key_exists('captured_at', $post)) {
      try { $metadata['captured_at'] = $this->ingestor->parseCapturedAt($post['captured_at']); }
      catch (\InvalidArgumentException) { return $this->error(400, 'invalid captured_at'); }
    }
    return $metadata;

  }

  private function cameraBase(Camera $camera): string
  {
    $root = $this->app->paths()->statePath('upload-chunks');
    $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($camera->slug())) ?: 'camera';
    $base = $root . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) {
      throw new \RuntimeException('chunk base unavailable');
    }
    return $base;
  }

  /**
   * @param array{upload_id:string,timestamp:int,total_size:int,chunk_size:int,total_chunks:int,sha256:string} $metadata
   * @return array{status:int,payload:array<string,mixed>}|null
   */
  private function ensureManifest(string $dir, array $metadata, bool $create = true): ?array
  {
    $path = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($path)) {
      if (!$create) {
        return $this->error(409, 'chunk manifest missing');
      }
      $temporary = $path . '.tmp';
      $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        return $this->error(500, 'chunk manifest store failed');
      }
      chmod($path, 0660);
      return null;
    }
    $stored = json_decode((string) file_get_contents($path), true);
    if (!is_array($stored) || $stored !== $metadata) {
      return $this->error(409, 'chunk manifest conflicts');
    }
    return null;
  }

  /** @return resource|null */
  private function lockUpload(string $base, string $uploadId)
  {
    $path = $this->lockPath($base, $uploadId);
    return $this->lockFile($path);
  }

  /**
   * Nicht auf belegte Locks warten: Der HTTP-Aufrufer kann spaeter wiederholen.
   * @return resource|null
   */
  private function lockFile(string $path)
  {
    $handle = @fopen($path, 'c');
    if ($handle === false) {
      return null;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return null;
    }
    return $handle;
  }

  private function lockPath(string $base, string $uploadId): string
  {
    // Upload-IDs auf 64 feste Sperrdateien je Kamera verteilen. Diese Dateien
    // nicht loeschen: Ein anderer Prozess koennte dieselbe Datei noch geoeffnet
    // haben und sonst eine neu angelegte Sperre gleichen Namens nicht bemerken.
    return $base . DIRECTORY_SEPARATOR . sprintf('.upload-%02x.lock', hexdec(substr($uploadId, 0, 2)) % 64);
  }

  /** @param resource $lock */
  private function unlock($lock): void
  {
    flock($lock, LOCK_UN);
    fclose($lock);
  }

  private function activeUploadCount(string $base): int
  {
    $count = 0;
    foreach (scandir($base) ?: [] as $entry) {
      if (preg_match('/^[a-f0-9]{32}$/D', $entry) && is_dir($base . DIRECTORY_SEPARATOR . $entry)) {
        $count++;
      }
    }
    return $count;
  }

  private function cleanupStale(string $base): void
  {
    $cutoff = time() - self::STALE_SECONDS;
    foreach (scandir($base) ?: [] as $entry) {
      if (!preg_match('/^[a-f0-9]{32}$/D', $entry)) {
        continue;
      }
      $dir = $base . DIRECTORY_SEPARATOR . $entry;
      $modified = @filemtime($dir);
      if (!is_dir($dir) || $modified === false || $modified >= $cutoff) {
        continue;
      }
      $lockPath = $this->lockPath($base, $entry);
      $lock = @fopen($lockPath, 'c');
      if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
          fclose($lock);
        }
        continue;
      }
      try {
        clearstatcache(true, $dir);
        // Der erste Alterscheck lag vor dem Lock; ein Upload kann inzwischen
        // neue Teile geschrieben haben und darf dann nicht entsorgt werden.
        $modified = @filemtime($dir);
        if ($modified !== false && $modified < $cutoff) {
          $this->removeTree($dir);
        }
      } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
      }
    }
  }

  private function removeTree(string $path): void
  {
    if (!is_dir($path)) {
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $child = $path . DIRECTORY_SEPARATOR . $entry;
      if (is_dir($child) && !is_link($child)) {
        $this->removeTree($child);
      } else {
        @unlink($child);
      }
    }
    @rmdir($path);
  }

  /** @return array{status:int,payload:array<string,mixed>} */
  private function chunkAccepted(string $uploadId, int $index, bool $duplicate): array
  {
    return [
      'status' => 200,
      'payload' => [
        'ok' => true,
        'protocol' => self::PROTOCOL,
        'upload_id' => $uploadId,
        'chunk_index' => $index,
        'duplicate' => $duplicate,
      ],
    ];
  }

  /** @return array{status:int,payload:array<string,mixed>} */
  private function error(int $status, string $error): array
  {
    return ['status' => $status, 'payload' => ['ok' => false, 'error' => $error]];
  }
}
