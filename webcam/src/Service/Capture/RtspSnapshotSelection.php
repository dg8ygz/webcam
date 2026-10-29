<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

/** Wählt ein späteres Videobild, ohne Schärfe mit stärkerem Rauschen zu verwechseln. */
final class RtspSnapshotSelection
{
  public static function filter(int $timeoutSeconds): string
  {
    // Bei Süd steigt die Körnung an jedem I-Bild und sinkt danach wieder.
    // Zwei Sekunden Abstand lassen die gemessene 3,2-s-Bildfolge beruhigen.
    // Kurze Aufnahme-Timeouts behalten Reserven für Verbindung/JPEG-Ausgabe.
    $settle = min(2.0, max(1, $timeoutSeconds) / 4);
    $limit = 2 * $settle;
    $settleText = number_format($settle, 3, '.', '');
    $limitText = number_format($limit, 3, '.', '');

    // FFmpeg wertet select für JEDES decodierte Bild aus (0 = verwerfen).
    // Register 0 merkt die Zeit des ersten bzw. letzten I-Bildes. Ein neues
    // I-Bild setzt die Wartezeit zurück. Nach höchstens vier Videosekunden
    // darf auch eine kurze GOP/All-I-Quelle ein Bild liefern. Ohne Zeitstempel
    // bleibt die bisherige Sofortaufnahme erhalten; keine Endlos-Wartephase.
    // Die äußere Prozessfrist begrenzt auch einen stockenden RTSP-Stream.
    return "select='if(isnan(t),1,"
      . "if(gte(t-start_t,{$limitText}),1,"
      . "if(eq(n,0)+eq(pict_type,I),st(0,t);0,"
      . "gte(t-ld(0),{$settleText}))))'";
  }
}
