<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\ReceivedCaptureSlot;

/** Real temporary SQLite, no live app, jobs, camera or configuration writes. */
final class ReceivedCaptureSlotTest extends TestCase
{
  public function testOnlyExactReceivedNonduplicateSlotCountsWithoutWrites(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'received-slot-');
    try {
      $pdo = new PDO('sqlite:' . $path);
      $pdo->exec('CREATE TABLE captures (slot_ts INTEGER, is_duplicate INTEGER)');
      $pdo->exec('INSERT INTO captures VALUES (1789252800, 0), (1789253400, 1), (1789254000, 0)');
      $pdo = null;
      $before = hash_file('sha256', $path);
      self::assertTrue(ReceivedCaptureSlot::contains($path, 1789252800));
      self::assertFalse(ReceivedCaptureSlot::contains($path, 1789252801));
      self::assertFalse(ReceivedCaptureSlot::contains($path, 1789252200));
      self::assertFalse(ReceivedCaptureSlot::contains($path, 1789253400));
      self::assertSame($before, hash_file('sha256', $path));
      self::assertFalse(ReceivedCaptureSlot::contains($path . '.missing', 1789252800));
      self::assertFileDoesNotExist($path . '.missing');
    } finally {
      unlink($path);
    }
  }

  public function testBrokenDatabaseIsNotTreatedAsMissingCapture(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'received-slot-');
    try {
      file_put_contents($path, 'broken SQLite');
      $this->expectException(PDOException::class);
      ReceivedCaptureSlot::contains($path, 1789252800);
    } finally {
      unlink($path);
    }
  }
}
