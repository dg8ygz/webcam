<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\SlotSnapshotEndpoint;

/** Reine URL-/Zeitvertraege: keine Live-Endpunkte, DBs oder Bilder im Test. */
final class SlotSnapshotEndpointTest extends TestCase
{
  public function testResolvesExactRequestedSlotInSourceTimezone(): void
  {
    $slot = strtotime('2026-09-11 22:00:00 UTC');
    $source = new SlotSnapshotEndpoint(
      'http://127.0.0.1/webcam2/cache/demo/{slot_path}/{slot_stamp}.jpg?slot={slot_unix}',
      $slot, 600, $slot + 211, 'Europe/Berlin'
    );
    self::assertTrue($source->templated);
    self::assertSame($slot, $source->slot);
    self::assertSame('http://127.0.0.1/webcam2/cache/demo/2026/09/12/20260912_000000.jpg?slot=' . $slot, $source->url);
    self::assertTrue($source->isAwaitingPublication(404, $slot + 599));
    self::assertFalse($source->isAwaitingPublication(404, $slot + 600));
    self::assertFalse($source->isAwaitingPublication(404, $slot - 1));
    foreach ([200, 204, 301, 401, 403, 410, 429, 500, 503] as $status) {
      self::assertFalse($source->isAwaitingPublication($status, $slot + 20));
    }
  }

  public function testFirstRunUsesCurrentSlotNeverTheFutureSlot(): void
  {
    $source = new SlotSnapshotEndpoint('https://example.test/{slot_unix}.jpg', null, 600, 1199, 'UTC');
    self::assertSame(600, $source->slot);
    self::assertSame('https://example.test/600.jpg', $source->url);
  }

  public function testPlainUrlKeepsPreviousBehaviourAndDoesNotHide404(): void
  {
    $source = new SlotSnapshotEndpoint('https://example.test/current.jpg', null, 600, 1199, 'UTC');
    self::assertFalse($source->templated);
    self::assertNull($source->slot);
    self::assertSame('https://example.test/current.jpg', $source->url);
    self::assertFalse($source->isAwaitingPublication(404, 1199));
  }

  #[DataProvider('invalidEndpoints')]
  public function testInvalidTemplatesFailClosed(string $endpoint): void
  {
    $this->expectException(RuntimeException::class);
    new SlotSnapshotEndpoint($endpoint, 600, 600, 620, 'UTC');
  }

  public static function invalidEndpoints(): iterable
  {
    foreach ([
      'file:///tmp/{slot_stamp}.jpg',
      'https://{slot_stamp}.test/image.jpg',
      'https://user:{slot_stamp}@example.test/image.jpg',
      'https://example.test/image.jpg#{slot_stamp}',
      'https://example.test/{unknown}.jpg',
      'https://example.test/{slot_stamp.jpg',
    ] as $endpoint) {
      yield [$endpoint];
    }
  }
}
