<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcam\Service\Dayvideo\DayvideoRequest;

final class DayvideoRequestTest extends TestCase
{
  public function testAcceptsOnlyUiBuildProfiles(): void
  {
    $request = DayvideoRequest::fromQuery([
      'date' => '2026-08-28',
      'fps' => '9',
      'max_width' => '1600',
      'refresh' => '1',
      'async' => 'true',
    ], new DateTimeZone('Europe/Berlin'));

    self::assertSame(9, $request->fps);
    self::assertSame(1600, $request->maxWidth);
    self::assertTrue($request->refresh);
    self::assertTrue($request->async);
    self::assertSame(300, DayvideoRequest::MAX_FRAMES);
  }

  #[DataProvider('invalidProfiles')]
  public function testRejectsValuesOutsideHardProfiles(array $query): void
  {
    $this->expectException(InvalidArgumentException::class);
    DayvideoRequest::fromQuery($query, new DateTimeZone('Europe/Berlin'));
  }

  /** @return iterable<string,array{array<string,mixed>}> */
  public static function invalidProfiles(): iterable
  {
    yield 'fps too high' => [['fps' => 60]];
    yield 'arbitrary fps' => [['fps' => 4]];
    yield 'width too high' => [['max_width' => 2000]];
    yield 'arbitrary width' => [['max_width' => 1300]];
    yield 'array injection' => [['fps' => ['5']]];
    yield 'invalid date' => [['date' => '2026-02-31']];
  }
}
