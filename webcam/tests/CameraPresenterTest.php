<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;

final class CameraPresenterTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = require __DIR__ . '/../bootstrap.php';
    }

    public function testResolutionUrlsDoNotContainApiPrefix(): void
    {
        foreach ($this->app->cameras()->all() as $camera) {
            $data = $this->app->cameraPresenter()->overviewData($camera);
            $this->assertArrayHasKey('resolutions_urls', $data);
            foreach ($data['resolutions_urls'] as $url) {
                $this->assertStringNotContainsString('/api/public/cameras', $url);
                $this->assertStringNotContainsString('/api/', $url);
                $this->assertNotEmpty($url);
            }
            $this->assertNotEmpty($data['current_image_url']);
            $this->assertNotEmpty($data['full_image_url']);
        }
    }

    public function testPublicBaseUrlStripsApiSuffix(): void
    {
        foreach ($this->app->cameras()->all() as $camera) {
            $base = $this->app->cameraPresenter()->publicBaseUrl($camera);
            $this->assertStringNotContainsString('/api', $base);
        }
    }
}
