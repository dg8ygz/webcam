<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Http;

use RuntimeException;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Minimaler HTTP-Client für Kamera- und Snapshot-Aufrufe.
 * Er erzwingt konfigurierte Verbindungs-/Gesamttimeouts und kapselt Status,
 * Header und Body in HttpResponse statt direkt HTTP-Ausgabe zu erzeugen.
 */
final class HttpClient
{
  public const MAX_TIMEOUT_SECONDS = 60;
  public const MAX_RESPONSE_BYTES = 64 * 1024 * 1024;

  /** Shared URL contract; credentials/internal camera hosts remain admin-only. */
  public static function isHttpUrl(string $url): bool
  {
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
      return false;
    }
    $parts = parse_url($url);
    return is_array($parts) && ($parts['host'] ?? '') !== ''
      && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
  }

  /**
   * @param array{timeout?:int, max_response_bytes?:int, headers?:array<string,string>, auth?:array{username:string,password:string}} $options
   */
  public function get(string $url, array $options = []): HttpResponse
  {
    return $this->request('GET', $url, $options);
  }

  /**
   * Fuehrt einen begrenzten HTTP(S)-Transfer aus; Budget umfasst auch Redirects.
   * Nicht-erfolgreiche HTTP-Status bleiben Antworten, Transport-/Budgetfehler werfen.
   * @param array{timeout?:int, max_response_bytes?:int, headers?:array<string,string>, auth?:array{username:string,password:string}, body?:string|array<string,mixed>} $options
   */
  public function request(string $method, string $url, array $options = []): HttpResponse
  {
    if (!self::isHttpUrl($url)) {
      throw new RuntimeException('HTTP URL must be an absolute http or https URL');
    }
    $timeout = array_key_exists('timeout', $options) ? $options['timeout'] : 10;
    if (!is_int($timeout) || $timeout < 1 || $timeout > self::MAX_TIMEOUT_SECONDS) {
      throw new RuntimeException('HTTP timeout must be an integer between 1 and 60 seconds');
    }
    $method = strtoupper(trim($method));
    $maxBytes = $options['max_response_bytes'] ?? 1024 * 1024;
    if (!is_int($maxBytes) || $maxBytes < 1 || $maxBytes > self::MAX_RESPONSE_BYTES) {
      throw new RuntimeException('HTTP response byte limit must be between 1 and 67108864');
    }
    if ($method === '') {
      throw new RuntimeException('HTTP method must not be empty');
    }

    $headerLines = [];
    $hasContentType = false;
    if (isset($options['headers']) && is_array($options['headers'])) {
      foreach ($options['headers'] as $key => $value) {
        $key = trim((string) $key);
        $value = trim((string) $value);
        if ($key === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $key) !== 1) {
          throw new RuntimeException('Invalid HTTP header name');
        }
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
          throw new RuntimeException("Invalid value for HTTP header {$key}");
        }
        $headerLines[] = "{$key}: {$value}";
        $hasContentType = $hasContentType || strcasecmp($key, 'Content-Type') === 0;
      }
    }
    if (isset($options['auth']) && is_array($options['auth'])) {
      $pair = $options['auth']['username'] . ':' . $options['auth']['password'];
      $headerLines[] = 'Authorization: Basic ' . base64_encode($pair);
    }
    if (isset($options['body']) && is_array($options['body']) && !$hasContentType) {
      $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    // Stream-wrapper timeouts only bound individual reads. cURL bounds the
    // whole transfer, including connect, slow bodies and all redirects.
    if (!function_exists('curl_init')) {
      throw new RuntimeException('HTTP client requires the PHP cURL extension');
    }
    $meta = [];
    $body = '';
    $receivedBytes = 0;
    $headerBytes = 0;
    $limitExceeded = false;
    $curlOptions = [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => false,
      // Auch Antworten ohne Content-Length werden während der Übertragung
      // begrenzt. Redirect-Bodys zählen zum Gesamtbudget, nicht nur das Ziel.
      CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$receivedBytes, &$limitExceeded, $maxBytes): int {
        $length = strlen($chunk);
        if ($length > $maxBytes - $receivedBytes) {
          $limitExceeded = true;
          return 0;
        }
        $receivedBytes += $length;
        $body .= $chunk;
        return $length;
      },
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      // Preserve the previous direct camera connection (no environment proxy).
      CURLOPT_PROXY => '',
      CURLOPT_HTTPHEADER => $headerLines,
      CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$meta, &$body, &$headerBytes, &$limitExceeded, $maxBytes, $method): int {
        $headerBytes += strlen($line);
        if ($headerBytes > 65536 || ($method !== 'HEAD'
          && preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', $line, $match)
          && (float) $match[1] > $maxBytes)) {
          $limitExceeded = true;
          return 0;
        }
        if (str_starts_with($line, 'HTTP/')) {
          $meta = [];
          $body = '';
        }
        if (trim($line) !== '') {
          $meta[] = rtrim($line, "\r\n");
        }
        return strlen($line);
      },
    ];
    // Same protocol allowlist on initial requests AND redirect destinations.
    if (defined('CURLOPT_PROTOCOLS_STR')) {
      $curlOptions[CURLOPT_PROTOCOLS_STR] = 'http,https';
      $curlOptions[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
    } else {
      $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
      $curlOptions[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (isset($options['body'])) {
      $curlOptions[CURLOPT_POSTFIELDS] = is_array($options['body'])
        ? http_build_query($options['body']) : $options['body'];
    }
    if ($method === 'POST') {
      $curlOptions[CURLOPT_POST] = true;
    } else {
      $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
      $curlOptions[CURLOPT_NOBODY] = $method === 'HEAD';
    }
    $handle = curl_init();
    if ($handle === false) {
      throw new RuntimeException('Unable to initialize HTTP client');
    }
    try {
      if (!curl_setopt_array($handle, $curlOptions)) {
        throw new RuntimeException('Unable to configure bounded HTTP client');
      }
      $success = curl_exec($handle);
      if ($limitExceeded) {
        throw new RuntimeException('HTTP response exceeds resource limit');
      }
      if ($success === false) {
        throw new RuntimeException(sprintf(
          'HTTP %s request failed for %s (cURL error %d)',
          $method,
          $this->redactUrl($url),
          curl_errno($handle)
        ));
      }
      return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $meta);
    } finally {
      curl_close($handle);
    }
  }

  private function redactUrl(string $url): string
  {
    return SecretRedactor::redact($url);
  }
}
