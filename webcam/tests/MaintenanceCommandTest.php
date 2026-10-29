<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\MaintenanceCommand;
use Webcam\Service\Maintenance\MaintenanceSchedule;

final class MaintenanceCommandTest extends TestCase
{
  public function testBuildsPhpCommandWithoutShellEvaluation(): void
  {
    $settings = MaintenanceSchedule::defaults()['backup_daily'];
    $settings['arguments'] = ['--slug=stromberg', '; touch /tmp/must-not-exist'];

    $execution = MaintenanceCommand::build(dirname(__DIR__), 'custom_backup', $settings);

    $this->assertSame(PHP_BINARY, $execution['command'][0]);
    $this->assertSame(realpath(__DIR__ . '/../bin/backup_daily.php'), $execution['command'][1]);
    $this->assertSame('--slug=stromberg', $execution['command'][2]);
    $this->assertSame('; touch /tmp/must-not-exist', $execution['command'][3]);
  }

  public function testWeatherCommandKeepsBackupDependencyEnvironment(): void
  {
    $settings = MaintenanceSchedule::defaults()['weather_quality'];

    $execution = MaintenanceCommand::build(
      dirname(__DIR__),
      MaintenanceSchedule::WEATHER_QUALITY,
      $settings
    );

    $this->assertSame('/usr/bin/env', $execution['command'][0]);
    $this->assertSame('WEBCAM_WEATHER_QUALITY_SKIP_TARGET_BACKUP=1', $execution['command'][1]);
    $this->assertSame('/bin/bash', $execution['command'][2]);
  }

  public function testRejectsResolvedPathOutsideBin(): void
  {
    $this->expectException(RuntimeException::class);

    MaintenanceCommand::build(dirname(__DIR__), 'bad', [
      'path' => 'bin/../bootstrap.php',
      'arguments' => [],
      'timeout_seconds' => 60,
    ]);
  }
}
