#!/usr/bin/env php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

// Liest das OCR-Log, behält pro Slot nur den jüngsten noch fehlerhaften Status und
// startet dafür gezielte Rescue-Aufrufe von extract_wx_ocr.php. Filter und Limit
// verhindern einen ungebremsten Vollbestand-Retry.
$args = $_SERVER['argv'] ?? [];
array_shift($args);

$logPath = $app->paths()->logPath('wx_ocr.log');
$messages = ['ocr_parse_failed', 'out_of_range'];
$slug = null;
$year = null;
$limit = null;
$since = null;
$dryRun = false;
$verbose = false;

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/retry_wx_ocr_from_log.php [--log=/path/to/wx_ocr.log] [--message=ocr_parse_failed,out_of_range] [--slug=<slug>] [--year=YYYY] [--since=YYYY-MM-DD] [--limit=N] [--dry-run] [--verbose]\n";
  echo "  Replays only slots whose latest log status is still one of the selected messages.\n";
  echo "  The retry uses extract_wx_ocr.php with --overwrite --rescue (multi-pass OCR).\n";
  echo "  A retry may finish either as HHMM_wx.txt or as HHMM_weather.no.\n";
  exit(0);
}

foreach ($args as $arg) {
  if (str_starts_with($arg, '--log=')) {
    $logPath = trim(substr($arg, 6));
  } elseif (str_starts_with($arg, '--message=')) {
    $raw = trim(substr($arg, 10));
    $messages = array_values(array_filter(array_map(static fn (string $part): string => trim($part), explode(',', $raw))));
  } elseif (str_starts_with($arg, '--slug=')) {
    $slug = trim(substr($arg, 7));
  } elseif (str_starts_with($arg, '--year=')) {
    $year = (int) substr($arg, 7);
  } elseif (str_starts_with($arg, '--since=')) {
    $since = trim(substr($arg, 8));
  } elseif (str_starts_with($arg, '--limit=')) {
    $limit = (int) substr($arg, 8);
  } elseif ($arg === '--dry-run') {
    $dryRun = true;
  } elseif ($arg === '--verbose') {
    $verbose = true;
  }
}

if (!is_file($logPath) || !is_readable($logPath)) {
  fwrite(STDERR, "Log not readable: {$logPath}\n");
  exit(1);
}

$messages = array_values(array_unique(array_filter(array_map(
  static fn (string $message): string => preg_replace('/[^a-z_]/', '', strtolower($message)) ?? '',
  $messages
))));
if ($messages === []) {
  fwrite(STDERR, "No valid target messages given.\n");
  exit(1);
}

$sinceTs = null;
if ($since !== null && $since !== '') {
  $sinceTs = strtotime($since);
  if ($sinceTs === false) {
    fwrite(STDERR, "Invalid --since value: {$since}\n");
    exit(1);
  }
}

$states = collectLatestStates($logPath, $slug, $year, $sinceTs);
$targets = [];
foreach ($states as $file => $state) {
  if (in_array($state['message'], $messages, true)) {
    $slot = buildRetrySlot($file);
    if ($slot !== null) {
      $targets[] = $slot + ['message' => $state['message'], 'timestamp' => $state['timestamp']];
    }
  }
}

usort($targets, static function (array $a, array $b): int {
  return strcmp($a['file'], $b['file']);
});

if ($limit !== null && $limit > 0) {
  $targets = array_slice($targets, 0, $limit);
}

echo stampLine(
  'Retry selection messages=' . implode(',', $messages)
    . ' count=' . count($targets)
    . ' log=' . $logPath
) . PHP_EOL;

