<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class HttpServerIsolationTest extends TestCase
{
  public function testReadinessRequiresIdentityNotJustHttp200(): void
  {
    $ping = new ReflectionMethod(HttpServer::class, 'ping');
    self::assertTrue($ping->invoke(null, HttpServer::baseUrl()));
    $nonce = new ReflectionProperty(HttpServer::class, 'nonce');
    $own = $nonce->getValue();
    try {
      $nonce->setValue(null, 'another-process-identity');
      self::assertFalse($ping->invoke(null, HttpServer::baseUrl()));
    } finally {
      $nonce->setValue(null, $own);
    }
    self::assertNotSame('http://127.0.0.1:8082', HttpServer::baseUrl());
  }
}
