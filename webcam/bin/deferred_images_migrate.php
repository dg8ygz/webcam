#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Storage\DeferredImageSchema;

require_once __DIR__ . '/../autoload.php';
// Absichtlich kein bootstrap: kein automatisch veraendertes Live-Schema,
// keine Kamera-Konfiguration und keine Dienste durch eine Planpruefung laden.
$options = getopt('', ['db:', 'backup:', 'apply', 'help']);
if (isset($options['help']) || !isset($options['db'])) {
  echo "Usage: php bin/deferred_images_migrate.php --db=/absolute/camera.sqlite [--apply --backup=/private/new.sqlite]\n";
  echo "Default: read-only schema plan. Apply only after operator approval.\n";
  exit(isset($options['help']) ? 0 : 2);
}
try {
  $path = realpath((string) $options['db']);
  if (!$path || !is_file($path) || is_link($options['db'])) throw new RuntimeException('Database must exist and not be a symlink');
  $db = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->query('SELECT slot_ts FROM captures LIMIT 1');
  if (DeferredImageSchema::exists($db)) { echo "Schema already present; no change.\n"; exit(0); }
  echo DeferredImageSchema::SQL . ";\n";
  if (!isset($options['apply'])) { echo "PLAN ONLY: no database changes.\n"; exit(0); }
  $backup = (string) ($options['backup'] ?? '');
  $parent = realpath(dirname($backup));
  if (!$parent || !str_starts_with($backup, '/') || file_exists($backup) || is_link($backup)
    || (fileperms($parent) & 0077) !== 0) throw new RuntimeException('New backup filename in private 0700 directory required');
  // VACUUM INTO nimmt auch bei WAL eine konsistente Sicherung, keine nackte Dateikopie.
  $oldMask = umask(0077);
  try { $db->exec('VACUUM INTO ' . $db->quote($backup)); }
  finally { umask($oldMask); }
  $check = new PDO('sqlite:file:' . $backup . '?mode=ro');
  if ($check->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new RuntimeException('Backup integrity check failed');
  $check = null; $db = null;
  $db = new PDO('sqlite:file:' . $path . '?mode=rw', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec('PRAGMA busy_timeout=5000');
  $db->beginTransaction();
  try { DeferredImageSchema::install($db); $db->commit(); }
  catch (Throwable $error) { $db->rollBack(); throw $error; }
  echo "BACKUP: {$backup}\nADDED: deferred_images. Existing rows unchanged; pilot still requires explicit activation.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