$processed = 0;
$failures = 0;
foreach ($targets as $target) {
  $command = buildRetryCommand($target['dir'], $target['hhmm'], $target['variant'], $verbose);
  if ($dryRun) {
    echo stampLine(
      '[DRY] ' . $target['file']
        . ' message=' . $target['message']
        . ' cmd=' . $command
    ) . PHP_EOL;
    $processed++;
    continue;
  }

  echo stampLine(
    '[RUN] ' . $target['file']
      . ' message=' . $target['message']
  ) . PHP_EOL;

  passthru($command, $exitCode);
  if ($exitCode !== 0) {
    $failures++;
    fwrite(STDERR, stampLine('[ERR] retry failed exit=' . $exitCode . ' file=' . $target['file']) . PHP_EOL);
  }
  $processed++;
}

echo stampLine(
  'Done. processed=' . $processed
    . ' failures=' . $failures
    . ' dry_run=' . ($dryRun ? '1' : '0')
) . PHP_EOL;

function collectLatestStates(string $logPath, ?string $slug, ?int $year, ?int $sinceTs): array
{
  $handle = fopen($logPath, 'rb');
  if (!is_resource($handle)) {
    return [];
  }

  $states = [];
  while (($line = fgets($handle)) !== false) {
    $entry = parseLogLine($line);
    if ($entry === null) {
      continue;
    }
    if ($sinceTs !== null && $entry['timestamp_unix'] < $sinceTs) {
      continue;
    }
    if ($slug !== null && extractSlugFromPath($entry['file']) !== $slug) {
      continue;
    }
    if ($year !== null && extractYearFromPath($entry['file']) !== $year) {
      continue;
    }
    $states[$entry['file']] = [
      'message' => $entry['message'],
      'timestamp' => $entry['timestamp'],
      'timestamp_unix' => $entry['timestamp_unix'],
    ];
  }
  fclose($handle);

  return $states;
}

function parseLogLine(string $line): ?array
{
  if (!preg_match('/^\[(?<ts>[^\]]+)\]\s+(?<kind>OK|MISS|ERROR)\s+file=(?<file>\S+)(?:\s+message=(?<message>[a-z_]+))?/i', trim($line), $m)) {
    return null;
  }

  $timestampUnix = strtotime($m['ts']);
  if ($timestampUnix === false) {
    return null;
  }

  $kind = strtoupper($m['kind']);
  $message = $kind === 'OK' ? 'ok' : strtolower($m['message'] ?? '');
  if ($message === '') {
    return null;
  }

  return [
    'timestamp' => $m['ts'],
    'timestamp_unix' => $timestampUnix,
    'file' => $m['file'],
    'message' => $message,
  ];
}

function buildRetrySlot(string $file): ?array
{
  if (!preg_match('#^(?<dir>.+)/(?<hhmm>\d{4})_(?<variant>[a-z0-9]+)\.jpg$#i', $file, $m)) {
    return null;
  }
  if (!is_dir($m['dir']) || !is_file($file)) {
    return null;
  }

  return [
    'file' => $file,
    'dir' => $m['dir'],
    'hhmm' => $m['hhmm'],
    'variant' => strtolower($m['variant']),
  ];
}

function buildRetryCommand(string $dir, string $hhmm, string $variant, bool $verbose): string
{
  $parts = [
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__ . '/extract_wx_ocr.php'),
    '--dir=' . escapeshellarg($dir),
    '--time=' . escapeshellarg($hhmm),
    '--variant=' . escapeshellarg($variant),
    '--overwrite',
    '--rescue',
  ];
  if ($verbose) {
    $parts[] = '--verbose';
  }

  return implode(' ', $parts);
}

function extractSlugFromPath(string $path): ?string
{
  if (preg_match('#/webcam_exthdd/([^/]+)/#', $path, $m)) {
    return $m[1];
  }

  return null;
}

function extractYearFromPath(string $path): ?int
{
  if (preg_match('#/\d{4}/#', $path, $m, PREG_OFFSET_CAPTURE) && isset($m[0][1])) {
    $value = substr($path, $m[0][1] + 1, 4);
    if (preg_match('/^\d{4}$/', $value)) {
      return (int) $value;
    }
  }

  return null;
}

function stampLine(string $message): string
{
  return '[' . gmdate('c') . '] ' . $message;
}
