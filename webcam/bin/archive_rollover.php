#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Storage\ArchiveDayLock;
use Webcam\Service\Storage\ArchiveMountGuard;
use Webcam\Service\Storage\DailyArchiver;

// Wählt einen lokalen Kalendertag und delegiert dessen Archivierung vollständig
// an DailyArchiver. Ohne --overwrite werden nur gueltige Zielsets uebersprungen;
// vorhandene defekte Sets werden vollstaendig atomar repariert.
$args = $argv;
array_shift($args);
$dateArg = null;
$overwrite = false;
$verbose = false;
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/archive_rollover.php [YYYY-MM-DD] [--overwrite] [--verbose|-v]\n";
  echo "  YYYY-MM-DD   Date to archive (defaults to yesterday in timezone config).\n";
  echo "  --overwrite  Replace existing archived files for that day.\n";
  echo "  --verbose|-v Print per-capture progress; interactive TTY runs show progress automatically.\n";
  exit(0);
}

foreach ($args as $argument) {
  if ($argument === '--overwrite') {
    $overwrite = true;
    continue;
  }
  if ($argument === '--verbose' || $argument === '-v') {
    $verbose = true;
    continue;
  }
  if ($dateArg === null) {
    $dateArg = $argument;
    continue;
  }
  fwrite(STDERR, "Unrecognized argument: {$argument}\n");
  exit(1);
}

$app = require __DIR__ . '/../bootstrap.php';
$archiver = new DailyArchiver($app);
$tz = new \DateTimeZone($app->config('timezone', 'UTC'));
$showProgress = $verbose || archive_rollover_stdout_is_interactive();

if ($dateArg !== null) {
  $targetDay = \DateTimeImmutable::createFromFormat('Y-m-d', $dateArg, $tz);
  if (!$targetDay) {
    fwrite(STDERR, "Invalid date format (expected YYYY-MM-DD): {$dateArg}\n");
    exit(1);
  }
} else {
  $now = new \DateTimeImmutable('now', $tz);
  $targetDay = $now->setTimezone($tz)->setTime(0, 0)->sub(new \DateInterval('P1D'));
}

try {
  (new ArchiveMountGuard($app))->assertWritable('archive rollover');
} catch (RuntimeException $exception) {
  fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
  exit(2);
}

printf("Archiving captures for %s (overwrite: %s)\n", $targetDay->format('Y-m-d'), $overwrite ? 'yes' : 'no');

$dayLock = ArchiveDayLock::acquire($app, $targetDay);
printf("Shared day lock: %s\n", $dayLock->path());

$totalProcessed = 0;
$totalSkipped = 0;
$missing = [];

foreach ($app->cameras()->all() as $camera) {
  $progress = null;
  if ($showProgress) {
    $progress = static function (array $event) use ($camera): void {
      $slug = $camera->slug();
      $current = $event['current'] ?? '--';
      switch ($event['phase']) {
        case 'checking':
          printf("  > %-15s checking source/cache state\n", $slug);
          break;
        case 'pruning':
          printf(
            "  > %-15s pruning cache years processed:%d skipped:%d\n",
            $slug,
            $event['processed'],
            $event['skipped']
          );
          break;
        case 'start':
          printf("  > %-15s start\n", $slug);
          break;
        case 'missing':
          printf("  > %-15s no source data\n", $slug);
          break;
        case 'done':
          printf("  > %-15s done processed:%d skipped:%d\n", $slug, $event['processed'], $event['skipped']);
          break;
        default:
          printf(
            "  > %-15s %s processed:%d skipped:%d\n",
            $slug,
            $current,
            $event['processed'],
            $event['skipped']
          );
          break;
      }
      archive_rollover_flush_stdout();
    };
  }

  $result = $archiver->archiveDay($camera, $targetDay, $overwrite, $progress);
  if ($result['missing']) {
    $missing[] = $camera->slug();
    printf("- %-15s no data\n", $camera->slug());
    continue;
  }
  $totalProcessed += $result['processed'];
  $totalSkipped += $result['skipped'];
  printf(
    "- %-15s processed: %d, skipped: %d\n",
    $camera->slug(),
    $result['processed'],
    $result['skipped']
  );
}

printf(
  "Summary: processed=%d, skipped=%d, missing=%d\n",
  $totalProcessed,
  $totalSkipped,
  count($missing)
);

if ($missing) {
  printf("No captures found for: %s\n", implode(', ', $missing));
}
$dayLock->release();

function archive_rollover_stdout_is_interactive(): bool
{
  if (function_exists('stream_isatty')) {
    return stream_isatty(STDOUT);
  }
  if (function_exists('posix_isatty')) {
    return posix_isatty(STDOUT);
  }
  return false;
}

function archive_rollover_flush_stdout(): void
{
  if (function_exists('ob_get_level') && ob_get_level() > 0) {
    @ob_flush();
  }
  fflush(STDOUT);
}
