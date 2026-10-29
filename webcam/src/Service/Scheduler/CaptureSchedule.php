<?php
declare(strict_types=1);
namespace Webcam\Service\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\Domain\Camera;

/**
 * Gemeinsame Zeitregeln fuer Kamera-Abfragen (HTTP) und den CLI-Aufnahmeplaner.
 * Ein Slot ist der geplante Bildzeitpunkt, nicht die spaetere Uploadzeit.
 * Tagesfenster und Zusatzzeiten gelten in der angegebenen lokalen Zeitzone.
 * Diese Klasse entscheidet nur ueber Zeiten; sie loest selbst keine Kamera aus.
 */
final class CaptureSchedule
{
  // Ohne Zeitfenster immer erlaubt; bei ungueltigen Angaben vorsichtshalber
  // sperren. Ein Fenster 20:00–06:00 schliesst Mitternacht mit ein.
  public static function withinWindow(Camera $camera, int $now, string $zone): bool
  {
    $window = $camera->capture()['window'] ?? $camera->capture()['capture_window'] ?? null;
    if ($window === null) { return true; }
    if (!is_array($window) || !self::validTime($window['start'] ?? null) || !self::validTime($window['end'] ?? null)) {
      return false;
    }
    $time = self::local($now, $zone)->format('H:i');
    $start = $window['start']; $end = $window['end'];
    return $end < $start ? ($time >= $start || $time <= $end) : ($time >= $start && $time <= $end);
  }

  // Letzte faellige Zusatzzeit liefern, aber nur bis 90 Sekunden danach.
  // null bedeutet: gerade kein gueltiger Zusatzauftrag.
  public static function extraSlot(Camera $camera, int $now, string $zone): ?int
  {
    $entries = $camera->capture()['extra_times'] ?? [];
    if (is_string($entries)) { $entries = [$entries]; }
    if (!is_array($entries)) { return null; }
    $ref = self::local($now, $zone);
    $latest = null;
    // Eine Zusatzzeit von gestern 23:59 darf kurz nach Mitternacht noch
    // faellig sein. Kalendertage in der lokalen Zeitzone verwenden.
    foreach ([$ref, $ref->modify('-1 day')] as $date) {
      foreach ($entries as $entry) {
        if (!self::validTime($entry)) { continue; }
        [$h, $m] = array_map('intval', explode(':', $entry));
        $due = $date->setTime($h, $m)->getTimestamp();
        if ($due <= $now && $now - $due <= 90) { $latest = max($latest ?? 0, $due); }
      }
    }
    return $latest;
  }

  // Das regulaere Intervall auf den begonnenen Slot abrunden und eine
  // gegebenenfalls neuere Zusatzzeit waehlen. Sowohl jetzt als auch der
  // gewaehlte Slot muessen im erlaubten Aufnahmefenster liegen.
  public static function dueSlot(Camera $camera, int $now, string $zone): ?int
  {
    if (!self::withinWindow($camera, $now, $zone)) { return null; }
    $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
    $slot = intdiv($now, $interval) * $interval;
    $extra = self::extraSlot($camera, $now, $zone);
    $slot = max($slot, $extra ?? 0);
    return self::withinWindow($camera, $slot, $zone) ? $slot : null;
  }

  // Rueckgabe ist die WARTEZEIT IN SEKUNDEN, kein absoluter Zeitstempel.
  // null: Fenster bereits offen oder kein gueltiger Start ermittelbar.
  // Einen Kalendertag weiterschalten statt pauschal 86400 Sekunden addieren,
  // damit ein Tag mit Sommer-/Winterzeitwechsel korrekt behandelt wird.
  public static function nextWindowStart(Camera $camera, int $now, string $zone): ?int
  {
    if (self::withinWindow($camera, $now, $zone)) { return null; }
    $window = $camera->capture()['window'] ?? $camera->capture()['capture_window'] ?? null;
    if (!is_array($window) || !self::validTime($window['start'] ?? null)) { return null; }
    [$h, $m] = array_map('intval', explode(':', $window['start']));
    $start = self::local($now, $zone)->setTime($h, $m);
    if ($start->getTimestamp() <= $now) { $start = $start->modify('+1 day'); }
    return $start->getTimestamp() - $now;
  }

  private static function validTime(mixed $value): bool
  {
    return is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1;
  }
  private static function local(int $now, string $zone): DateTimeImmutable
  {
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($zone));
  }
}
