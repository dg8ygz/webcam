<?php
declare(strict_types=1);

final class BestOfApiTest extends WebcamHttpTestCase
{
  public function testGlobalBestOfEndpointMergesChronologically(): void
  {
    $json = $this->getJson('/api/public/bestof?per_page=100&page=1');
    $this->assertIsArray($json['items'] ?? null);
    $this->assertSame(1, $json['page'] ?? null);
    $this->assertSame(100, $json['per_page'] ?? null);
    $this->assertIsArray($json['cameras'] ?? null);
    $this->assertIsArray($json['years'] ?? null);

    $timestamps = [];
    foreach ($json['items'] as $item) {
      $this->assertNotEmpty($item['camera_slug'] ?? null);
      $this->assertNotEmpty($item['camera_title'] ?? null);
      $this->assertNotEmpty($item['link'] ?? null);
      if (isset($item['slot_ts'])) {
        $timestamps[] = (int) $item['slot_ts'];
      }
    }
    $sorted = $timestamps;
    rsort($sorted, SORT_NUMERIC);
    $this->assertSame($sorted, $timestamps);
  }

  public function testGlobalBestOfCanFilterOneCamera(): void
  {
    $slug = $this->firstSlug();
    $json = $this->getJson('/api/public/bestof?camera=' . rawurlencode($slug) . '&per_page=10');
    $this->assertSame($slug, $json['selected_camera'] ?? null);
    foreach ($json['items'] ?? [] as $item) {
      $this->assertSame($slug, $item['camera_slug'] ?? null);
    }
  }

  public function testGlobalBestOfRejectsUnknownCamera(): void
  {
    [$status, $body] = $this->request('/api/public/bestof?camera=does-not-exist');
    $this->assertSame(404, $status, $body);
  }

  public function testBestOfEndpointPaged(): void
  {
    $slug = $this->firstSlug();
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/bestof?per_page=1&page=1');
    $this->assertIsArray($json['items'] ?? null);
    $this->assertSame(1, $json['page'] ?? null);
    $this->assertSame(1, $json['per_page'] ?? null);
    $this->assertGreaterThanOrEqual(1, $json['total'] ?? 0);
    $this->assertGreaterThanOrEqual(1, $json['total_pages'] ?? 0);

    $item = $json['items'][0] ?? null;
    $this->assertIsArray($item);
    $this->assertNotEmpty($item['filename'] ?? null);
    $this->assertNotEmpty($item['url'] ?? null);
    $link = $item['link'] ?? '';
    $this->assertIsString($link);
    $this->assertStringContainsString('bestof=', $link);
    $this->assertFalse(str_starts_with($link, '/'), 'Best-Of links must stay app-relative for proxy/subdir setups.');
  }
}
