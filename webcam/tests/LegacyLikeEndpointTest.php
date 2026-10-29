<?php
declare(strict_types=1);

/** HTTP-Vertraege fuer den weiterhin erreichbaren Legacy-Like-Endpunkt. */
final class LegacyLikeEndpointTest extends WebcamHttpTestCase
{
  public function testRejectsMutationWithoutCaptcha(): void
  {
    $slug = $this->firstSlug();
    $csrf = bin2hex(random_bytes(16));
    $payload = json_encode([
      'slug' => $slug,
      'slot_ts' => TestFixtures::seedTimestamp() ?? time(),
    ], JSON_THROW_ON_ERROR);

    [$status, $body] = $this->request('/like.php', 'POST', [
      'Content-Type: application/json',
      'Cookie: webcam_csrf=' . $csrf,
      'X-CSRF-Token: ' . $csrf,
      'X-Forwarded-For: 198.51.100.71',
    ], $payload);

    self::assertSame(200, $status, $body);
    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    self::assertFalse((bool) ($response['ok'] ?? true));
    self::assertSame('invalid_captcha', $response['code'] ?? null);
  }

  public function testRotatingSessionsCannotRenewTheIpBudget(): void
  {
    $slug = $this->firstSlug();
    $payload = json_encode([
      'slug' => $slug,
      // Der absichtlich unbekannte Slot verhindert echte Like-Schreibvorgaenge.
      // Das HTTP-IP-Budget liegt davor und kann damit isoliert geprueft werden.
      'slot_ts' => 1,
    ], JSON_THROW_ON_ERROR);
    $clientIp = '198.51.100.72';
    $limit = null;
    $limited = false;

    for ($requestNumber = 0; $requestNumber < 30; $requestNumber++) {
      [$status, $body, $headers] = $this->request('/like.php?test=1', 'POST', [
        'Content-Type: application/json',
        'X-WC-Test: 1',
        'X-Session-ID: ' . bin2hex(random_bytes(16)),
        'X-Forwarded-For: ' . $clientIp,
      ], $payload);

      $limit ??= $this->numericHeader($headers, 'X-RateLimit-Limit');
      if ($status === 429) {
        $limited = true;
        break;
      }
      self::assertSame(200, $status, $body);
    }

    self::assertNotNull($limit, 'Like response must disclose its IP budget.');
    self::assertGreaterThan(0, $limit);
    self::assertTrue($limited, 'Rotating session IDs must not renew the Like IP budget.');
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
