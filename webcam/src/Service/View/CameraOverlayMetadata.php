<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Webcam\Domain\Camera;

/**
 * Bereitet zeitabhängige Angaben für das Bild-Overlay auf.
 *
 * Die öffentliche Kameraseite muss für jedes Bild zwei Fragen beantworten:
 * Ist die Aufnahme nach den örtlichen Sonnenzeiten eine Nachtaufnahme, und
 * welche frei konfigurierbaren Beschriftungen gelten zu diesem Zeitpunkt?
 * Diese Regeln benötigen weder HTTP-Daten noch Rendering und liegen deshalb
 * getrennt vom Ablauf in camera.php.
 */
final class CameraOverlayMetadata
{
  /**
   * Ordnet eine Aufnahme anhand von Position, Sonnenauf/-untergang und dem
   * Aufnahmeintervall als Tag oder Nacht ein. Der Vorlauf berücksichtigt, dass
   * eine lange Belichtung bereits vor dem eigentlichen Sollzeitpunkt beginnt.
   */
  public function isNight(Camera $camera, int $timestamp, string $timezone): bool
  {
    $location = $camera->location();
    $latitude = isset($location['latitude']) ? (float) $location['latitude'] : 47.5;
    $longitude = isset($location['longitude']) ? (float) $location['longitude'] : 11.5;
    $interval = (int) ($camera->capture()['interval_seconds'] ?? 600);

    $tz = new DateTimeZone($timezone);
    $localDate = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d');
    $reference = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i:s',
      $localDate . ' 12:00:00',
      new DateTimeZone('UTC')
    );
    $referenceTimestamp = $reference instanceof DateTimeImmutable
      ? $reference->getTimestamp()
      : $timestamp;
    if (!$reference instanceof DateTimeImmutable) {
      error_log('CameraOverlayMetadata: date_sun_info reference timestamp fallback');
    }

    $sunInfo = date_sun_info($referenceTimestamp, $latitude, $longitude);
    $sunset = $this->validSunTimestamp($sunInfo['sunset'] ?? null, 'sunset');
    if ($sunset !== null && $timestamp > ($sunset - 1800)) {
      return true;
    }

    $sunrise = $this->validSunTimestamp($sunInfo['sunrise'] ?? null, 'sunrise');
    return $sunrise !== null && $timestamp < ($sunrise - $interval);
  }

  /**
   * Filtert die administrativ gepflegten Bildbeschriftungen für genau einen
   * Aufnahmezeitpunkt. Ungültige Einträge werden ausgelassen, statt später im
   * Template unvollständige Overlay-Elemente zu erzeugen.
   *
   * @param list<array<string,mixed>> $labels
   * @return list<array<string,mixed>>
   */
  public function labelsForTimestamp(array $labels, int $referenceTimestamp, DateTimeZone $timezone): array
  {
    $filtered = [];
    foreach ($labels as $label) {
      if (!is_array($label)) {
        continue;
      }

      // "txt" ist der Name aus älteren Konfigurationen; "text" ist der
      // aktuelle Name. Beide bleiben lesbar, bis alle Altbestände migriert sind.
      $text = isset($label['text']) ? trim((string) $label['text']) : '';
      if ($text === '' && isset($label['txt'])) {
        $text = trim((string) $label['txt']);
      }
      if ($text === '' || !is_numeric($label['x'] ?? null) || !is_numeric($label['y'] ?? null)) {
        continue;
      }

      $validFrom = $this->parseLabelDatetime($label['valid_from'] ?? null, $timezone);
      if ($validFrom !== null && $referenceTimestamp < $validFrom) {
        continue;
      }

      // Ein reines valid_to-Datum meint einschließlich dieses Tages. Ohne
      // Tagesende würde die Beschriftung bereits um 00:00 Uhr verschwinden.
      $validTo = $this->parseLabelDatetime($label['valid_to'] ?? null, $timezone, true);
      if ($validTo !== null && $referenceTimestamp > $validTo) {
        continue;
      }

      $filtered[] = $label;
    }

    return $filtered;
  }

  /** Konvertiert Unixzeit sowie gebräuchliche Datumstexte in Unixzeit. */
  private function parseLabelDatetime(mixed $value, DateTimeZone $timezone, bool $endOfDay = false): ?int
  {
    if ($value === null) {
      return null;
    }
    if (is_int($value)) {
      return $value > 0 ? $value : null;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
      return null;
    }
    if (is_numeric($raw)) {
      $timestamp = (int) $raw;
      return $timestamp > 0 ? $timestamp : null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
      if ($date instanceof DateTimeImmutable) {
        return ($endOfDay ? $date->setTime(23, 59, 59) : $date)->getTimestamp();
      }
    }

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
      $date = DateTimeImmutable::createFromFormat('!' . $format, $raw, $timezone);
      if ($date instanceof DateTimeImmutable) {
        return $date->getTimestamp();
      }
    }

    try {
      return (new DateTimeImmutable($raw, $timezone))->getTimestamp();
    } catch (Exception) {
      return null;
    }
  }

  /** Verwirft die booleschen Fehlerwerte, die date_sun_info liefern kann. */
  private function validSunTimestamp(mixed $value, string $field): ?int
  {
    if (is_int($value) || is_float($value)) {
      return (int) $value;
    }

    error_log('CameraOverlayMetadata: date_sun_info ' . $field . ' missing');
    return null;
  }
}
