#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Infrastructure\Storage\CameraDataStore;

require_once __DIR__ . '/backup_daily.php';

/**
 * Gleicht einen begrenzten DB-Zeitraum mit den tatsächlich vorhandenen JPEGs ab.
 * Wetter- und EXIF-Zeilen bleiben unangetastet; unbrauchbare Capture-Zeilen
 * erhalten captures.is_duplicate=1. Jede Variantenzeile wird zusätzlich mit
 * der realen Datei abgeglichen. Fehlende oder ungültige JPEGs erhalten
 * size_bytes=0, damit Bildsuche und Timeline sie nicht mehr auswählen.
 *
 * Ein Capture gilt als unbrauchbar, wenn sein SHA-256 dem letzten verwendbaren
 * Capture entspricht oder keine seiner in der DB vermerkten Bilddateien als
 * JPEG lesbar ist. Der Lauf ist standardmäßig ein Dry-Run.
 */

$usage = <<<'TXT'
Usage: php bin/reconcile_capture_images.php --slug=<camera> --from=<date> [options]

Required:
  --slug=<camera>       Genau eine Kamera.
  --from=<date>         Untere Grenze in Projektzeitzone, z.B. "2026-08-14 06:10".

Options:
  --to=<date>           Obere Grenze (inklusive), Standard: jetzt.
  --db-path=<path>      Abweichende Kamera-DB, z.B. für kontrollierte Alt-PROD-Läufe.
  --apply               Flags tatsächlich schreiben; sonst nur Dry-Run.
  --clear-restored      Bei --apply vorhandene Flags für wieder nutzbare Bilder entfernen.
  --verbose|-v          Jede geprüfte Capture-Zeile ausgeben.
  --help|-h             Diese Hilfe anzeigen.

Vor --apply wird automatisch ein konsistentes SQLite-Backup unter var/state/backup/db/<slug>/ angelegt.
Bild-, Wetter-, EXIF- und Variantenzeilen werden nicht gelöscht. Bei Varianten
werden ausschließlich size_bytes und mtime mit dem realen Dateizustand synchronisiert.
TXT;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
  echo $usage . PHP_EOL;
  exit(0);
}

$slug = null;
$fromValue = null;
$toValue = null;
$dbPathOverride = null;
$apply = false;
$clearRestored = false;
$verbose = false;
foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slug = trim(substr($arg, strlen('--slug=')));
  } elseif (str_starts_with($arg, '--from=')) {
    $fromValue = trim(substr($arg, strlen('--from=')));
  } elseif (str_starts_with($arg, '--to=')) {
    $toValue = trim(substr($arg, strlen('--to=')));
  } elseif (str_starts_with($arg, '--db-path=')) {
    $dbPathOverride = trim(substr($arg, strlen('--db-path=')));
  } elseif ($arg === '--apply') {
    $apply = true;
  } elseif ($arg === '--clear-restored') {
    $clearRestored = true;
  } elseif ($arg === '--verbose' || $arg === '-v') {
    $verbose = true;
  } else {
    fwrite(STDERR, "Unbekanntes Argument: {$arg}\n\n{$usage}\n");
    exit(2);
  }
}

if ($slug === null || $slug === '' || $fromValue === null || $fromValue === '') {
  fwrite(STDERR, "--slug und --from sind erforderlich.\n\n{$usage}\n");
  exit(2);
}
if ($clearRestored && !$apply) {
  fwrite(STDERR, "--clear-restored ist nur zusammen mit --apply zulässig.\n");
  exit(2);
}

$app = require __DIR__ . '/../bootstrap.php';
$camera = $app->cameras()->find($slug);
if ($camera === null) {
  fwrite(STDERR, "Unbekannte Kamera: {$slug}\n");
  exit(2);
}

try {
  $timezone = new DateTimeZone((string) $app->config('timezone', 'UTC'));
  $fromTs = parseBoundary($fromValue, $timezone, false);
  $toTs = $toValue !== null && $toValue !== ''
    ? parseBoundary($toValue, $timezone, true)
    : time();
} catch (Throwable $exception) {
  fwrite(STDERR, 'Ungültiger Zeitraum: ' . $exception->getMessage() . PHP_EOL);
  exit(2);
}
if ($toTs < $fromTs) {
  fwrite(STDERR, "--to liegt vor --from.\n");
  exit(2);
}

