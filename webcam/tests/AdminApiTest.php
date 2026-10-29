<?php
declare(strict_types=1);

final class AdminApiTest extends WebcamHttpTestCase
{
  public function testAdminJsonRejectsOversizedBodyBeforeParsingOrSaving(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'];
    $slug = (string) array_key_first($cameras);
    $path = '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/sections/labels&test=1';
    $invalidLabels = '{"labels":"invalid"}';
    // Genau 1 MiB erreicht die Fachvalidierung, ein Byte mehr nicht einmal JSON.
    [$status, $body] = $this->request($path, 'PUT', $this->jsonWriteHeaders(),
      str_pad($invalidLabels, 1048576, ' '));
    self::assertSame(400, $status, $body);
    self::assertStringContainsString('labels must be an array', $body);
    foreach ([str_pad($invalidLabels, 1048577, ' '), str_repeat('[', 1048577)] as $oversize) {
      [$status, $body] = $this->request($path, 'PUT', $this->jsonWriteHeaders(), $oversize);
      self::assertSame(413, $status, $body);
      self::assertSame('Admin JSON body exceeds 1 MiB limit', json_decode($body, true)['detail']);
    }
    self::assertSame($cameras, $this->getJson('/admin/api.php?path=cameras&test=1')['cameras']);
  }

