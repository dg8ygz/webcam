<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;

/**
 * Gemeinsame Groessenlimits fuer abgerufene und hochgeladene Bilder.
 * Eine kleine komprimierte JPEG-Datei kann beim Einlesen sehr viel RAM
 * benoetigen. Deshalb sowohl Dateigroesse als auch Breite/Hoehe/Pixelzahl
 * begrenzen, bevor die Bildbibliothek GD den gesamten Bildinhalt entpackt.
 */
final class SnapshotResourceLimits
{
  public const MAX_BYTES = 64 * 1024 * 1024;
  public const MAX_DIMENSION = 8192;
  public const MAX_PIXELS = 40000000;

  /**
   * Die Umgebungsvariable hat Vorrang vor der Anwendungskonfiguration.
   * Keine Einstellung darf die feste globale Obergrenze ueberschreiten.
   */
  public static function maxBytes(App $app): int
  {
    $value = getenv('WEBCAM_UPLOAD_MAX_BYTES');
    if ($value === false || trim($value) === '') {
      $value = $app->config('upload_max_bytes', 25 * 1024 * 1024);
    }
    $bytes = is_numeric($value) ? (int) $value : 0;
    return min($bytes > 0 ? $bytes : 25 * 1024 * 1024, self::MAX_BYTES);
  }

  /** Zu grosse Dateien auf allen Eingangswegen mit demselben Fehlertyp ablehnen. */
  public static function checkBytes(int $bytes, App $app): void
  {
    if ($bytes > self::maxBytes($app)) {
      throw new SnapshotResourceLimitException('Snapshot exceeds byte limit');
    }
  }

  /**
   * Abmessungen aus dem Bildkopf VOR dem vollstaendigen Einlesen pruefen.
   * Das ist eine Groessenpruefung, noch kein Beleg fuer ein intaktes JPEG.
   */
  public static function checkDimensions(array|false $info): void
  {
    if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
      throw new InvalidSnapshotException('Snapshot payload is not a supported image');
    }
    [$width, $height] = $info;
    if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
      || $width > intdiv(self::MAX_PIXELS, $height)) {
      throw new SnapshotResourceLimitException('Snapshot exceeds dimension or pixel limit');
    }
  }
}
