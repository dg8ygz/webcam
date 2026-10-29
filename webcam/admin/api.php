<?php
declare(strict_types=1);

use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Http\Admin\ArchiveCacheAdminHandler;
use Webcam\Http\Admin\CameraAdminHandler;
use Webcam\Http\Admin\GlobalConfigAdminHandler;
use Webcam\Http\Admin\StatusAdminHandler;
use Webcam\Http\Admin\StreamAdminHandler;

require_once __DIR__ . '/../include/api_helpers.php';
require_once __DIR__ . '/../include/admin_auth.php';

// Adminantworten koennen private Vorschaubilder enthalten.
header('Cache-Control: private, no-store');

// Die Datei ist HTTP-Einstiegspunkt und Router der Admin-API. Jeder Request wird
// zuerst authentifiziert; schreibende Methoden brauchen zusätzlich den
// Double-Submit-CSRF-Nachweis. route() delegiert Kamera-, globale Config- und
// Status-, Stream-, Kamera-, globale Config- und Legacy-Archivcache-Anfragen
// geschlossen an ihre kommentierten Handler.
//
// Leseführung:
// 1. Authentifizierung und CSRF prüfen,
// 2. Methode + URL in route() einer Aktion zuordnen,
// 3. fachliche Unterressourcen nur bei passendem Präfix an Handler übergeben,
// 4. Status und Streams an ihre schmalen HTTP-Handler delegieren,
// 5. genau ein neutrales Ergebnis als JSON ausgeben.
//
// Die ausgelagerten Handler kennen weder Admin-Token noch CSRF-Cookies und
// senden selbst keine Antwort. Damit bleibt diese Datei die einzige öffentliche
// HTTP-Grenze. Im Router verbleibt dadurch kein fachlicher Admin-Routenblock.

/**
 * Admin auth: Bearer oder X-Admin-Token Header, optional Basic-Fallback per Env.
 * Query-Token ist absichtlich nicht erlaubt (landet in Logs/Referer).
 */
function admin_is_test_request(): bool
{
  return (($_SERVER['HTTP_X_WC_TEST'] ?? '') === '1')
    || ((isset($_GET['test']) && $_GET['test'] === '1'));
}

function admin_is_loopback_request(): bool
{
  $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
  return in_array($remote, ['127.0.0.1', '::1'], true);
}

function admin_test_bypass_allowed(): bool
{
  $env = strtolower(trim((string) (getenv('WEBCAM_ENV') ?: '')));
  return admin_is_test_request()
    && $env === 'test'
    && admin_is_loopback_request();
}

function admin_legacy_archive_cache_allowed(): bool
{
  $envOverride = getenv('WEBCAM_ADMIN_ALLOW_LEGACY_ARCHIVE_CACHE');
  if (is_string($envOverride) && trim($envOverride) !== '') {
    return filter_var($envOverride, FILTER_VALIDATE_BOOL);
  }

  return strtolower(trim((string) (getenv('WEBCAM_ENV') ?: ''))) === 'test';
}

function require_auth(\Webcam\App $app): void
{
  if (admin_test_bypass_allowed()) {
    return;
  }
  $token = adminExpectedToken($app);
  if ($token === '') {
    respond(401, ['detail' => 'Admin token not configured']);
    exit;
  }

  if (!adminIsAuthorized($app)) {
    adminSendAuthChallenge();
    respond(401, ['detail' => 'Unauthorized']);
    exit;
  }
}

/**
 * Admin CSRF: Double-Submit (Cookie admin_csrf + Header X-CSRF-Token) für mutierende Methoden.
 */
