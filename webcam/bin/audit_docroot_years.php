#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Vergleicht alte Docroot-Jahresordner mit storage/archive.
 *
 * Das Skript ist vollständig read-only: Es zählt fehlende, abweichende und
 * identische Dateien, verschiebt oder löscht aber nichts. --hash vertieft nur
 * den Vergleich und kann auf großen Beständen deutlich langsamer sein.
 */

use Webcam\App;

$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/audit_docroot_years.php [--slug=<camera>] [--year=YYYY] [--hash] [--samples=N]\n";
  echo "  --slug     Restrict audit to one camera slug\n";
  echo "  --year     Restrict audit to one legacy year folder\n";
  echo "  --hash     For same-size files, compare sha256 hashes too (slower)\n";
  echo "  --samples  Number of example paths per category (default: 8)\n";
  echo "\n";
  echo "Read-only audit of legacy <slug>/<yyyy>/ folders against storage/archive/<slug>/<yyyy>/.\n";
  exit(0);
}

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';
$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$slugFilter = null;
$yearFilter = null;
$compareHash = false;
$sampleLimit = 8;
foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slugFilter = trim(substr($arg, strlen('--slug=')));
  } elseif (str_starts_with($arg, '--year=')) {
    $year = trim(substr($arg, strlen('--year=')));
    if (!preg_match('/^\d{4}$/', $year)) {
      fwrite(STDERR, "Invalid year: {$year}\n");
      exit(2);
    }
    $yearFilter = $year;
  } elseif ($arg === '--hash') {
    $compareHash = true;
  } elseif (str_starts_with($arg, '--samples=')) {
    $sampleLimit = max(0, (int) substr($arg, strlen('--samples=')));
  }
}

$cameras = $app->cameras()->all();
if ($slugFilter !== null && $slugFilter !== '') {
  $cameras = array_values(array_filter(
    $cameras,
    static fn($camera): bool => $camera->slug() === $slugFilter
  ));
  if ($cameras === []) {
    fwrite(STDERR, "Unknown camera slug: {$slugFilter}\n");
    exit(2);
  }
}

$overall = [
  'legacy_dirs' => 0,
  'files' => 0,
  'bytes' => 0,
  'identical' => 0,
  'missing' => 0,
  'size_conflict' => 0,
  'hash_conflict' => 0,
  'unreadable' => 0,
];

foreach ($cameras as $camera) {
  $slug = $camera->slug();
  $docrootDir = $projectRoot . DIRECTORY_SEPARATOR . $slug;
  $archiveDir = $app->paths()->archivePath($slug);
  $yearDirs = legacyYearDirs($docrootDir, $yearFilter);

  $stats = [
    'legacy_dirs' => count($yearDirs),
    'files' => 0,
    'bytes' => 0,
    'identical' => 0,
    'missing' => 0,
    'size_conflict' => 0,
    'hash_conflict' => 0,
    'unreadable' => 0,
  ];
  $samples = [
    'missing' => [],
    'size_conflict' => [],
    'hash_conflict' => [],
    'unreadable' => [],
  ];

  foreach ($yearDirs as $yearDir) {
    foreach (sortedFiles($yearDir) as $source) {
      $relative = ltrim(substr($source, strlen($docrootDir)), DIRECTORY_SEPARATOR);
      $target = rtrim($archiveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
      $stats['files']++;
      $size = filesize($source);
      if ($size === false) {
        $stats['unreadable']++;
        addSample($samples['unreadable'], $source, $sampleLimit);
        continue;
      }
      $stats['bytes'] += $size;

      if (!is_readable($source)) {
        $stats['unreadable']++;
        addSample($samples['unreadable'], $source, $sampleLimit);
        continue;
      }

      if (!is_file($target)) {
        $stats['missing']++;
        addSample($samples['missing'], $source . ' -> ' . $target, $sampleLimit);
        continue;
      }

      $targetSize = filesize($target);
      if ($targetSize === false || $targetSize !== $size) {
        $stats['size_conflict']++;
        addSample($samples['size_conflict'], $source . ' -> ' . $target, $sampleLimit);
        continue;
      }

      if ($compareHash && hash_file('sha256', $source) !== hash_file('sha256', $target)) {
        $stats['hash_conflict']++;
        addSample($samples['hash_conflict'], $source . ' -> ' . $target, $sampleLimit);
        continue;
      }

      $stats['identical']++;
    }
  }

  foreach ($stats as $key => $value) {
    $overall[$key] += $value;
  }

  printCameraReport($slug, $yearDirs, $stats, $samples, $compareHash);
}

printf(
  "TOTAL legacy_dirs=%d files=%d bytes=%d identical=%d missing=%d size_conflict=%d hash_conflict=%d unreadable=%d\n",
  $overall['legacy_dirs'],
  $overall['files'],
  $overall['bytes'],
  $overall['identical'],
  $overall['missing'],
  $overall['size_conflict'],
  $overall['hash_conflict'],
  $overall['unreadable']
);

exit(($overall['missing'] + $overall['size_conflict'] + $overall['hash_conflict'] + $overall['unreadable']) > 0 ? 1 : 0);

/**
 * @return list<string>
 */
function legacyYearDirs(string $docrootDir, ?string $yearFilter): array
{
  if (!is_dir($docrootDir)) {
    return [];
  }

  if ($yearFilter !== null) {
    $path = $docrootDir . DIRECTORY_SEPARATOR . $yearFilter;
    return is_dir($path) ? [$path] : [];
  }

  $dirs = [];
  foreach (new DirectoryIterator($docrootDir) as $entry) {
    if (!$entry->isDir() || $entry->isDot()) {
      continue;
    }
    $name = $entry->getFilename();
    if (preg_match('/^\d{4}$/', $name)) {
      $dirs[] = $entry->getPathname();
    }
  }
  sort($dirs, SORT_STRING);
  return $dirs;
}

/**
 * @param list<string> $samples
 */
function addSample(array &$samples, string $line, int $limit): void
{
  if ($limit <= 0 || count($samples) >= $limit) {
    return;
  }
  $samples[] = $line;
}

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

/**
 * @param list<string> $yearDirs
 * @param array<string,int> $stats
 * @param array<string,list<string>> $samples
 */
function printCameraReport(string $slug, array $yearDirs, array $stats, array $samples, bool $compareHash): void
{
  $years = array_map(static fn(string $path): string => basename($path), $yearDirs);
  printf(
    "%s years=[%s] files=%d bytes=%d identical=%d missing=%d size_conflict=%d hash_conflict=%d unreadable=%d%s\n",
    $slug,
    implode(',', $years),
    $stats['files'],
    $stats['bytes'],
    $stats['identical'],
    $stats['missing'],
    $stats['size_conflict'],
    $stats['hash_conflict'],
    $stats['unreadable'],
    $compareHash ? ' hash=on' : ' hash=off'
  );

  foreach (['missing', 'size_conflict', 'hash_conflict', 'unreadable'] as $category) {
    if ($samples[$category] === []) {
      continue;
    }
    echo "  {$category} samples:\n";
    foreach ($samples[$category] as $sample) {
      echo "   - {$sample}\n";
    }
  }
}
