-- Pull-Snapshot bleibt die Hauptquelle. Ein Wert größer null erlaubt externe
-- Uploads erst nach entsprechend vielen aufeinanderfolgenden Pull-Fehlern.
ALTER TABLE capture_rules
  ADD COLUMN active_upload_fallback_failures INTEGER NOT NULL DEFAULT 0;
