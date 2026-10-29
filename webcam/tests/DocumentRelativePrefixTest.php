<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentRelativePrefixTest extends TestCase
{
  #[DataProvider('routePrefixes')]
  public function testCalculatesPrefixForMountAndRouteDepth(
    string $baseUrl,
    string $requestUri,
    string $expected
  ): void {
    self::assertSame($expected, webcam_document_relative_prefix($baseUrl, $requestUri));
  }

  /** @return iterable<string,array{string,string,string}> */
  public static function routePrefixes(): iterable
  {
    yield 'overview' => ['webcam', '/webcam/', './'];
    yield 'camera route' => ['webcam', '/webcam/camera/bielefeld', '../'];
    yield 'camera route trailing slash' => ['webcam', '/webcam/camera/bielefeld/', '../../'];
    yield 'legacy camera php' => ['webcam', '/webcam/camera.php?cam=bielefeld', './'];
    yield 'best of route' => ['webcam', '/webcam/bestof', './'];
    yield 'best of trailing slash' => ['webcam', '/webcam/bestof/', '../'];
    yield 'alternate mount' => ['testx', '/testx/camera/bielefeld-nord', '../'];
    yield 'root installation' => ['', '/camera/bielefeld', '../'];
  }
}