$readStore = new CameraDataStore($app, $camera, $dbPathOverride, true);
$dbPath = $readStore->dbPath();
if (!is_file($dbPath)) {
  fwrite(STDERR, "Kamera-DB fehlt: {$dbPath}\n");
  exit(1);
}

if ($apply) {
  $backupDir = $app->paths()->statePath('backup/db/' . $camera->slug());
  $backupPath = backupDatabase($dbPath, $backupDir);
  fwrite(STDOUT, "[BACKUP] {$backupPath}\n");
  $readStore->close();
  $writeStore = new CameraDataStore($app, $camera, $dbPathOverride);
  $writeStore->ensureWritableSchema();
  $pdo = $writeStore->pdo();
} else {
  $writeStore = null;
  $pdo = $readStore->pdo();
}

$hasDuplicateColumn = hasColumn($pdo, 'captures', 'is_duplicate');
$duplicateSelect = $hasDuplicateColumn ? 'is_duplicate' : '0 AS is_duplicate';
$previousSql = "SELECT hash_sha256
                  FROM captures
                 WHERE slot_ts < :from
                   AND hash_sha256 IS NOT NULL
                   AND hash_sha256 <> ''";
if ($hasDuplicateColumn) {
  $previousSql .= ' AND is_duplicate = 0';
}
$previousSql .= ' ORDER BY slot_ts DESC LIMIT 1';
$previousStmt = $pdo->prepare($previousSql);
$previousStmt->execute([':from' => $fromTs]);
$previousValue = $previousStmt->fetchColumn();
$previousHash = is_string($previousValue) && $previousValue !== '' ? $previousValue : null;

