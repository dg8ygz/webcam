#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Backup\SidecarBackup;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$dateArg = null;
$fromArg = null;
$toArg = null;
$yearArg = null;
$slugs = [];
$overwrite = false;

foreach ($args as $argument) {
  if ($argument === '--help' || $argument === '-h') {
    echo "Usage: php bin/backup_sidecars.php [--date=YYYY-MM-DD | --year=YYYY | --from=YYYY-MM-DD --to=YYYY-MM-DD] [--slug=<camera>] [--overwrite]\n";
    echo "Without a date option, the completed previous day is backed up.\n";
    exit(0);
  }
  if (str_starts_with($argument, '--date=')) {
    $dateArg = substr($argument, 7);
    continue;
  }
  if (str_starts_with($argument, '--from=')) {
    $fromArg = substr($argument, 7);
    continue;
  }
  if (str_starts_with($argument, '--to=')) {
    $toArg = substr($argument, 5);
    continue;
  }
  if (str_starts_with($argument, '--year=')) {
    $yearArg = substr($argument, 7);
    continue;
  }
  if (str_starts_with($argument, '--slug=')) {
    $slugs[] = substr($argument, 7);
    continue;
  }
  if ($argument === '--overwrite') {
    $overwrite = true;
    continue;
  }
  fwrite(STDERR, "Unknown argument: {$argument}\n");
  exit(2);
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$timezone = new DateTimeZone((string) $app->config('timezone', 'UTC'));
$knownSlugs = array_map(static fn($camera): string => $camera->slug(), $app->cameras()->all());
$unknownSlugs = array_values(array_diff(array_unique($slugs), $knownSlugs));
if ($unknownSlugs !== []) {
  fwrite(STDERR, '[ERROR] unknown camera slug(s): ' . implode(', ', $unknownSlugs) . "\n");
  exit(2);
}

try {
  $days = sidecarBackupDays($timezone, $dateArg, $yearArg, $fromArg, $toArg);
  $stats = (new SidecarBackup($app))->backupDays($days, $slugs, $overwrite);
  printf(
    "Sidecar backup complete: dates=%d files=%d copied=%d unchanged=%d failed=%d target=%s\n",
    count($stats['dates']),
    $stats['total'],
    $stats['copied'],
    $stats['unchanged'],
    $stats['failed'],
    $stats['target_base']
  );
  exit($stats['failed'] === 0 ? 0 : 1);
} catch (Throwable $exception) {
  fwrite(STDERR, '[ERROR] sidecar backup failed: ' . $exception->getMessage() . "\n");
  exit(1);
}

/** @return list<DateTimeImmutable> */
function sidecarBackupDays(
  DateTimeZone $timezone,
  ?string $dateArg,
  ?string $yearArg,
  ?string $fromArg,
  ?string $toArg
): array {
  $modes = (int) ($dateArg !== null) + (int) ($yearArg !== null) + (int) ($fromArg !== null || $toArg !== null);
  if ($modes > 1 || (($fromArg === null) xor ($toArg === null))) {
    throw new InvalidArgumentException('choose exactly one of --date, --year or the complete --from/--to pair');
  }

  if ($dateArg !== null) {
    return [parseSidecarBackupDate($dateArg, $timezone)];
  }
  if ($yearArg !== null) {
    if (preg_match('/^\d{4}$/', $yearArg) !== 1) {
      throw new InvalidArgumentException('invalid --year value');
    }
    $fromArg = $yearArg . '-01-01';
    $toArg = $yearArg . '-12-31';
  }
  if ($fromArg === null && $toArg === null) {
    return [(new DateTimeImmutable('now', $timezone))->setTime(0, 0)->sub(new DateInterval('P1D'))];
  }

  $from = parseSidecarBackupDate((string) $fromArg, $timezone);
  $to = parseSidecarBackupDate((string) $toArg, $timezone);
  if ($to < $from) {
    throw new InvalidArgumentException('--to must not be earlier than --from');
  }
  $days = [];
  for ($day = $from; $day <= $to; $day = $day->add(new DateInterval('P1D'))) {
    $days[] = $day;
  }
  return $days;
}

function parseSidecarBackupDate(string $value, DateTimeZone $timezone): DateTimeImmutable
{
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
  $errors = DateTimeImmutable::getLastErrors();
  if (
    !$date
    || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
    || $date->format('Y-m-d') !== $value
  ) {
    throw new InvalidArgumentException("invalid date: {$value}");
  }
  return $date;
}
