<?php
declare(strict_types=1);

use Webcam\App;

/** Basic Auth ist nur als ausdrücklich gesetzter Betriebs-Fallback erlaubt. */
function adminAuthAllowBasic(): bool
{
  return filter_var(getenv('WEBCAM_ADMIN_ALLOW_BASIC'), FILTER_VALIDATE_BOOL);
}

/** Liefert den konfigurierten Admin-Token, optional das Basic-Passwort im Fallback. */
function adminExpectedToken(App $app): string
{
  $token = trim((string) $app->config('admin_api_token', ''));
  $basicPass = $_SERVER['PHP_AUTH_PW'] ?? '';
  if ($token === '' && adminAuthAllowBasic() && $basicPass !== '') {
    return (string) $basicPass;
  }

  return $token;
}

/** Liest Bearer bzw. X-Admin-Token; Query-Parameter werden bewusst ignoriert. */
function adminProvidedToken(): string
{
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (stripos($header, 'Bearer ') === 0) {
    return trim(substr($header, 7));
  }

  if (isset($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
    return trim((string) $_SERVER['HTTP_X_ADMIN_TOKEN']);
  }

  if (adminAuthAllowBasic()) {
    $basicPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($basicPass !== '') {
      return (string) $basicPass;
    }
  }

  return '';
}

/** Vergleicht erwarteten und gelieferten Token timing-sicher mit hash_equals. */
function adminIsAuthorized(App $app): bool
{
  $token = adminExpectedToken($app);
  if ($token === '') {
    return false;
  }

  $provided = adminProvidedToken();
  if ($provided === '') {
    return false;
  }

  return hash_equals($token, $provided);
}

/** Sendet die zum aktivierten Auth-Modus passenden 401-Header. */
function adminSendAuthChallenge(): void
{
  if (!headers_sent() && adminAuthAllowBasic()) {
    header('WWW-Authenticate: Basic realm="Webcam Admin"');
  }
}
