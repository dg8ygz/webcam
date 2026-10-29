PRAGMA foreign_keys = ON;
PRAGMA journal_mode = DELETE;
PRAGMA busy_timeout = 5000;

-- Cameras / Config (central DB)
CREATE TABLE IF NOT EXISTS cameras (
  id TEXT PRIMARY KEY,
  slug TEXT UNIQUE NOT NULL,
  name TEXT,
  title TEXT,
  short_title TEXT,
  description TEXT,
  mode TEXT,
  info_html TEXT,
  tags TEXT,
  resolutions TEXT,
  created_at INTEGER DEFAULT (strftime('%s','now')),
  updated_at INTEGER DEFAULT (strftime('%s','now'))
);
CREATE TABLE IF NOT EXISTS location (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  latitude REAL,
  longitude REAL,
  elevation REAL,
  orientation_deg REAL,
  field_of_view_deg REAL,
  map_center_offset REAL,
  map_center_offset_x REAL,
  orientation_visualization_km REAL,
  map_height_px REAL,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS capture_rules (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  interval_seconds INTEGER,
  transport TEXT,
  endpoint TEXT,
  timeout_seconds INTEGER,
  expected_response INTEGER,
  extra_times TEXT,
  window_start TEXT,
  window_end TEXT,
  payload TEXT,
  script_path TEXT,
  upload_key TEXT,
  active_upload_fallback_failures INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS storage_cfg (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  base_path TEXT,
  current_dir TEXT,
  archive_dir TEXT,
  best_of_dir TEXT,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS watchdog (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  enabled INTEGER,
  max_consecutive_failures INTEGER,
  cooldown_min_successes INTEGER,
  cooldown_min_seconds INTEGER,
  reset_sequence TEXT,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS stream (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  mode TEXT,
  playlist_url TEXT,
  snapshot_url TEXT,
  fps INTEGER,
  quality INTEGER,
  max_clients INTEGER,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS overlay (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  variant TEXT NOT NULL DEFAULT 'light',
  url TEXT,
  width INTEGER,
  height INTEGER,
  offset_top INTEGER,
  offset_right INTEGER,
  opacity REAL,
  PRIMARY KEY (camera_id, variant)
);
CREATE TABLE IF NOT EXISTS labels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  x REAL NOT NULL,
  y REAL NOT NULL,
  text TEXT NOT NULL,
  href TEXT,
  type INTEGER DEFAULT 0,
  resolution INTEGER DEFAULT 0,
  color TEXT DEFAULT '',
  text_tone TEXT DEFAULT 'auto',
  valid_from TEXT,
  valid_to TEXT,
  created_at INTEGER DEFAULT (strftime('%s','now')),
  updated_at INTEGER DEFAULT (strftime('%s','now'))
);
CREATE TABLE IF NOT EXISTS weather_commands (
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  commands TEXT,
  PRIMARY KEY (camera_id)
);
CREATE TABLE IF NOT EXISTS meta (
  key TEXT PRIMARY KEY,
  value TEXT
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor TEXT,
  action TEXT,
  camera_id TEXT,
  payload TEXT,
  created_at INTEGER DEFAULT (strftime('%s','now'))
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_cameras_slug ON cameras(slug);
CREATE INDEX IF NOT EXISTS idx_capture_endpoint ON capture_rules(endpoint);
CREATE INDEX IF NOT EXISTS idx_labels_camera ON labels(camera_id);
