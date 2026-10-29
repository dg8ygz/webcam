#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Infrastructure\Process\BoundedProcessRunner;
use Webcam\Service\Maintenance\MaintenanceBackupGuard;
use Webcam\Service\Maintenance\MaintenanceCommand;
use Webcam\Service\Maintenance\MaintenanceLog;
use Webcam\Service\Maintenance\MaintenanceSchedule;

/**
 * Interner Runner fuer die im Admin konfigurierten Wartungsscripte.
 * Gestartet wird er vom Kamera-Poll-Tick in include/command.php.
 * Pro Script und Kalendertag wird hoechstens ein Lauf gestartet.
 */

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$dryRun = false;
$nowOverride = null;
foreach ($args as $arg) {
  if ($arg === '--help' || $arg === '-h') {
    echo "Usage: php bin/maintenance_scheduler.php [--dry-run [--now=<ISO-8601>]]\n";
    echo "Runs due maintenance tasks configured in Admin > Scripte.\n";
    echo "--now is only allowed with --dry-run and is intended for deterministic tests.\n";
    exit(0);
  }
  if ($arg === '--dry-run') {
    $dryRun = true;
    continue;
  }
  if (str_starts_with($arg, '--now=')) {
    $value = substr($arg, strlen('--now='));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
      fwrite(STDERR, "--now must be an ISO-8601 timestamp\n");
      exit(2);
    }
    try {
      $nowOverride = new DateTimeImmutable($value);
    } catch (Throwable) {
      fwrite(STDERR, "--now must be a valid ISO-8601 timestamp\n");
      exit(2);
    }
    continue;
  }
  fwrite(STDERR, "Unknown argument: {$arg}\n");
  exit(2);
}
if ($nowOverride !== null && !$dryRun) {
  fwrite(STDERR, "--now is only allowed with --dry-run\n");
  exit(2);
}

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$settings = MaintenanceSchedule::normalize($app->camerasMeta()['maintenance_scripts'] ?? null);
$now = $nowOverride ?? new DateTimeImmutable('now');
$currentTime = $now->format('H:i');
$currentDay = $now->format('Y-m-d');
$exitCode = 0;

