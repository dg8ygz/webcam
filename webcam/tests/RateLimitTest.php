<?php
declare(strict_types=1);

final class RateLimitTest extends WebcamHttpTestCase
{
  public function testRateLimitIsEnforcedForBurst(): void
  {
    $cfg = $this->getJson('/admin/api.php?path=config/public-rate-limit&test=1');
    $limit = isset($cfg['limit']) && is_numeric($cfg['limit']) ? (int) $cfg['limit'] : 120;
    if ($limit < 1) {
      $this->markTestSkipped('Rate limit disabled');
    }
    $maxRequests = min(400, max(20, $limit * 2 + 5));
    if ($limit + 1 > $maxRequests) {
      $this->markTestSkipped('Rate limit too high for burst test');
    }

    $slug = $this->firstSlug();

    $sid = bin2hex(random_bytes(16));
    $headers = [
      'X-Session-Id: ' . $sid,
      'X-Forwarded-For: 198.51.100.10',
    ];
    $path = '/api/public/cameras/' . rawurlencode($slug);
    $hit = false;

    for ($i = 0; $i < $maxRequests; $i++) {
      [$status] = $this->request($path, 'GET', $headers);
      $this->assertNotSame(0, $status, 'Public API not reachable');
      if ($status === 429) {
        $hit = true;
        break;
      }
    }

    $this->assertTrue($hit, 'Expected rate limiter to return 429 after exceeding limit');
  }

  public function testHistoryHasAnIndependentRateLimitBudget(): void
  {
    $slug = $this->firstSlug();
    $sid = bin2hex(random_bytes(16));
    $headers = [
      'X-Session-Id: ' . $sid,
      'X-Forwarded-For: 198.51.100.20',
    ];
    $ts = TestFixtures::seedTimestamp() ?? time();
    $historyPath = '/api/public/cameras/' . rawurlencode($slug)
      . '/history?mode=days&days=1&ts=' . $ts;

    [$firstStatus, , $firstHeaders] = $this->request($historyPath, 'GET', $headers);
    $this->assertSame(200, $firstStatus);
    $historyLimit = $this->numericHeader($firstHeaders, 'X-RateLimit-Limit');
    $this->assertNotNull($historyLimit, 'History response must disclose its rate-limit budget.');
    $this->assertGreaterThan(0, $historyLimit);
    $this->assertLessThanOrEqual(100, $historyLimit, 'History budget unexpectedly high for this regression test.');

    $limited = false;
    for ($i = 0; $i < $historyLimit; $i++) {
      [$status] = $this->request($historyPath, 'GET', $headers);
      if ($status === 429) {
        $limited = true;
        break;
      }
    }
    $this->assertTrue($limited, 'Expected the dedicated History budget to return 429.');

    // Das allgemeine Budget derselben Client-IP darf vom History-Burst nicht
    // verbraucht worden sein. Die Session-ID aktiviert im Testbetrieb nur den
    // Limiter und ist ausdrücklich kein eigenes Kontingent mehr.
    [$detailStatus] = $this->request(
      '/api/public/cameras/' . rawurlencode($slug),
      'GET',
      $headers
    );
    $this->assertSame(200, $detailStatus);
  }

  /** @param list<string> $headers */
  private function numericHeader(array $headers, string $name): ?int
  {
    foreach ($headers as $header) {
      if (preg_match('/^' . preg_quote($name, '/') . ':\s*(\d+)$/i', $header, $matches)) {
        return (int) $matches[1];
      }
    }
    return null;
  }
}
