<?php
declare(strict_types=1);

namespace Webcam\Service\Backup;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/**
 * Besitzt Lauf-Lock, Tages-Deduplizierung und atomaren Ergebnisstatus.
 *
 * Diese Zustände entscheiden, ob ein Schedulerlauf überhaupt beginnen darf
 * und ob der nächste Lauf den Vortag als vollständig gesichert betrachten kann.
 * Gezielte --slug-Läufe schreiben deshalb weiterhin backup_partial.json und
 * ersetzen niemals den Status eines vollständigen Tageslaufs.
 */
final class BackupRunState
{
  public function __construct(private readonly App $app)
  {
  }

  /** @return resource|null */
  public function acquireLock()
  {
    $directory = $this->app->paths()->statePath('backup');
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException("cannot create backup lock dir: {$directory}");
    }
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backup_daily.run.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
      throw new RuntimeException("cannot open backup run lock: {$path}");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    return $handle;
  }

  /** @param resource|null $handle */
  public function releaseLock($handle): void
  {
    if (!is_resource($handle)) {
      return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
  }

  public function skipReasonForToday(): ?string
  {
    try {
      $stateFile = $this->app->paths()->statePath('backup_db.json');
    } catch (RuntimeException) {
      return null;
    }
    if (!is_file($stateFile)) {
      return null;
    }
    $raw = file_get_contents($stateFile);
    if ($raw === false) {
      fwrite(STDERR, "[WARN] cannot read backup status: {$stateFile}\n");
      return null;
    }
    $decoded = json_decode($raw, true);
    $timestamp = is_array($decoded) ? (int) ($decoded['ts'] ?? 0) : 0;
    if ($timestamp <= 0) {
      return null;
    }

    $timezone = $this->timezone();
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $lastRun = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    if ($lastRun->format('Y-m-d') !== $today) {
      return null;
    }
    $status = is_string($decoded['status'] ?? null) ? $decoded['status'] : 'unknown';
    return sprintf(
      'backup_daily already ran today at %s (status: %s)',
      $lastRun->format('Y-m-d H:i:s T'),
      $status
    );
  }

  /**
   * @param array{total:int,failed:int,target_base:string,ok_slugs:list<string>,failed_slugs:list<string>} $cameraStats
   * @param list<string> $requestedSlugs
   * @param array<string,mixed>|null $sidecarStats
   */
  public function writeStatus(
    string $timestamp,
    bool $configOk,
    array $cameraStats,
    bool $isFullRun = true,
    array $requestedSlugs = [],
    ?array $sidecarStats = null
  ): void {
    $stateFile = $this->app->paths()->statePath(
      $isFullRun ? 'backup_db.json' : 'backup_partial.json'
    );
    $timezone = $this->timezone();
    $date = DateTimeImmutable::createFromFormat('Ymd_His', $timestamp, $timezone)
      ?: new DateTimeImmutable('now', $timezone);

    $sidecarFailed = (int) ($sidecarStats['failed'] ?? 0);
    $sidecarFailedSlugs = is_array($sidecarStats['failed_slugs'] ?? null)
      ? array_map('strval', $sidecarStats['failed_slugs'])
      : [];
    $failedSlugs = array_values(array_unique(array_merge(
      $cameraStats['failed_slugs'],
      $sidecarFailedSlugs
    )));
    $okSlugs = array_values(array_diff($cameraStats['ok_slugs'], $failedSlugs));
    $status = $configOk && $cameraStats['failed'] === 0 && $sidecarFailed === 0
      ? 'ok'
      : 'partial';
    $note = sprintf(
      'backup_daily at %s %s; config ok=%s; camera ok=%d failed=%d; sidecars copied=%d unchanged=%d failed=%d; db_target_base=%s; sidecar_target_base=%s',
      $timestamp,
      $timezone->getName(),
      $configOk ? 'yes' : 'no',
      $cameraStats['total'],
      $cameraStats['failed'],
      (int) ($sidecarStats['copied'] ?? 0),
      (int) ($sidecarStats['unchanged'] ?? 0),
      $sidecarFailed,
      $cameraStats['target_base'],
      (string) ($sidecarStats['target_base'] ?? 'not-recorded')
    );
    $payload = [
      'status' => $status,
      'scope' => $isFullRun ? 'full' : 'partial',
      'requested_slugs' => array_values($requestedSlugs),
      'ts' => $date->getTimestamp(),
      'total' => $cameraStats['total'] + 1,
      'failed' => $cameraStats['failed'] + ($configOk ? 0 : 1) + $sidecarFailed,
      'note' => $note,
      'ok_slugs' => $okSlugs,
      'failed_slugs' => $failedSlugs,
      'sidecars' => $sidecarStats,
    ];

    try {
      $directory = $this->app->paths()->statePath('backup');
      if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('cannot create camera backup state directory');
      }
      // Der globale Partial-Status beschreibt weiterhin nur den letzten Lauf.
      // Eigene Kamera-Ergebnisse bleiben auch nach einem anderen --slug-Lauf
      // erhalten, damit Capture-Trigger nicht bereits gesicherte Kameras neu starten.
      foreach (array_unique(array_merge($okSlugs, $failedSlugs)) as $slug) {
        $cameraPayload = [
          'ts' => $date->getTimestamp(),
          'scope' => 'partial',
          'status' => in_array($slug, $okSlugs, true) ? 'ok' : 'partial',
          'ok_slugs' => in_array($slug, $okSlugs, true) ? [$slug] : [],
        ];
        $cameraState = $this->app->paths()->statePath('backup/camera-' . $slug . '.json');
        if (!AtomicFileWriter::write($cameraState, json_encode($cameraPayload, JSON_THROW_ON_ERROR) . "\n")) {
          throw new RuntimeException('cannot publish camera backup status: ' . $slug);
        }
      }
      $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
      );
      $temporaryPath = $stateFile . '.tmp';
      if (
        file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false
        || !rename($temporaryPath, $stateFile)
      ) {
        @unlink($temporaryPath);
        throw new RuntimeException('atomic status write failed');
      }
    } catch (Throwable $exception) {
      fwrite(STDERR, "[WARN] cannot update {$stateFile}: {$exception->getMessage()}\n");
    }
  }

  private function timezone(): DateTimeZone
  {
    try {
      return new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    } catch (Throwable) {
      return new DateTimeZone('UTC');
    }
  }
}
