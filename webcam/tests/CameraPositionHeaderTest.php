<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Capture\CameraPositionHeader;

final class CameraPositionHeaderTest extends TestCase
{
  public function testMissingCoordinatesAreNotZeroDefaults(): void
  {
    $data=json_decode(base64_decode(CameraPositionHeader::encode([], 'Europe/Berlin')),true);
    self::assertNull($data['latitude']);
    self::assertNull($data['longitude']);
    self::assertSame('Europe/Berlin',$data['timezone']);
    $data=json_decode(base64_decode(CameraPositionHeader::encode(['latitude'=>0,'longitude'=>0], 'UTC')),true);
    self::assertSame(0,$data['latitude']);
    self::assertSame(0,$data['longitude']);
    $data=json_decode(base64_decode(CameraPositionHeader::encode(['latitude'=>INF,'longitude'=>181], 'UTC')),true);
    self::assertNull($data['latitude']);
    self::assertNull($data['longitude']);
  }
}
