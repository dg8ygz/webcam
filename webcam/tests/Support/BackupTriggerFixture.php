<?php
declare(strict_types=1);

use Webcam\App;

/** Nur lokale, isolierte Pfade; kein Bootstrap, keine Live-DB, kein Backup-Job. */
final class BackupTriggerFixture
{
  public static function app(string $root): App
  {
    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $root . '/cameras.php',
      'storage_path' => $root . '/storage',
      'archive_path' => $root . '/archive',
      'state_path' => $root . '/state',
      'log_path' => $root . '/log',
      'info_path' => $root . '/info',
      'cache_path' => $root . '/cache',
      'camera_db_path' => $root . '/camera-db',
    ]);
  }
}
