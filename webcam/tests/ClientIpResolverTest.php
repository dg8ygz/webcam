<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Http\ClientIpResolver;

final class ClientIpResolverTest extends TestCase
{
  public function testLongForwardedChainUsesTheActualRightmostClient(): void
  {
    $resolver = new ClientIpResolver(['192.0.2.2']);
    foreach (['198.51.100.66', '198.51.100.67'] as $forged) {
      $result = $resolver->resolve([
        'REMOTE_ADDR' => '192.0.2.2',
        'HTTP_X_FORWARDED_FOR' => str_pad('203.0.113.5,', 287, ' ') . $forged . ', 192.0.2.10',
      ]);
      self::assertSame('192.0.2.10', $result['ip']);
      self::assertSame('x_forwarded_for', $result['ip_source']);
      self::assertSame(300, strlen($result['forwarded_for']));
    }
  }

  public function testInvalidOrExcessiveHeaderFallsBackToRemoteWithoutRepairingIt(): void
  {
    $resolver = new ClientIpResolver(['192.0.2.2']);
    foreach ([str_repeat('1.1.1.1,', 1100) . '192.0.2.10',
      str_pad('198.51.100.66,', 310) . 'invalid', "192.0.2.\r10", "192.0.2.\0" . '10', "192.0.2.10\v"] as $header) {
      $result = $resolver->resolve(['REMOTE_ADDR' => '192.0.2.2', 'HTTP_X_FORWARDED_FOR' => $header]);
      self::assertSame('192.0.2.2', $result['ip']);
      self::assertSame('remote_addr', $result['ip_source']);
    }
  }

  public function testUntrustedSenderCannotSupplyIdentityAndTrustedChainHandlesIpv6(): void
  {
    $resolver = new ClientIpResolver(['192.0.2.2', '2001:db8::/32']);
    self::assertSame('192.0.2.10', $resolver->resolve([
      'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
    ])['ip']);
    self::assertSame('192.0.2.10', $resolver->resolve([
      'REMOTE_ADDR' => '192.0.2.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66,192.0.2.10,2001:db8::1',
    ])['ip']);
  }
}
