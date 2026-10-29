#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Storage\CacheRetentionManager;

// Explicit maintenance entry point: never called by the upload/capture path.
$run = false;
$check = false;
$lockFile = null;
foreach (array_slice($argv, 1) as $argument) {
  if ($argument === '--help' || $argument === '-h') {
    echo "Usage: php bin/cache_retention.php (--run|--check) [--lock-file=/absolute/path]\n";
    echo "Serial camera retention with existing archive verification and daily claims.\n";
    echo "--check only validates the plan; no scans, locks, claims or deletion.\n";
    exit(0);
  }
  if ($argument === '--run') {
    $run = true;
  } elseif ($argument === '--check') {
    $check = true;
  } elseif (str_starts_with($argument, '--lock-file=/')) {
    $lockFile = substr($argument, strlen('--lock-file='));
  } else {
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
  }
}
if ($run === $check) {
  fwrite(STDERR, "Specify exactly one of --run or --check.\n");
  exit(2);
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$days = (int) $app->config('cache_retention_days', 0);
if ($days <= 0) {
  echo "[SKIP] Cache retention disabled.\n";
  exit(0);
}
$cameras = $app->cameras()->all();
$lockFile ??= $app->paths()->statePath('cache-retention-job.lock');
if ($check) {
  printf("[CHECK] retention_days=%d cameras=%d lock=%s\n", $days, count($cameras), $lockFile);
  foreach ($cameras as $camera) {
    printf("[CHECK] camera=%s cache=%s\n", $camera->slug(), $app->paths()->cachePath($camera->slug()));
  }
  exit(0);
}

// DEV and PROD on one host can share this pre-created local file. Never unlink
// it: the lock inode must remain stable across runs. The maintenance scheduler
// bounds the entire process, including waiting here, by its configured timeout.
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
  fwrite(STDERR, "[ERROR] Cannot open retention job lock.\n");
  exit(1);
}
echo "[WAIT] Acquiring retention job lock.\n";
if (!flock($lock, LOCK_EX)) {
  fclose($lock);
  fwrite(STDERR, "[ERROR] Cannot acquire retention job lock.\n");
  exit(1);
}
$exitCode = 0;
try {
  $manager = new CacheRetentionManager($app);
  $cutoff = time() - $days * 86400;
  foreach ($cameras as $camera) {
    $started = microtime(true);
    printf("[START] camera=%s at=%s\n", $camera->slug(), date(DATE_ATOM));
    try {
      $manager->run($camera, $app->paths()->cachePath($camera->slug()), $cutoff);
      printf("[DONE] camera=%s elapsed_seconds=%.3f\n", $camera->slug(), microtime(true) - $started);
    } catch (Throwable $error) {
      $exitCode = 1;
      fwrite(STDERR, sprintf("[ERROR] camera=%s reason=%s\n", $camera->slug(), $error->getMessage()));
    }
  }
} finally {
  flock($lock, LOCK_UN);
  fclose($lock);
}
exit($exitCode);
