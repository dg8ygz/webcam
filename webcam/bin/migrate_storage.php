#!/usr/bin/env php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/fs_helpers.php';

// Schreibende Legacy-Migration für alle Kameras: findet alte current-/Jahres-
// Layouts, legt Sollverzeichnisse an und verschiebt Dateien mit Ownership-Korrektur.
// Vor einem Live-Lauf ist deshalb ein Backup Pflicht; das Skript ist kein Audit.
$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/migrate_storage.php\n";
  echo "Moves legacy current/archive layouts into the new structure for all cameras.\n";
  exit(0);
}

$nowYear = (int)date('Y');
$errors = 0;

foreach ($app->cameras()->all() as $camera) {
  $currentDirName = $camera->storageCurrentDir();
  $archiveDirName = $camera->storageArchiveDir();
  $legacyRoot = locate_legacy_root($camera, $app);
  if ($legacyRoot === null) {
    echo "[INFO] {$camera->slug()}: Kein Altbestand gefunden\n";
    continue;
  }
  $targetRoot = $app->cameraStoragePaths()->baseRoot($camera);
  $currentTarget = $app->cameraStoragePaths()->join($targetRoot, $currentDirName . '/' . $camera->slug());
  $archiveTarget = $app->cameraStoragePaths()->join($targetRoot, $archiveDirName . '/' . $camera->slug());

  if (!is_dir($currentTarget)) {
    if (!mkdir($currentTarget, 0775, true) && !is_dir($currentTarget)) {
      fwrite(STDERR, "[WARN] cannot create current target: {$currentTarget}\n");
    }
  }
  if (!is_dir($archiveTarget)) {
    if (!mkdir($archiveTarget, 0775, true) && !is_dir($archiveTarget)) {
      fwrite(STDERR, "[WARN] cannot create archive target: {$archiveTarget}\n");
    }
  }

  $moved = 0;
  $yearSources = array_merge(
    collect_year_directories($legacyRoot, 'root'),
    collect_year_directories($legacyRoot . '/' . $archiveDirName, 'archive'),
    collect_year_directories($legacyRoot . '/' . strtolower($archiveDirName), 'archive'),
    collect_year_directories($legacyRoot . '/Archive', 'archive'),
    collect_year_directories($legacyRoot . '/archive', 'archive'),
    collect_year_directories($legacyRoot . '/' . $currentDirName, 'current'),
    collect_year_directories($legacyRoot . '/' . strtolower($currentDirName), 'current'),
    collect_year_directories($legacyRoot . '/Current', 'current'),
    collect_year_directories($legacyRoot . '/current', 'current')
  );
  $processed = [];
  foreach ($yearSources as $entry) {
    $sourcePath = $entry['path'];
    if (!is_dir($sourcePath) || isset($processed[$sourcePath])) {
      continue;
    }
    $processed[$sourcePath] = true;
    $year = $entry['year'];
    if ($entry['context'] === 'current') {
      $target = $app->cameraStoragePaths()->join($currentTarget, sprintf('%04d', $year));
    } elseif ($entry['context'] === 'archive') {
      $target = $app->cameraStoragePaths()->join($year === $nowYear ? $currentTarget : $archiveTarget, sprintf('%04d', $year));
    } else {
      $target = $app->cameraStoragePaths()->join($year === $nowYear ? $currentTarget : $archiveTarget, sprintf('%04d', $year));
    }
    if (is_dir($target)) {
      echo "[SKIP] {$camera->slug()}: {$year} bereits unter {$target}\n";
      continue;
    }
    if (!rename($sourcePath, $target)) {
      fwrite(STDERR, "[ERROR] Verschieben fehlgeschlagen: {$sourcePath} -> {$target}\n");
      $errors++;
      continue;
    }
    $moved++;
    echo "[OK] {$camera->slug()}: {$year} nach {$target} verschoben\n";
  }

  if ($moved === 0) {
    echo "[INFO] {$camera->slug()}: Keine Jahresordner zum Verschieben gefunden\n";
  }

  mirror_current_assets($legacyRoot, $currentTarget, $currentDirName);
  cleanup_empty_directories($legacyRoot, $currentDirName, $archiveDirName);
}

exit($errors > 0 ? 1 : 0);

