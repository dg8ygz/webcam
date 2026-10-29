#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Health-Check für Scheduler-State.
 * Meldet slugs, deren var/state/schedule/<slug>.json älter als Schwelle ist
 * oder bei denen nur Dry-Run-Einträge vorliegen.
 * Das Skript liest nur State-Dateien und signalisiert Probleme per Exit-Code.
 *
 * Usage: php bin/check_scheduler_state.php [--max-age=900]
 */

$app = require __DIR__ . '/../bootstrap.php';

$maxAge = 900; // 15 Minuten
foreach ($_SERVER['argv'] ?? [] as $arg) {
  if (preg_match('/^--max-age=(\d+)$/', $arg, $m)) {
    $maxAge = max(60, (int) $m[1]);
  }
}

$scheduleDir = $app->paths()->statePath('schedule');
$now = time();
$exit = 0;

$cameras = [];
foreach ($app->cameras()->all() as $cam) {
  $cameras[] = $cam->slug();
}

foreach ($cameras as $slug) {
  $path = $scheduleDir . DIRECTORY_SEPARATOR . $slug . '.json';
  if (!is_file($path)) {
    printf("[MISSING] %s: kein schedule state\n", $slug);
    $exit = 1;
    continue;
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    printf("[MISSING] %s: schedule state unreadable\n", $slug);
    $exit = 1;
    continue;
  }
  $data = json_decode((string) $raw, true) ?: [];
  $last = (int)($data['last_checked_at'] ?? 0);
  $age = $now - $last;
  if ($last === 0 || $age > $maxAge) {
    printf("[STALE]   %s: last_checked_at=%s (Age %ds > %ds)\n", $slug, $last ?: 'n/a', $age, $maxAge);
    $exit = 1;
  }
  if (!empty($data['dry_run'])) {
    printf("[DRYRUN]  %s: letzter Eintrag im Dry-Run (capture läuft evtl. nicht)\n", $slug);
    $exit = 1;
  }
}

exit($exit);
