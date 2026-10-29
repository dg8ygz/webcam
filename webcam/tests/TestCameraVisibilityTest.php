<?php
declare(strict_types=1);

use Webcam\Domain\Camera;
use Webcam\Domain\CameraRepository;
use Webcam\Service\Admin\CameraConfigNormalizer;

final class TestCameraVisibilityTest extends WebcamHttpTestCase
{
  public function testNewCameraCanHaveAnArbitraryNameAndStartUnlisted(): void
  {
    $visibleSlug = $this->firstSlug();
    $slug = 'labor-nord-' . bin2hex(random_bytes(4));
    $token = bin2hex(random_bytes(16));
    $headers = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token, 'Content-Type: application/json'];
    $path = '/admin/api.php?path=cameras';
    $created = false;
    try {
      [$status, $body] = $this->request($path . '&test=1', 'POST', $headers, json_encode([
        'slug' => $slug, 'name' => 'Mein USB-Versuch', 'title' => 'Labor Nord',
        'mode' => 'active-capture', 'tags' => [Camera::TEST_CAMERA_TAG],
      ], JSON_THROW_ON_ERROR));
      $created = $status === 201;
      self::assertSame(201, $status, $body);
      $camera = $this->getJson($path . '/' . $slug . '&test=1');
      self::assertSame('Mein USB-Versuch', $camera['name']);
      self::assertContains(Camera::TEST_CAMERA_TAG, $camera['tags']);
      $listed = array_column($this->getJson('/api/public/cameras')['cameras'], 'slug');
      self::assertContains($visibleSlug, $listed);
      self::assertNotContains($slug, $listed);
      self::assertSame($slug, $this->getJson('/api/public/cameras/' . $slug)['camera']['slug']);
      [$status] = $this->request('/' . $slug);
      self::assertSame(200, $status);
      // Fremde Kamera-Menues muessen den Schalter ebenfalls nachvollziehen.
      foreach ([[], [Camera::TEST_CAMERA_TAG]] as $tags) {
        [$status, $body] = $this->request($path . '/' . $slug . '/sections/general&test=1', 'PUT', $headers,
          json_encode(['tags' => $tags], JSON_THROW_ON_ERROR));
        self::assertSame(200, $status, $body);
        [$status, $html] = $this->request('/' . $visibleSlug);
        self::assertSame(200, $status);
        if ($tags === []) self::assertStringContainsString($slug, $html);
        else self::assertStringNotContainsString($slug, $html);
      }
    } finally {
      if ($created) {
        [$status, $body] = $this->request($path . '/' . $slug . '&test=1', 'DELETE', $headers);
        self::assertContains($status, [200, 204], $body);
      }
    }
  }

  public function testRepositoryKeepsTestCamerasForInternalAndDirectAccess(): void
  {
    $normalizer = new CameraConfigNormalizer();
    $configs = [];
    foreach (['labor-nord' => [Camera::TEST_CAMERA_TAG], 'test' => [], 'stadt' => ['test']] as $slug => $tags) {
      $configs[] = $normalizer->normalizePayload(['name' => $slug, 'title' => $slug, 'mode' => 'active-capture', 'tags' => $tags], $slug, []);
    }
    $path = TestTempDir::ensure() . '/visibility-cameras.php';
    file_put_contents($path, '<?php return ' . var_export($configs, true) . ';');
    $repository = new CameraRepository($path);
    self::assertCount(3, $repository->all());
    self::assertSame(['test', 'stadt'], array_map(static fn(Camera $camera): string => $camera->slug(), $repository->listed()));
    self::assertTrue($repository->find('labor-nord')->isTest());
    self::assertNull($repository->findListed('labor-nord'));
    self::assertNull($repository->findListed('missing'));
    self::assertFalse($repository->findListed('test')->isTest(), 'Slug alone must not hide a camera');
    self::assertFalse($repository->findListed('stadt')->isTest(), 'Visible tag test is not the internal attribute');
  }

  public function testAdminFlagRoundTripsAndOnlyHidesPublicCollections(): void
  {
    $slug = $this->firstSlug();
    $adminPath = '/admin/api.php?path=cameras&test=1';
    $before = $this->getJson($adminPath)['cameras'][$slug];
    $token = bin2hex(random_bytes(16));
    $headers = ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token, 'Content-Type: application/json'];
    $sectionPath = '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/sections/general&test=1';
    try {
      [$status, $body] = $this->request($sectionPath, 'PUT', $headers,
        json_encode(['tags' => array_merge($before['tags'] ?? [], [Camera::TEST_CAMERA_TAG])], JSON_THROW_ON_ERROR));
      self::assertSame(200, $status, $body);
      $saved = $this->getJson($adminPath)['cameras'][$slug];
      self::assertContains(Camera::TEST_CAMERA_TAG, $saved['tags']);
      self::assertSame($before['capture'], $saved['capture']);
      self::assertSame($before['storage'], $saved['storage']);

      $overview = $this->getJson('/api/public/cameras');
      self::assertNotContains($slug, array_column($overview['cameras'], 'slug'));
      self::assertSame(count($overview['cameras']), $overview['total']);
      $global = $this->getJson('/api/public/bestof');
      self::assertNotContains($slug, array_column($global['cameras'], 'slug'));
      foreach ($global['items'] as $item) {
        self::assertStringNotContainsString($slug, json_encode($item, JSON_THROW_ON_ERROR));
      }
      $this->getJson('/api/public/bestof?camera=' . rawurlencode($slug), 404);
      foreach (['/', '/status/', '/weather', '/bestof'] as $path) {
        [$status, $html] = $this->request($path);
        self::assertSame(200, $status, $path);
        self::assertStringNotContainsString($slug, $html, 'Test camera exposed on ' . $path);
      }

      foreach (['/' . $slug, '/camera/' . $slug, '/' . $slug . '?view=bestof'] as $path) {
        [$status, $html, $responseHeaders] = $this->request($path);
        self::assertSame(200, $status, $path);
        self::assertStringContainsString($slug, $html);
        self::assertContains('X-Robots-Tag: noindex, nofollow', $responseHeaders);
      }
      [$status, $html, $responseHeaders] = $this->request('/weather?camera=' . rawurlencode($slug));
      self::assertSame(200, $status);
      self::assertStringContainsString('name="camera" value="' . $slug . '"', $html);
      self::assertContains('X-Robots-Tag: noindex, nofollow', $responseHeaders);
      foreach (['/weather?camera=missing-camera', '/weather?camera[]=bad', '/weather?camera=../bad'] as $invalid) {
        [$status] = $this->request($invalid);
        self::assertSame(404, $status, $invalid);
      }
      $detail = $this->getJson('/api/public/cameras/' . $slug);
      self::assertSame($slug, $detail['camera']['slug']);
      self::assertNotEmpty($detail['best_of']);
      self::assertNotEmpty($this->getJson('/api/public/cameras/' . $slug . '/bestof')['items']);
      self::assertNotEmpty($this->getJson('/admin/api.php?path=status&test=1')['items']);
    } finally {
      [$status, $body] = $this->request($sectionPath, 'PUT', $headers,
        json_encode(['tags' => $before['tags'] ?? []], JSON_THROW_ON_ERROR));
      self::assertSame(200, $status, $body);
    }
    self::assertSame($before['tags'], $this->getJson($adminPath)['cameras'][$slug]['tags']);
    self::assertContains($slug, array_column($this->getJson('/api/public/cameras')['cameras'], 'slug'));
  }
}
