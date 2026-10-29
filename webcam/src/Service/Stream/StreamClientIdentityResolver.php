<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\App;
use Webcam\Infrastructure\Http\ClientIpResolver;

/**
 * Normalisiert die Identität und Bezeichner von Stream-Clients.
 *
 * Forwarded-Header werden ausschließlich über ClientIpResolver und die
 * konfigurierte Proxy-Allowlist ausgewertet. Dieselben Validatoren schützen
 * anschließend Dateinamen für Token, Slug und Disconnect-Marker.
 */
final class StreamClientIdentityResolver
{
  public function __construct(private readonly App $app) {}

  /**
   * @return array{ip:string,ip_source:string,remote_addr:string,forwarded_for:string,user_agent:string}
   */
  public function resolve(array $server): array
  {
    return (new ClientIpResolver($this->trustedProxies()))->resolve($server);
  }

  public function normalizeIp(string $ip): string
  {
    $ip = trim($ip);
    if ($ip === '') {
      return '';
    }
    $valid = filter_var($ip, FILTER_VALIDATE_IP);
    return is_string($valid) ? $valid : '';
  }

  public function requireIp(string $ip): string
  {
    $ip = $this->normalizeIp($ip);
    if ($ip === '') {
      throw new \InvalidArgumentException('Invalid IP address');
    }
    return $ip;
  }

  public function normalizeToken(string $token): string
  {
    $token = trim($token);
    return preg_match('/^[A-Za-z0-9_-]{4,80}$/', $token) === 1 ? $token : '';
  }

  public function requireToken(string $token): string
  {
    $token = $this->normalizeToken($token);
    if ($token === '') {
      throw new \InvalidArgumentException('Invalid stream token');
    }
    return $token;
  }

  public function normalizeSlug(string $slug): string
  {
    $slug = trim($slug);
    return preg_match('/^[a-z0-9-]{1,80}$/', $slug) === 1 ? $slug : '';
  }

  public function sanitizeHeader(string $value, int $maxLength): string
  {
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
    $value = trim($value);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
  }

  /** @return list<string> */
  private function trustedProxies(): array
  {
    $configured = $this->app->config('trusted_proxies', []);
    if (!is_array($configured)) {
      return ['127.0.0.1', '::1'];
    }
    $proxies = [];
    foreach ($configured as $proxy) {
      $proxy = trim((string) $proxy);
      if ($proxy !== '') {
        $proxies[] = $proxy;
      }
    }
    return $proxies ?: ['127.0.0.1', '::1'];
  }
}
