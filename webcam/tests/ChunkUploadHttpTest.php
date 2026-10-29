<?php
declare(strict_types=1);

use Webcam\App;

final class ChunkUploadHttpTest extends WebcamHttpTestCase
{
  /** @var list<string> */
  private array $cleanupPaths = [];

  protected function tearDown(): void
  {
    foreach (array_reverse($this->cleanupPaths) as $path) {
      $this->removePath($path);
    }
    parent::tearDown();
  }

  public function testCapabilitiesRequireHeaderKeyAndAdvertiseExactProtocol(): void
  {
    [$slug, $key] = $this->chunkCamera();
    $body = http_build_query(['action' => 'capabilities', 'wc' => $slug]);

    [$missingStatus] = $this->request('/include/upload-chunk.php', 'POST', [
      'Content-Type: application/x-www-form-urlencoded',
    ], $body);
    $this->assertSame(403, $missingStatus);

    [$postKeyStatus] = $this->request('/include/upload-chunk.php', 'POST', [
      'Content-Type: application/x-www-form-urlencoded',
    ], $body . '&key=' . rawurlencode($key));
    $this->assertSame(403, $postKeyStatus, 'Chunk-v1 must not accept a form key.');

    [$status, $response, $headers] = $this->request('/include/upload-chunk.php', 'POST', [
      'Content-Type: application/x-www-form-urlencoded',
      'X-Upload-Key: ' . $key,
    ], $body);
    $decoded = json_decode($response, true);

    $this->assertSame(200, $status, $response);
    $this->assertIsArray($decoded);
    $this->assertTrue($decoded['ok'] ?? false);
    $this->assertSame('camera-rpi-chunk-v1', $decoded['protocol'] ?? null);
    $this->assertSame(262144, $decoded['preferred_chunk_bytes'] ?? null);
    $this->assertContains('Cache-Control: no-store', $headers);
  }

  public function testAuthenticatedChunkIsStoredAndIdenticalRetryIsIdempotent(): void
  {
    [$slug, $key] = $this->chunkCamera();
    $binary = str_repeat('chunk-test-', 64);
    $timestamp = (string) time();
    $hash = hash('sha256', $binary);
    $uploadId = substr(hash('sha256', $slug . ':' . $timestamp . ':' . $hash), 0, 32);
    $fields = [
      'action' => 'chunk',
      'wc' => $slug,
      'upload_id' => $uploadId,
      'now' => $timestamp,
      'total_size' => (string) strlen($binary),
      'chunk_size' => '65536',
      'total_chunks' => '1',
      'sha256' => $hash,
      'chunk_index' => '0',
      'chunk_sha256' => $hash,
    ];
    $boundary = 'webcam-test-' . bin2hex(random_bytes(12));
    $body = $this->multipartBody($boundary, $fields, $binary);

    /** @var App $app */
    $app = require __DIR__ . '/../bootstrap.php';
    $base = $app->paths()->statePath('upload-chunks/' . $slug);
    $this->cleanupPaths[] = $base . '/' . $uploadId;
    $this->cleanupPaths[] = $base . '/' . $uploadId . '.lock';

    [$firstStatus, $firstResponse] = $this->request('/include/upload-chunk.php', 'POST', [
      'Content-Type: multipart/form-data; boundary=' . $boundary,
      'X-Upload-Key: ' . $key,
    ], $body);
    $first = json_decode($firstResponse, true);
    $this->assertSame(200, $firstStatus, $firstResponse);
    $this->assertIsArray($first);
    $this->assertTrue($first['ok'] ?? false);
    $this->assertFalse($first['duplicate'] ?? true);

    [$retryStatus, $retryResponse] = $this->request('/include/upload-chunk.php', 'POST', [
      'Content-Type: multipart/form-data; boundary=' . $boundary,
      'X-Upload-Key: ' . $key,
    ], $body);
    $retry = json_decode($retryResponse, true);
    $this->assertSame(200, $retryStatus);
    $this->assertIsArray($retry);
    $this->assertTrue($retry['duplicate'] ?? false);
  }

  public function testLegacyMultipartIsAcknowledgedButIgnoredWhilePullIsPrimary(): void
  {
    [$slug, $key] = $this->chunkCamera();
    /** @var App $app */
    $app = require __DIR__ . '/../bootstrap.php';
    $camera = $app->cameraOrFail($slug);
    $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
    $timestamp = intdiv(time(), $interval) * $interval;
    $safeSlug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?: 'camera';
    $this->cleanupPaths[] = $app->paths()->statePath(
      'upload-dedupe/' . $safeSlug . '-bucket-' . (hexdec(substr(hash('sha256', (string) $timestamp), 0, 2)) % 64) . '.lock'
    );
    $boundary = 'webcam-legacy-test-' . bin2hex(random_bytes(12));
    $body = $this->multipartBody($boundary, [
      'wc' => $slug,
      'key' => $key,
      'now' => (string) $timestamp,
    ], 'not-a-jpeg-but-a-complete-multipart-upload');

    [$status, $response] = $this->request('/include/upload.php', 'POST', [
      'Content-Type: multipart/form-data; boundary=' . $boundary,
    ], $body);
    $decoded = json_decode($response, true);

    $this->assertSame(200, $status, $response);
    $this->assertIsArray($decoded);
    $this->assertTrue($decoded['ok'] ?? false);
    $this->assertTrue($decoded['ignored'] ?? false);
    $this->assertFalse($decoded['accepted'] ?? true);
    $this->assertSame('pull_snapshot_primary', $decoded['reason'] ?? null);
  }

  /** @param array<string,string> $fields */
  private function multipartBody(string $boundary, array $fields, string $binary): string
  {
    $body = '';
    foreach ($fields as $name => $value) {
      $body .= '--' . $boundary . "\r\n";
      $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
      $body .= $value . "\r\n";
    }
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Disposition: form-data; name=\"upload\"; filename=\"chunk.bin\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $binary . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    return $body;
  }

  /** @return array{0:string,1:string} */
  private function chunkCamera(): array
  {
    /** @var App $app */
    $app = require __DIR__ . '/../bootstrap.php';
    foreach ($app->cameras()->all() as $camera) {
      $key = (string) ($camera->capture()['upload_key'] ?? '');
      if ($key !== '') {
        return [$camera->slug(), $key];
      }
    }
    $this->fail('No fixture camera with upload key available.');
  }

  private function removePath(string $path): void
  {
    if (is_dir($path) && !is_link($path)) {
      foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
          $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
      }
      @rmdir($path);
      return;
    }
    @unlink($path);
  }
}
