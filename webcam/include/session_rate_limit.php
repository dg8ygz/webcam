<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Infrastructure\Http\ClientIpResolver;
use Webcam\Service\Security\PublicRateLimiter;

/**
 * HTTP-Adapter für den öffentlichen SQLite-Limiter. Die eigentliche Zählung
 * liegt in PublicRateLimiter; hier werden nur die vertrauenswürdige Client-IP
 * aufgelöst und die HTTP-Header beziehungsweise Fehlerantwort ausgegeben.
 */

/** @param array{limit?:mixed,window_seconds?:mixed,ttl_days?:mixed}|null $settings */
function check_rate_limit(App $app, string $scope = 'public', ?array $settings = null): void
{
  $env = strtolower(trim((string) (getenv('WEBCAM_ENV') ?: '')));
  // Integrationstests können den Limiter gezielt mit einem X-Session-ID-
  // Header aktivieren. Der Header ist dabei nur ein Testschalter und fließt
  // ausdrücklich nicht mehr in das Rate-Limit-Subjekt ein.
  if ($env === 'test' && !isset($_SERVER['HTTP_X_SESSION_ID'])) {
    return;
  }

  try {
    $subject = resolve_public_rate_limit_subject($app, $_SERVER);
  } catch (Throwable $exception) {
    handle_rate_limit_failure($app, $exception);
    return;
  }
  $settings ??= $app->camerasMeta()['public_rate_limit'] ?? [];
  $settings += [
    'limit' => getenv('WEBCAM_PUBLIC_API_LIMIT') ?: 120,
    'window_seconds' => getenv('WEBCAM_PUBLIC_API_WINDOW') ?: 60,
    'ttl_days' => getenv('WEBCAM_PUBLIC_API_TTL_DAYS') ?: 7,
  ];
  try {
    $decision = (new PublicRateLimiter($app))->consume($scope, $subject, $settings);
  } catch (Throwable $exception) {
    handle_rate_limit_failure($app, $exception);
    return;
  }

  header('X-RateLimit-Limit: ' . $decision['limit']);
  header('X-RateLimit-Remaining: ' . $decision['remaining']);
  if (!$decision['allowed']) {
    header('Retry-After: ' . $decision['retry_after']);
    respond(429, ['detail' => 'Rate limit exceeded', 'retry_after' => $decision['retry_after']]);
    exit;
  }
}

function handle_rate_limit_failure(App $app, Throwable $exception): void
{
  $failOpenValue = getenv('WEBCAM_PUBLIC_RATE_FAIL_OPEN');
  $failOpen = $failOpenValue !== false && filter_var($failOpenValue, FILTER_VALIDATE_BOOL);
  if ($failOpen) {
    $app->logger()->warning('rate limit fail-open', ['error' => $exception->getMessage()]);
    return;
  }
  $app->logger()->error('rate limit hard fail', ['error' => $exception->getMessage()]);
  respond(429, ['detail' => 'Rate limit unavailable']);
  exit;
}

/**
 * Erzeugt das stabile, primär IP-basierte Subjekt des Public-Limiters.
 *
 * REMOTE_ADDR ist die Vertrauenswurzel. Forwarded-Header dürfen die Adresse
 * nur verändern, wenn der unmittelbare Absender in `trusted_proxies` steht;
 * die gemeinsame ClientIpResolver-Logik prüft die Kette von rechts nach links.
 * Eine frei gewählte Session-ID wird bewusst nicht angehängt: Würde sie Teil
 * des Datenbankschlüssels, könnte ihre Rotation das IP-Budget erneut teilen
 * und weiterhin unbegrenzt neue SQLite-Zeilen erzeugen.
 *
 * @param array<string,mixed> $server
 */
function resolve_public_rate_limit_subject(App $app, array $server): string
{
  $configured = $app->config('trusted_proxies', []);
  $trustedProxies = is_array($configured)
    ? array_values(array_filter(array_map(
      static fn(mixed $proxy): string => trim((string) $proxy),
      $configured
    )))
    : [];
  if ($trustedProxies === []) {
    $trustedProxies = ['127.0.0.1', '::1'];
  }

  $identity = (new ClientIpResolver($trustedProxies))->resolve($server);
  $clientIp = trim((string) ($identity['ip'] ?? ''));
  if ($clientIp === '') {
    throw new RuntimeException('Unable to resolve public rate-limit client IP');
  }

  return 'ip:' . $clientIp;
}
