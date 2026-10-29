<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcam\Service\Admin\CameraConfigNormalizer;
use Webcam\Service\Status\WatchdogConfigNormalizer;

/** Save boundaries are identical for full-camera and watchdog-tab requests. */
final class WatchdogConfigNormalizerTest extends TestCase
{
  #[DataProvider('invalidConfigurations')]
  public function testRejectsInvalidConfigurationsAtEverySaveBoundary(mixed $watchdog): void
  {
    $admin = new CameraConfigNormalizer();
    $paths = [
      fn() => (new WatchdogConfigNormalizer())->normalize($watchdog),
      fn() => $admin->normalizePayload(['status' => ['watchdog' => $watchdog]], 'demo', []),
      fn() => $admin->applySectionUpdate([], ['status' => ['watchdog' => $watchdog]], 'watchdog'),
      fn() => $admin->applySectionUpdate([], ['watchdog' => $watchdog], 'watchdog'),
    ];
    foreach ($paths as $path) {
      try {
        $path();
        self::fail('Invalid watchdog configuration was accepted');
      } catch (InvalidArgumentException $exception) {
        self::assertStringContainsString('watchdog', $exception->getMessage());
      }
    }
  }

  public static function invalidConfigurations(): iterable
  {
    yield 'null' => [null];
    yield 'boolean' => [false];
    yield 'list' => [[['enabled' => true]]];
    yield 'unknown root field' => [['enable' => true]];
    yield 'nonboolean enabled' => [['enabled' => 'false']];
    yield 'threshold zero' => [['max_consecutive_failures' => 0]];
    yield 'cooldown null' => [['cooldown' => null]];
    yield 'cooldown unknown' => [['cooldown' => ['seconds' => 10]]];
    yield 'cooldown negative' => [['cooldown' => ['min_seconds_after_reset' => -1]]];
    yield 'sequence null' => [['reset_sequence' => null]];
    yield 'sequence associative' => [['reset_sequence' => ['step' => ['type' => 'delay']]]];
    yield '21 steps' => [['reset_sequence' => array_fill(0, 21, ['type' => 'delay'])]];
    foreach ([null, false, 'delay', [], ['type' => 'http'], ['type' => ['delay']], ['type' => 'delay', 'typo' => 1], ['type' => 'http-get'], ['type' => 'http-get', 'url' => []], ['type' => 'shell'], ['type' => 'shell', 'command' => "echo\0bad"], ['type' => 'shell', 'command' => 'true', 'url' => 'https://example.test'], ['type' => 'shell', 'command' => 'true', 'timeout' => 1, 'timeout_seconds' => 1]] as $index => $step) {
      yield "invalid step $index" => [['reset_sequence' => [$step]]];
    }
    foreach (['file:///etc/passwd', 'php://filter/resource=test', 'data:text/plain,test', 'ftp://example.test/', '//example.test/', 'https:/broken', 'http://', "http://example.test/\r\nx", 'http://example.test\\evil/'] as $url) {
      yield "invalid URL $url" => [['reset_sequence' => [['type' => 'http-get', 'url' => $url]]]];
    }
    foreach ([null, false, [], '', '1.5', 1.5, 0, -1, 301, '999999999999999999999999', '1e2', INF, NAN] as $index => $value) {
      yield "invalid delay $index" => [['reset_sequence' => [['type' => 'delay', 'seconds' => $value]]]];
      yield "invalid HTTP timeout $index" => [['reset_sequence' => [['type' => 'http-get', 'url' => 'https://example.test', 'timeout' => $value]]]];
      yield "invalid shell timeout $index" => [['reset_sequence' => [['type' => 'shell', 'command' => 'true', 'timeout_seconds' => $value]]]];
    }
    yield 'HTTP timeout 61' => [['reset_sequence' => [['type' => 'http-get', 'url' => 'https://example.test', 'timeout' => 61]]]];
    yield 'unknown HTTP timeout field' => [['reset_sequence' => [['type' => 'http-get', 'url' => 'https://example.test', 'timeout_seconds' => 5]]]];
  }

  public function testAcceptsDocumentedBoundariesAndPreservesCooldown(): void
  {
    $sequence = array_fill(0, 17, ['type' => 'delay', 'seconds' => '300']);
    $sequence[] = ['type' => 'http-get', 'url' => 'HTTPS://camera.example.test/reset', 'timeout' => '60'];
    $sequence[] = ['type' => 'shell', 'command' => 'true', 'timeout_seconds' => '300'];
    $sequence[] = ['type' => 'shell', 'command' => 'true', 'timeout' => '300'];
    $input = ['enabled' => false, 'max_consecutive_failures' => '3', 'reset_sequence' => $sequence, 'cooldown' => ['min_seconds_after_reset' => '1200', 'min_successes_after_reset' => '0']];
    $normalized = (new WatchdogConfigNormalizer())->normalize($input);
    self::assertCount(20, $normalized['reset_sequence']);
    self::assertSame(300, $normalized['reset_sequence'][0]['seconds']);
    self::assertSame(60, $normalized['reset_sequence'][17]['timeout']);
    self::assertSame(300, $normalized['reset_sequence'][18]['timeout_seconds']);
    self::assertSame(300, $normalized['reset_sequence'][19]['timeout']);
    self::assertSame(1200, $normalized['cooldown']['min_seconds_after_reset']);
    self::assertSame(0, $normalized['cooldown']['min_successes_after_reset']);
    self::assertSame($normalized, (new WatchdogConfigNormalizer())->normalize($normalized));
  }

  public function testExistingOptionalDefaultsRemainCompatible(): void
  {
    $normalizer = new WatchdogConfigNormalizer();
    self::assertSame([], $normalizer->normalize([]));
    $result = $normalizer->normalize(['reset_sequence' => [
      ['type' => 'http-get', 'url' => 'http://camera.example.test/reset'],
      ['type' => 'delay'],
      ['type' => 'shell', 'command' => 'true'],
    ]]);
    self::assertSame(5, $result['reset_sequence'][0]['timeout']);
    self::assertSame(1, $result['reset_sequence'][1]['seconds']);
    self::assertArrayNotHasKey('timeout_seconds', $result['reset_sequence'][2]);
  }
}
