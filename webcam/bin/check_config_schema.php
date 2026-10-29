#!/usr/bin/env php
<?php
declare(strict_types=1);

// Rollout-Schranke: Runtime-Code darf nicht mehr Spalten verlangen, als die
// Ziel-DB besitzt. Dieses Werkzeug liest die Ziel-DB ausschliesslich; das
// Referenzschema wird nur in einer fluechtigen In-Memory-DB aufgebaut.
$options = getopt('', ['db:', 'help']);
if (isset($options['help'])) {
  echo "Usage: php bin/check_config_schema.php --db=/absolute/config.sqlite\n";
  exit(0);
}
$path = $options['db'] ?? null;
if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path)) {
  fwrite(STDERR, "--db muss eine vorhandene absolute Datenbankdatei bezeichnen\n");
  exit(2);
}
try {
  $reference = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $reference->exec((string) file_get_contents(__DIR__ . '/../database/schema.config.sql'));
  $live = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
  ]);
  $quote = static fn(string $name): string => '"' . str_replace('"', '""', $name) . '"';
  $missing = [];
  foreach ($reference->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $required = $reference->query('PRAGMA table_info(' . $quote($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    $actual = $live->query('PRAGMA table_info(' . $quote($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    $actualNames = array_column($actual, 'name');
    foreach ($required as $column) {
      if (!in_array($column['name'], $actualNames, true)) {
        $missing[] = $table . '.' . $column['name'];
      }
    }
  }
  if ($missing !== []) {
    fwrite(STDERR, "STOP: Ziel-DB benoetigt eine separat freigegebene Migration: " . implode(', ', $missing) . "\n");
    exit(1);
  }
  echo "PASS: alle vom Release verlangten Config-Tabellen/Spalten vorhanden; nichts geschrieben\n";
} catch (Throwable $error) {
  fwrite(STDERR, 'Schema-Pruefung fehlgeschlagen: ' . $error->getMessage() . "\n");
  exit(2);
}
