<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GlobalBestOfRenderTest extends TestCase
{
  public function testGlobalBestOfPageRendersFiltersAndCameraCards(): void
  {
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/bestof';

    ob_start();
    include __DIR__ . '/../bestof.php';
    $html = (string) ob_get_clean();

    $this->assertStringContainsString('Globales Best Of', $html);
    $this->assertStringContainsString('data-global-bestof', $html);
    $this->assertStringContainsString('name="camera"', $html);
    $this->assertStringContainsString('name="year"', $html);
    $this->assertStringContainsString('data-camera-slug=', $html);
    $this->assertStringContainsString('js/global-bestof.js', $html);
  }
}
