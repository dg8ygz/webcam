#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Kopiert alte Docroot-Jahresordner kontrolliert nach storage/archive.
 *
 * Standard ist Dry-Run. --copy überträgt nur am Ziel fehlende Dateien und
 * überschreibt niemals vorhandene Archivdaten.
 */

use Webcam\App;

$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/migrate_docroot_years.php --slug=<camera> --year=YYYY [--copy] [--limit=N] [--verbose]\n";
  echo "  --slug     Required camera slug\n";
  echo "  --year     Required legacy year folder\n";
  echo "  --copy     Actually copy files. Without this flag the script is dry-run only.\n";
  echo "  --limit    Stop after N missing files, useful for smoke tests\n";
  echo "  --verbose  Print each planned/copied file\n";
  echo "\n";
  echo "Copies only files missing from storage/archive/<slug>/<year>/... and never overwrites targets.\n";
  exit(0);
}

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';
$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$slug = null;
$year = null;
$copy = false;
$verbose = false;
$limit = null;
foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slug = trim(substr($arg, strlen('--slug=')));
  } elseif (str_starts_with($arg, '--year=')) {
    $year = trim(substr($arg, strlen('--year=')));
  } elseif ($arg === '--copy') {
    $copy = true;
  } elseif ($arg === '--verbose') {
    $verbose = true;
  } elseif (str_starts_with($arg, '--limit=')) {
    $limit = max(0, (int) substr($arg, strlen('--limit=')));
  }
}

if (!is_string($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
  fwrite(STDERR, "Missing or invalid --slug\n");
  exit(2);
}
if (!is_string($year) || !preg_match('/^\d{4}$/', $year)) {
  fwrite(STDERR, "Missing or invalid --year\n");
  exit(2);
}

$camera = $app->cameras()->find($slug);
if ($camera === null) {
  fwrite(STDERR, "Unknown camera slug: {$slug}\n");
  exit(2);
}

$sourceRoot = $projectRoot . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . $year;
$targetRoot = $app->paths()->archivePath($slug . DIRECTORY_SEPARATOR . $year);

if (!is_dir($sourceRoot)) {
  fwrite(STDERR, "Source year directory not found: {$sourceRoot}\n");
  exit(2);
}

$stats = [
  'seen' => 0,
  'missing' => 0,
  'copied' => 0,
  'already_exists' => 0,
  'size_conflict' => 0,
  'mkdir_failed' => 0,
  'copy_failed' => 0,
  'limited' => 0,
  'bytes_planned' => 0,
  'bytes_copied' => 0,
];

foreach (sortedFiles($sourceRoot) as $source) {
  $relative = ltrim(substr($source, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
  $target = rtrim($targetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
  $size = filesize($source);
  if ($size === false) {
    $stats['copy_failed']++;
    fwrite(STDERR, "[ERROR] cannot stat source file: {$source}\n");
    continue;
  }
  $stats['seen']++;

  if (is_file($target)) {
    $targetSize = filesize($target);
    if ($targetSize !== false && $targetSize === $size) {
      $stats['already_exists']++;
      continue;
    }
    $stats['size_conflict']++;
    if ($verbose) {
      echo "[CONFLICT] {$source} -> {$target}\n";
    }
    continue;
  }

  if ($limit !== null && $stats['missing'] >= $limit) {
    $stats['limited'] = 1;
    break;
  }

  $stats['missing']++;
  $stats['bytes_planned'] += $size;

  if (!$copy) {
    if ($verbose) {
      echo "[DRY] {$source} -> {$target}\n";
    }
    continue;
  }

  if ($verbose) {
    echo "[COPY] {$source} -> {$target}\n";
  }

  $targetDir = dirname($target);
  if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    $stats['mkdir_failed']++;
    fwrite(STDERR, "[ERROR] cannot create target directory: {$targetDir}\n");
    continue;
  }

  if (!copy($source, $target)) {
    $stats['copy_failed']++;
    fwrite(STDERR, "[ERROR] copy failed: {$source} -> {$target}\n");
    continue;
  }

  $mtime = filemtime($source);
  if ($mtime !== false) {
    touch($target, $mtime);
  }
  $stats['copied']++;
  $stats['bytes_copied'] += $size;
}

printf(
  "%s slug=%s year=%s source=%s target=%s seen=%d missing=%d copied=%d already_exists=%d size_conflict=%d mkdir_failed=%d copy_failed=%d bytes_planned=%d bytes_copied=%d%s\n",
  $copy ? 'COPY' : 'DRY-RUN',
  $slug,
  $year,
  $sourceRoot,
  $targetRoot,
  $stats['seen'],
  $stats['missing'],
  $stats['copied'],
  $stats['already_exists'],
  $stats['size_conflict'],
  $stats['mkdir_failed'],
  $stats['copy_failed'],
  $stats['bytes_planned'],
  $stats['bytes_copied'],
  $stats['limited'] ? ' limited=1' : ''
);

if ($stats['size_conflict'] > 0 || $stats['mkdir_failed'] > 0 || $stats['copy_failed'] > 0) {
  exit(1);
}

exit(0);

/**
 * @return list<string>
 */
function sortedFiles(string $root): array
{
  $files = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    if ($item->isFile()) {
      $files[] = $item->getPathname();
    }
  }

  sort($files, SORT_STRING);
  return $files;
}
