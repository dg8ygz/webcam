<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\MaintenanceLog;

final class MaintenanceLogTest extends TestCase
{
  public function testLastEntriesAreBoundedAndSecretsAreRedacted(): void
  {
    $entries = MaintenanceLog::lastEntries(implode("\n", [
      'eins',
      'zwei',
      'drei',
      'vier',
      'token=very-secret',
      'sechs',
    ]));

    self::assertCount(5, $entries);
    self::assertSame('zwei', $entries[0]);
    self::assertSame('sechs', $entries[4]);
    self::assertStringNotContainsString('very-secret', implode("\n", $entries));
    self::assertStringContainsString('[REDACTED]', implode("\n", $entries));
  }

  public function testLegacyEntriesComeOnlyFromTheExactTaskRun(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'maintenance-log-');
    self::assertIsString($path);
    $startedAt = '2026-08-30T13:17:20+02:00';
    $contents = implode("\n", [
      '[START] Datenbank-Backup at 2026-08-30T13:00:00+02:00',
      'backup darf nicht erscheinen',
      '[DONE] Datenbank-Backup exit=0 at 2026-08-30T13:00:04+02:00',
      '[START] Archiv-Rollover at 2026-08-29T13:17:20+02:00',
      'alter Archivlauf darf nicht erscheinen',
      '[DONE] Archiv-Rollover exit=1 at 2026-08-29T13:17:24+02:00',
      '[START] Archiv-Rollover at ' . $startedAt,
      'Archivpfad nicht beschreibbar',
      '[ERROR] konkrete Scriptmeldung',
      'password=do-not-expose',
      '[DONE] Archiv-Rollover exit=255 at 2026-08-30T13:17:24+02:00',
      '[START] Wetterdaten Vortag at 2026-08-30T13:30:00+02:00',
      'Wetter darf nicht erscheinen',
    ]);
    file_put_contents($path, $contents);

    try {
      $entries = MaintenanceLog::legacyTaskEntries(
        $path,
        'archive_rollover',
        'Archiv-Rollover',
        $startedAt
      );
    } finally {
      @unlink($path);
    }

    self::assertSame([
      'Archivpfad nicht beschreibbar',
      '[ERROR] konkrete Scriptmeldung',
      'password=[REDACTED]',
    ], $entries);
    self::assertStringNotContainsString('Backup', implode("\n", $entries));
    self::assertStringNotContainsString('Wetter', implode("\n", $entries));
    self::assertStringNotContainsString('alter Archivlauf', implode("\n", $entries));
  }

  public function testLegacyEntriesFailClosedWithoutMatchingStartTime(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'maintenance-log-');
    self::assertIsString($path);
    file_put_contents($path, implode("\n", [
      '[START] Archiv-Rollover at 2026-08-29T13:17:20+02:00',
      'nicht der angeforderte Lauf',
      '[DONE] Archiv-Rollover exit=255 at 2026-08-29T13:17:24+02:00',
    ]));

    try {
      $entries = MaintenanceLog::legacyTaskEntries(
        $path,
        'archive_rollover',
        'Archiv-Rollover',
        '2026-08-30T13:17:20+02:00'
      );
    } finally {
      @unlink($path);
    }

    self::assertSame([], $entries);
  }
}
