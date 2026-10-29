<?php
declare(strict_types=1);

namespace Webcam\Service\Maintenance;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Entscheidet rein aus Statusdaten, ob ein Wetter-Schreiblauf freigegeben ist.
 */
final class MaintenanceBackupGuard
{
  /**
   * @param array{enabled:bool,time:string} $backupSettings
   * @param array<string,mixed> $schedulerState
   * @param array<string,mixed> $backupState
   * @return array{ready:bool,terminal:bool,reason:string}
   */
  public static function evaluate(
    array $backupSettings,
    array $schedulerState,
    array $backupState,
    string $currentDay,
    string $timezoneName,
    string $requiredSlug
  ): array {
    if (!$backupSettings['enabled']) {
      return self::blocked('daily database backup is disabled');
    }

    if (($schedulerState['scheduled_day'] ?? null) === $currentDay) {
      $schedulerStatus = (string) ($schedulerState['status'] ?? 'unknown');
      if ($schedulerStatus === 'running') {
        return self::waiting('daily database backup is still running');
      }
      if ($schedulerStatus !== 'ok') {
        return self::blocked('daily database backup did not finish successfully');
      }
    }

    $timestamp = (int) ($backupState['ts'] ?? 0);
    if ($timestamp <= 0) {
      return self::waiting('daily database backup status is not available yet');
    }

    try {
      $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
      $timezone = new DateTimeZone('UTC');
    }
    $backupAt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    if ($backupAt->format('Y-m-d') !== $currentDay || $backupAt->format('H:i') < $backupSettings['time']) {
      return self::waiting('configured daily database backup has not completed yet');
    }

    $statusOk = ($backupState['status'] ?? null) === 'ok'
      && ($backupState['scope'] ?? null) === 'full'
      && (int) ($backupState['failed'] ?? 1) === 0;
    if (!$statusOk) {
      return self::blocked('daily database backup status reports an error');
    }
    $okSlugs = is_array($backupState['ok_slugs'] ?? null) ? $backupState['ok_slugs'] : [];
    if (!in_array($requiredSlug, $okSlugs, true)) {
      return self::blocked("daily database backup does not include {$requiredSlug}");
    }

    return ['ready' => true, 'terminal' => false, 'reason' => 'daily database backup completed'];
  }

  /** @return array{ready:false,terminal:false,reason:string} */
  private static function waiting(string $reason): array
  {
    return ['ready' => false, 'terminal' => false, 'reason' => $reason];
  }

  /** @return array{ready:false,terminal:true,reason:string} */
  private static function blocked(string $reason): array
  {
    return ['ready' => false, 'terminal' => true, 'reason' => $reason];
  }
}
