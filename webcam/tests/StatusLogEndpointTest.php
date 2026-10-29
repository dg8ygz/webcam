<?php
declare(strict_types=1);

final class StatusLogEndpointTest extends WebcamHttpTestCase
{
  public function testCaptureLogDownloadRequiresAdminAuth(): void
  {
    $slug = $this->firstSlug();

    [$status] = $this->request('/status/log.php?cam=' . rawurlencode($slug));

    $this->assertSame(401, $status);
  }

  public function testCaptureLogDownloadAllowsAdminToken(): void
  {
    $slug = $this->firstSlug();
    $logPath = __DIR__ . '/../var/log/capture/' . $slug . '.log';
    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
      $this->fail('Unable to create capture log dir.');
    }
    file_put_contents(
      $logPath,
      "test log rtsp://status-user:status-secret@example.test/live?token=query-secret\n"
    );

    try {
      [$status, $body, $headers] = $this->request('/status/log.php?test=1&cam=' . rawurlencode($slug), 'GET', [
        'X-WC-Test: 1',
      ]);
    } finally {
      @unlink($logPath);
    }

    $this->assertSame(200, $status);
    $this->assertStringContainsString('test log rtsp://[REDACTED]@example.test/live?token=[REDACTED]', $body);
    foreach (['status-user', 'status-secret', 'query-secret'] as $secret) {
      $this->assertStringNotContainsString($secret, $body);
    }
    $this->assertStringContainsString('Content-Type: text/plain', implode("\n", $headers));
  }
}
