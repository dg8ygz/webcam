<?php
declare(strict_types=1);

final class PublicApiTest extends WebcamHttpTestCase
{
    public function testCamerasList(): void
    {
        $json = $this->getJson('/api/public/cameras');
        $this->assertArrayHasKey('cameras', $json);
        $this->assertGreaterThan(0, $json['total'] ?? 0);
        $this->assertNotEmpty($json['cameras']);
        $first = $json['cameras'][0];
        $this->assertArrayHasKey('slug', $first);
    }

    public function testCameraDetail(): void
    {
        $list = $this->getJson('/api/public/cameras');
        $slug = $list['cameras'][0]['slug'];
        $detail = $this->getJson('/api/public/cameras/' . $slug);
        $this->assertSame($slug, $detail['camera']['slug'] ?? null);
        $this->assertIsArray($detail['best_of'] ?? null);
        $this->assertIsArray($detail['top_liked'] ?? null);
        $this->assertNotEmpty($detail['best_of'], 'Expected seeded best-of items in camera detail.');
    }

    public function testLikeMissingBodyReturns400(): void
    {
        $code = $this->postRaw('/like.php', '');
        $this->assertContains($code, [400, 403], 'Expected 400 (invalid body) or 403 (CSRF)');
    }

    public function testDayvideoRejectsUnboundedBuildProfiles(): void
    {
        $slug = $this->firstSlug();
        $fps = $this->getJson('/api/public/cameras/' . $slug . '/dayvideo?fps=60', 400);
        $width = $this->getJson('/api/public/cameras/' . $slug . '/dayvideo?max_width=2000', 400);

        $this->assertSame('Unsupported fps', $fps['detail'] ?? null);
        $this->assertSame('Unsupported max_width', $width['detail'] ?? null);
    }

    private function postRaw(string $path, string $body): int
    {
        $url = $this->base . $path;
        $opts = [
          'http' => [
            'method' => 'POST',
            'content' => $body,
            'header' => "Content-Type: application/json\r\n",
            'ignore_errors' => true,
          ]
        ];
        $context = stream_context_create($opts);
        $result = file_get_contents($url, false, $context);
        $meta = $http_response_header ?? [];
        foreach ($meta as $line) {
          if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $line, $m)) {
            return (int) $m[1];
          }
        }
        return 0;
    }
}
