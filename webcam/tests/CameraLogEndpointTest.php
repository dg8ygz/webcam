<?php
declare(strict_types=1);

use Webcam\App;

final class CameraLogEndpointTest extends WebcamHttpTestCase
{
  public function testCameraLogEndpointAcceptsPostFormKey(): void
  {
    $slug = $this->firstSlug();
    $key = $this->uploadKeyFor($slug);
    if ($key === '') {
      $this->markTestSkipped('Fixture camera has no upload key.');
    }
    $body = http_build_query([
      'wc' => $slug,
      'key' => $key,
      'serial' => 'test-serial',
    ]);

    [$status, $response] = $this->request('/include/log.php', 'POST', [
      'Content-Type: application/x-www-form-urlencoded',
    ], $body);

    $this->assertSame(200, $status);
    $this->assertSame("ok\n", $response);
  }

  public function testCameraLogEndpointRejectsQueryKey(): void
  {
    $slug = $this->firstSlug();
    $key = $this->uploadKeyFor($slug);
    if ($key === '') {
      $key = 'dummy-key';
    }

    [$status, $response] = $this->request(
      '/include/log.php?wc=' . rawurlencode($slug) . '&key=' . rawurlencode($key),
      'POST',
      ['Content-Type: application/x-www-form-urlencoded'],
      'serial=test-serial'
    );

    $this->assertSame(400, $status);
    $this->assertSame("query key not allowed\n", $response);
  }

  public function testCameraLogEndpointRequiresPost(): void
  {
    $slug = $this->firstSlug();

    [$status, $response] = $this->request('/include/log.php?wc=' . rawurlencode($slug));

    $this->assertSame(405, $status);
    $this->assertSame("POST required\n", $response);
  }

  private function uploadKeyFor(string $slug): string
  {
    /** @var App $app */
    $app = require __DIR__ . '/../bootstrap.php';
    $camera = $app->cameraOrFail($slug);
    $key = (string) ($camera->capture()['upload_key'] ?? '');
    return $key;
  }
}
