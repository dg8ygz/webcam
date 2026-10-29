<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\ManualCaptureQueue;

final class ManualCaptureQueueTest extends TestCase
{
  public function testCameraScopeCooldownExpiryAndSingleConsumption(): void
  {
    $directory = TestTempDir::ensure() . '/manual-' . bin2hex(random_bytes(5));
    $queue = new ManualCaptureQueue($directory);
    $now = 1800000000;
    $queue->request('camera-one', $now);
    self::assertTrue($queue->pending('camera-one', $now));
    self::assertFalse($queue->pending('camera-two', $now));
    $queue->request('camera-two', $now);
    try {
      $queue->request('camera-one', $now + 1);
      self::fail('Duplicate accepted');
    } catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
    self::assertFalse($queue->consume('camera-one', static fn(): bool => false, $now + 2));
    self::assertTrue($queue->pending('camera-one', $now + 2));
    $calls = 0;
    $dispatch = static function (int $serial) use (&$calls, $now): bool {
      self::assertSame($now + 3, $serial);
      ++$calls;
      return true;
    };
    self::assertTrue($queue->consume('camera-one', $dispatch, $now + 3));
    self::assertFalse($queue->consume('camera-one', $dispatch, $now + 3));
    self::assertSame(1, $calls);
    self::assertTrue($queue->pending('camera-two', $now + 3));
    try {
      $queue->request('camera-one', $now + 29);
      self::fail('Cooldown missing');
    } catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
    $queue->request('camera-one', $now + 30);
    self::assertFalse($queue->consume('camera-two', $dispatch, $now + 300));
    $queue->request('camera-two', $now + 300);
    self::assertTrue($queue->pending('camera-two', $now + 300));
  }

  public function testBusyCorruptAndInvalidStateFailClosed(): void
  {
    $directory = TestTempDir::ensure() . '/manual-' . bin2hex(random_bytes(5));
    $queue = new ManualCaptureQueue($directory);
    $queue->request('camera');
    $lock = fopen($directory . '/camera.json', 'c+');
    flock($lock, LOCK_EX);
    try {
      $queue->consume('camera', static function (): bool { self::fail('Busy callback ran'); });
      self::fail('Busy accepted');
    } catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    file_put_contents($directory . '/camera.json', 'broken');
    foreach (['camera', '../outside', ''] as $slug) {
      try { $queue->request($slug); self::fail('Invalid accepted'); }
      catch (RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
    }
  }
}
