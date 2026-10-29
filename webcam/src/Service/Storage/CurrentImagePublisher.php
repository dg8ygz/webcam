<?php
declare(strict_types=1);
namespace Webcam\Service\Storage;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Schuetzt die aktuellen Bild-URLs (current) vor verspaeteten alten Uploads.
 * Historische Bilder duerfen nachgeliefert werden, aber das aktuelle Bild
 * soll dadurch nicht zurueckspringen. Je Kamera darf nur ein Schreiber
 * gleichzeitig pruefen und veroeffentlichen; dafuer dient die Dateisperre.
 * Ein abgebrochener Upload darf denselben Slot nochmals vervollstaendigen.
 */
final class CurrentImagePublisher
{
  public function __construct(private readonly App $app) {}

  /**
   * Fuehrt $write nur aus, wenn $slot mindestens so neu ist wie der Merker
   * und der neueste regulaere Datenbankeintrag. true: Schreiben abgeschlossen;
   * false: aelterer Slot, $write nicht aufgerufen. Speicherfehler werfen eine
   * Ausnahme. Die Sperre macht aus mehreren Bilddateien keine Transaktion:
   * Ein Teilfehler kann eine Wiederholung desselben Slots erfordern.
   */
  public function publish(Camera $camera, int $slot, callable $write): bool
  {
    $dir = $this->app->paths()->statePath('current-publication');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('Current publication state unavailable');
    }
    $base = $dir . '/' . $camera->slug();
    $lock = @fopen($base . '.lock', 'c');
    if ($lock === false) { throw new RuntimeException('Current publication lock unavailable'); }
    try {
      if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Current publication lock failed'); }
      $latest = 0;
      if (is_file($base . '.slot')) {
        $value = @file_get_contents($base . '.slot');
        if (!is_string($value) || !ctype_digit(trim($value))) {
          throw new RuntimeException('Invalid current publication watermark');
        }
        $latest = (int) trim($value);
      }
      // Bei aelteren Installationen fehlt der Slot-Merker noch. Daher auch
      // den neuesten regulaeren Datenbankeintrag als Untergrenze verwenden.
      $db = (new CameraDataStore($this->app, $camera))->pdo();
      $latest = max($latest, (int) $db->query('SELECT MAX(slot_ts) FROM captures WHERE is_duplicate = 0')->fetchColumn());
      if ($slot < $latest) { return false; }
      // Den neuen Slot VOR dem Schreiben reservieren: Schlaegt eine der
      // Bildkopien fehl, darf kein aelterer Upload die schon neuen Dateien
      // wieder ersetzen. Ein erneuter Upload desselben Slots bleibt erlaubt.
      if (!AtomicFileWriter::write($base . '.slot', (string) $slot)) {
        throw new RuntimeException('Current publication watermark write failed');
      }
      $write();
      return true;
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }
}
