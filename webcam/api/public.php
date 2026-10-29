<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Http\Public\PublicBestOfHandler;
use Webcam\Http\Public\PublicCameraHandler;
use Webcam\Http\Public\PublicTimelineHistoryHandler;

/**
 * Public API entry (read-only):
 * - GET /cameras                          → overview list (pagination)
 * - GET /bestof                          → global Best-Of list (paged)
 * - GET /cameras/{slug}                   → camera detail + status + best-of
 * - GET /cameras/{slug}/bestof           → Best-Of list (paged)
 * - GET /cameras/{slug}/timelapse         → sampled frames for timelapse player
 * - GET /cameras/{slug}/timeline          → timeline + navigation meta
 * - GET /cameras/{slug}/history           → history day/month lists
 *   (legacy auch via ?resource=history&slug=...)
 *
 * Fehler werden absichtlich nur generisch beantwortet; Details landen im Server-Log.
 *
 * Leseführung: Nach Bootstrap und Rate-Limit ordnet route() den Request einem
 * der kommentierten Public-Handler zu. Im Einstieg verbleibt nur die
 * URL-Segmentnormalisierung. Interne Exceptions werden zentral protokolliert
 * und niemals als technische Details öffentlich gemacht.
 */
/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/api_helpers.php';
require_once __DIR__ . '/../include/session_rate_limit.php';

// ---------------------------------------------------------------------------
// Request bootstrap & routing setup (method, path segments, rate-limit).
// ---------------------------------------------------------------------------
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = resolve_segments();
if (isset($_GET['resource']) && $_GET['resource'] === 'history' && !empty($_GET['slug'])) {
  $segments = ['cameras', (string)$_GET['slug'], 'history'];
}

$isHistoryRequest = ($segments[0] ?? '') === 'cameras' && ($segments[2] ?? '') === 'history';
if ($isHistoryRequest) {
  check_rate_limit($app, 'history', [
    'limit' => getenv('WEBCAM_HISTORY_API_LIMIT') ?: 30,
    'window_seconds' => getenv('WEBCAM_HISTORY_API_WINDOW') ?: 60,
    'ttl_days' => getenv('WEBCAM_PUBLIC_API_TTL_DAYS') ?: 7,
  ]);
} elseif ($segments !== ['cameras']) {
  check_rate_limit($app);
}

try {
  route($method, $segments, $app);
} catch (Throwable $exception) {
  log_internal_error($app->logger(), $exception, ['route' => $segments]);
  respond(500, ['detail' => 'Internal error']);
}

exit;

/**
 * Ordnet Methode und URL-Segmente genau einem öffentlichen Endpunkt zu.
 *
 * Jede erfolgreiche Verzweigung beendet sich über respond() und return. Dadurch
 * kann kein Request versehentlich in die nächste Route fallen. Diese API liest
 * regulär nur; die optionale Dayvideo-Erzeugung ist separat abgeschaltet und
 * muss ausdrücklich freigegeben sein.
 */
function route(string $method, array $segments, App $app): void
{
  if ((new \Webcam\Http\Public\PublicPresentationHandler($app))->handle($method, $segments, $_GET)) {
    return;
  }
  if ((new PublicBestOfHandler($app))->handle($method, $segments, $_GET)) {
    return;
  }
  if ((new PublicCameraHandler($app))->handle($method, $segments, $_GET)) {
    return;
  }
  if ((new PublicTimelineHistoryHandler($app))->handle($method, $segments, $_GET)) {
    return;
  }
  if ($method === "GET" && count($segments) === 3 && $segments[0] === "cameras" && $segments[2] === "dayvideo") {
    (new \Webcam\Http\PublicDayvideoController($app))->handle($segments[1], $_GET, $_SERVER);
    return;
  }

  respond(404, ["detail" => "Not found"]);
}

/**
 * Vereinheitlicht Rewrite-, PATH_INFO- und Query-Varianten der API-URL.
 * Ein WAF-kompatibler resource/slug-Aufruf bleibt als Legacy-Eingang erhalten;
 * ohne Pfad wird die Kameraübersicht gewählt.
 *
 * @return list<string>
 */
function resolve_segments(): array
{
  // Highest priority: explicit ?path=... param
  if (!empty($_GET['path'])) {
    $segments = array_values(array_filter(explode('/', (string) $_GET['path']), static fn(string $s): bool => $s !== ''));
    if ($segments) {
      return $segments;
    }
  }

  // Fallback: explicit resource/slug query (avoids slashes in ?path= for WAF)
  if (!empty($_GET['resource']) && !empty($_GET['slug'])) {
    $res = (string) $_GET['resource'];
    $slug = (string) $_GET['slug'];
    if ($res === 'history') {
      return ['cameras', $slug, 'history'];
    }
    if ($res === 'timelapse') {
      return ['cameras', $slug, 'timelapse'];
    }
  }

  $pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
  if ($pathInfo !== '') {
    $segments = array_values(array_filter(explode('/', $pathInfo), static fn(string $s): bool => $s !== ''));
    if ($segments) {
      return $segments;
    }
  }

  $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $relative = ltrim(substr($requestUri, strlen($scriptDir)), '/');
  $relative = preg_replace('#^api/public/?#', '', $relative ?? '');
  $relative = preg_replace('#^public\.php/?#', '', $relative ?? '');
  $segments = array_values(array_filter(explode('/', (string) $relative), static fn(string $s): bool => $s !== ''));

  return $segments ?: ['cameras'];
}
