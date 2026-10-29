<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\MaintenanceSchedule;
use Webcam\Service\Maintenance\MaintenanceTick;

final class MaintenanceTickTest extends TestCase
{
  public function testRetentionStartsAtConfiguredTimeAndOnlyOncePerDay(): void
  {
    foreach (['00:15', '00:25'] as $time) {
      $settings = MaintenanceSchedule::normalize([
        'archive_rollover' => ['enabled' => false],
        'backup_daily' => ['enabled' => false],
        'weather_quality' => ['enabled' => false],
        'cache_retention' => [
          'enabled' => true, 'time' => $time, 'path' => 'bin/cache_retention.php',
          'arguments' => ['--run'], 'timeout_seconds' => 1800,
        ],
      ]);
      $at = new DateTimeImmutable('2026-10-08T' . $time . ':00+02:00');
      $this->assertSame([], MaintenanceTick::dueTasks($settings, [], $at->modify('-1 second')));
      $this->assertSame(['cache_retention'], MaintenanceTick::dueTasks($settings, [], $at));
      $this->assertSame([], MaintenanceTick::dueTasks($settings, ['cache_retention' => '2026-10-08'], $at));
      $this->assertSame(['cache_retention'], MaintenanceTick::dueTasks(
        $settings, ['cache_retention' => '2026-10-08'], $at->modify('+1 day')
      ));
    }
  }

  public function testDifferentDailyTimesAreIndependentlyDue(): void
  {
    $settings = [
      MaintenanceSchedule::BACKUP_DAILY => ['enabled' => true, 'time' => '01:00'],
      MaintenanceSchedule::WEATHER_QUALITY => ['enabled' => true, 'time' => '15:00'],
    ];
    $days = [
      MaintenanceSchedule::BACKUP_DAILY => null,
      MaintenanceSchedule::WEATHER_QUALITY => null,
    ];

    $this->assertSame([], MaintenanceTick::dueTasks(
      $settings,
      $days,
      new DateTimeImmutable('2026-07-25T00:59:00+02:00')
    ));
    $this->assertSame([MaintenanceSchedule::BACKUP_DAILY], MaintenanceTick::dueTasks(
      $settings,
      $days,
      new DateTimeImmutable('2026-07-25T01:00:00+02:00')
    ));
    $this->assertSame([
      MaintenanceSchedule::BACKUP_DAILY,
      MaintenanceSchedule::WEATHER_QUALITY,
    ], MaintenanceTick::dueTasks(
      $settings,
      $days,
      new DateTimeImmutable('2026-07-25T15:00:00+02:00')
    ));
  }

  public function testCompletedTaskDoesNotRunAgainOnSameDay(): void
  {
    $settings = MaintenanceSchedule::defaults();
    $days = [
      MaintenanceSchedule::ARCHIVE_ROLLOVER => null,
      MaintenanceSchedule::BACKUP_DAILY => '2026-07-25',
      MaintenanceSchedule::WEATHER_QUALITY => null,
    ];

    $this->assertSame([MaintenanceSchedule::WEATHER_QUALITY], MaintenanceTick::dueTasks(
      $settings,
      $days,
      new DateTimeImmutable('2026-07-25T15:00:00+02:00')
    ));
  }

  public function testDisabledTaskIsNeverDue(): void
  {
    $settings = MaintenanceSchedule::defaults();
    $settings[MaintenanceSchedule::BACKUP_DAILY]['enabled'] = false;
    $settings[MaintenanceSchedule::WEATHER_QUALITY]['enabled'] = false;

    $this->assertSame([], MaintenanceTick::dueTasks(
      $settings,
      [],
      new DateTimeImmutable('2026-07-25T03:25:00+02:00')
    ));
  }

  public function testAdditionalScriptUsesItsOwnDailyTime(): void
  {
    $settings = MaintenanceSchedule::defaults();
    $settings['cleanup_overlays'] = [
      'enabled' => true,
      'time' => '04:15',
    ];

    $this->assertSame(['cleanup_overlays'], MaintenanceTick::dueTasks(
      $settings,
      [
        MaintenanceSchedule::ARCHIVE_ROLLOVER => '2026-07-25',
        MaintenanceSchedule::BACKUP_DAILY => '2026-07-25',
        MaintenanceSchedule::WEATHER_QUALITY => '2026-07-25',
      ],
      new DateTimeImmutable('2026-07-25T04:15:00+02:00')
    ));
  }
}
