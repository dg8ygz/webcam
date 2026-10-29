<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\App;

/**
 * Öffentliche Fassade für den kurzlebigen Zustand aller Stream-Zuschauer.
 *
 * Leseführung:
 * 1. StreamClientIdentityResolver wertet vertrauenswürdige Clientdaten aus.
 * 2. StreamClientRegistry registriert und findet MJPEG-/HLS-Zuschauer.
 * 3. StreamBlocklist besitzt ausschließlich die persistierte IP-Sperrliste.
 * 4. Diese Fassade koordiniert administrative Disconnect-Marker zwischen ihnen.
 *
 * Bestehende Aufrufer behalten damit dieselbe API und dieselben Runtime-Pfade.
 */
final class StreamState
{
  private const DISCONNECT_SECONDS = 600;
  private const IDENTITY_DISCONNECT_SECONDS = 30;

  private readonly StreamRuntimeStore $store;
  private readonly StreamClientIdentityResolver $identity;
  private readonly StreamClientRegistry $clients;
  private readonly StreamBlocklist $blocklist;

  public function __construct(private readonly App $app)
  {
    $this->store = new StreamRuntimeStore($app);
    $this->identity = new StreamClientIdentityResolver($app);
    $this->clients = new StreamClientRegistry($this->store, $this->identity);
    $this->blocklist = new StreamBlocklist($this->store, $this->identity);
  }

  /** @return array{ip:string,ip_source:string,remote_addr:string,forwarded_for:string,user_agent:string} */
  public function clientIdentity(array $server): array
  {
    return $this->identity->resolve($server);
  }

  /** @return array<string,mixed> */
  public function mjpegMetadata(string $slug, int $slot, int $maxClients, array $server): array
  {
    return $this->clients->mjpegMetadata($slug, $slot, $maxClients, $server);
  }

  /** @return array<string,mixed> */
  public function registerHlsRequest(string $slug, array $server): array
  {
    return $this->clients->registerHlsRequest($slug, $server);
  }

  public function viewerCount(string $slug): int
  {
    $count = 0;
    foreach ($this->activeClients() as $client) {
      if (($client['camera'] ?? '') === $slug) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Ergänzt die vom Registry gelesenen Clients um administrative Zustände.
   *
   * @return list<array<string,mixed>>
   */
  public function activeClients(): array
  {
    $clients = $this->clients->activeClients();
    foreach ($clients as &$client) {
      $ip = (string) ($client['ip'] ?? '');
      $token = (string) ($client['token'] ?? '');
      $client['blocked'] = $ip !== '' && $this->blocklist->contains($ip);
      $client['disconnect_requested'] = $token !== '' && $this->disconnectRequested($token);
    }
    unset($client);
    return $clients;
  }

  /** @return list<array<string,mixed>> */
  public function blockedIps(): array
  {
    return $this->blocklist->all();
  }

  public function isBlockedIp(string $ip): bool
  {
    return $this->blocklist->contains($ip);
  }

  public function blockIp(string $ip, string $note = ''): void
  {
    $normalizedIp = $this->blocklist->block($ip, $note);
    $this->disconnectIp($normalizedIp);
  }

  public function unblockIp(string $ip): void
  {
    $this->blocklist->unblock($ip);
  }

  /** Markiert den konkreten Viewer und kurzzeitig auch seine Identität. */
  public function disconnectToken(string $token): void
  {
    $token = $this->identity->requireToken($token);
    $client = $this->activeClientByToken($token);
    $this->store->writeJson($this->disconnectPath($token), [
      'token' => $token,
      'created_at' => time(),
    ]);
    if ($client !== null) {
      $this->writeIdentityDisconnect($client);
    }
  }

  /** Trennt alle aktuell bekannten Viewer einer normalisierten IP. */
  public function disconnectIp(string $ip): int
  {
    $ip = $this->identity->requireIp($ip);
    $count = 0;
    foreach ($this->activeClients() as $client) {
      if (($client['ip'] ?? '') !== $ip || empty($client['token'])) {
        continue;
      }
      $this->disconnectToken((string) $client['token']);
      $count++;
    }
    return $count;
  }

  public function disconnectRequested(string $token): bool
  {
    $token = $this->identity->normalizeToken($token);
    if ($token === '') {
      return false;
    }
    return $this->freshMarker($this->disconnectPath($token), self::DISCONNECT_SECONDS);
  }

  /**
   * Verhindert, dass ein gerade getrennter MJPEG-Client sofort als HLS
   * reconnectet. Der Marker ist deshalb absichtlich viel kürzer gültig.
   */
  public function identityDisconnectRequested(string $slug, array $server): bool
  {
    $identity = $this->identity->resolve($server);
    if ($identity['ip'] === '') {
      return false;
    }
    return $this->freshMarker(
      $this->identityDisconnectPath($slug, $identity),
      self::IDENTITY_DISCONNECT_SECONDS
    );
  }

  public function clearDisconnect(string $token): void
  {
    $token = $this->identity->normalizeToken($token);
    if ($token !== '') {
      @unlink($this->disconnectPath($token));
    }
  }

  /** @return array<string,mixed>|null */
  private function activeClientByToken(string $token): ?array
  {
    foreach ($this->activeClients() as $client) {
      if ((string) ($client['token'] ?? '') === $token) {
        return $client;
      }
    }
    return null;
  }

  /** @param array<string,mixed> $client */
  private function writeIdentityDisconnect(array $client): void
  {
    $slug = $this->identity->normalizeSlug((string) ($client['camera'] ?? ''));
    $ip = $this->identity->normalizeIp((string) ($client['ip'] ?? ''));
    if ($slug === '' || $ip === '') {
      return;
    }
    $identity = [
      'ip' => $ip,
      'user_agent' => $this->identity->sanitizeHeader((string) ($client['user_agent'] ?? ''), 200),
    ];
    $this->store->writeJson($this->identityDisconnectPath($slug, $identity), [
      'camera' => $slug,
      'ip' => $ip,
      'user_agent' => $identity['user_agent'],
      'token' => (string) ($client['token'] ?? ''),
      'created_at' => time(),
    ]);
  }

  /** @param array<string,string> $identity */
  private function identityDisconnectPath(string $slug, array $identity): string
  {
    $slug = $this->identity->normalizeSlug($slug);
    if ($slug === '') {
      throw new \InvalidArgumentException('Invalid camera slug');
    }
    $key = hash('sha256', $slug . "\0" . ($identity['ip'] ?? '') . "\0" . ($identity['user_agent'] ?? ''));
    return $this->store->path('stream/disconnect-identities/' . $slug . '/' . $key . '.json');
  }

  private function disconnectPath(string $token): string
  {
    return $this->store->path('stream/disconnect/' . $this->identity->requireToken($token) . '.json');
  }

  private function freshMarker(string $path, int $maximumAge): bool
  {
    if (!is_file($path)) {
      return false;
    }
    if ((time() - (int) filemtime($path)) <= $maximumAge) {
      return true;
    }
    @unlink($path);
    return false;
  }
}