  public function testOversizedJsonWithoutContentLengthIsAlsoRejected(): void
  {
    $curl = curl_init($this->base . '/admin/api.php?path=config/timeline&test=1');
    curl_setopt_array($curl, [
      CURLOPT_CUSTOMREQUEST => 'PUT',
      CURLOPT_HTTPHEADER => array_merge($this->jsonWriteHeaders(), ['Transfer-Encoding: chunked', 'Expect:']),
      CURLOPT_POSTFIELDS => str_repeat(' ', 1048577),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($curl);
    self::assertSame(413, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $body);
    curl_close($curl);
  }

  public function testOversizedJsonDoesNotBypassAuthenticationOrCsrf(): void
  {
    [$status] = $this->request('/admin/api.php?path=config/timeline', 'PUT',
      ['Content-Type: application/json', 'Authorization: Bearer invalid'], str_repeat(' ', 1048577));
    self::assertSame(401, $status);
    [$status] = $this->request('/admin/api.php?path=config/timeline&test=1', 'PUT',
      ['Content-Type: application/json', 'X-WC-Test: 1'], str_repeat(' ', 1048577));
    self::assertSame(403, $status);
  }

  public function testInvalidJsonStillReturnsBadRequest(): void
  {
    [$status, $body] = $this->request('/admin/api.php?path=config/timeline&test=1', 'PUT',
      $this->jsonWriteHeaders(), '{');
    self::assertSame(400, $status, $body);
    self::assertSame('Invalid JSON body', json_decode($body, true)['detail']);
  }

  public function testHealthz(): void
  {
    $json = $this->getJson('/admin/api.php?path=healthz');
    $this->assertSame('ok', $json['status'] ?? null);
    $this->assertArrayNotHasKey('config_path', $json);
  }

  public function testStatusList(): void
  {
    $json = $this->getJson('/admin/api.php?path=status&test=1');
    $this->assertIsArray($json['items'] ?? null);
  }

  public function testStreamsList(): void
  {
    // Der echte HTTP-Einstieg muss die ausgelagerte Route weiterhin erreichen
    // und exakt das bestehende Übersichtsmodell als JSON ausgeben.
    $json = $this->getJson('/admin/api.php?path=streams&test=1');
    $this->assertIsArray($json['items'] ?? null);
    $this->assertIsArray($json['blocked_ips'] ?? null);
    $this->assertIsArray($json['summary']['by_camera'] ?? null);
  }

  public function testCamerasList(): void
  {
    $json = $this->getJson('/admin/api.php?path=cameras&test=1');
    $this->assertIsArray($json['cameras'] ?? null);
    $this->assertNotEmpty($json['cameras']);
  }

  public function testCameraUpdateReturnsBadRequestForInvalidTransport(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'] ?? [];
    $slug = (string) array_key_first($cameras);
    $this->assertNotSame('', $slug);

    [$status, $body] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '&test=1',
      'PUT',
      $this->jsonWriteHeaders(),
      json_encode(['capture' => ['transport' => 'ftp']], JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status, $body);
    $this->assertStringContainsString('capture.transport', $body);
  }

  public function testCameraSectionReturnsBadRequestForInvalidLabels(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'] ?? [];
    $slug = (string) array_key_first($cameras);
    $this->assertNotSame('', $slug);

    [$status, $body] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/sections/labels&test=1',
      'PUT',
      $this->jsonWriteHeaders(),
      json_encode(['labels' => 'invalid'], JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status, $body);
    $this->assertStringContainsString('labels must be an array', $body);
  }

  public function testCaptureUpdateReturnsBadRequestBeforeOpeningDatabase(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'] ?? [];
    $slug = (string) array_key_first($cameras);
    $this->assertNotSame('', $slug);

    // Der Slot muss für diesen Negativtest nicht existieren: Ein ungültiger
    // Hash wird an der neuen Normalisierungsgrenze vor jedem DB-Schreibzugriff
    // abgelehnt.
    [$status, $body] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/captures/123&test=1',
      'PUT',
      $this->jsonWriteHeaders(),
      json_encode(['hash_sha256' => 'invalid'], JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status, $body);
    $this->assertStringContainsString('hash_sha256', $body);
  }

  public function testCaptureQueryRejectsMissingRangeBeforeOpeningDatabase(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'] ?? [];
    $slug = (string) array_key_first($cameras);
    $this->assertNotSame('', $slug);

    // Ohne slot_ts, latest oder vollständigen Bereich ist die Abfrage nicht
    // eindeutig. Der neue Query-Service muss sie vor dem ersten DB-Read mit
    // derselben öffentlichen Fehlermeldung wie die frühere Route ablehnen.
    [$status, $body] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/captures&test=1',
      'GET',
      ['X-WC-Test: 1']
    );

    $this->assertSame(400, $status, $body);
    $this->assertStringContainsString('Provide slot_ts or a valid date range', $body);
  }

  public function testOverlayUploadRejectsNonImageMultipartContent(): void
  {
    $cameras = $this->getJson('/admin/api.php?path=cameras&test=1')['cameras'] ?? [];
    $slug = (string) array_key_first($cameras);
    $this->assertNotSame('', $slug);

    // Dieser HTTP-Test ergänzt die isolierten Service-Tests: Der eingebaute
    // PHP-Testserver muss den Multipart-Body wirklich in $_FILES überführen,
    // damit der Adapter is_uploaded_file() erfolgreich prüfen kann. Erst
    // danach lehnt der ausgelagerte Service den vorgetäuschten PNG-Inhalt ab.
    $boundary = 'webcam-overlay-test-' . bin2hex(random_bytes(12));
    $body = '--' . $boundary . "\r\n"
      . "Content-Disposition: form-data; name=\"file\"; filename=\"fake.png\"\r\n"
      . "Content-Type: image/png\r\n\r\n"
      . "this is not a png\r\n"
      . '--' . $boundary . "--\r\n";
    $token = bin2hex(random_bytes(16));

    [$status, $response] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/overlay&test=1',
      'POST',
      [
        'X-WC-Test: 1',
        'X-CSRF-Token: ' . $token,
        'Cookie: admin_csrf=' . $token,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
      ],
      $body
    );
    $decoded = json_decode($response, true);

    $this->assertSame(415, $status, $response);
    $this->assertIsArray($decoded);
    $this->assertSame('Unsupported media type', $decoded['detail'] ?? null);

    // Multipart darf groesser als das neue JSON-Limit bleiben; die eigene
    // Formatpruefung antwortet weiterhin 415, nicht pauschal 413.
    [$status, $response] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/overlay&test=1', 'POST',
      ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token,
        'Content-Type: multipart/form-data; boundary=' . $boundary],
      str_replace('this is not a png', str_repeat('x', 1048577), $body)
    );
    self::assertSame(415, $status, $response);

    // Ein winziger, gueltiger PNG mit zu breitem Header ergibt dagegen 413.
    $image = imagecreatetruecolor(4097, 1);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    [$status, $response] = $this->request(
      '/admin/api.php?path=cameras/' . rawurlencode($slug) . '/overlay&test=1', 'POST',
      ['X-WC-Test: 1', 'X-CSRF-Token: ' . $token, 'Cookie: admin_csrf=' . $token,
        'Content-Type: multipart/form-data; boundary=' . $boundary],
      str_replace('this is not a png', $png, $body)
    );
    self::assertSame(413, $status, $response);
    self::assertStringContainsString('dimension limit', $response);
  }

  public function testArchiveCacheStatus(): void
  {
    $json = $this->getJson('/admin/api.php?path=archive-cache&test=1');
    $this->assertArrayHasKey('enabled', $json);
    $this->assertArrayHasKey('size_bytes', $json);
    $this->assertArrayHasKey('admin_allowed', $json);
    $this->assertTrue((bool) ($json['admin_allowed'] ?? false));
  }

  /** @return list<string> */
  private function jsonWriteHeaders(): array
  {
    $token = bin2hex(random_bytes(16));
    return [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];
  }

  public function testRuntimeConfigCanBeUpdated(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];
    if (function_exists('webcam_camera_store_reset_connections')) {
      webcam_camera_store_reset_connections();
    }

    [$status, $body] = $this->request(
      '/admin/api.php?path=config/runtime&test=1',
      'PUT',
      $headers,
      json_encode(['weather_command_timeout_seconds' => 42], JSON_THROW_ON_ERROR)
    );
    $this->assertSame(200, $status, $body);
    $json = json_decode($body, true);
    $this->assertSame(42, $json['weather_command_timeout_seconds'] ?? null);

    $loaded = $this->getJson('/admin/api.php?path=config/runtime&test=1');
    $this->assertSame(42, $loaded['weather_command_timeout_seconds'] ?? null);
  }

  public function testRuntimeConfigRejectsInvalidTimeout(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];

    [$status] = $this->request(
      '/admin/api.php?path=config/runtime&test=1',
      'PUT',
      $headers,
      json_encode(['weather_command_timeout_seconds' => 301], JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status);
  }

  public function testScriptsWorkspaceReturnsSettingsDefinitionsAndStatus(): void
  {
    $json = $this->getJson('/admin/api.php?path=scripts&test=1');

    $this->assertIsArray($json['settings']['backup_daily'] ?? null);
    $this->assertIsArray($json['settings']['weather_quality'] ?? null);
    $this->assertIsArray($json['settings']['archive_rollover'] ?? null);
    $this->assertIsArray($json['definitions'] ?? null);
    $this->assertArrayHasKey('status', $json);
    $this->assertSame('camera_poll', $json['trigger']['mode'] ?? null);
    $this->assertArrayHasKey('last_tick_at', $json['trigger']);
  }

  public function testMaintenanceScriptsCanBeUpdated(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];
    $payload = [
      'archive_rollover' => ['enabled' => false, 'time' => '01:00'],
      'backup_daily' => ['enabled' => true, 'time' => '04:15'],
      'weather_quality' => ['enabled' => true, 'time' => '04:35'],
      'cleanup_overlays' => [
        'enabled' => false,
        'time' => '05:00',
        'label' => 'Overlay-Bereinigung',
        'description' => 'Entfernt verwaiste Overlays.',
        'path' => 'bin/cleanup_overlays.php',
        'arguments' => ['--delete', '--verbose'],
        'timeout_seconds' => 300,
      ],
    ];

    [$status, $body] = $this->request(
      '/admin/api.php?path=config/maintenance-scripts&test=1',
      'PUT',
      $headers,
      json_encode($payload, JSON_THROW_ON_ERROR)
    );

    $this->assertSame(200, $status, $body);
    $saved = json_decode($body, true);
    $this->assertSame('04:15', $saved['backup_daily']['time'] ?? null);
    $this->assertSame('bin/backup_daily.php', $saved['backup_daily']['path'] ?? null);
    $this->assertSame('bin/cleanup_overlays.php', $saved['cleanup_overlays']['path'] ?? null);
    $this->assertSame(['--delete', '--verbose'], $saved['cleanup_overlays']['arguments'] ?? null);

    $loaded = $this->getJson('/admin/api.php?path=config/maintenance-scripts&test=1');
    $this->assertSame($saved, $loaded);
  }

  public function testMaintenanceScriptsRejectInvalidTime(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];

    [$status] = $this->request(
      '/admin/api.php?path=config/maintenance-scripts&test=1',
      'PUT',
      $headers,
      json_encode([
        'archive_rollover' => ['enabled' => false, 'time' => '01:00'],
        'backup_daily' => ['enabled' => true, 'time' => '03:20'],
        'weather_quality' => ['enabled' => true, 'time' => '25:00'],
      ], JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status);
  }

  public function testMaintenanceScriptsRejectPathOutsideBin(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];
    $payload = Webcam\Service\Maintenance\MaintenanceSchedule::defaults();
    $payload['backup_daily']['path'] = '../backup_daily.php';

    [$status] = $this->request(
      '/admin/api.php?path=config/maintenance-scripts&test=1',
      'PUT',
      $headers,
      json_encode($payload, JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status);
  }

  public function testMaintenanceScriptsRejectMissingScript(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];
    $payload = Webcam\Service\Maintenance\MaintenanceSchedule::defaults();
    $payload['missing_script'] = [
      'enabled' => false,
      'time' => '05:00',
      'label' => 'Fehlt',
      'description' => '',
      'path' => 'bin/does-not-exist.php',
      'arguments' => [],
      'timeout_seconds' => 60,
    ];

    [$status] = $this->request(
      '/admin/api.php?path=config/maintenance-scripts&test=1',
      'PUT',
      $headers,
      json_encode($payload, JSON_THROW_ON_ERROR)
    );

    $this->assertSame(400, $status);
  }

  public function testStatusRequiresAuth(): void
  {
    [$status] = $this->request('/admin/api.php?path=status');
    $this->assertContains($status, [401, 403], 'Expected admin auth to reject missing token');
  }

  public function testAdminIndexRequiresAuthAndDoesNotLeakToken(): void
  {
    [$status, $body] = $this->request('/admin/');
    $this->assertSame(401, $status);
    $this->assertStringNotContainsString('data-api-token', $body);
    $this->assertStringNotContainsString('apiToken', $body);
  }

  public function testCsrfRequiredForPost(): void
  {
    [$status] = $this->request('/admin/api.php?path=status&test=1', 'POST', ['X-WC-Test: 1']);
    $this->assertSame(403, $status);
  }

  public function testCsrfAllowsPostWithMatchingToken(): void
  {
    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
    ];
    [$status] = $this->request('/admin/api.php?path=status&test=1', 'POST', $headers);
    $this->assertSame(404, $status);
  }

  public function testCsrfAllowsTokenMatchingOneOfDuplicatePathCookies(): void
  {
    $staleToken = bin2hex(random_bytes(16));
    $activeToken = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $activeToken,
      'Cookie: admin_csrf=' . $staleToken . '; admin_csrf=' . $activeToken,
    ];

    [$status] = $this->request('/admin/api.php?path=status&test=1', 'POST', $headers);

    $this->assertSame(404, $status);
  }

  public function testArchiveCacheRebuildIgnoresUnlockedStaleLockFile(): void
  {
    $root = realpath(__DIR__ . '/..');
    $this->assertIsString($root);

    $lockPath = $root . '/var/state/archive-cache/rebuild.lock';
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
      $this->fail('Unable to create archive-cache state dir');
    }
    file_put_contents($lockPath, "stale\n");
    touch($lockPath, time() - 3600);

    $token = bin2hex(random_bytes(16));
    $headers = [
      'X-WC-Test: 1',
      'X-CSRF-Token: ' . $token,
      'Cookie: admin_csrf=' . $token,
      'Content-Type: application/json',
    ];

    try {
      [$status, $body] = $this->request(
        '/admin/api.php?path=archive-cache/rebuild&test=1',
        'POST',
        $headers,
        json_encode([
          'slug' => 'testcam',
          'year' => (int) date('Y'),
          'dry_run' => true,
        ], JSON_THROW_ON_ERROR)
      );
    } finally {
      @unlink($lockPath);
    }

    $this->assertSame(200, $status, $body);
  }

  public function testArchiveCacheStatusFallsBackToStateTimestamp(): void
  {
    $root = realpath(__DIR__ . '/..');
    $this->assertIsString($root);

    $statePath = $root . '/var/state/archive-cache/rebuild.json';
    $stateDir = dirname($statePath);
    if (!is_dir($stateDir) && !mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
      $this->fail('Unable to create archive-cache state dir');
    }

    $finishedAt = '2026-04-12T20:19:00Z';
    file_put_contents($statePath, json_encode([
      'status' => 'done',
      'finished_at' => $finishedAt,
      'current' => null,
      'completed' => [],
      'dry_run' => false,
    ], JSON_PRETTY_PRINT));

    try {
      $json = $this->getJson('/admin/api.php?path=archive-cache&test=1');
    } finally {
      @unlink($statePath);
    }

    $this->assertSame($finishedAt, $json['last_built_at'] ?? null);
  }
}
