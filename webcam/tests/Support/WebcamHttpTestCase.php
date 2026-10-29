<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class WebcamHttpTestCase extends TestCase
{
  protected string $base;

  protected function setUp(): void
  {
    parent::setUp();
    HttpServer::ensure();
    TestFixtures::seed();

    $base = HttpServer::baseUrl();
    if (!is_string($base) || trim($base) === '') {
      $base = getenv('WEBCAM_BASE_URL');
    }
    $base = is_string($base) ? trim($base) : '';
    $this->assertNotSame('', $base, 'HTTP base URL unavailable (WEBCAM_BASE_URL empty and HttpServer not running).');
    $this->base = rtrim($base, '/');
  }

  /**
   * @param list<string> $headers
   * @return array{0:int,1:string,2:list<string>}
   */
  protected function request(string $path, string $method = 'GET', array $headers = [], ?string $body = null): array
  {
    $url = $this->base . $path;
    $opts = [
      'http' => [
        'method' => $method,
        'ignore_errors' => true,
        'header' => $headers ? implode("\r\n", $headers) . "\r\n" : '',
      ],
    ];
    if ($body !== null) {
      $opts['http']['content'] = $body;
    }
    $context = stream_context_create($opts);
    $data = file_get_contents($url, false, $context);
    $status = $this->statusFromHeaders($http_response_header ?? []);
    return [$status, $data === false ? '' : $data, $http_response_header ?? []];
  }

  /**
   * @return array<string,mixed>
   */
  protected function getJson(string $path, ?int $expectedStatus = 200): array
  {
    [$status, $data] = $this->request($path);
    if ($expectedStatus !== null) {
      $this->assertSame($expectedStatus, $status, 'Unexpected HTTP status for ' . $path);
    } else {
      $this->assertNotSame(0, $status, 'No HTTP response for ' . $path);
    }
    $decoded = json_decode($data, true);
    $this->assertIsArray($decoded, 'Expected JSON object for ' . $path);
    return $decoded;
  }

  protected function firstSlug(): string
  {
    $list = $this->getJson('/api/public/cameras');
    $slug = $list['cameras'][0]['slug'] ?? null;
    $this->assertIsString($slug, 'No cameras returned by public API.');
    $this->assertNotSame('', $slug, 'Camera slug empty in public API.');
    return (string) $slug;
  }

  /**
   * @param list<string> $headers
   */
  protected function statusFromHeaders(array $headers): int
  {
    foreach ($headers as $line) {
      if (preg_match('#^HTTP/\\d\\.\\d\\s+(\\d{3})#', $line, $m)) {
        return (int) $m[1];
      }
    }
    return 0;
  }
}
