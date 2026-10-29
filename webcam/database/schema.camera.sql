PRAGMA foreign_keys = ON;
PRAGMA journal_mode = DELETE;
PRAGMA busy_timeout = 5000;

-- Per-camera DB live path: /var/lib/webcam/camera-db/<slug>/db.sqlite
-- Older deployments may still contain stale copies under cache/<slug>/db.sqlite,
-- but that path is no longer the intended live location.
-- Runtime schema is created by Webcam\Infrastructure\Storage\CameraDataStore::schemaSql().
-- capture_logo is part of the regular runtime schema.

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
