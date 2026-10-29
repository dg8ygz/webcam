<?php
declare(strict_types=1);

final class BestofToggleEndpointTest extends WebcamHttpTestCase
{
    public function testRejectsGetRequests(): void
    {
        [$status, $out] = $this->request('/bestof-toggle.php');

        $this->assertSame(405, $status);
        $this->assertNotEmpty($out, 'bestof-toggle should emit JSON on GET');
        $this->assertStringContainsString('Nur POST', $out);
    }

    public function testRejectsPathsWhereSlugIsOnlyNestedSegment(): void
    {
        $slug = $this->firstSlug();
        $payload = json_encode([
            'slug' => $slug,
            'path' => 'cache/other/' . $slug . '/2026/05/25/20260525_120000_la.jpg',
        ], JSON_THROW_ON_ERROR);

        [$status, $out] = $this->request(
            '/bestof-toggle.php?test=1',
            'POST',
            [
                'X-WC-Test: 1',
                'Content-Type: application/json',
            ],
            $payload
        );

        $this->assertSame(403, $status);
        $this->assertStringContainsString('Pfad stimmt nicht mit der Kamera', $out);
    }

    public function testValidImageStillRequiresCaptcha(): void
    {
        $slug = $this->firstSlug();
        $timestamp = TestFixtures::seedTimestamp() ?? time();
        /** @var Webcam\App $app */
        $app = require __DIR__ . '/../bootstrap.php';
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(
            new DateTimeZone((string) $app->config('timezone', 'UTC'))
        );
        $relativePath = sprintf(
            'cache/%s/%s/%s_la.jpg',
            $slug,
            $date->format('Y/m/d'),
            $date->format('Ymd_His')
        );
        $csrf = bin2hex(random_bytes(16));
        $payload = json_encode([
            'slug' => $slug,
            'path' => $relativePath,
        ], JSON_THROW_ON_ERROR);

        [$status, $body] = $this->request('/bestof-toggle.php', 'POST', [
            'Content-Type: application/json',
            'Cookie: webcam_csrf=' . $csrf,
            'X-CSRF-Token: ' . $csrf,
            'X-Forwarded-For: 198.51.100.73',
        ], $payload);

        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((bool) ($response['ok'] ?? true));
        self::assertSame('invalid_captcha', $response['code'] ?? null);
    }
}
