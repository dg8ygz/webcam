<?php
declare(strict_types=1);

final class OverlayServeTest extends WebcamHttpTestCase
{
  public function testOverlayBlocksTraversal(): void
  {
    $slug = $this->firstSlug();
    [$status] = $this->request('/overlay.php?slug=' . rawurlencode($slug) . '&file=../oops.png');
    $this->assertSame(404, $status);
  }

  public function testOverlayServesPngFromCache(): void
  {
    $slug = $this->firstSlug();

    $app = require __DIR__ . '/../bootstrap.php';
    $dir = $app->paths()->cachePath($slug . '/overlays');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      $this->fail('Unable to create overlays dir: ' . $dir);
    }
    $filename = 'test-overlay-' . bin2hex(random_bytes(4)) . '.png';
    $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wwAAgMBgOnm2wAAAABJRU5ErkJggg==',
      true
    );
    $this->assertNotFalse($png, 'Failed to decode PNG payload');
    file_put_contents($path, $png);

    try {
      [$status, , $headers] = $this->request('/overlay.php?slug=' . rawurlencode($slug) . '&file=' . rawurlencode($filename));
      $this->assertSame(200, $status);
      $this->assertTrue($this->hasHeader($headers, 'content-type: image/png'));
    } finally {
      if (is_file($path) && !unlink($path)) {
        $this->fail('Unable to remove test overlay: ' . $path);
      }
    }
  }

  /**
   * @param list<string> $headers
   */
  private function hasHeader(array $headers, string $needle): bool
  {
    $needle = strtolower($needle);
    foreach ($headers as $line) {
      if (strtolower($line) === $needle) {
        return true;
      }
    }
    return false;
  }

}
