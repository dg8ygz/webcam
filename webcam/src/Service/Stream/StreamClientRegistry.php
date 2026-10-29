<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

/**
 * Registriert und findet aktive MJPEG- und HLS-Zuschauer.
 *
 * MJPEG-Aktivität wird weiterhin über den tatsächlich gehaltenen flock
 * erkannt. HLS besitzt keine Dauerverbindung und verwendet deshalb frische
 * JSON-Heartbeats. Sperren und Disconnect-Entscheidungen werden erst von der
 * StreamState-Fassade ergänzt.
 */
final class StreamClientRegistry
{
  private const HLS_ACTIVE_SECONDS = 45;
  private const HLS_KEEP_SECONDS = 300;

  public function __construct(
    private readonly StreamRuntimeStore $store,
    private readonly StreamClientIdentityResolver $identity
  ) {}

  /** @return array<string,mixed> */
  public function mjpegMetadata(string $slug, int $slot, int $maxClients, array $server): array
  {
    $identity = $this->identity->resolve($server);
    $now = time();
    return [
      'kind' => 'mjpeg', 'camera' => $slug, 'slot' => $slot,
      'token' => 'mjpeg-' . bin2hex(random_bytes(8)), 'pid' => getmypid(),
      'started_at' => $now, 'last_seen_at' => $now, 'max_clients' => $maxClients,
      'ip' => $identity['ip'], 'ip_source' => $identity['ip_source'],
      'remote_addr' => $identity['remote_addr'], 'forwarded_for' => $identity['forwarded_for'],
      'user_agent' => $identity['user_agent'],
    ];
  }

  /** @return array<string,mixed> */
  public function registerHlsRequest(string $slug, array $server): array
  {
    $identity = $this->identity->resolve($server);
    $token = 'hls-' . substr(hash('sha256', $slug . "\0" . $identity['ip'] . "\0" . $identity['user_agent']), 0, 24);
    $now = time();
    $path = $this->hlsClientPath($slug, $token);
    $existing = $this->store->readJson($path);
    $startedAt = (int) ($existing['started_at'] ?? 0);
    $metadata = [
      'kind' => 'hls', 'camera' => $slug, 'token' => $token, 'pid' => null,
      'started_at' => $startedAt > 0 ? $startedAt : $now, 'last_seen_at' => $now,
      'ip' => $identity['ip'], 'ip_source' => $identity['ip_source'],
      'remote_addr' => $identity['remote_addr'], 'forwarded_for' => $identity['forwarded_for'],
      'user_agent' => $identity['user_agent'],
    ];
    $this->store->writeJson($path, $metadata);
    $this->cleanupHlsClients($slug);
    return $metadata;
  }

  /** @return list<array<string,mixed>> */
  public function activeClients(): array
  {
    $now = time();
    $clients = array_merge($this->activeMjpegClients($now), $this->activeHlsClients($now));
    usort($clients, static function (array $a, array $b): int {
      return strcmp((string) ($a['camera'] ?? ''), (string) ($b['camera'] ?? ''))
        ?: strcmp((string) ($a['kind'] ?? ''), (string) ($b['kind'] ?? ''))
        ?: ((int) ($a['started_at'] ?? 0) <=> (int) ($b['started_at'] ?? 0));
    });
    return $clients;
  }

  /** @return list<array<string,mixed>> */
  private function activeMjpegClients(int $now): array
  {
    $base = $this->store->path('stream');
    $clients = [];
    foreach (is_dir($base) ? (glob($base . '/*/*.lock') ?: []) : [] as $path) {
      $handle = is_file($path) ? @fopen($path, 'c') : false;
      if (!is_resource($handle)) {
        continue;
      }
      $lockedByOther = !@flock($handle, LOCK_EX | LOCK_NB);
      if (!$lockedByOther) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        continue;
      }
      fclose($handle);
      $client = $this->store->readJson($path) ?? [];
      $fallback = !isset($client['kind'], $client['camera'], $client['token']);
      $mtime = (int) @filemtime($path);
      $defaultTimestamp = $fallback || $mtime <= 0 ? $now : $mtime;
      $client += [
        'kind' => 'mjpeg', 'camera' => basename(dirname($path)),
        'slot' => (int) basename($path, '.lock'),
        'started_at' => $defaultTimestamp, 'last_seen_at' => $defaultTimestamp,
      ];
      $client['metadata_fallback'] = $fallback;
      $clients[] = $this->withAges($client, $now);
    }
    return $clients;
  }

  /** @return list<array<string,mixed>> */
  private function activeHlsClients(int $now): array
  {
    $base = $this->store->path('stream-hls');
    $clients = [];
    foreach (is_dir($base) ? (glob($base . '/*/clients/*.json') ?: []) : [] as $path) {
      $client = is_file($path) ? $this->store->readJson($path) : null;
      if (!$client) {
        continue;
      }
      $age = $now - (int) ($client['last_seen_at'] ?? 0);
      if ($age > self::HLS_KEEP_SECONDS) {
        @unlink($path);
        continue;
      }
      if ($age <= self::HLS_ACTIVE_SECONDS) {
        $clients[] = $this->withAges($client, $now);
      }
    }
    return $clients;
  }

  /** @param array<string,mixed> $client @return array<string,mixed> */
  private function withAges(array $client, int $now): array
  {
    $started = (int) ($client['started_at'] ?? 0);
    $lastSeen = (int) ($client['last_seen_at'] ?? $started);
    $client['age_seconds'] = $started > 0 ? max(0, $now - $started) : null;
    $client['last_seen_age_seconds'] = $lastSeen > 0 ? max(0, $now - $lastSeen) : null;
    return $client;
  }

  private function cleanupHlsClients(string $slug): void
  {
    $directory = $this->store->path('stream-hls/' . $slug . '/clients');
    $now = time();
    foreach (is_dir($directory) ? (glob($directory . '/*.json') ?: []) : [] as $path) {
      if (is_file($path) && ($now - (int) filemtime($path)) > self::HLS_KEEP_SECONDS) {
        @unlink($path);
      }
    }
  }

  private function hlsClientPath(string $slug, string $token): string
  {
    return $this->store->path('stream-hls/' . $slug . '/clients/' . $this->identity->requireToken($token) . '.json');
  }
}