function require_csrf(): void
{
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
    return;
  }

  $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!is_string($header) || !preg_match('/^[a-f0-9]{32,64}$/', trim($header))) {
    respond(403, ['detail' => 'Invalid CSRF token']);
    exit;
  }
  $header = trim($header);

  // Browser koennen nach Alias-/Pfadwechseln mehrere gleichnamige Cookies mit
  // unterschiedlichen Path-Attributen mitsenden. PHP reduziert diese auf
  // einen Wert, waehrend document.cookie im Frontend einen anderen liefern
  // kann. Deshalb alle tatsaechlich gesendeten admin_csrf-Werte pruefen.
  $cookieTokens = [];
  $parsedCookie = $_COOKIE['admin_csrf'] ?? '';
  if (is_string($parsedCookie) && preg_match('/^[a-f0-9]{32,64}$/', $parsedCookie)) {
    $cookieTokens[] = $parsedCookie;
  }
  $rawCookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';
  if (is_string($rawCookieHeader)) {
    foreach (explode(';', $rawCookieHeader) as $cookiePart) {
      $pair = explode('=', trim($cookiePart), 2);
      if (($pair[0] ?? '') !== 'admin_csrf') {
        continue;
      }
      $candidate = rawurldecode((string) ($pair[1] ?? ''));
      if (preg_match('/^[a-f0-9]{32,64}$/', $candidate)) {
        $cookieTokens[] = $candidate;
      }
    }
  }
  $cookieTokens = array_values(array_unique($cookieTokens));
  if ($cookieTokens === []) {
    respond(403, ['detail' => 'CSRF token missing']);
    exit;
  }

  foreach ($cookieTokens as $cookieToken) {
    if (hash_equals($cookieToken, $header)) {
      return;
    }
  }

  respond(403, ['detail' => 'Invalid CSRF token']);
  exit;
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$pathParam = isset($_GET['path']) ? trim((string) $_GET['path'], '/') : '';
if ($pathInfo !== '') {
  $segments = array_values(array_filter(explode('/', $pathInfo), static fn(string $s): bool => $s !== ''));
} elseif ($pathParam !== '') {
  $segments = array_values(array_filter(explode('/', $pathParam), static fn(string $s): bool => $s !== ''));
} else {
  $relative = ltrim(substr($requestUri, strlen($scriptDir)), '/');
  $relative = preg_replace('#^api/admin/?#', '', $relative ?? '');
  $relative = preg_replace('#^api.php/?#', '', $relative ?? '');
  $segments = array_values(array_filter(explode('/', $relative ?? ''), static fn(string $s): bool => $s !== ''));
}

// ---------------------------------------------------------------------------
// Config store + route dispatch.
// ---------------------------------------------------------------------------
$store = new ConfigStore($app);

try {
  route($method, $segments, $store, $app);
} catch (\Webcam\Infrastructure\Config\ConfigConflictException $e) {
  respond(409, ['detail' => 'Die Konfiguration wurde zwischenzeitlich geändert. Bitte neu laden und die Änderung erneut prüfen.']);
} catch (Throwable $e) {
  $app->logger()->error('admin api error', ['error' => $e->getMessage()]);
  respond(500, ['detail' => 'Internal error']);
}

exit;

/**
 * Ordnet Methode und URL einer Admin-Aktion zu.
 *
 * Nur healthz liegt vor der Authentifizierung. Danach gelten Admin-Token und bei
 * schreibenden Methoden zusätzlich CSRF. Danach wird nur der zum Pfad passende
 * Handler konstruiert; er liefert Status und JSON-Daten zurück, während diese
 * Funktion die tatsächliche HTTP-Antwort ausgibt.
 */
