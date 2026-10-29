<?php
declare(strict_types=1);

final class ManualCaptureHttpTest extends WebcamHttpTestCase
{
  public function testAuthenticatedManualRequestReachesOnlySelectedHelper(): void
  {
    $slugs = ['manual-a-' . bin2hex(random_bytes(3)), 'manual-b-' . bin2hex(random_bytes(3))];
    $key = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(16));
    $headers = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token, 'Content-Type: application/json'];
    $created = [];
    try {
      foreach ($slugs as $index => $slug) {
        [$status, $body] = $this->request('/admin/api.php?path=cameras&test=1', 'POST', $headers,
          json_encode(['slug' => $slug, 'name' => $slug, 'title' => $slug, 'mode' => 'active-capture',
            'capture' => ['upload_key' => $key, 'interval_seconds' => 86400,
              'helper_upload_mode' => $index === 0 ? 'chunked' : 'local']], JSON_THROW_ON_ERROR));
        self::assertSame(201, $status, $body);
        $created[] = $slug;
        $before = time();
        [$status, $body, $responseHeaders] = $this->request('/include/command.php?wc=' . $slug, 'GET', ['X-Command-Key: ' . $key]);
        self::assertContains('X-Camera-Upload-Mode: ' . ($index === 0 ? 'chunked' : 'local'), $responseHeaders);
        self::assertSame(200, $status);
        self::assertStringContainsString('webcam_capture', $body);
        $serial = (int) explode("\n", $body)[1];
        self::assertContains($serial, [intdiv($before, 86400) * 86400, intdiv(time(), 86400) * 86400]);
      }
      $route = '/admin/api.php?path=cameras/' . $slugs[0] . '/capture';
      [$status] = $this->request($route, 'POST');
      self::assertSame(401, $status);
      [$status] = $this->request($route . '&test=1', 'POST', ['X-WC-Test: 1']);
      self::assertSame(403, $status);
      [$status, $body] = $this->request($route . '&test=1', 'POST', $headers);
      self::assertSame(202, $status, $body);
      self::assertSame($slugs[0], json_decode($body, true)['camera']);
      [$status] = $this->request($route . '&test=1', 'POST', $headers);
      self::assertSame(409, $status);
      [$status, , $responseHeaders] = $this->request('/include/command.php?wc=' . $slugs[0]);
      self::assertSame(403, $status);
      self::assertStringNotContainsString('X-Camera-Upload-Mode:', implode('\n', $responseHeaders));
      [$status, $body] = $this->request('/include/command.php?wc=' . $slugs[1], 'GET', ['X-Command-Key: ' . $key]);
      self::assertSame("wait\n0\nwait\n", $body);
      $manualBefore = time();
      [$status, $body] = $this->request('/include/command.php?wc=' . $slugs[0], 'GET', ['X-Command-Key: ' . $key]);
      self::assertSame(200, $status);
      self::assertStringContainsString('webcam_capture', $body);
      self::assertGreaterThanOrEqual($manualBefore, (int) explode("\n", $body)[1]);
      [$status, $body] = $this->request('/include/command.php?wc=' . $slugs[0], 'GET', ['X-Command-Key: ' . $key]);
      self::assertSame("wait\n0\nwait\n", $body);
    } finally {
      foreach ($created as $slug) $this->request('/admin/api.php?path=cameras/' . $slug . '&test=1', 'DELETE', $headers);
    }
  }
}
