<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use PDO;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Liefert ausschließlich Wetterdaten, die auf der Kameraseite sicher als
 * Messwert dargestellt werden können.
 *
 * Der Dienst bereinigt Textausgaben und prüft read-only die Qualitätsmarker
 * der Kamera-Datenbank. Er schreibt weder Messwerte noch Marker zurück.
 */
final class CameraWeatherMetadata
{
  public function __construct(private readonly App $app) {}

  /**
   * Entfernt Leerraum und verwirft typische curl-/Timeout-Ausgaben. Solche
   * Kommandoausgaben dürfen nicht wie echte Temperatur- oder Wetterwerte im
   * öffentlichen Modal erscheinen.
   */
  public function normalizeText(?string $weatherData): ?string
  {
    if ($weatherData === null || $this->looksLikeCommandError($weatherData)) {
      return null;
    }

    $lines = array_values(array_filter(
      array_map(
        static fn(string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)),
        preg_split('/\R/', $weatherData) ?: []
      ),
      static fn(string $line): bool => $line !== ''
    ));
    if ($lines === []) {
      return null;
    }

    $normalized = implode("\n", $lines);
    // Only compact our sensor format; external weather scripts keep their layout.
    if (preg_match('/^Source:\s*local DHT22\s*$/mi', $normalized)
      && preg_match('/^Temp:\s*(-?\d+(?:[.,]\d+)?)\s*C\s*$/mi', $normalized, $temp)
      && preg_match('/^Humidity:\s*(\d+(?:[.,]\d+)?)\s*%\s*$/mi', $normalized, $hum)) {
      return 'Temp: ' . str_replace('.', ',', $temp[1]) . ' °C · Hum: '
        . str_replace('.', ',', $hum[1]) . ' %';
    }
    return $this->looksLikeCommandError($normalized) ? null : $normalized;
  }

  /**
   * Prüft, ob der Slot direkt markiert ist oder innerhalb einer aktuell
   * laufenden Fehlerstrecke ab dem ersten frozen_value/out_of_range-Slot liegt.
   */
  public function slotHasQualityFlag(Camera $camera, ?int $slotTimestamp): bool
  {
    if ($slotTimestamp === null || $slotTimestamp <= 0) {
      return false;
    }

    $dbPath = rtrim(
      $this->app->paths()->cameraDbPath($camera->slug()),
      DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR . 'db.sqlite';
    if (!is_file($dbPath)) {
      return false;
    }

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      ]);
      $pdo->exec('PRAGMA query_only = ON');
      $pdo->exec('PRAGMA busy_timeout = 1000');
      if (!$this->hasQualityTable($pdo)) {
        return false;
      }

      $direct = $pdo->prepare(
        'SELECT 1
           FROM captures c
           JOIN weather_quality_flags q ON q.capture_id = c.id
          WHERE c.slot_ts = :slot_ts
          LIMIT 1'
      );
      $direct->execute([':slot_ts' => $slotTimestamp]);
      if ($direct->fetchColumn() !== false) {
        return true;
      }

      $active = $pdo->prepare(
        "SELECT MIN(c.slot_ts)
           FROM captures c
           JOIN weather_quality_flags q ON q.capture_id = c.id
          WHERE c.slot_ts >= :from_ts
            AND q.flag IN ('frozen_value', 'out_of_range')"
      );
      $active->execute([':from_ts' => time() - 86400]);
      $activeFrom = $active->fetchColumn();
      if ($activeFrom === false || $activeFrom === null) {
        return false;
      }

      return (int) $activeFrom > 0 && $slotTimestamp >= (int) $activeFrom;
    } catch (Throwable) {
      // Die öffentliche Seite bleibt auch bei einer kurz gesperrten Alt-DB
      // erreichbar. Ohne belastbare Qualitätsaussage greift der bisherige
      // Fallback; DB-Details werden nicht an Besucher ausgegeben.
      return false;
    }
  }

  /** Erkennt die Tabelle ohne eine Migration oder Schreibverbindung auszulösen. */
  private function hasQualityTable(PDO $pdo): bool
  {
    $table = $pdo->query(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'weather_quality_flags' LIMIT 1"
    );
    return $table !== false && $table->fetchColumn() !== false;
  }

  /** Erkennt bekannte Fehlertexte der externen Wetterkommandos. */
  private function looksLikeCommandError(string $text): bool
  {
    return \Webcam\Service\Weather\WeatherMetrics::looksLikeError($text);
  }
}
