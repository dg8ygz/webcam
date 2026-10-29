<?php
declare(strict_types=1);

final class IntegrationHttpTest extends WebcamHttpTestCase
{
  public function testCameraPageLoads(): void
  {
    $slug = $this->firstSlug();
    [$status, $body] = $this->request('/camera.php?cam=' . rawurlencode($slug));
    $this->assertSame(200, $status);
    $this->assertStringContainsString('data-camera-slug="' . $slug . '"', $body);
  }

  public function testLegacyCameraRouteLoads(): void
  {
    $slug = $this->firstSlug();
    [$status, $body] = $this->request('/camera/' . rawurlencode($slug));
    $this->assertSame(200, $status);
    $this->assertStringContainsString('data-camera-slug="' . $slug . '"', $body);
  }

  public function testSlugRouteLoads(): void
  {
    $slug = $this->firstSlug();
    [$status, $body] = $this->request('/' . rawurlencode($slug));
    $this->assertSame(200, $status);
    $this->assertStringContainsString('data-camera-slug="' . $slug . '"', $body);
  }

  public function testLiveProfileOutputsProfilerSummary(): void
  {
    $slug = $this->firstSlug();
    [$status, $body] = $this->request('/camera/' . rawurlencode($slug) . '?profile=1');
    $this->assertSame(200, $status);
    $this->assertStringContainsString('[WC_PROFILE_SUMMARY]', $body);
  }

  public function testLiveProfileRequiresDevOrTestContext(): void
  {
    $slug = $this->firstSlug();
    $this->assertSame('test', getenv('WEBCAM_ENV') ?: null);
    [$status, $body] = $this->request('/camera/' . rawurlencode($slug) . '?profile=1');
    $this->assertSame(200, $status);
    $this->assertStringContainsString('[WC_PROFILE_SUMMARY]', $body);
  }

  public function testStatusPageLoads(): void
  {
    [$status, $body] = $this->request('/status/');
    $this->assertSame(200, $status);
    $this->assertStringContainsString('<title>', $body);
  }

  public function testDevRouterDeniesSensitiveFilesBeforeStaticServing(): void
  {
    $paths = [
      '/.git/config',
      '/%2egit/config',
      '/composer.json',
      '/database/webcams.sqlite',
      '/var/log/app.log',
      '/config/app.php',
      '/src/App.php',
      '/docs/VERSIONIERUNG.md',
      '/issues.md',
      '/cache/test-camera/overlays/logo.png',
      '/future-backup.sqlite-wal',
    ];

    foreach ($paths as $path) {
      [$status, $body, $headers] = $this->request($path);
      $this->assertSame(403, $status, 'Sensitive path was not denied: ' . $path);
      $this->assertSame("Forbidden\n", $body, 'Sensitive response leaked details: ' . $path);
      $this->assertContains('X-Content-Type-Options: nosniff', $headers);
    }
  }
}
