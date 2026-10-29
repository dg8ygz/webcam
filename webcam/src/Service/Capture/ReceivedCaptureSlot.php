<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use PDO;

/**
 * Prueft bereits angenommene Bilder vor einem automatischen Helper-Auftrag.
 * Ein Pool kann liefern, bevor dieser Server seinen ersten Slot-Poll bekommt.
 * Keine Zeitfenster-Heuristik: nur der exakte Sollslot in der Kamera-DB zaehlt.
 * Fehler werfen statt einen weiteren Kameraauftrag auf Verdacht auszuloesen.
 */
final class ReceivedCaptureSlot
{
  /**
   * $databasePath ist die vom PathResolver bestimmte Kamera-Metadatenbank,
   * $slot die Soll-Epoche des Schedulers (nicht Uploadzeit oder Pi-Request-ID).
   * Fehlende DB: false. Vorhandene unlesbare/defekte DB: PDOException an den
   * Aufrufer; dieser darf den Zustellmarker dann nicht weiterschreiben.
   */
  public static function contains(string $databasePath, int $slot): bool
  {
    // Eine neue Kamera ohne Datenbank benoetigt ihre erste Aufnahme. Niemals
    // durch einen Command-Poll eine leere DB oder ein Schema erzeugen.
    if (!is_file($databasePath)) {
      return false;
    }
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_TIMEOUT => 2,
      PDO::SQLITE_ATTR_OPEN_FLAGS => SQLITE3_OPEN_READONLY,
    ]);
    $query = $pdo->prepare('SELECT 1 FROM captures WHERE slot_ts = :slot AND is_duplicate = 0 LIMIT 1');
    $query->execute([':slot' => $slot]);
    return $query->fetchColumn() !== false;
  }
}
