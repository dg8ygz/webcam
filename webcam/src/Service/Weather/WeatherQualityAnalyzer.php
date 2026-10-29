<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use PDO;
use Throwable;

/**
 * Analysiert Wetterreihen und synchronisiert optionale Qualitätsmarker.
 *
 * Leseführung:
 * 1. loadWeatherQualityRows hydriert Capture und Wetterzeilen read-only.
 * 2. evaluateWeatherQualityRows meldet Lücken, Grenzwerte und starre Reihen.
 * 3. syncWeatherQualityFlags ersetzt nur Marker dieses Prüfwerkzeugs.
 * 4. summarizeWeatherQualityFlags formt die kompakte CLI-Ausgabe.
 */
final class WeatherQualityAnalyzer
{
  public function configureWeatherQualityPdo(PDO $pdo, bool $write): void
  {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    if ($write) {
      $pdo->exec('PRAGMA foreign_keys = ON');
    }
  }

  /**
   * @return list<array{capture_id:int,slot_ts:int,line:string,metrics:array<string,float>}>
   */
  public function loadWeatherQualityRows(PDO $pdo, int $fromTs, int $toTs): array
  {
    $stmt = $pdo->prepare(
      "SELECT c.id AS capture_id,
              c.slot_ts,
              COALESCE(GROUP_CONCAT(w.value, char(10)), '') AS line
         FROM captures c
         LEFT JOIN weather_entries w
           ON w.capture_id = c.id
          AND w.key = 'line'
        WHERE c.slot_ts BETWEEN :from AND :to
        GROUP BY c.id, c.slot_ts
        ORDER BY c.slot_ts ASC"
    );
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $line = trim((string) ($row['line'] ?? ''));
      $rows[] = [
        'capture_id' => (int) $row['capture_id'],
        'slot_ts' => (int) $row['slot_ts'],
        'line' => $line,
        'metrics' => $this->parseWeatherQualityMetrics($line),
      ];
    }
    return $rows;
  }

  /**
   * @return array<string,float>
   */
  private function parseWeatherQualityMetrics(string $line): array
  {
    $metrics = [];
    $patterns = [
      'temperature' => '/\b(?:Temp(?:\.|eratur)?|T)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:°)?\s*C\b/iu',
      'pressure' => '/\b(?:Press(?:\.|ure)?|Luftdruck|P)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:mbar|hpa)\b/i',
      'wind' => '/\b(?:Wind|W)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(km\/h|kmh|m\/s|kn)?\b/i',
      'humidity' => '/\b(?:Hum|Humidity|Luftfeuchte)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*%/i',
    ];

    foreach ($patterns as $metric => $pattern) {
      if (!preg_match($pattern, $line, $match)) {
        continue;
      }
      $value = $this->parseWeatherQualityFloat((string) $match[1]);
      if ($metric === 'wind') {
        $unit = strtolower((string) ($match[2] ?? 'km/h'));
        if ($unit === 'm/s') {
          $value *= 3.6;
        } elseif ($unit === 'kn') {
          $value *= 1.852;
        }
      }
      $metrics[$metric] = $value;
    }

    return $metrics;
  }

  private function parseWeatherQualityFloat(string $value): float
  {
    return (float) str_replace(',', '.', trim($value));
  }

  /**
   * @param list<array{capture_id:int,slot_ts:int,line:string,metrics:array<string,float>}> $rows
   * @return list<array{capture_id:int,slot_ts:int,flag:string,metric:?string,value:?string,reason:string}>
   */
  public function evaluateWeatherQualityRows(array $rows, int $minRepeat): array
  {
    $flags = [];
    $seen = [];

    foreach ($rows as $row) {
      $captureId = $row['capture_id'];
      if ($row['line'] === '') {
        $this->addWeatherQualityFlag($flags, $seen, $row, 'missing_weather', null, null, 'no weather row for capture');
        continue;
      }

      foreach ($this->weatherQualityRanges() as $metric => $range) {
        if (!array_key_exists($metric, $row['metrics'])) {
          continue;
        }
        $value = $row['metrics'][$metric];
        if ($value < $range['min'] || $value > $range['max']) {
          $this->addWeatherQualityFlag(
            $flags,
            $seen,
            $row,
            'out_of_range',
            $metric,
            $this->formatWeatherQualityValue($metric, $value),
            sprintf(
              '%s %.1f outside %.1f..%.1f',
              $metric,
              $value,
              $range['min'],
              $range['max']
            )
          );
        }
      }
      unset($captureId);
    }

    $this->addFrozenWeatherFlags($flags, $seen, $rows, 'weather_line', $minRepeat);
    $this->addFrozenWeatherFlags($flags, $seen, $rows, 'temperature', $minRepeat);
    $this->addFrozenWeatherFlags($flags, $seen, $rows, 'pressure', max($minRepeat, 36));

    usort($flags, static fn(array $a, array $b): int => ($a['slot_ts'] <=> $b['slot_ts']) ?: strcmp($a['flag'], $b['flag']));
    return $flags;
  }

  /**
   * @return array<string,array{min:float,max:float}>
   */
  private function weatherQualityRanges(): array
  {
    return [
      'temperature' => ['min' => -35.0, 'max' => 45.0],
      'pressure' => ['min' => 930.0, 'max' => 1060.0],
      'wind' => ['min' => 0.0, 'max' => 180.0],
      'humidity' => ['min' => 0.0, 'max' => 100.0],
    ];
  }

  /**
   * @param list<array{capture_id:int,slot_ts:int,line:string,metrics:array<string,float>}> $rows
   * @param array<string,bool> $seen
   * @param list<array{capture_id:int,slot_ts:int,flag:string,metric:?string,value:?string,reason:string}> $flags
   */
  private function addFrozenWeatherFlags(array &$flags, array &$seen, array $rows, string $metric, int $minRepeat): void
  {
    $run = [];
    $lastValue = null;

    $flush = function () use (&$flags, &$seen, &$run, $metric, $minRepeat): void {
      if (count($run) < $minRepeat) {
        $run = [];
        return;
      }
      $first = $run[0];
      $last = $run[count($run) - 1];
      $value = (string) $first['compare_value'];
      $reason = sprintf(
        '%s unchanged at %s for %d captures (%s..%s)',
        $metric,
        $value,
        count($run),
        date('Y-m-d H:i', (int) $first['slot_ts']),
        date('Y-m-d H:i', (int) $last['slot_ts'])
      );
      foreach ($run as $item) {
        $this->addWeatherQualityFlag(
          $flags,
          $seen,
          $item,
          'frozen_value',
          $metric,
          $value,
          $reason
        );
      }
      $run = [];
    };

    foreach ($rows as $row) {
      $value = $this->weatherQualityCompareValue($row, $metric);
      if ($value === null) {
        $flush();
        $lastValue = null;
        continue;
      }
      $item = $row;
      $item['compare_value'] = $value;
      if ($lastValue === null || $value !== $lastValue) {
        $flush();
        $run = [$item];
        $lastValue = $value;
        continue;
      }
      $run[] = $item;
    }
    $flush();
  }

  /**
   * @param array{capture_id:int,slot_ts:int,line:string,metrics:array<string,float>} $row
   */
  private function weatherQualityCompareValue(array $row, string $metric): ?string
  {
    if ($metric === 'weather_line') {
      $line = preg_replace('/\s+/', ' ', trim($row['line']));
      return $line === '' ? null : $line;
    }
    if (!array_key_exists($metric, $row['metrics'])) {
      return null;
    }
    return $this->formatWeatherQualityValue($metric, (float) $row['metrics'][$metric]);
  }

  private function formatWeatherQualityValue(string $metric, float $value): string
  {
    $precision = $metric === 'humidity' ? 0 : 1;
    return number_format($value, $precision, '.', '');
  }

  /**
   * @param list<array{capture_id:int,slot_ts:int,flag:string,metric:?string,value:?string,reason:string}> $flags
   * @param array<string,bool> $seen
   * @param array{capture_id:int,slot_ts:int} $row
   */
  private function addWeatherQualityFlag(
    array &$flags,
    array &$seen,
    array $row,
    string $flag,
    ?string $metric,
    ?string $value,
    string $reason
  ): void {
    $key = (int) $row['capture_id'] . '|' . $flag . '|' . (string) $metric;
    if (isset($seen[$key])) {
      return;
    }
    $seen[$key] = true;
    $flags[] = [
      'capture_id' => (int) $row['capture_id'],
      'slot_ts' => (int) $row['slot_ts'],
      'flag' => $flag,
      'metric' => $metric,
      'value' => $value,
      'reason' => $reason,
    ];
  }

  /**
   * @param list<int> $captureIds
   * @param list<array{capture_id:int,slot_ts:int,flag:string,metric:?string,value:?string,reason:string}> $flags
   */
  public function syncWeatherQualityFlags(PDO $pdo, array $captureIds, array $flags, int $checkedAt): void
  {
    $this->ensureWeatherQualitySchema($pdo);
    $pdo->beginTransaction();
    try {
      foreach (array_chunk(array_values(array_unique(array_map('intval', $captureIds))), 500) as $chunk) {
        if ($chunk === []) {
          continue;
        }
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("DELETE FROM weather_quality_flags WHERE source = 'check_weather_quality' AND capture_id IN ($ph)");
        $stmt->execute($chunk);
      }

      $insert = $pdo->prepare(
        'INSERT INTO weather_quality_flags (capture_id, flag, metric, value, reason, checked_at, source)
         VALUES (:capture_id, :flag, :metric, :value, :reason, :checked_at, :source)'
      );
      foreach ($flags as $flag) {
        $insert->execute([
          ':capture_id' => $flag['capture_id'],
          ':flag' => $flag['flag'],
          ':metric' => $flag['metric'],
          ':value' => $flag['value'],
          ':reason' => $flag['reason'],
          ':checked_at' => $checkedAt,
          ':source' => 'check_weather_quality',
        ]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  private function ensureWeatherQualitySchema(PDO $pdo): void
  {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS weather_quality_flags (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         capture_id INTEGER NOT NULL,
         flag TEXT NOT NULL,
         metric TEXT,
         value TEXT,
         reason TEXT NOT NULL,
         checked_at INTEGER NOT NULL,
         source TEXT NOT NULL DEFAULT 'check_weather_quality',
         FOREIGN KEY (capture_id) REFERENCES captures(id) ON DELETE CASCADE
       )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_quality_capture ON weather_quality_flags(capture_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_quality_flag ON weather_quality_flags(flag, metric)');
  }

  /**
   * @param list<array{flag:string,metric:?string}> $flags
   */
  public function summarizeWeatherQualityFlags(array $flags): string
  {
    if ($flags === []) {
      return '';
    }
    $counts = [];
    foreach ($flags as $flag) {
      $key = $flag['flag'] . ($flag['metric'] !== null ? ':' . $flag['metric'] : '');
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    ksort($counts);
    $parts = [];
    foreach ($counts as $key => $count) {
      $parts[] = $key . '=' . $count;
    }
    return implode(' ', $parts);
  }
}
