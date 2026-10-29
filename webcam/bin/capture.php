#!/usr/bin/env php
<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

// CLI-Adapter: Argumente auswählen und die eigentliche Aufnahmelogik vollständig
// an CaptureManager delegieren. Ohne Slug werden alle konfigurierten Kameras
// nacheinander verarbeitet; der Exit-Code wird bei mindestens einem Fehler 1.
// Noch nicht publizierte Slot-Quellen zeigen WAIT statt FAIL, bleiben ohne
// aufgenommenes Bild aber ebenfalls ein Nichtnull-Ergebnis fuer CLI-Aufrufer.
$captureManager = $app->captureManager();
$cameras = $app->cameras()->all();
$args = $argv;
array_shift($args);
$forcedCamera = null;
$forceNow = false;
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/capture.php [slug] [--now]\n";
  echo "  slug   Optional camera slug. If omitted, all cameras are captured.\n";
  echo "  --now  Force immediate capture even for passive cameras (where applicable).\n";
  exit(0);
}
foreach ($args as $arg) {
  if ($arg === '--now') {
    $forceNow = true;
    continue;
  }
  if ($forcedCamera === null) {
    $forcedCamera = $arg;
  }
}

if ($forcedCamera) {
  $camera = $app->cameras()->find($forcedCamera);
  if (!$camera) {
    fwrite(STDERR, "Camera not found: {$forcedCamera}\n");
    exit(2);
  }
$result = $captureManager->capture($camera, $forceNow);
  echo sprintf(
    "[%s] %s: %s\n",
    $result->pending ? 'WAIT' : ($result->successful ? 'OK' : 'FAIL'),
    $camera->slug(),
    $result->message
  );
  exit($result->successful ? 0 : 1);
}

$exitCode = 0;
foreach ($cameras as $camera) {
  $result = $captureManager->capture($camera, $forceNow);
  echo sprintf(
    "[%s] %s: %s\n",
    $result->pending ? 'WAIT' : ($result->successful ? 'OK' : 'FAIL'),
    $camera->slug(),
    $result->message
  );
  if (!$result->successful) {
    $exitCode = 1;
  }
}

exit($exitCode);
