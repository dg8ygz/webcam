<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Stream\RtspInput;

final class RtspInputTest extends TestCase
{
  public function testFfmpegArgumentsContainOnlyProtectedPipeInput(): void
  {
    $arguments = RtspInput::ffmpegArguments();
    $serialized = implode(' ', $arguments);

    self::assertStringContainsString('-i pipe:0', $serialized);
    self::assertStringNotContainsString('rtsp://', $serialized);
    self::assertStringNotContainsString('password', $serialized);
  }

  public function testManifestCarriesTransportAndTimeoutOutsideArguments(): void
  {
    $manifest = RtspInput::manifest(
      "rtsp://user:secret@example.test/camera's-stream",
      'tcp',
      '-timeout',
      5000000
    );

    self::assertStringStartsWith("ffconcat version 1.0\n", $manifest);
    self::assertStringContainsString('option rtsp_transport tcp', $manifest);
    self::assertStringContainsString('option timeout 5000000', $manifest);
    self::assertStringContainsString("camera'\\''s-stream", $manifest);
  }

  public function testRejectsControlCharactersAndUnknownTransport(): void
  {
    $this->expectException(InvalidArgumentException::class);
    RtspInput::manifest("rtsp://user:secret@example.test/live\nnext", 'tcp');
  }

  public function testRejectsUnknownTransportSeparately(): void
  {
    $this->expectException(InvalidArgumentException::class);
    RtspInput::manifest('rtsp://user:secret@example.test/live', 'invalid transport');
  }
}
