<?php
declare(strict_types=1);

final class FocusHttpTest extends WebcamHttpTestCase
{
  public function testAuthenticatedFocusDispatchPreservesCaptureAndRequiresCapability(): void
  {
    $slug = 'focus-' . bin2hex(random_bytes(4));
    $key = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(16));
    $admin = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token, 'Content-Type: application/json'];
    $helper = ['X-Command-Key: ' . $key, 'X-Camera-Focus-Protocol: camera-rpi-focus-v1'];
    $route = '/admin/api.php?path=cameras/' . $slug . '/focus';
    $poll = '/include/command.php?wc=' . $slug;
    try {
      [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'POST', $admin,
        json_encode(['slug' => $slug, 'name' => $slug, 'mode' => 'active-capture', 'capture' => ['upload_key' => $key, 'interval_seconds' => 86400]], JSON_THROW_ON_ERROR));
      self::assertSame(201, $status, $body);
      self::assertSame(401, $this->request($route, 'POST', ['Content-Type: application/json'], '{"mode":"auto"}')[0]);
      self::assertSame(403, $this->request($route . '&test=1', 'POST', ['X-WC-Test: 1', 'Content-Type: application/json'], '{"mode":"auto"}')[0]);
      self::assertSame(403, $this->request($poll, 'GET', ['X-Camera-Focus-Protocol: camera-rpi-focus-v1'])[0]);
      self::assertSame(409, $this->request($route . '&test=1', 'POST', $admin, '{"mode":"auto"}')[0]);
      [$status, $body] = $this->request($poll, 'GET', $helper);
      self::assertSame(200, $status);
      self::assertStringContainsString('webcam_capture', $body);
      self::assertSame(202, $this->request($route . '&test=1', 'POST', $admin, '{"mode":"++"}')[0]);
      // Queue a simultaneous manual capture: focus must not consume it.
      self::assertSame(202, $this->request('/admin/api.php?path=cameras/' . $slug . '/capture&test=1', 'POST', $admin)[0]);
      [$status, $body] = $this->request($poll, 'GET', $helper);
      self::assertSame(200, $status);
      self::assertMatchesRegularExpression('/^focus\n[0-9]{10}\n\+\+\n$/D', $body);
      [$status, $body] = $this->request($poll, 'GET', $helper);
      self::assertStringContainsString('webcam_capture', $body);
      [$status, $body] = $this->request($poll, 'GET', $helper);
      self::assertSame("wait\n0\nwait\n", $body);
      $this->request($poll, 'GET', ['X-Command-Key: ' . $key, 'X-Camera-Focus-Protocol: none']);
      self::assertSame(409, $this->request($route . '&test=1', 'POST', $admin, '{"mode":"auto"}')[0]);
    } finally {
      $this->request('/admin/api.php?path=cameras/' . $slug . '&test=1', 'DELETE', $admin);
    }
  }
}