foreach ($settings as $task => $taskSettings) {
  $label = $taskSettings['label'];
  if (!$taskSettings['enabled'] || $currentTime < $taskSettings['time']) {
    continue;
  }

  $lockPath = $app->paths()->statePath('maintenance-scheduler/' . $task . '.lock');
  $lockDir = dirname($lockPath);
  if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "[ERROR] cannot create maintenance state directory\n");
    $exitCode = max($exitCode, 2);
    continue;
  }
  $lock = fopen($lockPath, 'c');
  if ($lock === false) {
    fwrite(STDERR, "[ERROR] cannot open lock for {$task}\n");
    $exitCode = max($exitCode, 2);
    continue;
  }
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    printf("[SKIP] %s already locked\n", $label);
    fclose($lock);
    continue;
  }

  $statePath = null;
  $startedAt = null;
  try {
    $statePath = $app->paths()->statePath('maintenance-scheduler/' . $task . '.json');
    $state = loadMaintenanceState($statePath);
    if (($state['scheduled_day'] ?? null) === $currentDay) {
      continue;
    }

    if (!$dryRun && $task === MaintenanceSchedule::WEATHER_QUALITY) {
      $dependency = fullBackupDependency(
        $app,
        $currentDay,
        $settings[MaintenanceSchedule::BACKUP_DAILY],
        'stromberg'
      );
      if (!$dependency['ready']) {
        if (!$dependency['terminal']) {
          printf("[WAIT] %s: %s\n", $label, $dependency['reason']);
          continue;
        }
        $blockedAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        writeMaintenanceState($statePath, [
          'task' => $task,
          'status' => 'blocked',
          'scheduled_day' => $currentDay,
          'scheduled_time' => $taskSettings['time'],
          'started_at' => $blockedAt,
          'finished_at' => $blockedAt,
          'exit_code' => 3,
          'log_entries' => MaintenanceLog::lastEntries($dependency['reason']),
        ]);
        fwrite(STDERR, "[BLOCKED] {$label}: {$dependency['reason']}\n");
        $exitCode = max($exitCode, 1);
        continue;
      }
    }

    if ($dryRun) {
      printf("[DRY] %s due at %s\n", $label, $currentTime);
      continue;
    }

    $startedAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
    writeMaintenanceState($statePath, [
      'task' => $task,
      'status' => 'running',
      'scheduled_day' => $currentDay,
      'scheduled_time' => $taskSettings['time'],
      'started_at' => $startedAt,
      'finished_at' => null,
      'exit_code' => null,
      'log_entries' => [],
    ]);

    printf("[START] %s at %s\n", $label, $startedAt);
    $execution = MaintenanceCommand::build(dirname(__DIR__), $task, $taskSettings);
    $command = $execution['command'];
    $timeoutSeconds = $execution['timeout_seconds'];
    $taskResult = runMaintenanceCommand($command, $timeoutSeconds, dirname(__DIR__));
    $taskExitCode = $taskResult['exit_code'];
    $finishedAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
    writeMaintenanceState($statePath, [
      'task' => $task,
      'status' => $taskExitCode === 0 ? 'ok' : 'error',
      'scheduled_day' => $currentDay,
      'scheduled_time' => $taskSettings['time'],
      'started_at' => $startedAt,
      'finished_at' => $finishedAt,
      'exit_code' => $taskExitCode,
      'log_entries' => $taskResult['log_entries'],
    ]);
    printf("[DONE] %s exit=%d at %s\n", $label, $taskExitCode, $finishedAt);
    if ($taskExitCode !== 0) {
      $exitCode = max($exitCode, 1);
    }
  } catch (Throwable $exception) {
    fwrite(STDERR, "[ERROR] {$task}: {$exception->getMessage()}\n");
    if (!$dryRun && $statePath !== null && $startedAt !== null) {
      try {
        writeMaintenanceState($statePath, [
          'task' => $task,
          'status' => 'error',
          'scheduled_day' => $currentDay,
          'scheduled_time' => $taskSettings['time'],
          'started_at' => $startedAt,
          'finished_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
          'exit_code' => 2,
          'log_entries' => MaintenanceLog::lastEntries($exception->getMessage()),
        ]);
      } catch (Throwable $stateException) {
        fwrite(STDERR, "[ERROR] {$task} state: {$stateException->getMessage()}\n");
      }
    }
    $exitCode = max($exitCode, 2);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

exit($exitCode);

/** @return array<string,mixed> */
function loadMaintenanceState(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  if (!is_string($raw)) {
    throw new RuntimeException('cannot read maintenance scheduler state');
  }
  try {
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $exception) {
    throw new RuntimeException('invalid maintenance scheduler state', 0, $exception);
  }
  if (!is_array($decoded)) {
    throw new RuntimeException('maintenance scheduler state must be an object');
  }
  return $decoded;
}

/**
 * @param array{enabled:bool,time:string} $backupSettings
 * @return array{ready:bool,terminal:bool,reason:string}
 */
function fullBackupDependency(
  \Webcam\App $app,
  string $currentDay,
  array $backupSettings,
  string $requiredSlug
): array {
  $schedulerState = loadMaintenanceState(
    $app->paths()->statePath('maintenance-scheduler/' . MaintenanceSchedule::BACKUP_DAILY . '.json')
  );
  $backupState = loadMaintenanceState($app->paths()->statePath('backup_db.json'));
  return MaintenanceBackupGuard::evaluate(
    $backupSettings,
    $schedulerState,
    $backupState,
    $currentDay,
    (string) $app->config('timezone', 'UTC'),
    $requiredSlug
  );
}

/** @param array<string,mixed> $state */
function writeMaintenanceState(string $path, array $state): void
{
  if (!\webcam_atomic_write_json($path, $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
    throw new RuntimeException('cannot write maintenance scheduler state');
  }
}

/** @param list<string> $command @return array{exit_code:int,log_entries:list<string>} */
function runMaintenanceCommand(array $command, int $timeoutSeconds, string $cwd): array
{
  $combinedOutput = '';
  $appendOutput = static function (string $chunk) use (&$combinedOutput): void {
    $combinedOutput .= $chunk;
    if (strlen($combinedOutput) > 65536) {
      $combinedOutput = substr($combinedOutput, -65536);
    }
  };
  $result = (new BoundedProcessRunner())->run($command, $timeoutSeconds, [
    'cwd' => $cwd,
    // Der Scheduler schreibt weiter live ins Journal. Der interne Puffer ist
    // nur eine begrenzte Reserve für den gemeinsamen Prozessablauf.
    'output_max_bytes' => 65536,
    'output_retention' => 'tail',
    'require_process_group' => true,
    'stdout_handler' => static function (string $chunk) use ($appendOutput): void {
      $appendOutput($chunk);
      fwrite(STDOUT, $chunk);
    },
    'stderr_handler' => static function (string $chunk) use ($appendOutput): void {
      $appendOutput($chunk);
      fwrite(STDERR, $chunk);
    },
  ]);
  if (!$result['started']) {
    throw new RuntimeException('cannot start maintenance command');
  }
  return [
    'exit_code' => $result['exit_code'],
    'log_entries' => MaintenanceLog::lastEntries($combinedOutput),
  ];
}
