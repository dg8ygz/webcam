<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TrustedProxyHelperTest extends TestCase
{
  public function testUntrustedClientCannotSetForwardedPrefix(): void
  {
    $prefix = webcam_trusted_forwarded_prefix([
      'REMOTE_ADDR' => '203.0.113.10',
      'HTTP_X_FORWARDED_PREFIX' => '/spoofed',
    ], ['127.0.0.1']);

    self::assertSame('', $prefix);
  }

  public function testTrustedExactProxyMaySetSafePrefix(): void
  {
    $prefix = webcam_trusted_forwarded_prefix([
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_PREFIX' => '/testx/',
    ], ['127.0.0.1']);

    self::assertSame('/testx', $prefix);
  }

  public function testTrustedCidrMaySetSafeNestedPrefix(): void
  {
    $prefix = webcam_trusted_forwarded_prefix([
      'REMOTE_ADDR' => '10.12.3.4',
      'HTTP_X_FORWARDED_PREFIX' => 'proxy/webcam',
    ], ['10.0.0.0/8']);

    self::assertSame('/proxy/webcam', $prefix);
  }

  #[DataProvider('unsafePrefixes')]
  public function testUnsafeForwardedPrefixIsRejected(string $prefix): void
  {
    self::assertSame('', webcam_trusted_forwarded_prefix([
      'REMOTE_ADDR' => '127.0.0.1',
      'HTTP_X_FORWARDED_PREFIX' => $prefix,
    ], ['127.0.0.1']));
  }

  /** @return iterable<string,array{string}> */
  public static function unsafePrefixes(): iterable
  {
    yield 'parent traversal' => ['/test/../admin'];
    yield 'query suffix' => ['/test?admin=1'];
    yield 'backslash' => ['/test\\admin'];
    yield 'header list' => ['/test, /other'];
  }
}
