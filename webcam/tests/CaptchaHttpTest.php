<?php
declare(strict_types=1);

final class CaptchaHttpTest extends WebcamHttpTestCase
{
  /** @var list<string> */
  private array $challengePaths = [];

  protected function tearDown(): void
  {
    foreach ($this->challengePaths as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  public function testCaptchaEndpointHasDedicatedRateLimit(): void
  {
    $sid = bin2hex(random_bytes(16));
    $limited = false;
    for ($i = 0; $i < 10; $i++) {
      [$status, $body] = $this->request('/bestof-captcha.php', 'GET', ['X-Session-Id: ' . $sid]);
      if ($status === 500 && str_contains($body, 'GD Erweiterung fehlt')) {
        $this->markTestSkipped('GD extension unavailable');
      }
      if ($status === 429) {
        $limited = true;
        break;
      }
      self::assertSame(200, $status, $body);
      $payload = json_decode($body, true);
      self::assertTrue((bool) ($payload['ok'] ?? false));
      $id = (string) ($payload['captcha']['id'] ?? '');
      if (preg_match('/^[a-f0-9]{32}$/', $id)) {
        /** @var Webcam\App $app */
        $app = require __DIR__ . '/../bootstrap.php';
        $this->challengePaths[] = $app->paths()->statePath('captcha/' . $id . '.json');
      }
    }

    self::assertTrue($limited, 'Expected the dedicated Captcha budget to return 429.');
  }
}
