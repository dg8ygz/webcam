<?php
declare(strict_types=1);

require_once __DIR__ . '/camera_store.php';
require_once __DIR__ . '/../src/Support/helpers.php';

// Diese Datei baut die gemeinsame Laufzeitkonfiguration auf. Sie erkennt zuerst
// den URL-Prefix der aktuellen Installation, löst danach Pfade und Env-Werte auf
// und bindet zuletzt die Kamerakonfiguration aus der Config-SQLite ein. Sie gibt
// nur ein Array zurück; das App-Objekt wird anschließend in bootstrap.php gebaut.
$trustedProxies = webcam_trusted_proxy_list(getenv('WEBCAM_TRUSTED_PROXIES'));
$forwardedPrefix = webcam_trusted_forwarded_prefix($_SERVER, $trustedProxies);
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$detectedBase = '';

$shouldDetect = PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
if ($forwardedPrefix !== '') {
  $detectedBase = '/' . ltrim($forwardedPrefix, '/');
  $detectedBase = rtrim($detectedBase, '/');
}

if ($detectedBase === '' && $shouldDetect && $requestUri !== '') {
  $path = parse_url($requestUri, PHP_URL_PATH);
  if (is_string($path)) {
    $path = rtrim($path, '/');
    $segments = array_values(array_filter(explode('/', ltrim($path, '/')), static fn(string $part): bool => $part !== ''));
    if ($segments) {
      $last = end($segments);
      if ($last !== false && str_contains($last, '.')) {
        array_pop($segments);
        $last = $segments ? end($segments) : false;
      }
      if ($last === 'admin' || $last === 'status' || $last === 'weather') {
        array_pop($segments);
      }
      if ($segments) {
        $last = end($segments);
        $secondLast = count($segments) > 1 ? $segments[count($segments) - 2] : null;
        if ($last === 'camera') {
          array_pop($segments);
        } elseif ($secondLast === 'camera') {
          array_pop($segments);
          array_pop($segments);
        }
      }
      if ($segments) {
        $detectedBase = '/' . implode('/', $segments);
      }
    }
  }
}

if ($detectedBase === '' && $shouldDetect && $scriptName !== '') {
  $scriptDir = str_replace('\\', '/', dirname($scriptName));
  if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
    $scriptDir = '';
  }
  $scriptDir = rtrim($scriptDir, '/');
  if ($scriptDir !== '') {
    if (str_ends_with($scriptDir, '/admin')) {
      $scriptDir = rtrim(substr($scriptDir, 0, -6), '/');
    } elseif (str_ends_with($scriptDir, '/status')) {
      $scriptDir = rtrim(substr($scriptDir, 0, -7), '/');
    }
    $detectedBase = $scriptDir;
  }
}

if ($detectedBase === '' && $requestUri !== '') {
  $path = parse_url($requestUri, PHP_URL_PATH);
  if (is_string($path)) {
    $path = rtrim($path, '/');
    if ($path !== '') {
      if (preg_match('#/camera(?:/[^/]+)?$#', $path)) {
        $path = preg_replace('#/camera(?:/[^/]+)?$#', '', $path) ?? $path;
      } elseif (preg_match('#/(admin|status|weather)$#', $path)) {
        $path = preg_replace('#/(admin|status|weather)$#', '', $path) ?? $path;
      }
      $detectedBase = $path;
    }
  }
}

$detectedBase = $detectedBase === '/' ? '' : $detectedBase;

$envBase = getenv('WEBCAM_BASE_URL');
if ($envBase !== false && trim($envBase) !== '') {
  $baseUrl = trim($envBase, '/');
} elseif ($detectedBase !== '') {
  $baseUrl = trim($detectedBase, '/');
} else {
  $baseUrl = '';
}

// Falls der Pfad noch das Skript enthält (z.B. /webcam/camera.php), entferne die Datei.
$baseUrl = preg_replace('#/camera\\.php$#i', '', $baseUrl) ?? $baseUrl;
$baseUrl = trim($baseUrl, '/');

$envAssets = getenv('WEBCAM_ASSETS_URL');
if ($envAssets !== false && trim($envAssets) !== '') {
  $assetsUrl = trim($envAssets, '/');
} else {
  $assetsUrl = 'assets';
}

