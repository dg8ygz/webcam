<?php
declare(strict_types=1);

// Router ausschließlich für den PHP Built-in Server in Dev/Test. Er entfernt
// optional den Mount-Prefix, reicht echte statische Dateien direkt durch und
// mappt bekannte Pretty-URLs auf die gleichen PHP-Einstiegspunkte wie Apache.
$docRoot = __DIR__;
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
if (!is_string($path)) {
  $path = '/';
}

$path = '/' . ltrim($path, '/');

// Optional base prefix (e.g. /webcam) for local dev.
$basePrefix = trim((string) (getenv('WEBCAM_BASE_URL') ?: ''), '/');
if ($basePrefix !== '') {
  $prefix = '/' . $basePrefix;
  if ($path === $prefix) {
    $path = '/';
  } elseif (str_starts_with($path, $prefix . '/')) {
    $path = substr($path, strlen($prefix));
  }
}

$path = rtrim($path, '/');
if ($path === '') {
  $path = '/';
}

$decodedPath = rawurldecode($path);
if ($decodedPath !== $path) {
  // Auch doppelt kodierte Punkte/Slashes duerfen die Sperre nicht umgehen.
  $decodedPath = rawurldecode($decodedPath);
}
$path = str_replace('\\', '/', $decodedPath);

// Der Built-in-Server kennt keine .htaccess. Darum muss seine Router-Schicht
// dieselben Runtime-, Quellcode- und Backup-Pfade sperren, bevor vorhandene
// Dateien mit `return false` direkt ausgeliefert werden.
if (routerPathIsForbidden($path)) {
  return routerDeny();
}

$fullPath = $docRoot . $path;
if ($path !== '/' && is_file($fullPath)) {
  return false;
}
if (preg_match('#^/((?:cache/|storage/archive/)?bielefeld/[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9_]+(?:[a-z]+)?\.jpg)$#D', $path, $matches)) {
  $_GET = ['legacy' => $matches[1]];
  return dispatch('/image.php');
}
// Same current compatibility route as Apache, for the built-in test server.
if (preg_match('#^/([a-z0-9-]+)/current/((?:raw|full|hu|current|[0-9]+)\.jpg|(?:current_ex|ex|latest_wx|current_wx)\.txt)$#D', $path, $matches)) {
  $currentFile = $docRoot . '/cache/' . $matches[1] . '/current/' . $matches[2];
  if (is_file($currentFile)) {
    header('Content-Type: ' . (str_ends_with($currentFile, '.jpg') ? 'image/jpeg' : 'text/plain; charset=UTF-8'));
    header('Cache-Control: public, max-age=120, stale-while-revalidate=60');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($currentFile);
    return true;
  }
}
if (preg_match('#^/[a-z0-9-]+/current/#', $path)) {
  http_response_code(404);
  header('Cache-Control: no-store');
  return true;
}
if ($path !== '/' && is_dir($fullPath)) {
  if (is_file($fullPath . '/index.php')) {
    return dispatch($path . '/index.php');
  }
  if (is_file($fullPath . '/index.html')) {
    return false;
  }
}

$routes = [
  '/' => '/index.php',
  '/index.php' => '/index.php',
  '/status' => '/status/index.php',
  '/admin' => '/admin/index.php',
  '/bestof' => '/bestof.php',
  '/like' => '/like.php',
  '/bestof-toggle' => '/bestof-toggle.php',
  '/bestof-captcha' => '/bestof-captcha.php',
  '/overlay' => '/overlay.php',
  '/camera' => '/camera.php',
  '/weather' => '/weather.php',
  '/embed' => '/embed.php',
  '/stream' => '/stream.php',
];

if (isset($routes[$path])) {
  return dispatch($routes[$path]);
}

if (str_starts_with($path, '/camera/')) {
  $slug = trim(substr($path, strlen('/camera/')), '/');
  if ($slug !== '') {
    $_GET['cam'] = $slug;
  }
  return dispatch('/camera.php');
}

if (str_starts_with($path, '/embed/')) {
  $slug = trim(substr($path, strlen('/embed/')), '/');
  if ($slug !== '') {
    $_GET['cam'] = $slug;
  }
  return dispatch('/embed.php');
}

if (str_starts_with($path, '/stream/')) {
  $slug = trim(substr($path, strlen('/stream/')), '/');
  if ($slug !== '') {
    $_GET['slug'] = $slug;
  }
  return dispatch('/stream.php');
}