function route(string $method, array $segments, ConfigStore $store, \Webcam\App $app): void
{
  $isTestRequest = admin_test_bypass_allowed();
  $legacyArchiveCacheAllowed = admin_legacy_archive_cache_allowed();
  // -------------------------------------------------------------------------
  // Health endpoint (no auth).
  // -------------------------------------------------------------------------
  // /healthz
  if ($method === 'GET' && ($segments === ['healthz'] || $segments === ['health'])) {
    respond(200, [
      'status' => 'ok',
      'version' => ($store->load()['version'] ?? null),
    ]);
    return;
  }

  // All other routes require auth
  require_auth($app);
  require_csrf();

  // Die Statusaufbereitung ist lesend und wird nur für den exakten Endpunkt
  // konstruiert. Der Router kennt weder Statusfelder noch Intervallgrenzen.
  if (($segments[0] ?? null) === 'status') {
    $statusAdminHandler = new StatusAdminHandler($app);
    $result = $statusAdminHandler->handle($method, $segments);
    respond($result->status, $result->payload);
    return;
  }

  // Viewerübersicht, Trennen und IP-Sperren liegen hinter derselben zentralen
  // Auth-/CSRF-Grenze, ihre Runtime-Details aber im StreamAdminHandler.
  if (($segments[0] ?? null) === 'streams') {
    $streamAdminHandler = new StreamAdminHandler($app);
    $result = $streamAdminHandler->handle(
      $method,
      $segments,
      static fn(): array => readJson()
    );
    respond($result->status, $result->payload);
    return;
  }

  // Globale Einstellungen und die zugehörige Wartungsübersicht teilen einen
  // eigenen Handler. Er wird erst für passende Pfade erzeugt; Status-, Stream-
  // oder Kameraanfragen laden dadurch keine unnötigen Config-Hilfsdienste.
  if (($segments[0] ?? null) === 'config' || $segments === ['scripts']) {
    $globalConfigHandler = new GlobalConfigAdminHandler($app, $store, dirname(__DIR__));
    $result = $globalConfigHandler->handle(
      $method,
      $segments,
      static fn(): array => readJson()
    );
    respond($result->status, $result->payload);
    return;
  }

  // Das optionale Legacy-Werkzeug besitzt seine eigene Prozess- und
  // Lock-Grenze. Der Handler wird nur für archive-cache-Pfade konstruiert.
  if (($segments[0] ?? null) === 'archive-cache') {
    $archiveCacheHandler = new ArchiveCacheAdminHandler(
      $app,
      $store,
      dirname(__DIR__),
      $legacyArchiveCacheAllowed,
      $isTestRequest
    );
    $result = $archiveCacheHandler->handle(
      $method,
      $segments,
      $_GET,
      static fn(): array => readJson()
    );
    respond($result->status, $result->payload);
    return;
  }

  // Alle Kamera-Unterressourcen werden nach zentraler Auth-/CSRF-Prüfung an
  // ihren eigenen HTTP-Handler gegeben. Der globale Router kennt weder
  // Kamera-Payloadfelder noch SQLite- oder Multipart-Details.
  if (($segments[0] ?? null) === 'cameras') {
    $cameraAdminHandler = new CameraAdminHandler($app, $store);
    $result = $cameraAdminHandler->handle(
      $method,
      $segments,
      $_GET,
      $_FILES,
      static fn(): array => readJson()
    );
    respond($result->status, $result->payload);
    return;
  }

  respond(404, ['detail' => 'Not found']);
}

/**
 * Liest genau ein JSON-Objekt aus dem Request und lehnt ungültigen Body mit 400 ab.
 *
 * @return array<string,mixed>
 */
function readJson(): array
{
  // Nur JSON-Routen lesen hier; Multipart-Overlays behalten ihr eigenes Limit.
  // Ein zusaetzliches Byte erkennt Uebergroesse auch ohne Content-Length,
  // bevor json_decode() weitere Strukturen im Speicher anlegt.
  $limit = 1024 * 1024;
  $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? '';
  if (is_string($declaredLength) && ctype_digit($declaredLength)
    && (float) $declaredLength > $limit
  ) {
    respond(413, ['detail' => 'Admin JSON body exceeds 1 MiB limit']);
    exit;
  }
  $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
  if ($raw === false) {
    throw new RuntimeException('Unable to read admin JSON body');
  }
  if (strlen($raw) > $limit) {
    respond(413, ['detail' => 'Admin JSON body exceeds 1 MiB limit']);
    exit;
  }
  if (trim($raw) === '') {
    return [];
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    respond(400, ['detail' => 'Invalid JSON body']);
    exit;
  }
  return $data;
}

// Use shared respond() from api_helpers (kept for clarity that we intentionally allow JSON_PRETTY_PRINT)