$stmt = $pdo->prepare(
  "SELECT id, slot_ts, relative_path, hash_sha256, {$duplicateSelect}
     FROM captures
    WHERE slot_ts BETWEEN :from AND :to
    ORDER BY slot_ts ASC"
);
$stmt->execute([':from' => $fromTs, ':to' => $toTs]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$variantStmt = $pdo->prepare(
  'SELECT id, relative_path, size_bytes, mtime FROM variants WHERE capture_id = :capture_id ORDER BY id ASC'
);

$stats = [
  'checked' => 0,
  'usable' => 0,
  'same_hash' => 0,
  'missing_image' => 0,
  'marked' => 0,
  'already_marked' => 0,
  'cleared' => 0,
  'variants_checked' => 0,
  'variants_valid' => 0,
  'variants_missing' => 0,
  'variants_invalid' => 0,
  'variants_updated' => 0,
];
$changes = [];
$variantChanges = [];
foreach ($rows as $row) {
  $stats['checked']++;
  $captureId = (int) ($row['id'] ?? 0);
  $slotTs = (int) ($row['slot_ts'] ?? 0);
  $storedHash = is_string($row['hash_sha256'] ?? null) && $row['hash_sha256'] !== ''
    ? strtolower((string) $row['hash_sha256'])
    : null;
  $currentlyDuplicate = (int) ($row['is_duplicate'] ?? 0) === 1;

  $relativePath = trim((string) ($row['relative_path'] ?? ''));
  $primaryState = inspectJpegState($app, $camera, $relativePath);
  $hasValidJpeg = $primaryState['valid'];
  $hashPath = $primaryState['valid'] ? $primaryState['path'] : null;

  $variantStmt->execute([':capture_id' => $captureId]);
  foreach ($variantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $variantRow) {
    $stats['variants_checked']++;
    $variantId = (int) ($variantRow['id'] ?? 0);
    $variantPath = trim((string) ($variantRow['relative_path'] ?? ''));
    $state = inspectJpegState($app, $camera, $variantPath);
    if ($state['valid']) {
      $stats['variants_valid']++;
      $hasValidJpeg = true;
      $hashPath ??= $state['path'];
    } elseif ($state['exists']) {
      $stats['variants_invalid']++;
    } else {
      $stats['variants_missing']++;
    }

    $expectedSize = $state['valid'] ? $state['size'] : 0;
    $expectedMtime = $state['exists'] ? $state['mtime'] : 0;
    $storedSize = isset($variantRow['size_bytes']) ? (int) $variantRow['size_bytes'] : null;
    $storedMtime = isset($variantRow['mtime']) ? (int) $variantRow['mtime'] : null;
    if ($variantId > 0 && ($storedSize !== $expectedSize || $storedMtime !== $expectedMtime)) {
      $stats['variants_updated']++;
      $variantChanges[] = [$variantId, $expectedSize, $expectedMtime];
      if ($verbose) {
        fwrite(STDOUT, sprintf(
          "[%s-VARIANT] id=%d capture=%d size=%d mtime=%d path=%s\n",
          $apply ? 'SYNC' : 'WOULD-SYNC',
          $variantId,
          $captureId,
          $expectedSize,
          $expectedMtime,
          $variantPath
        ));
      }
    }
  }

  $missingImage = !$hasValidJpeg;
  $effectiveHash = $storedHash;
  if ($effectiveHash === null && $hashPath !== null) {
    $calculated = hash_file('sha256', $hashPath);
    $effectiveHash = is_string($calculated) && $calculated !== '' ? strtolower($calculated) : null;
  }
  $sameHash = $effectiveHash !== null
    && $previousHash !== null
    && hash_equals($previousHash, $effectiveHash);
  $shouldMark = $sameHash || $missingImage;

  if ($sameHash) {
    $stats['same_hash']++;
  }
  if ($missingImage) {
    $stats['missing_image']++;
  }

  $action = 'keep';
  if ($shouldMark) {
    if ($currentlyDuplicate) {
      $stats['already_marked']++;
      $action = 'already-marked';
    } else {
      $stats['marked']++;
      $action = $apply ? 'mark' : 'would-mark';
      $changes[] = [$captureId, true];
    }
  } else {
    $stats['usable']++;
    if ($currentlyDuplicate && $clearRestored) {
      $stats['cleared']++;
      $action = 'clear';
      $changes[] = [$captureId, false];
    }
    if ($effectiveHash !== null) {
      $previousHash = $effectiveHash;
    }
  }

  if ($verbose || $action !== 'keep') {
    $reasons = [];
    if ($sameHash) {
      $reasons[] = 'same-hash';
    }
    if ($missingImage) {
      $reasons[] = 'no-valid-jpeg';
    }
    fwrite(STDOUT, sprintf(
      "[%s] id=%d slot=%s %s\n",
      strtoupper($action),
      $captureId,
      (new DateTimeImmutable('@' . $slotTs))->setTimezone($timezone)->format('Y-m-d H:i:s'),
      $reasons !== [] ? implode(',', $reasons) : 'usable'
    ));
  }
}

// Alle Scan-Cursor und die lokale PDO-Referenz vor dem Schreibpfad freigeben.
// CameraDataStore oeffnet fuer den atomaren Batch bewusst eine frische
// Verbindung; eine noch gehaltene Leserverbindung wuerde diesen eigenen
// BEGIN-IMMEDIATE-Schreibzugriff als "database is locked" blockieren.
$previousStmt->closeCursor();
$stmt->closeCursor();
$variantStmt->closeCursor();
unset($previousStmt, $stmt, $variantStmt, $pdo);

if ($apply && ($changes !== [] || $variantChanges !== [])) {
  $updated = $writeStore->reconcileCaptureImageState($changes, $variantChanges);
  if ($updated['captures'] !== count($changes) || $updated['variants'] !== count($variantChanges)) {
    throw new RuntimeException(sprintf(
      'Nicht alle Bildzustaende wurden aktualisiert: captures=%d/%d variants=%d/%d',
      $updated['captures'],
      count($changes),
      $updated['variants'],
      count($variantChanges)
    ));
  }
}

if ($apply) {
  $latest = $writeStore->pdo()->query(
    'SELECT id, slot_ts, is_duplicate FROM captures ORDER BY slot_ts DESC LIMIT 1'
  )->fetch(PDO::FETCH_ASSOC);
  $currentState = $app->statusService()->state($camera);
  if (
    is_array($latest)
    && (int) ($latest['is_duplicate'] ?? 0) === 1
    && empty($currentState['duplicate_active'])
  ) {
    $weatherStmt = $writeStore->pdo()->prepare(
      'SELECT value FROM weather_entries WHERE capture_id = :capture_id ORDER BY position ASC, id ASC'
    );
    $weatherStmt->execute([':capture_id' => (int) $latest['id']]);
    $weatherLines = array_values(array_filter(array_map(
      'strval',
      $weatherStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
    )));
    $app->statusService()->recordDuplicate(
      $camera,
      sprintf('Duplicate image inventory reconciled [SLOT:%d]', (int) $latest['slot_ts']),
      null,
      $weatherLines !== [] ? implode("\n", $weatherLines) : null
    );
  }
}

fwrite(STDOUT, sprintf(
  "[%s] %s: checked=%d usable=%d same_hash=%d missing_image=%d marked=%d already_marked=%d cleared=%d variants_checked=%d variants_valid=%d variants_missing=%d variants_invalid=%d variants_updated=%d\n",
  $apply ? 'APPLIED' : 'DRY-RUN',
  $slug,
  $stats['checked'],
  $stats['usable'],
  $stats['same_hash'],
  $stats['missing_image'],
  $stats['marked'],
  $stats['already_marked'],
  $stats['cleared'],
  $stats['variants_checked'],
  $stats['variants_valid'],
  $stats['variants_missing'],
  $stats['variants_invalid'],
  $stats['variants_updated']
));

exit(0);

function parseBoundary(string $value, DateTimeZone $timezone, bool $upper): int
{
  $date = new DateTimeImmutable($value, $timezone);
  if ($upper && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
    $date = $date->setTime(23, 59, 59);
  }
  return $date->getTimestamp();
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
  $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $row) {
    if (($row['name'] ?? null) === $column) {
      return true;
    }
  }
  return false;
}

