<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\ImageProcessor;
use Webcam\Service\Storage\ImageProcessingConfig;
use Webcam\Service\Admin\CameraConfigNormalizer;
require_once __DIR__ . '/../bootstrap.php';

final class ImageProcessorTest extends TestCase
{
  private function source(): GdImage
  {
    $im = imagecreatetruecolor(120, 80);
    imagefilledrectangle($im, 0, 0, 59, 79, 0xff0000);
    imagefilledrectangle($im, 60, 0, 119, 79, 0x0000ff);
    return $im;
  }
  private function region(string $type, array $extra = []): array
  {
    return $extra + ['type' => $type, 'x' => 0, 'y' => 0, 'width' => 60, 'height' => 80];
  }
  public function testOrderChangesResultAndDoesNotChangeSource(): void
  {
    $src = $this->source(); $processor = new ImageProcessor();
    $mask = $this->region('mask', ['color' => '#00ff00']);
    $copy = $this->region('copy', ['dest_x' => 60]);
    $a = $processor->apply($src, ['enabled' => true, 'regions' => [$mask, $copy]]);
    $b = $processor->apply($src, ['enabled' => true, 'regions' => [$copy, $mask]]);
    self::assertSame(0x00ff00, imagecolorat($a, 90, 40));
    self::assertSame(0xff0000, imagecolorat($b, 90, 40));
    self::assertSame(0xff0000, imagecolorat($src, 30, 40));
    foreach ([$src, $a, $b] as $im) imagedestroy($im);
  }
  public function testCropIsLastAndMaskIsOpaque(): void
  {
    $src = $this->source();
    $out = (new ImageProcessor())->apply($src, ['enabled' => true, 'crop' => ['left' => 60], 'regions' => [
      $this->region('mask', ['x' => 60, 'opacity' => 1, 'feather' => 64, 'color' => '#00ff00']),
    ]]);
    self::assertSame(60, imagesx($out));
    self::assertSame(0x00ff00, imagecolorat($out, 0, 0));
    imagedestroy($src); imagedestroy($out);
  }
  public function testDisabledIsUnchangedAndScrambleIsRepeatable(): void
  {
    $src = $this->source(); $p = new ImageProcessor();
    $disabled = $p->apply($src, ['enabled' => false, 'gamma' => 2, 'crop' => ['bottom' => 20]]);
    self::assertSame($p->jpeg($src), $p->jpeg($disabled));
    $c = ['enabled' => true, 'regions' => [$this->region('scramble', ['width' => 120])]];
    $a = $p->apply($src, $c); $b = $p->apply($src, $c);
    self::assertSame($p->jpeg($a), $p->jpeg($b));
    foreach ([$src, $disabled, $a, $b] as $im) imagedestroy($im);
  }
  public function testOutOfBoundsAndInvalidOptionsFailClosed(): void
  {
    foreach ([['gamma' => '2'], ['enabled' => 1], ['regions' => [$this->region('shell')]], ['path' => '/etc/passwd'], ['regions' => array_fill(0, 17, $this->region('blur'))]] as $c) {
      try { ImageProcessingConfig::normalize($c); self::fail('Invalid configuration accepted'); }
      catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
    }
    $src = $this->source();
    try {
      foreach ([['crop' => ['left' => 120]], ['regions' => [$this->region('mask', ['x' => 100])]], ['regions' => [$this->region('copy', ['dest_x' => 61])]]] as $c) {
        try { (new ImageProcessor())->apply($src, ['enabled' => true] + $c); self::fail('Out-of-bounds accepted'); }
        catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
      }
    } finally { imagedestroy($src); }
  }
  public function testSectionPreservesOtherCaptureSettings(): void
  {
    $normalizer = new CameraConfigNormalizer();
    $camera = ['capture' => ['interval_seconds' => 600, 'upload_key' => 'fixture-private', 'transport' => 'rtsp']];
    $c = ['enabled' => true, 'regions' => [$this->region('mask')]];
    $saved = $normalizer->normalizeSectionUpdate($camera, ['capture' => ['image_processing' => $c, 'upload_key' => 'tampered']], 'image-processing', 'testcam', []);
    self::assertSame(ImageProcessingConfig::normalize($c), $saved['capture']['image_processing']);
    unset($saved['capture']['image_processing']);
    self::assertSame($camera['capture'], $saved['capture']);
  }
  public function testKuwaharaRemovesFineTextureButPreservesLargeEdge(): void
  {
    if (!is_executable('/usr/bin/magick')) self::markTestSkipped('ImageMagick 7 not installed');
    $src = imagecreatetruecolor(120, 80);
    for ($y = 0; $y < 80; $y++) for ($x = 0; $x < 120; $x++) {
      $v = ($x < 60 ? 50 : 200) + (($x + $y) % 2 ? 12 : -12);
      imagesetpixel($src, $x, $y, ($v << 16) | ($v << 8) | $v);
    }
    $out = (new ImageProcessor())->apply($src, ['enabled' => true, 'regions' => [$this->region('edge', ['width' => 120, 'strength' => 6])]]);
    $v = static fn($im, $x, $y) => imagecolorat($im, $x, $y) & 255;
    self::assertLessThan(10, abs($v($out, 20, 20) - $v($out, 21, 20)));
    self::assertGreaterThan(100, $v($out, 61, 40) - $v($out, 58, 40));
    imagedestroy($src); imagedestroy($out);
  }
}
