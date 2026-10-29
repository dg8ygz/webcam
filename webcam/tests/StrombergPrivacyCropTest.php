<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\StrombergPrivacyCrop as Crop;
require_once __DIR__ . '/../bootstrap.php';

final class StrombergPrivacyCropTest extends TestCase
{
  public function testBoundaryAndOtherCameras(): void
  {
    $ts = (new DateTimeImmutable('2026-03-30 22:20:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    self::assertSame(Crop::FIRST_SLOT, $ts);
    self::assertFalse(Crop::required('stromberg', $ts - 1));
    self::assertTrue(Crop::required('stromberg', $ts));
    self::assertFalse(Crop::required('steinhagen', $ts));
  }
  public function testBottomPixelsRemovedAndAlreadyCroppedPreserved(): void
  {
    $input=imagecreatetruecolor(600,400);
    imagefilledrectangle($input,0,0,599,369,0x0000ff);
    imagefilledrectangle($input,0,370,599,399,0xff0000);
    $cropped=Crop::apply($input);
    self::assertSame(370,imagesy($cropped));
    self::assertSame(0x0000ff,imagecolorat($cropped,300,369));
    $again=Crop::apply($cropped);
    self::assertSame(Crop::jpeg($cropped),Crop::jpeg($again));
    foreach ([$input,$cropped,$again] as $im) imagedestroy($im);
    self::assertSame(503,Crop::height(816,544));
    self::assertSame(459,Crop::height(816,459));
    self::assertSame(70,Crop::height(114,76));
    self::assertSame(3700,Crop::height(6000,4000));
  }
  public function testConfiguredBottomCropCountsMandatoryStripOnlyOnce(): void
  {
    $input=imagecreatetruecolor(600,400);
    $safe=Crop::apply($input);
    $config=\Webcam\Service\Storage\ImageProcessingConfig::normalize([
      'enabled'=>true,'crop'=>['bottom'=>30],
    ]);
    $processor=new \Webcam\Service\Storage\ImageProcessor();
    $result=$processor->apply($safe,Crop::remainingProcessing($config,600));
    self::assertSame(370,imagesy($result));
    $config['crop']['bottom']=50;
    $more=$processor->apply($safe,Crop::remainingProcessing($config,600));
    self::assertSame(350,imagesy($more));
    foreach ([$input,$safe,$result,$more] as $image) imagedestroy($image);
  }

  public function testUnexpectedGeometryIsNotPublished(): void
  {
    $this->expectException(RuntimeException::class);
    Crop::height(6000,3900);
  }
}
