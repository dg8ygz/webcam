<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\MaintenanceSchedule;

require_once __DIR__ . '/../src/Service/Maintenance/MaintenanceSchedule.php';

final class MaintenanceScheduleTest extends TestCase
{
  public function testDefaultsExposeThreeDailyTasksWithArchiveOptIn(): void
  {
    $defaults = MaintenanceSchedule::defaults();

    $this->assertFalse($defaults['archive_rollover']['enabled']);
    $this->assertSame('01:00', $defaults['archive_rollover']['time']);
    $this->assertSame('bin/archive_rollover.php', $defaults['archive_rollover']['path']);
    $this->assertSame([], $defaults['archive_rollover']['arguments']);
    $this->assertTrue($defaults['backup_daily']['enabled']);
    $this->assertSame('03:20', $defaults['backup_daily']['time']);
    $this->assertSame('bin/backup_daily.php', $defaults['backup_daily']['path']);
    $this->assertSame(['--force', '--verbose'], $defaults['backup_daily']['arguments']);
    $this->assertSame('03:30', $defaults['weather_quality']['time']);
    $this->assertSame('bin/weather_quality_previous_day.sh', $defaults['weather_quality']['path']);
  }

  public function testNormalizePreservesValidValuesAndCompletesMissingTask(): void
  {
    $normalized = MaintenanceSchedule::normalize([
      'backup_daily' => ['enabled' => false, 'time' => '04:05'],
    ]);

    $this->assertFalse($normalized['backup_daily']['enabled']);
    $this->assertSame('04:05', $normalized['backup_daily']['time']);
    $this->assertSame('bin/backup_daily.php', $normalized['backup_daily']['path']);
    $this->assertTrue($normalized['weather_quality']['enabled']);
    $this->assertSame('03:30', $normalized['weather_quality']['time']);
    $this->assertFalse($normalized['archive_rollover']['enabled']);
  }

  public function testNormalizeFailsClosedForMalformedEnabledValue(): void
  {
    $normalized = MaintenanceSchedule::normalize([
      'backup_daily' => ['enabled' => 'false', 'time' => '04:05'],
    ]);

    $this->assertFalse($normalized['backup_daily']['enabled']);
  }

  public function testValidateUpdateRejectsInvalidTime(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    $settings['backup_daily']['time'] = '24:00';
    MaintenanceSchedule::validateUpdate($settings);
  }

  public function testValidateUpdateRejectsNonBooleanSwitch(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    $settings['backup_daily']['enabled'] = 1;
    MaintenanceSchedule::validateUpdate($settings);
  }

  public function testValidateUpdateRequiresBackupForWeatherQuality(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    $settings['backup_daily']['enabled'] = false;
    MaintenanceSchedule::validateUpdate($settings);
  }

  public function testValidateUpdateRequiresWeatherAfterBackup(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    $settings['weather_quality']['time'] = '03:10';
    MaintenanceSchedule::validateUpdate($settings);
  }

  public function testValidateUpdateAcceptsAdditionalScript(): void
  {
    $settings = MaintenanceSchedule::defaults();
    $settings['cleanup_overlays'] = [
      'enabled' => true,
      'time' => '04:00',
      'label' => 'Overlays aufräumen',
      'description' => 'Entfernt verwaiste Overlays.',
      'path' => 'bin/cleanup_overlays.php',
      'arguments' => ['--delete', '--verbose'],
      'timeout_seconds' => 300,
    ];

    $validated = MaintenanceSchedule::validateUpdate($settings);

    $this->assertSame('bin/cleanup_overlays.php', $validated['cleanup_overlays']['path']);
    $this->assertSame(['--delete', '--verbose'], $validated['cleanup_overlays']['arguments']);
  }

  public function testValidateUpdateRejectsPathTraversal(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    $settings['backup_daily']['path'] = 'bin/../bootstrap.php';
    MaintenanceSchedule::validateUpdate($settings);
  }

  public function testValidateUpdateRequiresBuiltInTasks(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $settings = MaintenanceSchedule::defaults();
    unset($settings['backup_daily']);
    MaintenanceSchedule::validateUpdate($settings);
  }
}
