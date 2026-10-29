CREATE TABLE IF NOT EXISTS labels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  camera_id TEXT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
  x REAL NOT NULL,
  y REAL NOT NULL,
  text TEXT NOT NULL,
  href TEXT,
  type INTEGER DEFAULT 0,
  resolution INTEGER DEFAULT 0,
  valid_from TEXT,
  valid_to TEXT,
  created_at INTEGER DEFAULT (strftime('%s','now')),
  updated_at INTEGER DEFAULT (strftime('%s','now'))
);
CREATE INDEX IF NOT EXISTS idx_labels_camera ON labels(camera_id);
