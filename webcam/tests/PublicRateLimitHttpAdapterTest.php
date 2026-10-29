<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Security\PublicRateLimiter;

require_once __DIR__ . '/../include/session_rate_limit.php';

/**
 * Prüft die Sicherheitsgrenze zwischen HTTP-Request und SQLite-Limiter.
 *
 * Die Service-Tests belegen bereits die Zählung eines gegebenen Subjekts.
 * Diese Tests ergänzen den zuvor fehlenden Adaptervertrag: Unvertrauenswürdige
 * Forwarded-Header werden ignoriert und rotierende Session-IDs erzeugen weder
 * ein frisches Budget noch zusätzliche SQLite-Schlüssel.
 */
final class PublicRateLimitHttpAdapterTest extends TestCase
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
      return;
    }
    putenv('WEBCAM_PUBLIC_RATE_DB=' . $this->previousDb);
  }

  public function testRotatingSessionHeaderKeepsOneIpBudgetAndOneDatabaseRow(): void
  {
    $root = $this->makeRoot('rotation');
    $database = $root . '/rate.sqlite';
    putenv('WEBCAM_PUBLIC_RATE_DB=' . $database);
    $app = $this->makeApp($root, ['127.0.0.1', '10.0.0.0/8']);
    $limiter = new PublicRateLimiter($app);
    $settings = ['limit' => 1, 'window_seconds' => 600, 'ttl_days' => 1];

    $firstSubject = resolve_public_rate_limit_subject($app, [
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.5',
      'HTTP_X_SESSION_ID' => str_repeat('a', 32),
    ]);
    $rotatedSubject = resolve_public_rate_limit_subject($app, [
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.5',
      'HTTP_X_SESSION_ID' => str_repeat('b', 32),
    ]);

    self::assertSame('ip:198.51.100.20', $firstSubject);
    self::assertSame($firstSubject, $rotatedSubject);
    self::assertTrue($limiter->consume('public', $firstSubject, $settings)['allowed']);
    self::assertFalse($limiter->consume('public', $rotatedSubject, $settings)['allowed']);

    $pdo = new PDO('sqlite:' . $database);
    self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM hits')->fetchColumn());
  }

  public function testUntrustedRemoteCannotSpoofItsBudgetWithForwardedHeader(): void
  {
    $root = $this->makeRoot('untrusted');
    $app = $this->makeApp($root, ['127.0.0.1']);

    $subject = resolve_public_rate_limit_subject($app, [
      'REMOTE_ADDR' => '203.0.113.40',
      'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
      'HTTP_X_SESSION_ID' => str_repeat('c', 32),
    ]);

    self::assertSame('ip:203.0.113.40', $subject);
  }

  public function testMissingClientAddressFailsClosed(): void
  {
    $root = $this->makeRoot('missing');
    $app = $this->makeApp($root, ['127.0.0.1']);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Unable to resolve public rate-limit client IP');

    resolve_public_rate_limit_subject($app, [
      'HTTP_X_SESSION_ID' => str_repeat('d', 32),
    ]);
  }

  private function makeRoot(string $suffix): string
  {
    $root = TestTempDir::ensure() . '/public-rate-adapter-' . $suffix . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    return $root;
  }

  /** @param list<string> $trustedProxies */
  private function makeApp(string $root, array $trustedProxies): App
  {
    foreach (['cache', 'storage', 'archive', 'state', 'log', 'info', 'camera-db'] as $directory) {
      mkdir($root . '/' . $directory, 0775, true);
    }
    $config = $root . '/cameras.php';
    file_put_contents($config, "<?php\nreturn [];\n");

    return new App([
      'timezone' => 'Europe/Berlin',
      'trusted_proxies' => $trustedProxies,
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
