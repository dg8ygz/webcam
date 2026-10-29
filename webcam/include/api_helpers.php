<?php
declare(strict_types=1);

/**
 * Gemeinsame frameworkfreie HTTP-Helfer für Public-, History- und Like-Endpunkte.
 * Sie kapseln JSON-Antworten, internes Fehlerlogging, Pfadnormalisierung und den
 * öffentlichen Double-Submit-CSRF-Check.
 */

if (!function_exists('respond')) {
  /**
   * Emit JSON with a given status; falls back to a generic error if encoding fails.
   */
  function respond(int $status, ?array $payload): void
  {
    http_response_code($status);
    if ($payload === null) {
      return;
    }
    try {
      echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      http_response_code(500);
      echo '{"detail":"encoding_error"}';
    }
  }
}

if (!function_exists('log_internal_error')) {
  /**
   * Log a generic internal error with context; keeps messages consistent.
   */
  function log_internal_error($logger, \Throwable $exception, array $context = []): void
  {
    if ($logger && method_exists($logger, 'error')) {
      $context['error'] = $exception->getMessage();
      $logger->error('api error', $context);
    }
  }
}

// ---------------------------------------------------------------------------
// Shared request helpers (CSRF + path normalization).
// ---------------------------------------------------------------------------
if (!function_exists('public_test_bypass_allowed')) {
  /**
   * Test-Bypass nur in echter Testumgebung zulassen.
   *
   * Wichtig: `?test=1` oder `X-WC-TEST: 1` dürfen in Produktion keine
   * mutierenden Endpunkte freischalten. PHPUnit/Built-in-Server laufen als
   * Loopback-Requests mit `WEBCAM_ENV=test`, direkte CLI-Includes haben keine
   * REMOTE_ADDR und bleiben deshalb ebenfalls erlaubt.
   */
  function public_test_bypass_allowed(): bool
  {
    $isTaggedTestRequest = (($_SERVER['HTTP_X_WC_TEST'] ?? '') === '1')
      || ((isset($_GET['test']) && $_GET['test'] === '1'));
    if (!$isTaggedTestRequest) {
      return false;
    }

    $env = strtolower(trim((string) (getenv('WEBCAM_ENV') ?: '')));
    if ($env !== 'test') {
      return false;
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote === '' || in_array($remote, ['127.0.0.1', '::1'], true);
  }
}

if (!function_exists('require_public_csrf')) {
  /**
   * Simple CSRF/Origin guard for public mutations (likes, best-of).
   */
  function require_public_csrf(\Webcam\App $app): bool
  {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
      return true;
    }
    if (public_test_bypass_allowed()) {
      return true;
    }

    $cookie = $_COOKIE['admin_csrf'] ?? ($_COOKIE['webcam_csrf'] ?? '');
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($cookie) && is_string($header) && $cookie !== '' && hash_equals($cookie, trim($header))) {
      return true;
    }

    // Fallback: Origin/Referer must match same host
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $base = (string) $app->config('base_url', '');
    $baseHost = parse_url($base, PHP_URL_HOST) ?: $host;
    $check = static function (string $value, string $expectedHost): bool {
      if ($value === '') return false;
      $h = parse_url($value, PHP_URL_HOST);
      return $h !== false && $h !== null && strcasecmp($h, $expectedHost) === 0;
    };
    if ($check($origin, (string) $baseHost) || $check($referer, (string) $baseHost)) {
      return true;
    }

    respond(403, ['ok' => false, 'error' => 'CSRF/Origin Prüfung fehlgeschlagen']);
    return false;
  }
}

if (!function_exists('normalize_relative_path')) {
  /**
   * Normalize a relative storage path (no "..", no empty segments).
   */
  function normalize_relative_path(string $path): ?string
  {
    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('#/+#', '/', $normalized);
    $normalized = trim((string) $normalized);
    if ($normalized === '') {
      return null;
    }
    $normalized = ltrim($normalized, '/');
    if ($normalized === '') {
      return null;
    }
    $segments = explode('/', $normalized);
    $cleanSegments = [];
    foreach ($segments as $segment) {
      $segment = trim($segment);
      if ($segment === '' || $segment === '.' || $segment === '..') {
        return null;
      }
      if (str_contains($segment, ':')) {
        return null;
      }
      $cleanSegments[] = $segment;
    }
    return implode('/', $cleanSegments);
  }
}

