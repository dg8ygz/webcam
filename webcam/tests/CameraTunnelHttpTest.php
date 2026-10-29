<?php
declare(strict_types=1);

final class CameraTunnelHttpTest extends WebcamHttpTestCase
{
  public function testAuthCsrfCaptureAndScopedLease(): void
  {
    $slug = 'tunnel-' . bin2hex(random_bytes(4));
    $key = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(16));
    $admin = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token, 'Content-Type: application/json'];
    $helper = ['X-Command-Key: ' . $key, 'X-Camera-Tunnel-Protocol: camera-rpi-tunnel-v1', 'X-Camera-Tunnel-State: closed'];
    $route = '/admin/api.php?path=cameras/' . $slug . '/tunnel';
    $poll = '/include/command.php?wc=' . $slug;
    try {
      [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'POST', $admin,
        json_encode(['slug' => $slug, 'name' => $slug, 'mode' => 'active-capture', 'capture' => ['upload_key' => $key, 'interval_seconds' => 86400]], JSON_THROW_ON_ERROR));
      self::assertSame(201, $status, $body);
      self::assertSame(401, $this->request($route, 'POST', ['Content-Type: application/json'], '{"action":"open"}')[0]);
      self::assertSame(403, $this->request($route . '&test=1', 'POST', ['X-WC-Test: 1', 'Content-Type: application/json'], '{"action":"open"}')[0]);
      self::assertSame(403, $this->request($poll, 'GET', ['X-Camera-Tunnel-Protocol: camera-rpi-tunnel-v1'])[0]);
      self::assertSame(409, $this->request($route . '&test=1', 'POST', $admin, '{"action":"open"}')[0]);
      [$status, $body, $headers] = $this->request($poll, 'GET', $helper);
      self::assertSame(200, $status);
      self::assertStringContainsString('webcam_capture', $body);
      self::assertContains('X-Camera-Tunnel-Lease: closed', $headers);
      self::assertSame(400, $this->request($route . '&test=1', 'POST', $admin, '{"action":"open","target":"bad"}')[0]);
      self::assertSame(202, $this->request($route . '&test=1', 'POST', $admin, '{"action":"open"}')[0]);
      self::assertSame(202, $this->request('/admin/api.php?path=cameras/' . $slug . '/capture&test=1', 'POST', $admin)[0]);
      [$status, $body, $headers] = $this->request($poll, 'GET', $helper);
      self::assertStringContainsString('webcam_capture', $body);
      self::assertMatchesRegularExpression('/X-Camera-Tunnel-Lease: [a-f0-9]{32}:[0-9]{10}/', implode("\n", $headers));
      self::assertSame(202, $this->request($route . '&test=1', 'POST', $admin, '{"action":"close"}')[0]);
      [$status, $body, $headers] = $this->request($poll, 'GET', $helper);
      self::assertSame("wait\n0\nwait\n", $body);
      self::assertContains('X-Camera-Tunnel-Lease: closed', $headers);
    } finally {
      $this->request('/admin/api.php?path=cameras/' . $slug . '&test=1', 'DELETE', $admin);
    }
  }
}
