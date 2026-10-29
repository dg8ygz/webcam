<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Webcam\Service\Maintenance\CameraTunnelLease;

final class CameraTunnelLeaseTest extends TestCase
{
  public function testScopeFixedExpiryCloseAndCapability(): void
  {
    $q = new CameraTunnelLease(TestTempDir::ensure() . '/tunnel-' . bin2hex(random_bytes(4)));
    $now = 1800000000;
    self::assertFalse($q->status('cam', $now)['available']);
    self::assertNull($q->poll('cam', 'none', 'closed', $now));
    try { $q->request('cam', 'open', $now); self::fail(); } catch (RuntimeException $e) { self::assertSame(409, $e->getCode()); }
    self::assertSame('closed', $q->poll('cam', $q::PROTOCOL, 'closed', $now));
    $q->request('cam', 'open', $now);
    $lease = $q->poll('cam', $q::PROTOCOL, 'running', $now + 5);
    self::assertMatchesRegularExpression('/^[a-f0-9]{32}:1800001800$/D', $lease);
    self::assertSame($lease, $q->poll('cam', $q::PROTOCOL, 'running', $now + 1000));
    self::assertSame('closed', $q->poll('other', $q::PROTOCOL, 'closed', $now));
    try { $q->request('cam', 'open', $now + 1000); self::fail(); } catch (RuntimeException $e) { self::assertSame(409, $e->getCode()); }
    self::assertSame('closed', $q->poll('cam', $q::PROTOCOL, 'closed', $now + 1800));
    $q->request('cam', 'open', $now + 1801);
    $q->request('cam', 'close', $now + 1802);
    self::assertSame('closed', $q->poll('cam', $q::PROTOCOL, 'closed', $now + 1803));
    self::assertFalse($q->status('cam', $now + 4000)['available']);
    foreach (['shell', 'open;id', ''] as $action) {
      try { $q->request('cam', $action, $now); self::fail(); } catch (RuntimeException $e) { self::assertSame(400, $e->getCode()); }
    }
  }
}