if (!function_exists('normalizeRelativePath')) {
  /**
   * CamelCase alias for normalize_relative_path.
   */
  function normalizeRelativePath(string $path): ?string
  {
    return normalize_relative_path($path);
  }
}

if (!function_exists('pathIncludesSegment')) {
  /**
   * Checks whether a path contains the given slug as a segment.
   */
  function pathIncludesSegment(string $path, string $slug): bool
  {
    $segments = explode('/', strtolower($path));
    return in_array(strtolower($slug), $segments, true);
  }
}

if (!function_exists('path_includes_slug')) {
  /**
   * Snake_case alias for pathIncludesSegment.
   */
  function path_includes_slug(string $path, string $slug): bool
  {
    return pathIncludesSegment($path, $slug);
  }
}

// ---------------------------------------------------------------------------
// Archive helper: nearest entry for a target timestamp.
// ---------------------------------------------------------------------------
if (!function_exists('nearest_entry_for_timestamp')) {
  function nearest_entry_for_timestamp(
    \Webcam\App $app,
    \Webcam\Service\Storage\ArchiveBrowser $archive,
    \Webcam\Domain\Camera $camera,
    int $targetTs,
    DateTimeZone $tz,
    int $windowDays = 0,
    ?\Webcam\Infrastructure\Storage\CameraDataStore $store = null
  ): ?array {
    $dt = (new DateTimeImmutable('@' . $targetTs))->setTimezone($tz);
    $dayStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dt->format('Y-m-d') . ' 00:00:00', $tz);
    $dayEnd = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dt->format('Y-m-d') . ' 23:59:59', $tz);
    if (!$dayStart || !$dayEnd) {
      $dayStart = $dt->setTime(0, 0, 0);
      $dayEnd = $dt->setTime(23, 59, 59);
    }
    $windowDays = max(0, min(30, $windowDays));
    $start = $dayStart->getTimestamp();
    $end = $dayEnd->getTimestamp();
    if ($windowDays > 0) {
      $start = $dayStart->modify(sprintf('-%d days', $windowDays))->getTimestamp();
      $end = $dayEnd->modify(sprintf('+%d days', $windowDays))->getTimestamp();
    }
    try {
      $dbEntry = $archive->nearestEntryFromDatabase($camera, $targetTs, $start, $end, $store);
      if ($dbEntry !== null) {
        return $dbEntry;
      }
      if ($store !== null && $store->rangeContainsOnlyDuplicateCaptures($start, $end)) {
        return null;
      }
    } catch (\Throwable) {
      // DB lookup is optional; fall back to filesystem scans
    }
    $y = (int) $dt->format('Y');
    $m = (int) $dt->format('n');
    $d = (int) $dt->format('j');
    $dayData = $archive->entriesForDay($camera, $y, $m, $d);
    $entries = $dayData['entries'] ?? [];
    if (!$entries) {
      return null;
    }
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($entries as $entry) {
      $ts = (int) ($entry['timestamp'] ?? 0);
      $diff = abs($ts - $targetTs);
      if ($diff < $bestDiff) {
        $best = $entry;
        $bestDiff = $diff;
      }
    }
    if ($best) {
      return $best;
    }
    for ($i = 1; $i <= $windowDays; $i++) {
      $future = $dt->modify(sprintf('+%d days', $i));
      if ($future instanceof DateTimeImmutable) {
        $entry = nearest_entry_for_timestamp($app, $archive, $camera, $future->getTimestamp(), $tz, 0, $store);
        if ($entry) {
          return $entry;
        }
      }
      $past = $dt->modify(sprintf('-%d days', $i));
      if ($past instanceof DateTimeImmutable) {
        $entry = nearest_entry_for_timestamp($app, $archive, $camera, $past->getTimestamp(), $tz, 0, $store);
        if ($entry) {
          return $entry;
        }
      }
    }
    return null;
  }
}
