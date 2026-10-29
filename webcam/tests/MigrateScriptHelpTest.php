<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrateScriptHelpTest extends TestCase
{
  public function testHelpReturnsUsage(): void
  {
    $cmd = 'cd ' . escapeshellarg(__DIR__ . '/..') . ' && php bin/migrate_camera_db.php --help';
    $output = [];
    $exitCode = null;
    exec($cmd, $output, $exitCode);
    $this->assertSame(0, $exitCode, 'Expected exit code 0 for --help');
    $this->assertStringContainsString('Usage: php bin/migrate_camera_db.php', implode("\n", $output));
  }
}
