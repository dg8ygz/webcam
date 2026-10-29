<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Lokale Uhrzeiten sind beim Zurueckstellen der Uhr nicht eindeutig.
 * Nur die zweite Ausfuehrung einer solchen Uhrzeit erhaelt einen UTC-Slot
 * im Dateinamen. Normale Namen und die vorhandene Bielefeld-Ablage bleiben
 * unveraendert; Cache, Archiv und Leser benutzen dieselbe Zuordnung.
 */
final class CaptureFilename
{
  public static function suffix(DateTimeImmutable $date): string
  {
    $slot = $date->getTimestamp();
    $previous = null;
    foreach ($date->getTimezone()->getTransitions($slot - 172800, $slot + 1) ?: [] as $change) {
      if ($previous !== null && $previous > $change['offset']
        && $slot >= $change['ts'] && $slot < $change['ts'] + $previous - $change['offset']) {
        return '_utc' . $slot;
      }
      $previous = $change['offset'];
    }
    return '';
  }

  public static function cache(DateTimeImmutable $date): string
  {
    return $date->format('Ymd_His') . self::suffix($date);
  }

  public static function archive(DateTimeImmutable $date): string
  {
    return $date->format('Hi') . self::suffix($date);
  }

  /** UTC-Zusatz beim Kuerzen ins HHMM-Archiv unbedingt erhalten. */
  public static function archiveFromCache(string $base): string
  {
    if (!preg_match('/\A\d{8}_(\d{4})\d{2}(_utc[1-9]\d{0,10})?\z/D', $base, $m)) {
      throw new \InvalidArgumentException('Invalid capture filename');
    }
    return $m[1] . ($m[2] ?? '');
  }

  /**
   * Rueckweg fuer Dateisystem-/Sidecar-Leser. Ohne Zusatz bedeutet eine
   * doppelte Uhrzeit die erste Stunde; PHP darf nicht zufaellig die zweite
   * auswaehlen. Ein UTC-Zusatz muss exakt zum lokalen Datum passen.
   */
  public static function date(string $civil, DateTimeZone $timezone, string $epoch = ''): ?DateTimeImmutable
  {
    if ($epoch !== '') {
      if (!preg_match('/\A[1-9]\d{0,10}\z/D', $epoch)) return null;
      $date = (new DateTimeImmutable('@' . $epoch))->setTimezone($timezone);
      return $date->format('YmdHis') === $civil ? $date : null;
    }
    $wall = DateTimeImmutable::createFromFormat('!YmdHis', $civil, new DateTimeZone('UTC'));
    if (!$wall || $wall->format('YmdHis') !== $civil) return null;
    $guess = DateTimeImmutable::createFromFormat('!YmdHis', $civil, $timezone);
    if (!$guess || $guess->format('YmdHis') !== $civil) return null;
    $first = $guess;
    foreach ($timezone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) ?: [] as $change) {
      $candidate = (new DateTimeImmutable('@' . ($wall->getTimestamp() - $change['offset'])))->setTimezone($timezone);
      if ($candidate->format('YmdHis') === $civil && $candidate < $first) $first = $candidate;
    }
    return $first;
  }
}
