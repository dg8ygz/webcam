<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\FocusQueue;

final class FocusQueueTest extends TestCase
{
  public function testScopeCapabilityExpiryAndAtMostOnce(): void
  {
    $queue = new FocusQueue(TestTempDir::ensure() . '/focus-' . bin2hex(random_bytes(5)));
    $now = 1800000000;
    foreach (FocusQueue::MODES as $i => $mode) {
      $slug = 'camera-' . $i;
      self::assertNull($queue->poll($slug, FocusQueue::PROTOCOL, $now));
      $queue->request($slug, $mode, $now);
      try { $queue->request($slug, '-', $now + 3); self::fail('Pending overwritten'); }
      catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
      self::assertNull($queue->poll('other', FocusQueue::PROTOCOL, $now));
      self::assertNull($queue->poll($slug, 'none', $now + 1));
      self::assertSame("focus\n1800000700\n{$mode}\n", $queue->poll($slug, FocusQueue::PROTOCOL, $now + 700));
      self::assertNull($queue->poll($slug, FocusQueue::PROTOCOL, $now + 701));
    }
    $queue->poll('expires', FocusQueue::PROTOCOL, $now);
    $queue->request('expires', 'auto', $now);
    self::assertNull($queue->poll('expires', FocusQueue::PROTOCOL, $now + 900));
    foreach (['unknown', 'camera-0'] as $slug) {
      try { $queue->request($slug, 'auto', $now + 4000); self::fail('Missing/stale capability accepted'); }
      catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
    }
  }

  public function testInvalidBusyAndCorruptFailClosed(): void
  {
    $dir = TestTempDir::ensure() . '/focus-' . bin2hex(random_bytes(5));
    $queue = new FocusQueue($dir);
    $queue->poll('camera', FocusQueue::PROTOCOL);
    foreach (['', '+++', 'auto; reboot', "auto\nwebcam_capture", '$(id)'] as $mode) {
      try { $queue->request('camera', $mode); self::fail('Invalid mode accepted'); }
      catch (RuntimeException $error) { self::assertSame(400, $error->getCode()); }
    }
    $lock = fopen($dir . '/camera.json', 'c+');
    flock($lock, LOCK_EX);
    try { $queue->request('camera', '+'); self::fail('Busy accepted'); }
    catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    file_put_contents($dir . '/camera.json', 'broken');
    foreach (['camera', '../outside', ''] as $slug) {
      try { $queue->poll($slug, FocusQueue::PROTOCOL); self::fail('Invalid state accepted'); }
      catch (RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
    }
  }
}
