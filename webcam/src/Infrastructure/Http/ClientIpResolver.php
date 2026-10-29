<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Http;

/**
 * Ermittelt die Client-IP entlang einer explizit vertrauten Proxy-Kette.
 *
 * Die Kette wird von REMOTE_ADDR aus rückwärts gelesen. Dadurch kann ein
 * vorgeschalteter, aber nicht vertrauenswürdiger X-Forwarded-For-Wert keinen
 * weiter links stehenden Wert als Client einschleusen.
 */
final class ClientIpResolver
{
  private const MAX_FORWARDED_BYTES = 8192;
  /** @param list<string> $trustedProxies */
  public function __construct(private readonly array $trustedProxies) {}

  /**
   * @return array{ip:string,ip_source:string,remote_addr:string,forwarded_for:string,user_agent:string}
   */
  public function resolve(array $server): array
  {
    $remoteAddr = $this->normalizeIp((string) ($server['REMOTE_ADDR'] ?? ''));
    $rawForwardedFor = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
    // Die Vertrauenskette niemals kürzen: Rechts steht der vom Proxy ergänzte
    // Client. Nur der davon getrennte Diagnosewert darf abgeschnitten werden.
    $forwardedFor = strlen($rawForwardedFor) <= self::MAX_FORWARDED_BYTES
      && !preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $rawForwardedFor)
      ? trim($rawForwardedFor) : '';
    $clientIp = $remoteAddr;
    $source = $clientIp !== '' ? 'remote_addr' : '';

    if ($clientIp !== '' && $this->isTrustedProxy($clientIp) && $forwardedFor !== '') {
      $chain = [];
      foreach (explode(',', $forwardedFor) as $value) {
        $ip = $this->normalizeIp($value);
        if ($ip === '') {
          $chain = [];
          break;
        }
        $chain[] = $ip;
      }
      if ($chain !== []) {
        for ($index = count($chain) - 1; $index >= 0; $index--) {
          if (!$this->isTrustedProxy($clientIp)) {
            break;
          }
          $clientIp = $chain[$index];
          $source = 'x_forwarded_for';
        }
      }
    }

    return [
      'ip' => $clientIp,
      'ip_source' => $source,
      'remote_addr' => $remoteAddr,
      'forwarded_for' => $this->sanitizeHeader($rawForwardedFor, 300),
      'user_agent' => $this->sanitizeHeader((string) ($server['HTTP_USER_AGENT'] ?? ''), 200),
    ];
  }

  private function normalizeIp(string $ip): string
  {
    $valid = filter_var(trim($ip), FILTER_VALIDATE_IP);
    if (!is_string($valid)) {
      return '';
    }
    $packed = inet_pton($valid);
    $canonical = $packed !== false ? inet_ntop($packed) : false;
    return is_string($canonical) ? $canonical : '';
  }

  private function isTrustedProxy(string $ip): bool
  {
    foreach ($this->trustedProxies as $proxy) {
      if (\webcam_ip_matches_proxy($ip, (string) $proxy)) {
        return true;
      }
    }
    return false;
  }

  private function sanitizeHeader(string $value, int $maxLength): string
  {
    $value = str_replace(["\r", "\n", "\0"], '', trim($value));
    return substr($value, 0, $maxLength);
  }
}
