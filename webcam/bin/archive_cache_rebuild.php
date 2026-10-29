#!/usr/bin/env php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

// Explizites Legacy-Werkzeug: Argumente begrenzen Kamera/Jahr, danach liest
// ArchiveBrowser die vorhandenen Dateien und schreibt optional den alten
// Archive-Cache unter var/state. Dieses Skript gehört nicht zum Normalbetrieb.
$args = $_SERVER['argv'] ?? [];
array_shift($args);
$slugFilter = null;
$yearFilter = null;
$dryRun = false;
$verbose = false;
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/archive_cache_rebuild.php [--slug=<slug>] [--year=YYYY] [--dry-run] [--verbose]\n";
  echo "  --slug     Optional camera slug to limit rebuild.\n";
  echo "  --year     Optional year to rebuild (defaults: all available years).\n";
  echo "  --dry-run  Do not write cache files, just iterate.\n";
  echo "  --verbose  Print progress info.\n";
  exit(0);
}

foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slugFilter = substr($arg, 7);
  } elseif (str_starts_with($arg, '--year=')) {
    $yearFilter = (int) substr($arg, 7);
  } elseif ($arg === '--dry-run') {
    $dryRun = true;
  } elseif ($arg === '--verbose') {
    $verbose = true;
  }
}

$cameras = $app->cameras()->all();
$total = 0;
$rebuildSuccess = false;
$lockPath = $app->paths()->statePath('archive-cache/rebuild.lock');
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
  fwrite(STDERR, "Unable to open rebuild lock file: {$lockPath}\n");
  exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
  $pid = null;
  $lockAge = null;
  if (is_file($lockPath)) {
    $lockAge = filemtime($lockPath);
    $pid = trim((string) file_get_contents($lockPath));
  }
  $ageNote = $lockAge ? ' (age ' . (time() - $lockAge) . 's)' : '';
  $pidNote = $pid !== '' ? ' pid=' . $pid : '';
  echo "[SKIP] Another rebuild is already running{$ageNote}{$pidNote}.\n";
  exit(0);
}
// Schreibe PID ins Lockfile (Hilft bei Debug/Abbrüchen)
if (!ftruncate($lockHandle, 0)) {
  fwrite(STDERR, "[WARN] Unable to truncate lock file: {$lockPath}\n");
}
if (fwrite($lockHandle, (string) getmypid()) === false) {
  fwrite(STDERR, "[WARN] Unable to write PID to lock file: {$lockPath}\n");
}
$stateFile = $app->paths()->statePath('archive-cache/rebuild.json');
$stateDir = dirname($stateFile);
if (!is_dir($stateDir) && !mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
  fwrite(STDERR, "[WARN] Unable to create state dir: {$stateDir}\n");
}
// bestimme aktives Statefile (per Slug, falls Filter gesetzt)
$stateFileActive = $slugFilter ? $app->paths()->statePath('archive-cache/rebuild_' . $slugFilter . '.json') : $stateFile;
writeState($stateFileActive, [
  'status' => 'running',
  'started_at' => gmdate('c'),
  'current' => null,
  'completed' => [],
  'dry_run' => $dryRun,
  'pid' => getmypid(),
]);

