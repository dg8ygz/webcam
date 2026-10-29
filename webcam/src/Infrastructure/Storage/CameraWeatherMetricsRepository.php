<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Webcam\Service\Weather\WeatherMetrics;

/**
 * Optional numeric projection in the camera SQLite DB, never a JSON cache.
 * Raw lines remain authoritative. Triggers invalidate changed captures;
 * ingestion refreshes them in the SAME transaction. Backfill is explicit.
 */
final class CameraWeatherMetricsRepository
{
  public function __construct(private readonly PDO $pdo) {}

  /** Nur Existenz pruefen; Lesen darf keine Migration oder Nachberechnung ausloesen. */
  public function installed(): bool
  {
    return $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='weather_measurements'")->fetchColumn() !== false;
  }

  /** Explicit migration only; never invoked by a public GET or ordinary read. */
  public function install(): void
  {
    $this->pdo->exec('BEGIN IMMEDIATE');
    try {
      $previousVersion = $this->pdo->query("SELECT value FROM meta WHERE key='weather_metrics_version'")->fetchColumn();
      $this->pdo->exec('CREATE TABLE IF NOT EXISTS weather_measurements (
        capture_id INTEGER PRIMARY KEY REFERENCES captures(id) ON DELETE CASCADE,
        temperature REAL, pressure REAL, wind REAL, humidity REAL, dewpoint REAL
      );
      CREATE TABLE IF NOT EXISTS weather_measurements_dirty (
        capture_id INTEGER PRIMARY KEY REFERENCES captures(id) ON DELETE CASCADE
      )');
      foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD', 'UPDATE' => 'NEW'] as $event => $row) {
        $old = $event === 'UPDATE'
          ? 'INSERT OR IGNORE INTO weather_measurements_dirty SELECT OLD.capture_id WHERE EXISTS (SELECT 1 FROM captures WHERE id=OLD.capture_id);' : '';
        $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS weather_metrics_{$event}
          AFTER {$event} ON weather_entries BEGIN
            INSERT OR IGNORE INTO weather_measurements_dirty
              SELECT {$row}.capture_id WHERE EXISTS (SELECT 1 FROM captures WHERE id={$row}.capture_id);
            {$old}
          END");
      }
      if ((int) $previousVersion !== WeatherMetrics::VERSION) {
        // First installation/parser upgrades rebuild once. Interrupted runs
        // retain their queue; a resume must not requeue already completed rows.
        $this->pdo->exec('INSERT OR IGNORE INTO weather_measurements_dirty
          SELECT DISTINCT w.capture_id FROM weather_entries w JOIN captures c ON c.id=w.capture_id');
        $this->pdo->exec('INSERT OR IGNORE INTO weather_measurements_dirty SELECT capture_id FROM weather_measurements');
      } else {
        $this->pdo->exec('INSERT OR IGNORE INTO weather_measurements_dirty
          SELECT DISTINCT w.capture_id FROM weather_entries w JOIN captures c ON c.id=w.capture_id
          LEFT JOIN weather_measurements m ON m.capture_id=w.capture_id WHERE m.capture_id IS NULL');
      }
      $stmt = $this->pdo->prepare("INSERT INTO meta(key,value) VALUES('weather_metrics_version',?)
        ON CONFLICT(key) DO UPDATE SET value=excluded.value");
      $stmt->execute([(string) WeatherMetrics::VERSION]);
      $this->pdo->exec('COMMIT');
    } catch (\Throwable $error) {
      $this->pdo->exec('ROLLBACK');
      throw $error;
    }
  }

  /** Caller must hold the raw-weather write transaction. */
  public function replace(int $captureId, array $lines): void
  {
    $values = WeatherMetrics::extract(implode("\n", array_map('strval', $lines)));
    $columns = array_keys(WeatherMetrics::definitions());
    $names = implode(',', $columns);
    $updates = implode(',', array_map(static fn ($name) => "{$name}=excluded.{$name}", $columns));
    $statement = $this->pdo->prepare("INSERT INTO weather_measurements(capture_id,{$names}) VALUES(?,?,?,?,?,?)
      ON CONFLICT(capture_id) DO UPDATE SET {$updates}");
    $statement->execute([$captureId, ...array_map(static fn ($name) => $values[$name] ?? null, $columns)]);
    $this->pdo->prepare('DELETE FROM weather_measurements_dirty WHERE capture_id=?')->execute([$captureId]);
  }

  /** Bounded, resumable transaction; captures/images themselves are untouched. */
  public function backfillBatch(int $limit = 250): int
  {
    $limit = max(1, min(1000, $limit));
    $this->pdo->exec('BEGIN IMMEDIATE');
    try {
      $ids = $this->pdo->query('SELECT capture_id FROM weather_measurements_dirty ORDER BY capture_id LIMIT ' . $limit)->fetchAll(PDO::FETCH_COLUMN);
      $query = $this->pdo->prepare('SELECT value FROM weather_entries WHERE capture_id=? ORDER BY position,id');
      foreach ($ids as $id) {
        $query->execute([$id]);
        $this->replace((int) $id, $query->fetchAll(PDO::FETCH_COLUMN));
      }
      $this->pdo->exec('COMMIT');
      return count($ids);
    } catch (\Throwable $error) {
      $this->pdo->exec('ROLLBACK');
      throw $error;
    }
  }

  /**
   * null means explicitly unmigrated/dirty: callers must use raw data visibly.
   * Only daily sums/counts and five latest values cross the SQL/PHP boundary.
   * Calendar boundaries are computed in the configured timezone, including DST.
   * @param list<string> $days
   */
  public function aggregate(array $days, DateTimeZone $timezone): ?array
  {
    if (!$this->installed()) {
      return null;
    }
    if ($this->pdo->inTransaction()) {
      throw new RuntimeException('Weather aggregation requires its own read snapshot');
    }
    $this->pdo->beginTransaction();
    try {
      $version = $this->pdo->query("SELECT value FROM meta WHERE key='weather_metrics_version'")->fetchColumn();
      if ((int) $version !== WeatherMetrics::VERSION
        || $this->pdo->query('SELECT 1 FROM weather_measurements_dirty LIMIT 1')->fetchColumn() !== false) {
        return null;
      }
      if ($days === [] || count($days) > 366) {
        throw new RuntimeException('Invalid weather calendar range');
      }
      $ranges = [];
      $from = PHP_INT_MAX;
      $to = 0;
      foreach (array_values($days) as $index => $day) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $timezone);
        if (!$date || $date->format('Y-m-d') !== $day) {
          throw new RuntimeException('Invalid weather calendar date');
        }
        $start = $date->getTimestamp();
        $end = $date->modify('+1 day')->getTimestamp();
        // Only internally computed integers, no request strings enter this SQL.
        $ranges[] = "({$index},{$start},{$end})";
        $from = min($from, $start);
        $to = max($to, $end);
      }
      $quality = $this->qualityFilter();
      $expressions = [];
      foreach (array_keys(WeatherMetrics::definitions()) as $metric) {
        $expressions[] = "SUM(m.{$metric}) AS {$metric}_sum, COUNT(m.{$metric}) AS {$metric}_count";
      }
      $query = 'WITH days(n,lo,hi) AS (VALUES ' . implode(',', $ranges) . ')
        SELECT d.n, ' . implode(',', $expressions) . '
        FROM days d CROSS JOIN captures c ON c.slot_ts >= d.lo AND c.slot_ts < d.hi
        JOIN weather_measurements m ON m.capture_id=c.id
        WHERE 1=1 ' . $quality . ' GROUP BY d.n ORDER BY d.n';
      $daily = [];
      $counts = array_fill_keys(array_keys(WeatherMetrics::definitions()), 0);
      foreach ($this->pdo->query($query) as $row) {
        foreach (array_keys($counts) as $metric) {
          $count = (int) $row[$metric . '_count'];
          if ($count > 0) {
            $daily[$metric][$days[(int) $row['n']]] = ['sum' => (float) $row[$metric . '_sum'], 'count' => $count];
            $counts[$metric] += $count;
          }
        }
      }
      $latest = [];
      foreach (array_keys($counts) as $metric) {
        if ($counts[$metric] === 0) {
          continue;
        }
        $row = $this->pdo->query("SELECT c.slot_ts,m.{$metric} AS value FROM captures c
          JOIN weather_measurements m ON m.capture_id=c.id
          WHERE c.slot_ts >= {$from} AND c.slot_ts < {$to} AND m.{$metric} IS NOT NULL
          {$quality} ORDER BY c.slot_ts DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
          $latest[$metric] = ['value' => (float) $row['value'], 'timestamp' => (int) $row['slot_ts']];
        }
      }
      return ['daily' => $daily, 'sample_counts' => $counts, 'latest' => $latest];
    } finally {
      $this->pdo->commit();
    }
  }

  /** Same quality semantics as the camera view; evaluated in the read snapshot. */
  private function qualityFilter(): string
  {
    if ($this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='weather_quality_flags'")->fetchColumn() === false) {
      return '';
    }
    $filter = ' AND NOT EXISTS(SELECT 1 FROM weather_quality_flags q WHERE q.capture_id=c.id)';
    $query = $this->pdo->prepare("SELECT MIN(c.slot_ts) FROM captures c
      JOIN weather_quality_flags q ON q.capture_id=c.id
      WHERE c.slot_ts >= ? AND q.flag IN ('frozen_value','out_of_range')");
    $query->execute([time() - 86400]);
    $activeFrom = $query->fetchColumn();
    return $activeFrom === null || $activeFrom === false || (int) $activeFrom <= 0
      ? $filter : $filter . ' AND c.slot_ts < ' . (int) $activeFrom;
  }
}