/** @return array{exists:bool,valid:bool,size:int,mtime:int,path:?string} */
function inspectJpegState(Webcam\App $app, Webcam\Domain\Camera $camera, string $relativePath): array
{
  if ($relativePath === '') {
    return ['exists' => false, 'valid' => false, 'size' => 0, 'mtime' => 0, 'path' => null];
  }
  $absolutePath = $app->resolveCameraStoragePath($camera, $relativePath);
  if ($absolutePath === null || !is_file($absolutePath)) {
    return ['exists' => false, 'valid' => false, 'size' => 0, 'mtime' => 0, 'path' => null];
  }
  $size = filesize($absolutePath);
  $mtime = filemtime($absolutePath);
  $size = is_int($size) ? max(0, $size) : 0;
  $mtime = is_int($mtime) ? max(0, $mtime) : 0;
  $info = $size > 0 ? @getimagesize($absolutePath) : false;
  $valid = is_array($info) && ($info[2] ?? null) === IMAGETYPE_JPEG;
  return [
    'exists' => true,
    'valid' => $valid,
    'size' => $size,
    'mtime' => $mtime,
    'path' => $valid ? $absolutePath : null,
  ];
}

function backupDatabase(string $sourcePath, string $backupDir): string
{
  if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException("Backupverzeichnis kann nicht erstellt werden: {$backupDir}");
  }
  $backupPath = rtrim($backupDir, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'db.sqlite.bak-dupe-'
    . date('Ymd_His');
  if (file_exists($backupPath)) {
    throw new RuntimeException("Backupziel existiert bereits: {$backupPath}");
  }

  // Den aktiven Snapshot wie backup_daily zuerst lokal erzeugen und prüfen.
  // Ein SQLite-Ziel direkt unter var/state kann auf einem CIFS-Deploy trotz
  // lokaler Live-DB irreführend als "source database is busy" fehlschlagen.
  if (!backupSqliteDatabase($sourcePath, $backupPath, 'duplicate reconciliation DB')) {
    throw new RuntimeException('SQLite-Online-Backup ist fehlgeschlagen.');
  }
  if (!is_file($backupPath) || (filesize($backupPath) ?: 0) <= 0) {
    throw new RuntimeException("Backup wurde nicht verifiziert: {$backupPath}");
  }
  chmod($backupPath, 0640);
  return $backupPath;
}
