<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use PDO;

/** Additives Pilotschema. Nur das ausdrueckliche CLI-Migrationswerkzeug ruft install auf. */
final class DeferredImageSchema
{
  public const SQL = 'CREATE TABLE IF NOT EXISTS deferred_images (
    image_key TEXT PRIMARY KEY,
    slot_ts INTEGER NOT NULL UNIQUE,
    source_hash TEXT NOT NULL,
    source_bytes INTEGER NOT NULL,
    recipe_json TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    created_at INTEGER NOT NULL
  )';

  public static function exists(PDO $db): bool
  {
    return $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='deferred_images'")->fetchColumn() !== false;
  }

  public static function install(PDO $db): void
  {
    $db->exec(self::SQL);
  }
}
