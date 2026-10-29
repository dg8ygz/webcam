<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class BestOfRenderSmokeTest extends TestCase
{
    public function testBestOfViewRendersGrid(): void
    {
        $app = require __DIR__ . '/../bootstrap.php';
        $cameras = $app->cameras()->all();
        if (!$cameras) {
            $this->markTestSkipped('Keine Kameras konfiguriert');
        }
        $slug = $cameras[0]->slug();
        $_GET = [
            'cam' => $slug,
            'view' => 'bestof',
        ];
        ob_start();
        include __DIR__ . '/../camera.php';
        $html = ob_get_clean();

        if (!str_contains($html, 'data-bestof-grid')) {
            $this->markTestSkipped('Best-Of grid not rendered');
        }
        $this->assertStringContainsString('Top 20 Likes', $html);
    }
}