foreach ($cameras as $camera) {
  if ($slugFilter && $camera->slug() !== $slugFilter) {
    continue;
  }
  $years = $app->archiveBrowser()->listYears($camera);
  $targetYears = [];
  foreach (['current', 'archive'] as $kind) {
    foreach ($years[$kind] ?? [] as $y) {
      $targetYears[] = (int) $y;
    }
  }
  $targetYears = array_unique($targetYears);
  if ($yearFilter !== null) {
    $targetYears = array_intersect($targetYears, [$yearFilter]);
  }
  if (!$targetYears) {
    if ($verbose) {
      echo "[SKIP] {$camera->slug()} (keine Jahre)\n";
    }
    continue;
  }
  sort($targetYears);
  foreach ($targetYears as $year) {
    $total++;
    if ($dryRun) {
      echo "[DRY]  {$camera->slug()} {$year}\n";
      $statePath = $app->paths()->statePath('archive-cache/rebuild_' . $camera->slug() . '.json');
      writeState($statePath, [
        'status' => 'running',
        'started_at' => gmdate('c'),
        'current' => ['slug' => $camera->slug(), 'year' => $year],
        'completed' => [],
        'dry_run' => true,
        'last_message' => "DRY {$camera->slug()} {$year}",
      ]);
      writeState($statePath, [
        'status' => 'done',
        'started_at' => gmdate('c'),
        'finished_at' => gmdate('c'),
        'current' => null,
        'completed' => [
          ['slug' => $camera->slug(), 'year' => $year, 'entries' => 0],
        ],
        'dry_run' => true,
        'last_message' => "DRY {$camera->slug()} {$year}",
      ]);
      continue;
    }
    $currentState = [
      'status' => 'running',
      'started_at' => gmdate('c'),
      'current' => ['slug' => $camera->slug(), 'year' => $year, 'progress' => 0],
      'completed' => [],
      'dry_run' => false,
      'last_message' => "Building {$camera->slug()} {$year}",
    ];
    writeState($app->paths()->statePath('archive-cache/rebuild_' . $camera->slug() . '.json'), $currentState);
    if ($verbose) {
      echo "[INFO] Building {$camera->slug()} {$year}...\n";
    }
    try {
      $app->archiveBrowser()->rebuildCache($camera, [$year]);
      $cachePath = $app->paths()->statePath('archive-cache/' . $camera->slug() . '/' . $year . '.json');
      $count = 0;
      if (is_file($cachePath)) {
        $payload = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($payload) && isset($payload['entries']) && is_array($payload['entries'])) {
          $count = count($payload['entries']);
        }
      }
      $currentState['current']['progress'] = $count;
      $currentState['last_message'] = "Done {$camera->slug()} {$year} ({$count} entries)";
      echo "[OK]   {$camera->slug()} {$year} entries={$count}\n";
      $currentState['current'] = null;
      $currentState['status'] = 'done';
      $currentState['finished_at'] = gmdate('c');
      $currentState['completed'] = [['slug' => $camera->slug(), 'year' => $year, 'entries' => $count]];
      writeState($app->paths()->statePath('archive-cache/rebuild_' . $camera->slug() . '.json'), $currentState);
    } catch (\Throwable $e) {
      $currentState['last_message'] = "ERROR {$camera->slug()} {$year}: " . $e->getMessage();
      echo "[ERR]  {$camera->slug()} {$year} " . $e->getMessage() . "\n";
      writeState($app->paths()->statePath('archive-cache/rebuild_' . $camera->slug() . '.json'), $currentState);
      exit(1);
    }
  }
}

if ($total === 0) {
  echo "No cache rebuild executed.\n";
}

writeState($stateFileActive, [
  'status' => $total === 0 ? 'skipped' : 'done',
  'started_at' => gmdate('c'),
  'finished_at' => gmdate('c'),
  'current' => null,
  'total_jobs' => $total,
  'dry_run' => $dryRun,
  'last_message' => $total === 0 ? 'No jobs executed' : 'Finished',
  'pid' => getmypid(),
]);
$rebuildSuccess = true;

function writeState(string $file, array $data): void
{
  if (!webcam_atomic_write_json($file, $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
    fwrite(STDERR, "[WARN] Unable to write state file: {$file}\n");
  }
}

register_shutdown_function(function () use ($lockHandle, $lockPath, &$rebuildSuccess) {
  if ($lockHandle) {
    if (!flock($lockHandle, LOCK_UN)) {
      error_log('archive_cache_rebuild: failed to unlock ' . $lockPath);
    }
    if (!fclose($lockHandle)) {
      error_log('archive_cache_rebuild: failed to close ' . $lockPath);
    }
  }
  if ($rebuildSuccess && is_file($lockPath)) {
    if (!unlink($lockPath)) {
      error_log('archive_cache_rebuild: failed to remove ' . $lockPath);
    }
  }
});
