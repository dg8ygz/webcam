<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;
use PDOException;

/**
 * Kapselt Wetterzeilen, Wetter-Qualitätsfilter und Logo-Erkennungsstatus.
 *
 * Leseführung:
 * 1. Wetter kann innerhalb einer laufenden Capture-Transaktion vollständig
 *    ersetzt oder nachträglich für einen vorhandenen Slot aktualisiert werden.
 * 2. `weatherRowsForCaptures()` berücksichtigt die optionale Qualitätsprüfung.
 * 3. Logo-Lese- und Schreibzugriffe behandeln ältere DBs ohne Logo-Tabelle
 *    ausdrücklich rückwärtskompatibel.
 */
final class CameraWeatherLogoRepository
{
  public function __construct(
    private readonly CameraDataConnection $connection,
    private readonly CameraDataSchemaRepository $schema
  ) {}

  /**
   * Ersetzt Wetterzeilen innerhalb der bereits vom Aufrufer gehaltenen
   * Transaktion. Diese Methode führt absichtlich kein eigenes Commit aus.
   *
   * @param list<string> $lines
   */
  public function replaceWeather(PDO $pdo, int $captureId, array $lines): void
  {
    $pdo->prepare('DELETE FROM weather_entries WHERE capture_id = :id')->execute([':id' => $captureId]);
    if ($lines === []) {
      $this->refreshMeasurements($pdo, $captureId, $lines);
      return;
    }

    $statement = $pdo->prepare(
      'INSERT INTO weather_entries (capture_id, key, value, position)
       VALUES (:capture_id, :key, :value, :position)'
    );
    foreach (array_values($lines) as $position => $line) {
      $statement->execute([
        ':capture_id' => $captureId,
        ':key' => 'line',
        ':value' => (string) $line,
        ':position' => $position,
      ]);
    }
    $this->refreshMeasurements($pdo, $captureId, $lines);
  }

  /** The optional projection is installed/backfilled only by the explicit CLI. */
  private function refreshMeasurements(PDO $pdo, int $captureId, array $lines): void
  {
    $metrics = new CameraWeatherMetricsRepository($pdo);
    if ($metrics->installed()) {
      $metrics->replace($captureId, $lines);
    }
  }

  /**
   * Aktualisiert nur einen existierenden Capture-Slot; fehlende Slots werden
   * nicht als künstliche Capture-Zeilen angelegt.
   *
   * @param list<string> $lines
   */
  public function upsertWeatherForSlot(int $slotTimestamp, array $lines): bool
  {
    return $this->connection->write(
      fn(CameraDataConnection $connection): bool => $this->upsertWeatherDirect(
        $connection,
        $slotTimestamp,
        $lines
      ),
      'upsert_weather'
    );
  }

  /** @param list<string> $lines */
  private function upsertWeatherDirect(
    CameraDataConnection $connection,
    int $slotTimestamp,
    array $lines
  ): bool {
    $pdo = $connection->pdo();
    if ($connection->isReadOnly()) {
      return false;
    }

    $statement = $pdo->prepare('SELECT id FROM captures WHERE slot_ts = :slot LIMIT 1');
    $statement->execute([':slot' => $slotTimestamp]);
    $captureId = $statement->fetchColumn();
    if ($captureId === false) {
      return false;
    }

    $connection->beginWriteTransaction($pdo);
    try {
      $this->replaceWeather($pdo, (int) $captureId, $lines);
      $pdo->commit();
      return true;
    } catch (\Throwable $exception) {
      $connection->rollBackQuietly($pdo);
      throw $exception;
    }
  }