$defaultCameraDbPath = getenv('WEBCAM_CAMERA_DB_PATH');
$cameraDbAutoPath = '/var/lib/webcam/camera-db';
$cameraDbPath = $defaultCameraDbPath !== false && trim($defaultCameraDbPath) !== ''
  ? trim((string) $defaultCameraDbPath)
  : ((is_dir($cameraDbAutoPath) || is_link($cameraDbAutoPath))
    ? $cameraDbAutoPath
    : (realpath(__DIR__ . '/../cache') ?: (__DIR__ . '/../cache')));

$defaultDbPath = getenv('WEBCAM_CONFIG_DB');
$cameraConfigDb = $defaultDbPath && trim($defaultDbPath) !== ''
  ? trim($defaultDbPath)
  : __DIR__ . '/../database/webcams.sqlite';
$cameraConfigDb = webcam_camera_store_normalize_db_path((string) $cameraConfigDb);
$cameraConfigDriver = 'sqlite';

webcam_camera_store_assert_db_path_allowed($cameraConfigDb);

if (!is_file($cameraConfigDb)) {
  throw new RuntimeException("SQLite config database not found: {$cameraConfigDb}");
}

$GLOBALS['webcam_camera_config_options'] = [
  'driver' => $cameraConfigDriver,
  'db_path' => $cameraConfigDb,
  'json_path' => null,
];

// Rootverwaltete, installationsgebundene Vorgabe fuer Web UND Hintergrundjobs.
// Explizite Env-Werte (auch eine leere Kameraliste) behalten Vorrang.
$deferredRuntime = \Webcam\Service\Storage\DeferredImageRuntimeConfig::load(
  realpath(__DIR__ . '/..') ?: dirname(__DIR__), $cameraConfigDb, $cameraDbPath
);
$deferredValue = static function (string $name, string $key) use ($deferredRuntime): mixed {
  $value = getenv($name);
  return $value === false ? ($deferredRuntime[$key] ?? '') : $value;
};
$deferredCameras = $deferredValue('WEBCAM_DEFERRED_IMAGE_CAMERAS', 'deferred_image_cameras');