function locate_legacy_root(\Webcam\Domain\Camera $camera, \Webcam\App $app): ?string
{
  $storagePaths = $app->cameraStoragePaths();
  $basePath = normalize_path($storagePaths->configuredPath($camera));
  $slug = $camera->slug();
  $candidates = [];
  if ($basePath !== '') {
    $candidates[] = $basePath;
    $candidates[] = $basePath . '.old';
  }
  $defaultWithSlug = normalize_path($app->paths()->storagePath($slug));
  $candidates[] = $defaultWithSlug;
  $candidates[] = $defaultWithSlug . '.old';
  $rootBase = $storagePaths->baseRoot($camera);
  $candidates[] = normalize_path($storagePaths->join($rootBase, $slug));
  $candidates[] = normalize_path($storagePaths->join($rootBase, $slug . '.old'));
  $visited = [];
  foreach ($candidates as $path) {
    if ($path === '' || isset($visited[$path])) {
      continue;
    }
    $visited[$path] = true;
    if (is_dir($path)) {
      return $path;
    }
  }
  return null;
}

/**
 * @return list<array{year:int,path:string,context:string}>
 */
function collect_year_directories(string $path, string $context): array
{
  $normalized = normalize_path($path);
  if ($normalized === '' || !is_dir($normalized)) {
    return [];
  }
  $result = [];
  $iterator = new DirectoryIterator($normalized);
  foreach ($iterator as $entry) {
    if ($entry->isDot() || !$entry->isDir()) {
      continue;
    }
    $name = $entry->getFilename();
    if (!preg_match('/^\d{4}$/', $name)) {
      continue;
    }
    $result[] = [
      'year' => (int)$name,
      'path' => $entry->getPathname(),
      'context' => $context,
    ];
  }
  return $result;
}

function mirror_current_assets(string $legacyRoot, string $currentTarget, string $currentDirName): void
{
  $sources = [
    normalize_path($legacyRoot . '/' . $currentDirName),
    normalize_path($legacyRoot . '/' . strtolower($currentDirName)),
    normalize_path($legacyRoot . '/' . ucfirst($currentDirName)),
    normalize_path($legacyRoot . '/current'),
    normalize_path($legacyRoot . '/Current'),
  ];
  $visited = [];
  foreach ($sources as $dir) {
    if ($dir === '' || isset($visited[$dir]) || !is_dir($dir)) {
      continue;
    }
    $visited[$dir] = true;
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $entry) {
      if ($entry->isDot() || !$entry->isFile()) {
        continue;
      }
      $source = $entry->getPathname();
      $target = $currentTarget . '/' . $entry->getFilename();
      copy_if_readable($source, $target);
    }
  }
}

function copy_if_readable(string $source, string $target): void
{
  if (!is_readable($source)) {
    return;
  }
  if (!copy($source, $target)) {
    fwrite(STDERR, "[WARN] copy failed: {$source} -> {$target}\n");
    return;
  }
  $mtime = filemtime($source);
  if ($mtime === false) {
    $mtime = time();
  }
  if (!touch($target, $mtime)) {
    fwrite(STDERR, "[WARN] touch failed: {$target}\n");
  }
}

function cleanup_empty_directories(string $legacyRoot, string $currentDirName, string $archiveDirName): void
{
  $candidates = [
    normalize_path($legacyRoot . '/' . $currentDirName),
    normalize_path($legacyRoot . '/' . strtolower($currentDirName)),
    normalize_path($legacyRoot . '/' . $archiveDirName),
    normalize_path($legacyRoot . '/' . strtolower($archiveDirName)),
    normalize_path($legacyRoot . '/Current'),
    normalize_path($legacyRoot . '/current'),
    normalize_path($legacyRoot . '/Archive'),
    normalize_path($legacyRoot . '/archive'),
    normalize_path($legacyRoot),
  ];
  $visited = [];
  foreach ($candidates as $dir) {
    if ($dir === '' || $dir === '.' || isset($visited[$dir]) || !is_dir($dir)) {
      continue;
    }
    $visited[$dir] = true;
    if (!rmdir($dir)) {
      fwrite(STDERR, "[WARN] cleanup failed: {$dir}\n");
    }
  }
}