  /**
   * Lädt Wetterzeilen für einen begrenzten Capture-ID-Block und blendet aktive
   * Qualitätsfehler nach denselben Regeln wie zuvor aus.
   *
   * @param list<int> $captureIds
   * @return array<int,list<array<string,mixed>>>
   */
  public function weatherRowsForCaptures(PDO $pdo, array $captureIds): array
  {
    if ($captureIds === []) {
      return [];
    }

    $qualityFilter = '';
    if ($this->schema->hasTable($pdo, 'weather_quality_flags')) {
      $qualityFilter = ' AND NOT EXISTS (
        SELECT 1 FROM weather_quality_flags q WHERE q.capture_id = w.capture_id
      )';
      $activeFrom = $this->activeWeatherQualityFrom($pdo);
      if ($activeFrom !== null) {
        $qualityFilter .= ' AND (
          SELECT quality_capture.slot_ts
          FROM captures quality_capture
          WHERE quality_capture.id = w.capture_id
        ) < ' . $activeFrom;
      }
    }

    $placeholders = implode(',', array_fill(0, count($captureIds), '?'));
    $statement = $pdo->prepare(
      "SELECT * FROM weather_entries w
       WHERE w.capture_id IN ({$placeholders}){$qualityFilter}
       ORDER BY w.position ASC"
    );
    $statement->execute($captureIds);

    $weather = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $weather[(int) $row['capture_id']][] = $row;
    }
    return $weather;
  }

  private function activeWeatherQualityFrom(PDO $pdo): ?int
  {
    $statement = $pdo->prepare(
      "SELECT MIN(c.slot_ts)
         FROM captures c
         JOIN weather_quality_flags q ON q.capture_id = c.id
        WHERE c.slot_ts >= :from_ts
          AND q.flag IN ('frozen_value', 'out_of_range')"
    );
    $statement->execute([':from_ts' => time() - 86400]);
    $value = $statement->fetchColumn();
    $timestamp = ($value === false || $value === null) ? 0 : (int) $value;
    return $timestamp > 0 ? $timestamp : null;
  }

  // -----------------------------------------------------------------------
  // Logo-Erkennungsstatus.
  // -----------------------------------------------------------------------

  /** @return array{detected:bool,score:?float}|null */
  public function logoStatusById(int $captureId): ?array
  {
    return $this->connection->read(function (PDO $pdo) use ($captureId): ?array {
      try {
        $statement = $pdo->prepare(
          'SELECT detected, score FROM capture_logo WHERE capture_id = :id'
        );
        $statement->execute([':id' => $captureId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
          return null;
        }
        return [
          'detected' => (int) ($row['detected'] ?? 0) === 1,
          'score' => isset($row['score']) ? (float) $row['score'] : null,
        ];
      } catch (PDOException $exception) {
        if ($this->schema->isMissingCaptureLogoTable($exception)) {
          return null;
        }
        throw $exception;
      }
    });
  }

  public function upsertLogoStatus(
    int $captureId,
    bool $detected,
    ?float $score,
    ?int $checkedAt = null
  ): bool {
    return $this->connection->write(
      fn(CameraDataConnection $connection): bool => $this->upsertLogoDirect(
        $connection,
        $captureId,
        $detected,
        $score,
        $checkedAt
      ),
      'upsert_logo_status'
    );
  }

  private function upsertLogoDirect(
    CameraDataConnection $connection,
    int $captureId,
    bool $detected,
    ?float $score,
    ?int $checkedAt
  ): bool {
    $pdo = $connection->pdo();
    if ($connection->isReadOnly()) {
      return false;
    }

    $connection->beginWriteTransaction($pdo);
    try {
      $this->schema->ensureCaptureLogoTable($pdo);
      $statement = $pdo->prepare(
        'INSERT INTO capture_logo (capture_id, detected, score, checked_at)
         VALUES (:capture_id, :detected, :score, :checked_at)
         ON CONFLICT(capture_id) DO UPDATE SET
           detected = excluded.detected,
           score = excluded.score,
           checked_at = excluded.checked_at'
      );
      $statement->execute([
        ':capture_id' => $captureId,
        ':detected' => $detected ? 1 : 0,
        ':score' => $score,
        ':checked_at' => $checkedAt ?? time(),
      ]);
      $pdo->commit();
      return true;
    } catch (\Throwable $exception) {
      $connection->rollBackQuietly($pdo);
      throw $exception;
    }
  }
}
