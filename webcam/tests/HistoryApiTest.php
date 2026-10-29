<?php
declare(strict_types=1);

final class HistoryApiTest extends WebcamHttpTestCase
{
  public function testHistoryDaysViaPublicApi(): void
  {
    $slug = $this->firstSlug();
    $ts = TestFixtures::seedTimestamp() ?? time();
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/history?mode=days&days=3&ts=' . $ts);
    $this->assertIsArray($json['items'] ?? null);
    $this->assertSame('days', $json['mode'] ?? null);
  }

  public function testHistoryDaysViaShortEndpoint(): void
  {
    $slug = $this->firstSlug();
    $ts = TestFixtures::seedTimestamp() ?? time();
    $json = $this->getJson('/api/history.php?slug=' . rawurlencode($slug) . '&mode=days&days=3&ts=' . $ts);
    $this->assertIsArray($json['items'] ?? null);
  }

  public function testHistoryMonthsViaPublicApi(): void
  {
    $slug = $this->firstSlug();
    $ts = TestFixtures::seedTimestamp() ?? time();
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/history?mode=months&months=6&ts=' . $ts);
    $this->assertIsArray($json['items'] ?? null);
    $this->assertSame('months', $json['mode'] ?? null);
  }

  public function testHistoryYearsViaPublicApi(): void
  {
    $slug = $this->firstSlug();
    $ts = TestFixtures::seedTimestamp() ?? time();
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/history?mode=years&years=3&ts=' . $ts);
    $this->assertIsArray($json['items'] ?? null);
    $this->assertSame('years', $json['mode'] ?? null);
  }
}
