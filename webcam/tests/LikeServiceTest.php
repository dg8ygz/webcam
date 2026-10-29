<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Interaction\LikeService;

require_once __DIR__ . '/Support/TempSqlite.php';
require_once __DIR__ . '/../bootstrap.php';

final class LikeServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Silence notices about missing env token in tests
        putenv('WEBCAM_ADMIN_API_TOKEN=test-token');
    }

    public function testRejectsUnknownCapture(): void
    {
        $app = $this->fakeApp();
        $camera = $app->cameras()->find('testcam');
        (new CameraDataStore($app, $camera))->ensureWritableSchema();

        $service = new LikeService($app);
        $res = $service->like($camera, 'sid', ['relative_path' => 'does/not/exist.jpg']);
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['code']);
    }

    private function fakeApp(): \Webcam\App
    {
        // use real app/bootstrap; DB paths will still point to existing config
        return require __DIR__ . '/../bootstrap.php';
    }
}
