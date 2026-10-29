<?php
declare(strict_types=1);

namespace Webcam\Service\Backup;

use PDO;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Service\Storage\DeferredImageSchema;
use Webcam\Service\Storage\DeferredImages;

/**
 * Ergaenzt einen fertigen Kamera-DB-Snapshot um genau dessen private Originale.
 * Die Quelle ist unveraenderlich. Neue Uploads waehrenddessen gehoeren erst zur
 * naechsten Sicherung. Erfolgsmarker erst nach allen Kopien, niemals raw im Webroot.
 */
final class DeferredImageBackup
{
  public function __construct(private readonly App $app) {}

  public function backup(Camera $camera, string $snapshot, string $timestamp): int
  {
    if (!$this->app->config('deferred_image_root')) return 0;
    $db = new PDO('sqlite:file:' . $snapshot . '?mode=ro');
    if (!DeferredImageSchema::exists($db)) return 0;
    if ((int) $db->query('SELECT count(*) FROM deferred_images')->fetchColumn() === 0) return 0;
    $rows = $db->query('SELECT * FROM deferred_images ORDER BY slot_ts');
    if (!preg_match('/\A[0-9]{8}_[0-9]{6}\z/D', $timestamp)) throw new RuntimeException('Invalid backup timestamp');
    $real = $this->app->deferredImages()->backupRoot();
    $dir = $real;
    foreach ([$camera->slug(), 'sources'] as $part) {
      $dir .= '/' . $part;
      if (is_link($dir)) throw new RuntimeException('Symlink in backup destination');
      if (!is_dir($dir) && !mkdir($dir, 0700)) throw new RuntimeException('Cannot create backup directory');
    }
    $count = 0;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
      $this->app->deferredImages()->decodeRecipe($row);
      $source = $this->app->deferredImages()->sourcePath($camera, $row['source_hash'], (int) $row['slot_ts']);
      $target = $dir . '/' . $row['source_hash'] . '.jpg';
      if (is_link($source) || is_link($target)) throw new RuntimeException('Symlink source/backup rejected');
      if (!is_file($target)) {
        if (!AtomicFileWriter::copy($source, $target, 0600)
          || !hash_equals($row['source_hash'], (string) hash_file('sha256', $target))) throw new RuntimeException('Original backup failed');
      }
      // Bestehende unveraenderliche Blobkopien nur auf Laenge pruefen, damit das
      // Tagesbackup nicht jeden Tag den gesamten Rohbestand erneut lesen muss.
      if ((int) filesize($target) !== (int) $row['source_bytes']) throw new RuntimeException('Backup original size changed');
      $count++;
    }
    $rows->closeCursor(); $db = null;
    $snapshotPath = dirname($dir) . '/db-' . $timestamp . '.sqlite';
    if (is_link($snapshotPath) || !AtomicFileWriter::copy($snapshot, $snapshotPath, 0600)) throw new RuntimeException('Recipe backup failed');
    $marker = json_encode(['snapshot' => basename($snapshotPath), 'sha256' => hash_file('sha256', $snapshotPath), 'sources' => $count], JSON_THROW_ON_ERROR);
    if (!AtomicFileWriter::write($snapshotPath . '.complete.json', $marker, null, 0600)) throw new RuntimeException('Backup completion marker failed');
    return $count;
  }
}