// Backwards compatible: /<slug> -> /camera.php?cam=<slug>
$single = trim($path, '/');
if ($single !== '' && !str_contains($single, '/')) {
  $reserved = [
    'camera',
    'weather',
    'embed',
    'stream',
    'api',
    'admin',
    'status',
    'assets',
    'storage',
    'cache',
    'current',
    'vendor',
    'bin',
    'scripts',
    'src',
    'templates',
    'tests',
    'include',
    'var',
    'database',
    'config',
    'ops',
    'favicon.ico',
    'filter.png',
    'like',
    'bestof-toggle',
    'bestof-captcha',
    'bestof',
    'overlay',
  ];
  if (!in_array($single, $reserved, true)) {
    $_GET['cam'] = $single;
    return dispatch('/camera.php');
  }
}

if ($path === '/api/public' || $path === '/api/public.php') {
  return dispatch('/api/public.php');
}
if (str_starts_with($path, '/api/public.php/')) {
  return dispatch('/api/public.php', substr($path, strlen('/api/public.php')));
}
if (str_starts_with($path, '/api/public/')) {
  return dispatch('/api/public.php', substr($path, strlen('/api/public')));
}

if ($path === '/api/history' || $path === '/api/history.php') {
  return dispatch('/api/history.php');
}
if (str_starts_with($path, '/api/history/')) {
  $slug = trim(substr($path, strlen('/api/history/')), '/');
  if ($slug !== '') {
    $_GET['slug'] = $slug;
  }
  return dispatch('/api/history.php');
}

return false;

/**
 * Spiegelt die sicherheitsrelevanten Apache-Sperren fuer Dev/Test.
 *
 * Die Pruefung arbeitet auf dem bereits URL-dekodierten Pfad und blockiert
 * bewusst auch noch nicht vorhandene Ziele. Dadurch verraten 403/404-Unterschiede
 * nichts ueber interne Dateien und spaeter angelegte Backups bleiben geschuetzt.
 */
function routerPathIsForbidden(string $path): bool
{
  if ($path === '' || str_contains($path, "\0")) {
    return true;
  }

  // Diese Geräte-Endpunkte sind auch in .htaccess ausdrücklich vor der
  // allgemeinen include/-Sperre ausgenommen und validieren Methode/Key selbst.
  if (in_array($path, ['/include/command.php', '/include/video-command.php', '/include/upload.php', '/include/upload-chunk.php', '/include/log.php'], true)) {
    return false;
  }

  $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
  foreach ($segments as $index => $segment) {
    if ($segment === '.' || $segment === '..') {
      return true;
    }
    if (str_starts_with($segment, '.') && !($index === 0 && $segment === '.well-known')) {
      return true;
    }
  }

  $first = strtolower($segments[0] ?? '');
  $protectedRoots = [
    'orgin',
    'var',
    'database',
    '.git',
    '.npmhome',
    'node_modules',
    'vendor',
    'bin',
    'scripts',
    'src',
    'include',
    'tests',
    'templates',
    'config',
    'docs',
    'ops',
  ];
  if (in_array($first, $protectedRoots, true)) {
    return true;
  }

  if ($first === 'cache' && isset($segments[2]) && strtolower($segments[2]) === 'overlays') {
    return true;
  }

  $basename = strtolower((string) end($segments));
  $protectedNames = [
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'phpunit.xml',
    'phpunit.xml.dist',
    'agents.md',
    'readme.md',
    'audit.md',
    'changelog.md',
  ];
  if (in_array($basename, $protectedNames, true)) {
    return true;
  }

  return preg_match('/\.(?:sqlite(?:-[^\/]*)?|db|bak|log|lock|pid|env|ini|phar|sql|ya?ml|json|xml|md)$/i', $basename) === 1;
}

/** Sendet fuer geschuetzte Dev-Router-Pfade dieselbe knappe 403-Semantik wie Apache. */
function routerDeny(): bool
{
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo "Forbidden\n";
  return true;
}

function dispatch(string $script, string $pathInfo = ''): bool
{
  $docRoot = __DIR__;
  $target = $docRoot . $script;
  if (!is_file($target)) {
    return false;
  }

  $_SERVER['SCRIPT_NAME'] = $script;
  $_SERVER['PHP_SELF'] = $script;
  $_SERVER['SCRIPT_FILENAME'] = $target;
  if ($pathInfo !== '') {
    $_SERVER['PATH_INFO'] = $pathInfo;
  } else {
    unset($_SERVER['PATH_INFO']);
  }

  require $target;
  return true;
}
