<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MaintenanceSchedulerScriptTest extends TestCase
{
  public function testHelpDoesNotRequireLiveConfigDatabase(): void
  {
    $command = 'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && php bin/maintenance_scheduler.php --help 2>&1';
    $output = [];
    $exitCode = -1;

    exec($command, $output, $exitCode);

    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString(
      'Usage: php bin/maintenance_scheduler.php',
      implode("\n", $output)
    );
  }

  public function testUnknownArgumentFailsBeforeDispatch(): void
  {
    $command = 'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && php bin/maintenance_scheduler.php --unknown 2>&1';
    $output = [];
    $exitCode = -1;

    exec($command, $output, $exitCode);

    $this->assertSame(2, $exitCode);
    $this->assertStringContainsString('Unknown argument', implode("\n", $output));
  }

  public function testDryRunDispatchesSchedulesDueNowFromConfigDatabase(): void
  {
    $source = __DIR__ . '/../database/webcams.sqlite';
    $tempBase = tempnam(sys_get_temp_dir(), 'webcam-maintenance-schedule-');
    $this->assertNotFalse($tempBase);
    @unlink($tempBase);
    $tempDb = $tempBase . '.sqlite';
    $this->assertTrue(copy($source, $tempDb));

    // Bewusst weit ausserhalb realer Laufstatus-Tage: Der Test darf nie mit
    // var/state/maintenance-scheduler eines echten Tages kollidieren.
    $now = new DateTimeImmutable('2037-07-25T00:47:00+02:00');
    $time = $now->format('H:i');
    $settings = [
      'backup_daily' => ['enabled' => true, 'time' => '00:40'],
      'weather_quality' => ['enabled' => true, 'time' => $time],
    ];
    $pdo = new PDO('sqlite:' . $tempDb);
    $stmt = $pdo->prepare(
      'INSERT INTO meta (key, value) VALUES (:key, :value) '
      . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([
      ':key' => 'maintenance_scripts',
      ':value' => json_encode($settings, JSON_THROW_ON_ERROR),
    ]);
    $pdo = null;

    $command = 'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && WEBCAM_CONFIG_DB=' . escapeshellarg($tempDb)
      . ' php bin/maintenance_scheduler.php --dry-run --now='
      . escapeshellarg($now->format(DATE_ATOM)) . ' 2>&1';
    $output = [];
    $exitCode = -1;

    try {
      exec($command, $output, $exitCode);
    } finally {
      @unlink($tempDb);
    }

    $text = implode("\n", $output);
    $this->assertSame(0, $exitCode, $text);
    $this->assertStringContainsString('[DRY] Datenbank-Backup due at ' . $time, $text);
    $this->assertStringContainsString('[DRY] Wetterdaten Vortag due at ' . $time, $text);
    $this->assertStringNotContainsString('[DRY] Archiv-Rollover', $text);
  }
}
