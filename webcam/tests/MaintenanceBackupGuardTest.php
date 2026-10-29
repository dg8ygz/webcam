<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\MaintenanceBackupGuard;

require_once __DIR__ . '/../src/Service/Maintenance/MaintenanceBackupGuard.php';

final class MaintenanceBackupGuardTest extends TestCase
{
  private const DAY = '2026-07-26';

  public function testAcceptsSuccessfulFullBackupAtConfiguredTime(): void
  {
    $result = MaintenanceBackupGuard::evaluate(
      ['enabled' => true, 'time' => '03:20'],
      ['scheduled_day' => self::DAY, 'status' => 'ok'],
      $this->backupState('03:20'),
      self::DAY,
      'Europe/Berlin',
      'stromberg'
    );

    $this->assertTrue($result['ready']);
    $this->assertFalse($result['terminal']);
  }

  public function testRejectsLegacyBackupAt0310AsNotReady(): void
  {
    $result = MaintenanceBackupGuard::evaluate(
      ['enabled' => true, 'time' => '03:20'],
      [],
      $this->backupState('03:10'),
      self::DAY,
      'Europe/Berlin',
      'stromberg'
    );

    $this->assertFalse($result['ready']);
    $this->assertFalse($result['terminal']);
  }

  public function testWaitsWhileBackupIsRunning(): void
  {
    $result = MaintenanceBackupGuard::evaluate(
      ['enabled' => true, 'time' => '03:20'],
      ['scheduled_day' => self::DAY, 'status' => 'running'],
      [],
      self::DAY,
      'Europe/Berlin',
      'stromberg'
    );

    $this->assertFalse($result['ready']);
    $this->assertFalse($result['terminal']);
  }

  public function testBlocksAfterFailedBackup(): void
  {
    $result = MaintenanceBackupGuard::evaluate(
      ['enabled' => true, 'time' => '03:20'],
      ['scheduled_day' => self::DAY, 'status' => 'error'],
      [],
      self::DAY,
      'Europe/Berlin',
      'stromberg'
    );

    $this->assertFalse($result['ready']);
    $this->assertTrue($result['terminal']);
  }

  public function testBlocksWhenStrombergWasNotBackedUp(): void
  {
    $state = $this->backupState('03:20');
    $state['ok_slugs'] = ['bielefeld'];
    $result = MaintenanceBackupGuard::evaluate(
      ['enabled' => true, 'time' => '03:20'],
      ['scheduled_day' => self::DAY, 'status' => 'ok'],
      $state,
      self::DAY,
      'Europe/Berlin',
      'stromberg'
    );

    $this->assertFalse($result['ready']);
    $this->assertTrue($result['terminal']);
  }

  /** @return array<string,mixed> */
  private function backupState(string $time): array
  {
    $timestamp = (new DateTimeImmutable(self::DAY . 'T' . $time . ':00+02:00'))->getTimestamp();
    return [
      'status' => 'ok',
      'scope' => 'full',
      'ts' => $timestamp,
      'failed' => 0,
      'ok_slugs' => ['bielefeld', 'stromberg'],
    ];
  }
}
