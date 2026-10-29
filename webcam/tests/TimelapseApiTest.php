<?php
declare(strict_types=1);

final class TimelapseApiTest extends WebcamHttpTestCase
{
  public function testTimelapseFramesWithinRange(): void
  {
    $slug = $this->firstSlug();
    $seed = TestFixtures::seedTimestamp() ?? time();
    $from = $seed - 3600;
    $to = $seed + 3600;
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/timelapse?from=' . $from . '&to=' . $to . '&frame_count=5&max_width=1200');
    $frames = $json['frames'] ?? null;
    $this->assertIsArray($frames);
    $this->assertLessThanOrEqual(5, count($frames));
    if ($frames) {
      $frame = $frames[0];
      $this->assertArrayHasKey('timestamp', $frame);
      $this->assertArrayHasKey('url', $frame);
    }
  }
}
