<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Security\PublicRateLimiter;

final class PublicRateLimiterTest extends TestCase
{
  private string|false $previousDb;

  protected function setUp(): void
  {
    $this->previousDb = getenv('WEBCAM_PUBLIC_RATE_DB');
    putenv('WEBCAM_PUBLIC_RATE_DB');
  }

  protected function tearDown(): void
  {
    if ($this->previousDb === false) {
      putenv('WEBCAM_PUBLIC_RATE_DB');
    } else {
      putenv('WEBCAM_PUBLIC_RATE_DB=' . $this->previousDb);
    }
  }

  public function testClientIpBudgetCannotBeResetByChangingSession(): void
  {
    $root = TestTempDir::ensure() . '/public-rate-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    putenv('WEBCAM_PUBLIC_RATE_DB=' . $root . '/rate.sqlite');
    $limiter = new PublicRateLimiter($this->makeApp($root));
    $settings = ['limit' => 1, 'window_seconds' => 600, 'ttl_days' => 1];

    $first = $limiter->consume('dayvideo_build', 'ip:198.51.100.20', $settings);
    $sameIp = $limiter->consume('dayvideo_build', 'ip:198.51.100.20', $settings);
    $otherIp = $limiter->consume('dayvideo_build', 'ip:198.51.100.21', $settings);

    self::assertTrue($first['allowed']);
    self::assertFalse($sameIp['allowed']);
    self::assertTrue($otherIp['allowed']);
  }

  public function testTwoUnusableDatabaseCandidatesFailWithClearException(): void
  {
    $root = TestTempDir::ensure() . '/public-rate-unusable-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $blocker = $root . '/not-a-directory';
    file_put_contents($blocker, 'blocked');
    $limiter = new PublicRateLimiter($this->makeApp($root), [
      $blocker . '/primary.sqlite',
      $blocker . '/fallback.sqlite',
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('No usable public rate-limit database path');
    $limiter->consume('public', 'session:test', ['limit' => 1]);
  }

  public function testPruningDoesNotMixMinuteAndHourBuckets(): void
  {
    $root = TestTempDir::ensure() . '/rate-windows-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $path = $root . '/rate.sqlite';
    putenv('WEBCAM_PUBLIC_RATE_DB=' . $path);
    $limiter = new PublicRateLimiter($this->makeApp($root));
    $settings = ['limit' => 1, 'window_seconds' => 3600];
    self::assertTrue($limiter->consume('likes', 'ip:192.0.2.10', $settings)['allowed']);
    self::assertFalse($limiter->consume('likes', 'ip:192.0.2.10', $settings)['allowed']);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec("UPDATE meta SET value = '0' WHERE key = 'last_pruned_at'");
    $pdo->exec("INSERT INTO hits VALUES ('expired', 0, 1, 1)");
    self::assertTrue($limiter->consume('public', 'ip:192.0.2.99', ['window_seconds' => 60])['allowed']);
    self::assertFalse($limiter->consume('likes', 'ip:192.0.2.10', $settings)['allowed']);
    self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM hits WHERE sid='expired'")->fetchColumn());
    self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM hits')->fetchColumn());
  }

  public function testOldSchemaMigrationPreservesExistingBudget(): void
  {
    $root = TestTempDir::ensure() . '/rate-migrate-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    $path = $root . '/rate.sqlite';
    putenv('WEBCAM_PUBLIC_RATE_DB=' . $path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec('CREATE TABLE hits (sid TEXT, bucket INTEGER, count INTEGER, PRIMARY KEY(sid,bucket))');
    $pdo->prepare('INSERT INTO hits VALUES (?,?,?)')->execute([
      hash('sha256', "likes\0ip:192.0.2.10"), (int) floor(time() / 3600), 1,
    ]);
    $limiter = new PublicRateLimiter($this->makeApp($root));
    $limiter->consume('public', 'ip:192.0.2.99', ['window_seconds' => 60]);
    self::assertFalse($limiter->consume('likes', 'ip:192.0.2.10', ['limit' => 1, 'window_seconds' => 3600])['allowed']);
    self::assertSame(2, (int) $pdo->query('SELECT MAX(count) FROM hits')->fetchColumn());
    self::assertGreaterThan(time(), (int) $pdo->query('SELECT MIN(expires_at) FROM hits')->fetchColumn());
  }

  private function makeApp(string $root): App
  {
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db'] as $directory) {
      mkdir($root . '/' . $directory, 0775, true);
    }
    $config = $root . '/cameras.php';
    file_put_contents($config, "<?php\nreturn [];\n");
    return new App([
      'timezone' => 'Europe/Berlin',
      'camera_config_path' => $config,
      'cache_path' => $root . '/cache',
      'storage_path' => $root . '/storage',
      'archive_path' => $root . '/archive',
      'state_path' => $root . '/state',
      'log_path' => $root . '/log',
      'info_path' => $root . '/info',
      'camera_db_path' => $root . '/camera-db',
    ]);
  }
}
