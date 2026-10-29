<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;
use PDOException;

/**
 * Verwaltet ausschließlich Aufbau und additive Migration der Kamera-SQLite-DB.
 *
 * Leseführung:
 * 1. `ensure()` erkennt neue beziehungsweise ältere Datenbanken.
 * 2. Kleine Migrationen ergänzen fehlende Spalten und optionale Tabellen.
 * 3. `schemaSql()` bleibt die eine vollständige Referenz für neue Datenbanken
 *    und wird gegen `database/schema.camera.sql` getestet.
 *
 * Fachliche Capture-, Like- oder Wetterabfragen gehören bewusst nicht hierher.
 * Eine beschädigte Datenbank wird ebenfalls nicht still neu aufgebaut.
 */
final class CameraDataSchemaRepository
{
  public const VERSION = 4;

  /** Legt das Schema an oder hebt einen vorhandenen Stand additiv auf VERSION. */
  public function ensure(PDO $pdo): void
  {
    $hasMeta = $this->hasTable($pdo, 'meta');
    if (!$hasMeta) {
      $pdo->exec(self::schemaSql());
      $this->ensureCaptureDuplicateColumn($pdo);
      $this->writeMeta($pdo, 'version', (string) self::VERSION);
      return;
    }

    $current = null;
    try {
      $current = $this->readMeta($pdo, 'version');
    } catch (\Throwable) {
      // Eine unlesbare Meta-Tabelle wird nicht als aktueller Stand behandelt.
    }

    if ($current !== null && (int) $current === self::VERSION) {
      $this->ensureCaptureDuplicateColumn($pdo);
      $this->ensureCaptureLogoTable($pdo);
      return;
    }

    // Bei einer älteren DB existiert `captures` bereits, aber noch ohne
    // `is_duplicate`. Das Gesamtschema enthält schon den neuen Index und darf
    // deshalb erst nach der Spaltenergänzung ausgeführt werden.
    if ($this->hasTable($pdo, 'captures')) {
      $this->ensureCaptureDuplicateColumn($pdo);
    }
    $pdo->exec(self::schemaSql());
    $this->ensureCaptureDuplicateColumn($pdo);
    $this->ensureCaptureLogoTable($pdo);
    $this->writeMeta($pdo, 'version', (string) self::VERSION);
  }

  /** Ergänzt das Duplikat-Flag und seinen häufig verwendeten Timeline-Index. */
  private function ensureCaptureDuplicateColumn(PDO $pdo): void
  {
    if (!$this->hasTable($pdo, 'captures')) {
      return;
    }

    $columns = $pdo->query('PRAGMA table_info(captures)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($columns as $column) {
      if (($column['name'] ?? null) === 'is_duplicate') {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_captures_usable_slot ON captures(is_duplicate, slot_ts)');
        return;
      }
    }

    $pdo->exec('ALTER TABLE captures ADD COLUMN is_duplicate INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_captures_usable_slot ON captures(is_duplicate, slot_ts)');
  }

  /** Die Logo-Tabelle war historisch optional und bleibt additiv nachrüstbar. */
  public function ensureCaptureLogoTable(PDO $pdo): void
  {
    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS capture_logo (
        capture_id INTEGER PRIMARY KEY REFERENCES captures(id) ON DELETE CASCADE,
        detected INTEGER,
        score REAL,
        checked_at INTEGER
      )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_capture_logo_checked_at ON capture_logo(checked_at)');
  }

  public function hasTable(PDO $pdo, string $table): bool
  {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    return $stmt->fetchColumn() !== false;
  }

  public function isMissingCaptureLogoTable(PDOException $exception): bool
  {
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'no such table') && str_contains($message, 'capture_logo');
  }

  private function readMeta(PDO $pdo, string $key): ?string
  {
    $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
  }

  private function writeMeta(PDO $pdo, string $key, string $value): void
  {
    $stmt = $pdo->prepare('REPLACE INTO meta (key, value) VALUES (:key, :value)');
    $stmt->execute([':key' => $key, ':value' => $value]);
  }

  /** Vollständiges Sollschema für eine neue Kamera-Datenbank. */
  public static function schemaSql(): string
  {
    return <<<SQL
CREATE TABLE IF NOT EXISTS captures (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slot_ts INTEGER NOT NULL,
  captured_ts INTEGER,
  relative_path TEXT NOT NULL,
  luminance REAL,
  hash_sha256 TEXT,
  is_duplicate INTEGER NOT NULL DEFAULT 0,
  likes_count INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL,
  UNIQUE(slot_ts)
);

CREATE TABLE IF NOT EXISTS variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capture_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
  variant TEXT NOT NULL,
  width INTEGER,
  height INTEGER,
  relative_path TEXT NOT NULL,
  size_bytes INTEGER,
  mtime INTEGER,
  UNIQUE(capture_id, variant)
);

CREATE TABLE IF NOT EXISTS exif_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capture_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
  tag TEXT NOT NULL,
  value TEXT NOT NULL,
  sort_order INTEGER DEFAULT 0,
  UNIQUE(capture_id, tag)
);

CREATE TABLE IF NOT EXISTS weather_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capture_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
  key TEXT NOT NULL,
  value TEXT NOT NULL,
  position INTEGER DEFAULT 0,
  UNIQUE(capture_id, key, position)
);

CREATE TABLE IF NOT EXISTS likes_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  capture_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
  camera_slug TEXT NOT NULL,
  session_id TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  hour_bucket INTEGER NOT NULL,
  UNIQUE(session_id, capture_id, hour_bucket)
);

CREATE TABLE IF NOT EXISTS capture_logo (
  capture_id INTEGER PRIMARY KEY REFERENCES captures(id) ON DELETE CASCADE,
  detected INTEGER,
  score REAL,
  checked_at INTEGER
);

CREATE TABLE IF NOT EXISTS meta (
  key TEXT PRIMARY KEY,
  value TEXT
);

CREATE INDEX IF NOT EXISTS idx_captures_slot_ts ON captures(slot_ts);
CREATE INDEX IF NOT EXISTS idx_captures_relative_path ON captures(relative_path);
CREATE INDEX IF NOT EXISTS idx_captures_usable_slot ON captures(is_duplicate, slot_ts);
CREATE INDEX IF NOT EXISTS idx_variants_capture ON variants(capture_id);
CREATE INDEX IF NOT EXISTS idx_variants_relative_path ON variants(relative_path);
CREATE INDEX IF NOT EXISTS idx_exif_capture ON exif_entries(capture_id);
CREATE INDEX IF NOT EXISTS idx_weather_capture ON weather_entries(capture_id);
CREATE INDEX IF NOT EXISTS idx_likes_session ON likes_log(session_id, created_at);
CREATE INDEX IF NOT EXISTS idx_capture_logo_checked_at ON capture_logo(checked_at);
SQL;
  }
}
