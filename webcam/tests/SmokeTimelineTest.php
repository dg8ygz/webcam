<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeTimelineTest extends TestCase
{
  private \Webcam\App $app;

  protected function setUp(): void
  {
    $this->app = require __DIR__ . '/../bootstrap.php';
  }

  public function testLatestEntriesHaveFiles(): void
  {
    $currentYear = (int) gmdate('Y');
    foreach ($this->app->cameras()->all() as $camera) {
      $timeline = $this->app->archiveBrowser()->timeline($camera, $currentYear);
      $entries = $timeline['entries'] ?? [];
      if (!$entries) {
        $this->markTestSkipped("No timeline entries for camera {$camera->slug()}.");
        continue;
      }
      // Nur die letzten 50 prüfen, um Laufzeit klein zu halten.
      $entries = array_slice($entries, -50);
      foreach ($entries as $entry) {
        $rel = $entry['relative_path'] ?? null;
        $variants = $entry['variants'] ?? [];
        if ((!is_string($rel) || $rel === '') && $variants) {
          $firstVariant = reset($variants);
          if (is_array($firstVariant)) {
            $rel = $firstVariant['relative_path'] ?? null;
          }
        }
        $this->assertNotEmpty($rel, "Missing relative path for {$camera->slug()} ts {$entry['timestamp']}");

        $abs = $rel ? $this->app->resolveCameraStoragePath($camera, $rel) : null;
        $this->assertNotEmpty($abs, "Cannot resolve path {$rel}");
        $this->assertFileExists($abs, "File missing: {$abs}");
      }
    }
  }
}
