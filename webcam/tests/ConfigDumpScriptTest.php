<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConfigDumpScriptTest extends TestCase
{
  /**
   * @return array{0:int|null,1:string}
   */
  private function runCommand(string $command): array
  {
    $output = [];
    $exitCode = null;
    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
  }

  public function testHelpReturnsUsage(): void
  {
    [$exitCode, $output] = $this->runCommand(
      'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && env -u WEBCAM_CONFIG_DB -u WEBCAM_ALLOW_SAMPLE_CONFIG php bin/config_dump.php --help 2>&1'
    );

    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('Usage: php bin/config_dump.php', $output);
  }

  public function testSampleDbRequiresExplicitOptIn(): void
  {
    [$exitCode, $output] = $this->runCommand(
      'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && env -u WEBCAM_CONFIG_DB -u WEBCAM_ALLOW_SAMPLE_CONFIG php bin/config_dump.php 2>&1'
    );

    $this->assertNotSame(0, $exitCode);
    $this->assertStringContainsString('Refusing to use the repository sample DB for CLI runs', $output);
  }

  public function testExplicitSampleDbOptInWorks(): void
  {
    [$exitCode, $output] = $this->runCommand(
      'cd ' . escapeshellarg(__DIR__ . '/..')
      . ' && env -u WEBCAM_CONFIG_DB WEBCAM_ALLOW_SAMPLE_CONFIG=1 php bin/config_dump.php'
    );

    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('"slug": "testcam"', $output);
  }
}
