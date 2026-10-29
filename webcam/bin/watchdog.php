#!/usr/bin/env php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

// Prüft pro aktivierter Kamera die Frische der Current-Dateien und den Status.
// Nur wenn StatusService Fehlerschwelle/Cooldown bestätigt, delegiert das Skript
// einen konfigurierten Reset an ResetService und liefert bei Problemen Exit 1.
$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/watchdog.php\n";
  echo "Checks freshness of current images and triggers resets based on watchdog config.\n";
  exit(0);
}

$statusService = $app->statusService();
$resetService = $app->resetService();
$now = time();
$exitCode = 0;

foreach ($app->cameras()->all() as $camera) {
  $watchdog = $camera->status()['watchdog'] ?? null;
  if (!is_array($watchdog) || empty($watchdog['enabled'])) {
    continue;
  }
  $preferred = [];
  $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
  $latestDir = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $camera->slug() . '/current';
  $preferred[] = $latestDir . '/current.jpg';
  $preferred[] = $latestDir . '/400.jpg';
  $preferred[] = $latestDir . '/full.jpg';
  $preferred[] = $latestDir . '/hu.jpg';

  $imagePath = null;
  foreach ($preferred as $candidate) {
    if (is_file($candidate)) {
      $imagePath = $candidate;
      break;
    }
  }

  if (!$imagePath) {
    $statusService->recordFailure($camera, 'watchdog missing current image');
    if ($statusService->shouldTriggerReset($camera)) {
      $resetService->execute($camera, 'missing current image');
    }
    $exitCode = 1;
    echo "[WARN] {$camera->slug()}: missing current image\n";
    continue;
  }

  $ageSeconds = $now - filemtime($imagePath);
  $interval = (int)($camera->capture()['interval_seconds'] ?? 600);
  $threshold = $interval * 3;

  if ($ageSeconds > $threshold) {
    $note = sprintf('watchdog stale image (%ds old)', $ageSeconds);
    $statusService->recordFailure($camera, $note);
    if ($statusService->shouldTriggerReset($camera)) {
      $resetService->execute($camera, $note);
    }
    $exitCode = 1;
    echo "[WARN] {$camera->slug()}: {$note}\n";
  } else {
    echo "[OK] {$camera->slug()}: image age {$ageSeconds}s\n";
  }
}

exit($exitCode);
