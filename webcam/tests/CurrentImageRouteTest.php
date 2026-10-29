<?php
declare(strict_types=1);

final class CurrentImageRouteTest extends WebcamHttpTestCase
{
  public function testNewCameraLegacyUrlServesRuntimeFileWithShortCacheLifetime(): void
  {
    $slug = 'current-route-' . bin2hex(random_bytes(6));
    $directory = dirname(__DIR__) . '/cache/' . $slug . '/current';
    mkdir($directory, 0775, true);
    $jpeg = imagecreatetruecolor(8, 8);
    imagejpeg($jpeg, $directory . '/raw.jpg');
    imagedestroy($jpeg);
    try {
      [$status, $body, $headers] = $this->request('/' . $slug . '/current/raw.jpg');
      self::assertSame(200, $status);
      self::assertSame(file_get_contents($directory . '/raw.jpg'), $body);
      self::assertStringContainsString('max-age=120', implode('\n', $headers));
      self::assertStringNotContainsString('immutable', implode('\n', $headers));
      self::assertSame(404, $this->request('/' . $slug . '/current/400.jpg')[0]);
      self::assertNotSame(200, $this->request('/' . $slug . '/current/secret.php')[0]);
      self::assertSame('', $this->request('/' . $slug . '/current/raw.jpg', 'HEAD')[1]);
    } finally {
      unlink($directory . '/raw.jpg');
      rmdir($directory);
      rmdir(dirname($directory));
    }
  }
}
