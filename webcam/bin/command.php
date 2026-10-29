#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Manueller/Legacy-Tick-Scheduler für Diagnose und bewusste CLI-Läufe.
 *
 * Er entscheidet je Kamera anhand Intervall, Extra-Zeiten, Window, Backoff und
 * letztem State, ob CaptureManager laufen soll. --dry-run schreibt nur den
 * dokumentierten Scheduler-State. Produktion nutzt stattdessen externe Polls
 * über include/command.php und richtet keinen lokalen Capture-Cron ein.
 *
 * Aufruf: php bin/command.php [--slug=<camera>] [--dry-run]
 */
$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/command.php [--slug=<camera>] [--dry-run]\n";
  echo "  --slug=<camera>  Limit scheduler to a single camera (can repeat).\n";
  echo "  --dry-run        Do not execute captures, only report due cameras.\n";
  exit(0);
}

$app = require __DIR__ . '/../bootstrap.php';

array_shift($args);
$filterSlugs = [];
$dryRun = false;

foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $filterSlugs[] = substr($arg, strlen('--slug='));
    continue;
  }
  if ($arg === '--dry-run') {
    $dryRun = true;
    continue;
  }
}

$now = time();
$exitCode = 0;
$captureManager = $app->captureManager();
$statusService = $app->statusService();

$scheduleDir = $app->paths()->statePath('schedule');
if (!is_dir($scheduleDir) && !mkdir($scheduleDir, 0775, true) && !is_dir($scheduleDir)) {
  fwrite(STDERR, "[WARN] cannot create schedule dir: {$scheduleDir}\n");
}

function readScheduleState(string $dir, string $slug): array
{
  $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug . '.json';
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    fwrite(STDERR, "[WARN] cannot read schedule state: {$path}\n");
    return [];
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}


foreach ($app->cameras()->all() as $camera) {
  if ($filterSlugs && !in_array($camera->slug(), $filterSlugs, true)) {
    continue;
  }

  $status = $statusService->state($camera);
  $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
  $lastCaptureAt = isset($status['last_capture_at']) ? (int) $status['last_capture_at'] : null;
  $age = $lastCaptureAt ? max(0, $now - $lastCaptureAt) : $interval + 1;
  $scheduleState = readScheduleState($scheduleDir, $camera->slug());

  $tzName = (string)$app->config('timezone', 'UTC');
  $dueWindow = isWithinWindow($camera, $now, $tzName);

  // Backoff nach Fehlern
  $failures = (int) ($status['consecutive_failures'] ?? 0);
  $lastFailureAt = isset($status['last_failure_at']) ? (int) $status['last_failure_at'] : null;
  $cooldownUntil = null;
  if ($failures > 0 && $lastFailureAt) {
    $cooldownUntil = $lastFailureAt + \Webcam\Service\Scheduler\Backoff::seconds($failures);
  }

  $nextDue = $lastCaptureAt ? $lastCaptureAt + $interval : $now - 1;
  if (isset($scheduleState['next_due_at'])) {
    $nextDue = max($nextDue, (int) $scheduleState['next_due_at']);
  }

  // Extra-Times können Due erzwingen
  $extraSlot = \Webcam\Service\Scheduler\CaptureSchedule::extraSlot($camera, $now, $tzName);
  $extraDue = $extraSlot !== null && $extraSlot > (int) ($scheduleState['last_extra_slot'] ?? 0);
  $due = $dueWindow && ($extraDue || $now >= $nextDue) && ($cooldownUntil === null || $now >= $cooldownUntil);
  $windowRemaining = nextWindowStartInSeconds($camera, $now, $tzName);

  if (!$due) {
    $remaining = max(0, $nextDue - $now);
    if ($cooldownUntil !== null && $cooldownUntil > $now) {
      $remaining = $cooldownUntil - $now;
    }
    $nextNote = $windowRemaining !== null ? sprintf('window in %ds', $windowRemaining) : sprintf('next in %ds', $remaining);
    printf("[SKIP] %-15s %s\n", $camera->slug(), $nextNote);
    writeScheduleState($scheduleDir, $camera->slug(), [
      'last_extra_slot' => $scheduleState['last_extra_slot'] ?? 0,
      'last_checked_at' => $now,
      'last_decision' => 'skip',
      'next_due_at' => $nextDue,
      'cooldown_until' => $cooldownUntil,
      'window_in_seconds' => $windowRemaining,
      'dry_run' => $dryRun,
    ]);
    continue;
  }

  if ($dryRun) {
    printf("[DUE]  %-15s would capture now (age %ds)\n", $camera->slug(), $age);
    writeScheduleState($scheduleDir, $camera->slug(), [
      'last_checked_at' => $now,
      'last_decision' => 'dry_due',
      'last_extra_slot' => $extraDue ? $extraSlot : ($scheduleState['last_extra_slot'] ?? 0),
      'next_due_at' => $now + $interval,
      'dry_run' => true,
    ]);
    continue;
  }

  $result = $captureManager->capture($camera, slotTimestamp: $extraDue ? $extraSlot : null);
  if (!$dryRun) {
    printf("[%s] %-15s %s\n", $result->successful ? 'OK' : 'FAIL', $camera->slug(), $result->message);
  }
  if (!$result->successful) {
    $exitCode = 1;
  }

  writeScheduleState($scheduleDir, $camera->slug(), [
    'last_checked_at' => $now,
    'last_decision' => 'capture',
    'last_extra_slot' => $extraDue ? $extraSlot : ($scheduleState['last_extra_slot'] ?? 0),
    'last_due_at' => $now,
    'next_due_at' => $now + $interval,
    'dry_run' => $dryRun,
  ]);
}

exit($exitCode);

function isWithinWindow(\Webcam\Domain\Camera $camera, int $now, string $tzName): bool
{
  return \Webcam\Service\Scheduler\CaptureSchedule::withinWindow($camera, $now, $tzName);
}

function nextWindowStartInSeconds(\Webcam\Domain\Camera $camera, int $now, string $tzName): ?int
{
  return \Webcam\Service\Scheduler\CaptureSchedule::nextWindowStart($camera, $now, $tzName);
}

function writeScheduleState(string $dir, string $slug, array $data): void
{
  $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug . '.json';
  if (!webcam_atomic_write_json($path, $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
    fwrite(STDERR, "[WARN] cannot write schedule state: {$path}\n");
  }
}
