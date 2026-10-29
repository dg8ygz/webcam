<?php
declare(strict_types=1);

use Webcam\App;

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/captcha_state.php';
require_once __DIR__ . '/session_rate_limit.php';

/**
 * Gemeinsame Schutzschicht fuer beide oeffentlichen Like-Endpunkte.
 *
 * Lesefuehrung:
 * 1. Eine Captcha-Challenge wird genau einmal verbraucht.
 * 2. Nur ein gueltiges Captcha erreicht das gemeinsame IP-Budget.
 * 3. Das IP-Budget verwendet dieselben Grenzen wie das fachliche Like-Limit.
 *
 * Der Session-Cookie bleibt fuer „dieses Bild bereits geliked“ nuetzlich, ist
 * aber absichtlich kein Sicherheitsanker: Ein Client kann Cookies loeschen.
 * Das zusaetzliche SQLite-Budget wird deshalb ueber die vertrauenswuerdig
 * aufgeloeste Client-IP gebildet und gilt gemeinsam fuer `like.php` und
 * `bestof-toggle.php`.
 *
 * @param array<string,mixed> $requestData
 */
function require_public_like_protection(App $app, array $requestData): bool
{
  if (!public_test_bypass_allowed()) {
    $captchaId = trim((string) ($requestData['captcha_id'] ?? ''));
    $captchaCode = trim((string) ($requestData['captcha_code'] ?? ''));
    if (!validate_public_like_captcha($app, $captchaId, $captchaCode)) {
      // Ungueltige Eingaben bleiben HTTP 200, damit der vorgeschaltete
      // Fail2Ban-404/4xx-Zaehler nicht durch normale Tippfehler anschlaegt.
      respond(200, [
        'ok' => false,
        'error' => 'Bitte das angezeigte Captcha eingeben.',
        'code' => 'invalid_captcha',
      ]);
      return false;
    }
  }

  // Das feste IP-Budget liegt vor Dateikopie und Like-DB. Beide Endpunkte
  // verwenden denselben Scope; ein Wechsel zwischen den URLs erzeugt daher
  // kein zweites Kontingent. `check_rate_limit()` antwortet bei Ueberschreitung
  // oder fehlender vertrauenswuerdiger Client-IP fail-closed.
  check_rate_limit($app, 'likes', [
    'limit' => $app->config('likes_max_per_window', 10),
    'window_seconds' => $app->config('likes_window_seconds', 3600),
    'ttl_days' => getenv('WEBCAM_PUBLIC_API_TTL_DAYS') ?: 7,
  ]);

  return true;
}

/**
 * Kapselt den fehlertoleranten Zugriff auf den kurzlebigen Captcha-State.
 * Interne Speicherfehler werden protokolliert und wie ein ungueltiger Code
 * behandelt; ein Like darf bei unklarem Schutzstatus nie geschrieben werden.
 */
function validate_public_like_captcha(App $app, string $captchaId, string $captchaCode): bool
{
  try {
    return captcha_state_validate(
      $app->paths()->statePath('captcha'),
      $captchaId,
      $captchaCode,
      time(),
      600
    );
  } catch (Throwable $exception) {
    $app->logger()->warning('captcha validation failed', ['error' => $exception->getMessage()]);
    return false;
  }
}
