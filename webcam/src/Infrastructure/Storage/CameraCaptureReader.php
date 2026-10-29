<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;

/**
 * Liest Capture-Pfade, Kalenderbereiche und Timeline-Zeilen.
 *
 * Diese Klasse verändert die Kamera-Datenbank nicht. Große Bereiche werden
 * weiterhin in 500er-Blöcken hydriert, damit SQLite-Parameterlimit und
 * PHP-Speicher kontrolliert bleiben.
 */
final class CameraCaptureReader
{
  public function __construct(
    private readonly CameraDataConnection $connection,
    private readonly CameraWeatherLogoRepository $weatherLogo
  ) {}

  // -----------------------------------------------------------------------
  // Kleine Capture- und Pfad-Lookups.
  // -----------------------------------------------------------------------

  public function findCaptureIdByRelativePath(string $relativePath): ?int
  {
    return $this->connection->read(function (PDO $pdo) use ($relativePath): ?int {
      $statement = $pdo->prepare(
        'SELECT id FROM captures
         WHERE relative_path = :relative_path AND is_duplicate = 0
         LIMIT 1'
      );
      $statement->execute([':relative_path' => $relativePath]);
      $id = $statement->fetchColumn();
      return $id === false ? null : (int) $id;
    });
  }

  /** Prüft Capturepfad, Variantenpfad und bekannte Variant-Suffixe. */
  public function findCaptureIdByAnyPath(string $relativePath): ?int
  {
    return $this->connection->read(function (PDO $pdo) use ($relativePath): ?int {
      $captureId = $this->captureIdForExactPath($pdo, $relativePath);
      if ($captureId !== null) {
        return $captureId;
      }

      if (!preg_match('/_(la|lm|full|sm|hu)(\.jpe?g)$/i', $relativePath, $match)) {
        return null;
      }
      $basePath = substr($relativePath, 0, -strlen($match[0])) . $match[2];
      return $this->captureIdForExactPath($pdo, $basePath);
    });
  }

  private function captureIdForExactPath(PDO $pdo, string $relativePath): ?int
  {
    $capture = $pdo->prepare(
      'SELECT id FROM captures
       WHERE relative_path = :relative_path AND is_duplicate = 0
       LIMIT 1'
    );
    $capture->execute([':relative_path' => $relativePath]);
    $id = $capture->fetchColumn();
    if ($id !== false) {
      return (int) $id;
    }

    $variant = $pdo->prepare(
      'SELECT v.capture_id
       FROM variants v
       JOIN captures c ON c.id = v.capture_id
       WHERE v.relative_path = :relative_path AND c.is_duplicate = 0
       LIMIT 1'
    );
    $variant->execute([':relative_path' => $relativePath]);
    $id = $variant->fetchColumn();
    return $id === false ? null : (int) $id;
  }

  public function relativePathByCaptureId(int $captureId): ?string
  {
    return $this->connection->read(function (PDO $pdo) use ($captureId): ?string {
      $statement = $pdo->prepare('SELECT relative_path FROM captures WHERE id = :id');
      $statement->execute([':id' => $captureId]);
      $relativePath = $statement->fetchColumn();
      return $relativePath === false ? null : (string) $relativePath;
    });
  }

  public function findCaptureIdBySlot(int $slotTimestamp): ?int
  {
    return $this->connection->read(function (PDO $pdo) use ($slotTimestamp): ?int {
      $statement = $pdo->prepare('SELECT id FROM captures WHERE slot_ts = :slot LIMIT 1');
      $statement->execute([':slot' => $slotTimestamp]);
      $id = $statement->fetchColumn();
      return $id === false ? null : (int) $id;
    });
  }

  /** Vereinheitlicht Slash-Richtung und entfernt einen führenden Slash. */
  public function normalizeRelativePath(string $path): string
  {
    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
    return ltrim($normalized, '/');
  }

  // -----------------------------------------------------------------------
  // Kalender- und Bereichsabfragen.
  // -----------------------------------------------------------------------

  /** @return list<int> */
  public function years(): array
  {
    return $this->connection->read(function (PDO $pdo): array {
      $minimum = $pdo->query(
        'SELECT slot_ts FROM captures WHERE is_duplicate = 0 ORDER BY slot_ts ASC LIMIT 1'
      );
      $maximum = $pdo->query(
        'SELECT slot_ts FROM captures WHERE is_duplicate = 0 ORDER BY slot_ts DESC LIMIT 1'
      );
      $minimumValue = $minimum ? $minimum->fetchColumn() : null;
      $maximumValue = $maximum ? $maximum->fetchColumn() : null;
      if ($minimumValue === null || $minimumValue === false
        || $maximumValue === null || $maximumValue === false) {
        return [];
      }

      $minimumTimestamp = (int) $minimumValue;
      $maximumTimestamp = (int) $maximumValue;
      if ($minimumTimestamp <= 0 || $maximumTimestamp < $minimumTimestamp) {
        return [];
      }

      $timezone = $this->connection->appTimezone();
      $minimumYear = (int) (new \DateTimeImmutable('@' . $minimumTimestamp))
        ->setTimezone($timezone)
        ->format('Y');
      $maximumYear = (int) (new \DateTimeImmutable('@' . $maximumTimestamp))
        ->setTimezone($timezone)
        ->format('Y');

      $exists = $pdo->prepare(
        'SELECT 1 FROM captures
         WHERE slot_ts BETWEEN :start_timestamp AND :end_timestamp
           AND is_duplicate = 0
         LIMIT 1'
      );
      $years = [];
      for ($year = $maximumYear; $year >= $minimumYear; $year--) {
        $start = (new \DateTimeImmutable("{$year}-01-01 00:00:00", $timezone))->getTimestamp();
        $end = (new \DateTimeImmutable("{$year}-12-31 23:59:59", $timezone))->getTimestamp();
        $exists->execute([
          ':start_timestamp' => $start,
          ':end_timestamp' => $end,
        ]);
        if ($exists->fetchColumn() !== false) {
          $years[] = $year;
        }
      }
      return $years;
    });
  }

