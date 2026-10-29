<?php
declare(strict_types=1);

namespace Webcam\Service\Status;

use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Liest die ergänzenden Wetterinformationen für den operativen Kamerastatus.
 *
 * Aktuelle Sidecars sind nur ein ausdrücklich benannter Legacy-Fallback. Die
 * Qualitätsflags stammen aus der read-only Kamera-SQLite. Schreib- und
 * Watchdog-Entscheidungen gehören bewusst nicht in diese Klasse.
 */
final class StatusWeatherReader
{
  private readonly CameraStoragePathResolver $storagePaths;

  public function __construct(private readonly App $app)
  {
    $this->storagePaths = $app->cameraStoragePaths();
  }

  /**
   * @return array{path:string,data:string,timestamp:int}|null
   */
  // Legacy-Fallback: liest die aktuelle Wetter-Sidecar, wenn State/DB keinen Wert liefern.
  public function currentWeather(Camera $camera): ?array
  {
    $candidates = [];
    $baseRoot = $this->storagePaths->baseRoot($camera);
    $currentDirName = $camera->storageCurrentDir();
    $currentDir = $this->storagePaths->join($baseRoot, $currentDirName . '/' . $camera->slug());
    $candidates[] = $currentDir . '/latest_wx.txt';
    $candidates[] = $currentDir . '/current_wx.txt';

    $projectRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    $liveCurrentDir = (new \Webcam\Infrastructure\Filesystem\CurrentImageDirectory($projectRoot))->path($camera->slug());
    $candidates[] = $liveCurrentDir . '/latest_wx.txt';
    $candidates[] = $liveCurrentDir . '/current_wx.txt';

    foreach ($candidates as $candidate) {
      if (!is_file($candidate) || !is_readable($candidate)) {
        continue;
      }
      $content = file_get_contents($candidate);
      if ($content === false) {
        $this->app->logger()->warning('weather read failed', ['path' => $candidate, 'camera' => $camera->slug()]);
        continue;
      }
      return [
        'path' => $candidate,
        'data' => trim($content),
        'timestamp' => filemtime($candidate) ?: time(),
      ];
    }

    return null;
  }

  /**
   * @return array{status:string,window_hours:int,flags_total:int,counts:array<string,int>,latest_slot_ts:?int,latest_reason:?string,active_from_ts:?int,latest_capture_flagged:bool}
   */
  // Fasst aktive Wetter-Qualitätsflags aus der Kamera-DB für Status und Public API zusammen.
  public function qualitySummary(Camera $camera): array
  {
    $empty = [
      'status' => 'ok',
      'window_hours' => 24,
      'flags_total' => 0,
      'counts' => [],
      'latest_slot_ts' => null,
      'latest_reason' => null,
      'active_from_ts' => null,
      'latest_capture_flagged' => false,
    ];

    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $pdo = $store->pdo();
      $table = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'weather_quality_flags' LIMIT 1");
      if ($table === false || $table->fetchColumn() === false) {
        return $empty;
      }

      $latestCapture = $pdo->query('SELECT MAX(slot_ts) FROM captures')->fetchColumn();
      $latestCaptureTs = $latestCapture !== false ? (int) $latestCapture : 0;
      $fromTs = time() - 86400;

      $counts = [];
      $total = 0;
      $stmt = $pdo->prepare(
        "SELECT q.flag, COALESCE(q.metric, '') AS metric, COUNT(*) AS cnt
           FROM weather_quality_flags q
           JOIN captures c ON c.id = q.capture_id
          WHERE c.slot_ts >= :from_ts
          GROUP BY q.flag, q.metric
          ORDER BY q.flag, q.metric"
      );
      $stmt->execute([':from_ts' => $fromTs]);
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string) $row['flag'];
        $metric = (string) ($row['metric'] ?? '');
        if ($metric !== '') {
          $key .= ':' . $metric;
        }
        $count = (int) $row['cnt'];
        $counts[$key] = $count;
        $total += $count;
      }

      $latestStmt = $pdo->prepare(
        "SELECT c.slot_ts, q.reason
           FROM weather_quality_flags q
           JOIN captures c ON c.id = q.capture_id
          WHERE c.slot_ts >= :from_ts
          ORDER BY c.slot_ts DESC, q.id DESC
          LIMIT 1"
      );
      $latestStmt->execute([':from_ts' => $fromTs]);
      $latest = $latestStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

      $activeStmt = $pdo->prepare(
        "SELECT MIN(c.slot_ts)
           FROM captures c
           JOIN weather_quality_flags q ON q.capture_id = c.id
          WHERE c.slot_ts >= :from_ts
            AND q.flag IN ('frozen_value', 'out_of_range')"
      );
      $activeStmt->execute([':from_ts' => $fromTs]);
      $activeValue = $activeStmt->fetchColumn();
      $activeFromTs = null;
      if ($activeValue !== false && $activeValue !== null) {
        $activeTimestamp = (int) $activeValue;
        $activeFromTs = $activeTimestamp > 0 ? $activeTimestamp : null;
      }

      $currentStmt = $pdo->prepare(
        'SELECT 1
           FROM captures c
           JOIN weather_quality_flags q ON q.capture_id = c.id
          WHERE c.slot_ts = :slot_ts
          LIMIT 1'
      );
      $currentStmt->execute([':slot_ts' => $latestCaptureTs]);
      $latestCaptureHasFlag = $currentStmt->fetchColumn() !== false;
      $latestCaptureFlagged = $latestCaptureTs > 0
        && ($latestCaptureHasFlag || ($activeFromTs !== null && $latestCaptureTs >= $activeFromTs));

      return [
        'status' => $total > 0 ? 'error' : 'ok',
        'window_hours' => 24,
        'flags_total' => $total,
        'counts' => $counts,
        'latest_slot_ts' => is_array($latest) ? (int) ($latest['slot_ts'] ?? 0) : null,
        'latest_reason' => is_array($latest) ? (string) ($latest['reason'] ?? '') : null,
        'active_from_ts' => $activeFromTs,
        'latest_capture_flagged' => $latestCaptureFlagged,
      ];
    } catch (\Throwable $exception) {
      $this->app->logger()->warning('weather quality status failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return $empty;
    }
  }

  public function truncate(string $value): string
  {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return '';
    }
    if (strlen($trimmed) > 4000) {
      return substr($trimmed, 0, 4000) . '…';
    }
    return $trimmed;
  }
}