return [
  // Opt-in erst nach ausdruecklicher Kamera-DB-Migration und privater Ablage.
  // Keine Eintraege bedeutet unveraenderten klassischen Uploadweg.
  'deferred_image_cameras' => is_array($deferredCameras) ? $deferredCameras : array_values(array_filter(explode(',', $deferredCameras))),
  'deferred_image_root' => $deferredValue('WEBCAM_DEFERRED_IMAGE_ROOT', 'deferred_image_root'),
  'deferred_image_backup_root' => $deferredValue('WEBCAM_DEFERRED_IMAGE_BACKUP_ROOT', 'deferred_image_backup_root'),
  'deferred_image_guard' => $deferredValue('WEBCAM_DEFERRED_IMAGE_GUARD', 'deferred_image_guard'),
  'timezone' => 'Europe/Berlin',
  // Basispfad relativ zur Webroot (z.B. "webcam"); nutzt erkannte oder per Env gesetzte Basis
  'base_url' => $baseUrl,
  'assets_url' => $assetsUrl,
  // Neues Layout: Rolling Cache + Archiv; Live-Kopien liegen direkt unter <slug>/current/
  'cache_path' => realpath(__DIR__ . '/../cache') ?: (__DIR__ . '/../cache'),
  'camera_db_path' => $cameraDbPath,
  'storage_path' => realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage'),
  'archive_path' => realpath(__DIR__ . '/../storage/archive') ?: (__DIR__ . '/../storage/archive'),
  'state_path' => realpath(__DIR__ . '/../var/state') ?: (__DIR__ . '/../var/state'),
  'log_path' => realpath(__DIR__ . '/../var/log') ?: (__DIR__ . '/../var/log'),
  'info_path' => realpath(__DIR__ . '/../var/info') ?: (__DIR__ . '/../var/info'),
  'camera_config_path' => __DIR__ . '/cameras.php',
  'camera_config_driver' => $cameraConfigDriver,
  'camera_config_db' => $cameraConfigDb,
  'default_resolutions' => [150, 180, 240, 320, 400, 640, 720, 816, 1200, 1600],
  'fallback_best_of_count' => 12,
  'admin_api_base_url' => getenv('WEBCAM_ADMIN_API_URL') ?: null,
  'trusted_proxies' => $trustedProxies,
  // Admin API token must be provided via environment/server vars (no default in code)
  'admin_api_token' => (function (): string {
    $env = getenv('WEBCAM_ADMIN_API_TOKEN');
    if (is_string($env) && trim($env) !== '') {
      return trim($env);
    }
    $server = $_SERVER['WEBCAM_ADMIN_API_TOKEN'] ?? '';
    return is_string($server) ? trim($server) : '';
  })(),
  'storage_owner_user' => getenv('WEBCAM_STORAGE_USER') ?: 'www-data',
  'storage_owner_group' => getenv('WEBCAM_STORAGE_GROUP') ?: 'www-data',
  'archive_min_jpeg_bytes' => (int) (getenv('WEBCAM_ARCHIVE_MIN_JPEG_BYTES') ?: 8192),
  'archive_day_lock_enabled' => (static function (): bool {
    $configured = getenv('WEBCAM_ARCHIVE_DAY_LOCK_ENABLED');
    if ($configured === false || trim((string) $configured) === '') {
      return true;
    }
    return filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
  })(),
  'archive_day_lock_timeout_seconds' => (static function (): int {
    $configured = getenv('WEBCAM_ARCHIVE_DAY_LOCK_TIMEOUT_SECONDS');
    if ($configured === false || trim((string) $configured) === '') {
      return 30;
    }
    return max(0, min(300, (int) $configured));
  })(),
  // Reale Installationen muessen den Sentinel auf dem eingebundenen Archiv sehen.
  // Nur bewusst isolierte Tests duerfen den Guard per Env deaktivieren.
  'archive_mount_sentinel_required' => (static function (): bool {
    $configured = getenv('WEBCAM_ARCHIVE_MOUNT_SENTINEL_REQUIRED');
    if ($configured === false || trim((string) $configured) === '') {
      return true;
    }
    $parsed = filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    return $parsed ?? true;
  })(),
  // Rolling Cache Retention (Tage) – wird automatisch bei jeder neuen Ablage bereinigt
  'cache_retention_days' => (int) (getenv('WEBCAM_CACHE_RETENTION_DAYS') ?: 180),
  'upload_max_bytes' => (int) (getenv('WEBCAM_UPLOAD_MAX_BYTES') ?: 25 * 1024 * 1024),
  'watchdog_shell_timeout_seconds' => max(1, min(300, (int) (getenv('WEBCAM_WATCHDOG_SHELL_TIMEOUT_SECONDS') ?: 20))),
  'dayvideo_build_timeout_seconds' => max(10, min(3600, (int) (getenv('WEBCAM_DAYVIDEO_BUILD_TIMEOUT_SECONDS') ?: 300))),
  'dayvideo_max_concurrent_builds' => max(1, min(2, (int) (getenv('WEBCAM_DAYVIDEO_MAX_CONCURRENT_BUILDS') ?: 1))),
  'dayvideo_global_lock_dir' => getenv('WEBCAM_DAYVIDEO_GLOBAL_LOCK_DIR') ?: '/var/local/webcam/dayvideo-build-locks',
  'dayvideo_build_limit' => max(1, min(20, (int) (getenv('WEBCAM_DAYVIDEO_BUILD_LIMIT') ?: 3))),
  'dayvideo_build_window_seconds' => max(30, min(3600, (int) (getenv('WEBCAM_DAYVIDEO_BUILD_WINDOW_SECONDS') ?: 600))),
  'weather_command_timeout_seconds' => max(1, min(300, (int) (getenv('WEBCAM_WEATHER_COMMAND_TIMEOUT_SECONDS') ?: 20))),
  // Likes / Rate-Limits
  'likes_window_seconds' => (int) (getenv('WEBCAM_LIKES_WINDOW_SECONDS') ?: 3600),
  'likes_max_per_window' => (int) (getenv('WEBCAM_LIKES_MAX_PER_WINDOW') ?: 10),
];
