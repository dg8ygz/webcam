<?php
declare(strict_types=1);

use Webcam\Service\Capture\AutoIsoController;

final class AutoIsoHttpTest extends WebcamHttpTestCase
{
  public function testIsoUsesAuthenticatedPollAndLeavesManualCaptureQueued(): void
  {
    $slug = 'iso-http-' . bin2hex(random_bytes(4)); $key = bin2hex(random_bytes(16)); $csrf = bin2hex(random_bytes(16));
    $admin = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $csrf, 'Cookie: admin_csrf=' . $csrf, 'Content-Type: application/json'];
    $helper = ['X-Command-Key: ' . $key];
    $poll = '/include/command.php?wc=' . $slug;
    $app = require __DIR__ . '/../bootstrap.php';
    $queue = new AutoIsoController($app->paths()->statePath('auto-iso'));
    try {
      [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'POST', $admin, json_encode([
        'slug' => $slug, 'name' => $slug, 'mode' => 'active-capture',
        'capture' => ['upload_key' => $key, 'interval_seconds' => 86400,
          'auto_iso' => ['mode' => 'auto', 'min_iso' => 100, 'max_iso' => 400]],
      ], JSON_THROW_ON_ERROR));
      self::assertSame(201, $status, $body);
      $app = require __DIR__ . '/../bootstrap.php'; $camera = $app->cameraOrFail($slug);
      self::assertSame(403, $this->request($poll, 'GET', [])[0]);
      self::assertFalse($queue->status($camera)['poll_recent']);
      self::assertStringContainsString('webcam_capture', $this->request($poll, 'GET', $helper)[1]);
      self::assertSame('queued', $queue->observe($camera, ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30], 5, time())['state']);
      self::assertSame(202, $this->request('/admin/api.php?path=cameras/' . $slug . '/capture&test=1', 'POST', $admin)[0]);
      self::assertSame(403, $this->request($poll, 'GET', ['X-Command-Key: wrong'])[0]);
      [$status, $body] = $this->request($poll, 'GET', $helper);
      self::assertSame(200, $status, $body);
      self::assertMatchesRegularExpression('/^command\n[0-9]{10}\nwebcam_isoexp 400\n$/D', $body);
      self::assertStringContainsString('webcam_capture', $this->request($poll, 'GET', $helper)[1]);
      self::assertSame("wait\n0\nwait\n", $this->request($poll, 'GET', $helper)[1]);
      [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'GET', $admin);
      self::assertSame(200, $status);
      self::assertSame('dispatched', json_decode($body, true)['runtime']['auto_iso'][$slug]['state']);
    } finally {
      $this->request('/admin/api.php?path=cameras/' . $slug . '&test=1', 'DELETE', $admin);
      foreach (['auto-iso/' . $slug . '.json', 'command/' . $slug . '.slot', 'focus/' . $slug . '.json', 'manual-capture/' . $slug . '.json'] as $relative) {
        $file = $app->paths()->statePath($relative); if (is_file($file)) unlink($file);
      }
    }
  }
}
