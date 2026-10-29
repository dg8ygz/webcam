<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Webcam\Infrastructure\Http\HttpClient;

require_once __DIR__ . '/../src/Infrastructure/Http/HttpResponse.php';
require_once __DIR__ . '/../src/Infrastructure/Http/HttpClient.php';

final class HttpClientTest extends TestCase
{
  private static mixed $server = null;
  private static string $base;

  public static function setUpBeforeClass(): void
  {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    self::assertNotFalse($socket, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    self::$base = 'http://' . $address;
    self::$server = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-S', $address, __DIR__ . '/Support/http_client_endpoint.php'], [
      0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    self::assertIsResource(self::$server);
    for ($attempt = 0; $attempt < 50; $attempt++) {
      $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
      if ($ready !== false) {
        fclose($ready);
        return;
      }
      usleep(20000);
    }
    self::tearDownAfterClass();
    self::fail('HTTP fixture did not start');
  }

  public static function tearDownAfterClass(): void
  {
    if (is_resource(self::$server)) {
      proc_terminate(self::$server);
      proc_close(self::$server);
      self::$server = null;
    }
  }

  #[DataProvider('invalidTimeouts')]
  public function testRejectsUnboundedTimeoutBeforeNetworkAccess(mixed $timeout): void
  {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('between 1 and 60');
    (new HttpClient())->get('http://127.0.0.1:1', ['timeout' => $timeout]);
  }

  public static function invalidTimeouts(): array
  {
    return [[0], [-1], [61], [PHP_INT_MAX], [null], [false], [[]], [1.5], ['60'], [INF]];
  }

  #[DataProvider('invalidUrls')]
  public function testRejectsNonHttpUrls(string $url): void
  {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('absolute http or https');
    (new HttpClient())->get($url);
  }

  public static function invalidUrls(): array
  {
    return [['file:///etc/passwd'], ['ftp://127.0.0.1:1'], ['php://memory'], ['data:text/plain,x'], ['//example.test'], ["http://example.test/\n"]];
  }

  public function testHttpRedirectReturnsFinalStatusAndHeaders(): void
  {
    $response = (new HttpClient())->get(self::$base . '/redirect', ['timeout' => 60]);
    self::assertSame(201, $response->status);
    self::assertContains('X-Fixture: bounded-http', $response->headers);
    self::assertSame('GET', json_decode($response->body, true)['method']);
  }

  public function testResponseBudgetWorksWithAndWithoutDeclaredLength(): void
  {
    foreach (['/large-known', '/large-unknown'] as $path) {
      try {
        (new HttpClient())->get(self::$base . $path, ['max_response_bytes' => 4096]);
        self::fail('Oversized HTTP response accepted');
      } catch (RuntimeException $exception) {
        self::assertSame('HTTP response exceeds resource limit', $exception->getMessage());
      }
    }
    self::assertSame(4096, strlen((new HttpClient())->get(self::$base . '/exact-budget', [
      'max_response_bytes' => 4096,
    ])->body));
  }

  public function testInvalidResponseBudgetIsRejectedBeforeNetwork(): void
  {
    foreach ([0, -1, 67108865, '100', 1.5] as $limit) {
      try {
        (new HttpClient())->get('http://127.0.0.1:1', ['max_response_bytes' => $limit]);
        self::fail('Invalid budget accepted');
      } catch (RuntimeException $exception) {
        self::assertStringContainsString('byte limit', $exception->getMessage());
      }
    }
  }

  public function testPostBodyAndAuthorizationRemainCompatible(): void
  {
    $response = (new HttpClient())->request('POST', self::$base . '/ok', [
      'body' => ['camera' => 'unit test'], 'auth' => ['username' => 'test', 'password' => 'fixture'],
    ]);
    $payload = json_decode($response->body, true);
    self::assertSame('POST', $payload['method']);
    self::assertSame('camera=unit+test', $payload['body']);
    self::assertSame('application/x-www-form-urlencoded', $payload['content_type']);
    self::assertSame('Basic ' . base64_encode('test:fixture'), $payload['authorization']);
  }

  public function testRedirectsCannotEscapeProtocolOrHopLimits(): void
  {
    foreach (['/file', '/ftp', '/loop'] as $path) {
      try {
        (new HttpClient())->get(self::$base . $path, ['timeout' => 1]);
        self::fail('Expected redirect guard to fail');
      } catch (RuntimeException $exception) {
        self::assertStringContainsString('request failed', $exception->getMessage());
      }
    }
  }

  public function testContinuousSlowBodyCannotExtendTotalTimeout(): void
  {
    $start = microtime(true);
    try {
      (new HttpClient())->get(self::$base . '/slow', ['timeout' => 1]);
      self::fail('Slow body must time out despite ongoing data');
    } catch (RuntimeException $exception) {
      self::assertStringContainsString('cURL error 28', $exception->getMessage());
      self::assertLessThan(2.5, microtime(true) - $start);
    }
  }

  public function testRejectsHeaderInjectionBeforeSendingRequest(): void
  {
    $client = new HttpClient();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Invalid value for HTTP header');
    $client->get('http://127.0.0.1:1/', [
      'headers' => ['X-Test' => "safe\r\nX-Injected: yes"],
    ]);
  }

  public function testFailureMessageRedactsCredentialsAndSecretQueryValues(): void
  {
    $client = new HttpClient();

    try {
      $client->get('http://user:password@127.0.0.1:1/image.jpg?token=secret-value', ['timeout' => 1]);
      $this->fail('Expected the unreachable endpoint to fail');
    } catch (RuntimeException $exception) {
      $this->assertStringNotContainsString('password', $exception->getMessage());
      $this->assertStringNotContainsString('secret-value', $exception->getMessage());
      $this->assertStringContainsString('[REDACTED]', $exception->getMessage());
    }
  }
}
