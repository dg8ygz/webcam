<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Service\Stream\UsbStreamWindow;

final class UsbStreamWindowTest extends TestCase
{
  public function testTenMinuteBoundariesAndReceivedPhoto(): void
  {
    $slot = 1789318800; // Exact ten-minute boundary; independent of browser TZ.
    foreach ([0, 1, 479, 480, 539] as $offset) {
      self::assertTrue(UsbStreamWindow::decision($slot + $offset, true)['ready']);
      self::assertFalse(UsbStreamWindow::decision($slot + $offset, false)['ready']);
    }
    foreach ([540, 599] as $offset) {
      self::assertFalse(UsbStreamWindow::decision($slot + $offset, true)['ready']);
      self::assertStringContainsString('In Kürze', UsbStreamWindow::decision($slot + $offset, true)['message']);
    }
    self::assertFalse(UsbStreamWindow::decision($slot + 600, false)['ready']);
    self::assertTrue(UsbStreamWindow::decision($slot + 600, true)['ready']);
    self::assertSame(300, UsbStreamWindow::decision($slot, true)['duration']);
    foreach ([480, 540, 599, 600, 601] as $offset) {
      self::assertTrue(UsbStreamWindow::decision($slot + $offset, false, true)['ready'], 'Running viewer must not be stopped by the start guard');
    }
  }

  public function testUsesExactReceivedSlotReadOnlyAndLeavesOtherCamerasAlone(): void
  {
    $root = TestTempDir::ensure() . '/usb-window-' . bin2hex(random_bytes(4));
    mkdir($root . '/test', 0700, true);
    $s = new UsbStreamWindow(new PathResolver(null, null, null, null, null, null, $root));
    $slot = 1789318800;
    self::assertFalse($s->status('test', $slot)['ready']);
    self::assertFileDoesNotExist($root . '/test/db.sqlite');
    $db = new PDO('sqlite:' . $root . '/test/db.sqlite');
    $db->exec('CREATE TABLE captures (slot_ts INTEGER, is_duplicate INTEGER)');
    $db->exec('INSERT INTO captures VALUES (' . ($slot - 600) . ',0),(' . $slot . ',1)');
    self::assertFalse($s->status('test', $slot + 5)['ready']);
    $db->exec('INSERT INTO captures VALUES (' . $slot . ',0)');
    self::assertTrue($s->status('test', $slot + 6)['ready']);
    self::assertTrue($s->status('test', $slot + 480)['ready']);
    self::assertFalse($s->status('test', $slot + 540)['ready']);
    self::assertTrue($s->status('test', $slot + 540, true)['ready']);
    self::assertTrue($s->status('test', $slot + 600, true)['ready']);
    self::assertTrue($s->status('another', $slot + 480)['ready']);
    self::assertSame(1800, $s->status('another', $slot)['duration']);
  }
}