  /**
   * Sample the entire indexed range before loading variants. Integer rank
   * buckets include both endpoints and return at most $limit captures. EXIF
   * and weather are deliberately not loaded for the image-only timelapse API.
   * @return list<array<string,mixed>>
   */
  public function sampledCapturesForRange(int $from, int $to, int $limit): array
  {
    $limit = max(2, min(600, $limit));
    return $this->connection->read(function (PDO $pdo) use ($from, $to, $limit): array {
      $statement = $pdo->prepare('WITH ranked AS (
        SELECT id, ROW_NUMBER() OVER (ORDER BY slot_ts, id) - 1 AS ordinal,
          COUNT(*) OVER () AS total
        FROM captures WHERE slot_ts BETWEEN :start AND :end AND is_duplicate = 0
      ) SELECT c.* FROM ranked r JOIN captures c ON c.id = r.id
        WHERE total <= :limit OR ordinal = 0
          OR (ordinal * (:limit - 1)) / (total - 1)
            > ((ordinal - 1) * (:limit - 1)) / (total - 1)
        ORDER BY c.slot_ts, c.id');
      $statement->bindValue(':start', $from, PDO::PARAM_INT);
      $statement->bindValue(':end', $to, PDO::PARAM_INT);
      $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
      $statement->execute();
      $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $variants = [];
      foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $variants += $this->rowsGroupedByCapture($pdo,
          "SELECT * FROM variants WHERE capture_id IN ($marks)", $ids);
      }
      foreach ($rows as &$row) {
        $row['variants_rows'] = $variants[(int) $row['id']] ?? [];
      }
      unset($row);
      return $rows;
    });
  }

  /**
   * Lädt Captures und ihre Varianten/EXIF/Wetter blockweise für einen Zeitraum.
   * @return list<array<string,mixed>>
   */
  public function capturesForRange(
    int $fromTimestamp,
    int $toTimestamp,
    ?int $maxRows = null
  ): array {
    return $this->connection->read(function (PDO $pdo) use (
      $fromTimestamp,
      $toTimestamp,
      $maxRows
    ): array {
      $limited = $maxRows !== null && $maxRows > 0;
      $sql = 'SELECT * FROM captures
              WHERE slot_ts BETWEEN :from_timestamp AND :to_timestamp
                AND is_duplicate = 0'
        . ($limited ? ' ORDER BY slot_ts DESC LIMIT :max_rows' : ' ORDER BY slot_ts ASC');
      $statement = $pdo->prepare($sql);
      $statement->bindValue(':from_timestamp', $fromTimestamp, PDO::PARAM_INT);
      $statement->bindValue(':to_timestamp', $toTimestamp, PDO::PARAM_INT);
      if ($limited) {
        $statement->bindValue(':max_rows', $maxRows, PDO::PARAM_INT);
      }
      $statement->execute();
      $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if ($limited) {
        $rows = array_reverse($rows);
      }
      if ($rows === []) {
        return [];
      }

      $rowIndexById = [];
      foreach ($rows as $index => &$row) {
        $captureId = (int) $row['id'];
        $rowIndexById[$captureId] = $index;
        $row['variants_rows'] = [];
        $row['exif_rows'] = [];
        $row['weather_rows'] = [];
      }
      unset($row);

      // 500 IDs bleiben sicher unter SQLite's üblichem 999-Parameter-Limit.
      foreach (array_chunk(array_keys($rowIndexById), 500) as $captureIds) {
        $placeholders = implode(',', array_fill(0, count($captureIds), '?'));
        $variants = $this->rowsGroupedByCapture(
          $pdo,
          "SELECT * FROM variants WHERE capture_id IN ({$placeholders})",
          $captureIds
        );
        $exif = $this->rowsGroupedByCapture(
          $pdo,
          "SELECT * FROM exif_entries
           WHERE capture_id IN ({$placeholders}) ORDER BY sort_order ASC",
          $captureIds
        );
        $weather = $this->weatherLogo->weatherRowsForCaptures($pdo, $captureIds);

        foreach ($captureIds as $captureId) {
          $rowIndex = $rowIndexById[$captureId];
          $rows[$rowIndex]['variants_rows'] = $variants[$captureId] ?? [];
          $rows[$rowIndex]['exif_rows'] = $exif[$captureId] ?? [];
          $rows[$rowIndex]['weather_rows'] = $weather[$captureId] ?? [];
        }
      }
      return $rows;
    });
  }

  /**
   * @param list<int> $captureIds
   * @return array<int,list<array<string,mixed>>>
   */
  private function rowsGroupedByCapture(PDO $pdo, string $sql, array $captureIds): array
  {
    $statement = $pdo->prepare($sql);
    $statement->execute($captureIds);
    $grouped = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $grouped[(int) $row['capture_id']][] = $row;
    }
    return $grouped;
  }

  public function rangeContainsOnlyDuplicateCaptures(int $fromTimestamp, int $toTimestamp): bool
  {
    if ($toTimestamp < $fromTimestamp) {
      return false;
    }
    return $this->connection->read(function (PDO $pdo) use ($fromTimestamp, $toTimestamp): bool {
      $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_duplicate = 0 THEN 1 ELSE 0 END) AS usable
         FROM captures
         WHERE slot_ts BETWEEN :from_timestamp AND :to_timestamp'
      );
      $statement->execute([
        ':from_timestamp' => $fromTimestamp,
        ':to_timestamp' => $toTimestamp,
      ]);
      $row = $statement->fetch(PDO::FETCH_ASSOC);
      return is_array($row)
        && (int) ($row['total'] ?? 0) > 0
        && (int) ($row['usable'] ?? 0) === 0;
    });
  }

  /** @return list<int> */
  public function duplicateCaptureSlotsForRange(int $fromTimestamp, int $toTimestamp): array
  {
    if ($toTimestamp < $fromTimestamp) {
      return [];
    }
    return $this->connection->read(function (PDO $pdo) use ($fromTimestamp, $toTimestamp): array {
      $statement = $pdo->prepare(
        'SELECT slot_ts FROM captures
         WHERE slot_ts BETWEEN :from_timestamp AND :to_timestamp
           AND is_duplicate = 1
         ORDER BY slot_ts ASC'
      );
      $statement->execute([
        ':from_timestamp' => $fromTimestamp,
        ':to_timestamp' => $toTimestamp,
      ]);
      return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    });
  }

  /** @return array{int,int}|null Oldest/newest usable slot, excluding future captures. */
  public function captureTimeBounds(int $until): ?array
  {
    return $this->connection->read(static function (PDO $pdo) use ($until): ?array {
      $bounds = [];
      foreach (['ASC', 'DESC'] as $direction) {
        $statement = $pdo->prepare('SELECT slot_ts FROM captures WHERE slot_ts > 0 AND slot_ts <= :until
          AND is_duplicate = 0 ORDER BY slot_ts ' . $direction . ' LIMIT 1');
        $statement->execute([':until' => $until]);
        $value = $statement->fetchColumn();
        if ($value === false) { return null; }
        $bounds[] = (int) $value;
      }
      return $bounds;
    });
  }

  /** Liefert bei gleichem Abstand bewusst den früheren Slot. */
  public function nearestCaptureSlot(int $targetTimestamp, int $startTimestamp, int $endTimestamp): ?int
  {
    if ($targetTimestamp <= 0 || $endTimestamp < $startTimestamp) {
      return null;
    }

    return $this->connection->read(function (PDO $pdo) use (
      $targetTimestamp,
      $startTimestamp,
      $endTimestamp
    ): ?int {
      $previous = $pdo->prepare(
        'SELECT slot_ts FROM captures
         WHERE slot_ts BETWEEN :start_timestamp AND :end_timestamp
           AND slot_ts <= :target_timestamp
           AND is_duplicate = 0
         ORDER BY slot_ts DESC LIMIT 1'
      );
      $next = $pdo->prepare(
        'SELECT slot_ts FROM captures
         WHERE slot_ts BETWEEN :start_timestamp AND :end_timestamp
           AND slot_ts >= :target_timestamp
           AND is_duplicate = 0
         ORDER BY slot_ts ASC LIMIT 1'
      );
      $parameters = [
        ':start_timestamp' => $startTimestamp,
        ':end_timestamp' => $endTimestamp,
        ':target_timestamp' => $targetTimestamp,
      ];
      $previous->execute($parameters);
      $next->execute($parameters);
      $previousValue = $previous->fetchColumn();
      $nextValue = $next->fetchColumn();
      $previousTimestamp = $previousValue === false ? null : (int) $previousValue;
      $nextTimestamp = $nextValue === false ? null : (int) $nextValue;

      if ($previousTimestamp === null) {
        return $nextTimestamp;
      }
      if ($nextTimestamp === null) {
        return $previousTimestamp;
      }
      return abs($targetTimestamp - $previousTimestamp) <= abs($targetTimestamp - $nextTimestamp)
        ? $previousTimestamp
        : $nextTimestamp;
    });
  }
}
