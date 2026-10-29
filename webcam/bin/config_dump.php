#!/usr/bin/env php
<?php
declare(strict_types=1);

// Read-only Diagnose: lädt genau die aktuell konfigurierte Config-SQLite über
// camera_store.php und gibt das Dokument als formatiertes JSON auf stdout aus.
$args = $_SERVER['argv'] ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo "Usage: php bin/config_dump.php\n";
  echo "Prints the current camera configuration (SQLite) as prettified JSON.\n";
  exit(0);
}

require __DIR__ . '/../config/camera_store.php';

$doc = webcam_camera_store_load();
$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  fwrite(STDERR, "Failed to encode configuration.\n");
  exit(1);
}

echo $json . "\n";
