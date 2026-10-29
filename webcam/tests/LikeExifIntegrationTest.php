<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Interaction\LikeService;
use Webcam\Infrastructure\Storage\CameraDataStore;

require_once __DIR__ . '/../bootstrap.php';

final class LikeExifIntegrationTest extends TestCase
{
    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wcam_like_it_' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            $this->fail('Unable to create test root: ' . $this->root);
        }

        $cameraConfig = [
            [
                'id' => 'testcam',
                'slug' => 'testcam',
                'name' => 'testcam',
                'title' => 'Test Cam',
                'short_title' => 'Test Cam',
                'description' => '',
                'location' => [
                    'latitude' => 0,
                    'longitude' => 0,
                    'map_center_offset' => 0,
                    'map_center_offset_x' => 0,
                ],
                'tags' => [],
                'mode' => 'active-capture',
                'capture' => [
                    'interval_seconds' => 600,
                ],
                'storage' => [
                    'base_path' => $this->root . '/storage/testcam',
                    'current_dir' => 'current',
                    'archive_dir' => 'archive',
                    'best_of_dir' => 'bestof',
                ],
                'status' => [],
                'stream' => ['mode' => 'none'],
                'resolutions' => [],
                'weather_commands' => [],
            ],
        ];
        $this->configFile = $this->root . '/cameras.php';
        file_put_contents($this->configFile, '<?php return ' . var_export($cameraConfig, true) . ';');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testLikeWritesExifSidecar(): void
    {
        $app = $this->makeApp();
        $camera = $app->cameraOrFail('testcam');
        $store = new CameraDataStore($app, $camera);

        $rel = 'cache/testcam/2025/11/30/20251130_123000.jpg';
        $abs = $app->resolveCameraStoragePath($camera, $rel);
        $this->assertNotNull($abs);
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0777, true) && !is_dir(dirname($abs))) {
            $this->fail('Unable to create snapshot dir: ' . dirname($abs));
        }
        file_put_contents($abs, 'JPEG');

        $captureId = $store->recordCapture([
            'slot_timestamp' => 1764502200,
            'captured_timestamp' => 1764502200,
            'relative_path' => $rel,
            'variants' => [
                ['variant' => 'la', 'relative_path' => 'cache/testcam/2025/11/30/20251130_123000_la.jpg', 'width' => 816],
            ],
            'exif' => [],
            'weather_lines' => [],
        ]);

        // Create variant file too
        $variantAbs = $app->resolveCameraStoragePath($camera, 'cache/testcam/2025/11/30/20251130_123000_la.jpg');
        $this->assertNotNull($variantAbs);
        if (!is_dir(dirname($variantAbs)) && !mkdir(dirname($variantAbs), 0777, true) && !is_dir(dirname($variantAbs))) {
            $this->fail('Unable to create variant dir: ' . dirname($variantAbs));
        }
        file_put_contents($variantAbs, 'JPEG');

        // Wärme beide Caches einmal leer auf. Danach müssen Like-Update und neue
        // Best-Of-Dateien trotzdem sichtbar werden, sonst hängt der Detail-Endpoint
        // an veralteten Performance-Caches fest.
        $this->assertSame([], $app->bestOfGallery()->topLiked($camera, 5));
        $this->assertSame([], $app->bestOfGallery()->list($camera, 5));
        sleep(1);

        $service = new LikeService($app);
        $res = $service->like($camera, 'sid-smoke', ['relative_path' => $rel]);

        $this->assertTrue($res['ok'] ?? false);
        $this->assertSame(1, $res['likes_count'] ?? null);

        $sidecarBase = preg_replace('/\.jpe?g$/i', '_ex.txt', $abs);
        $this->assertFileExists($sidecarBase);
        $this->assertStringContainsString('Likes: 1', (string) file_get_contents($sidecarBase));

        // Variant sidecar is optional; base sidecar is the primary assertion.

        // Simuliere Best-Of: copy + thumb
        $bestofDir = $app->paths()->storagePath('bestof/testcam');
        if (!is_dir($bestofDir) && !mkdir($bestofDir, 0777, true) && !is_dir($bestofDir)) {
            $this->fail('Unable to create bestof dir: ' . $bestofDir);
        }
        $bestTarget = $bestofDir . '/20251130_123000_la.jpg';
        $this->assertTrue(copy($abs, $bestTarget));
        $thumb = $bestofDir . '/20251130_123000_la_thumb.jpg';
        file_put_contents($thumb, 'THUMB');

        $listed = $app->bestOfGallery()->list($camera, 5);
        $this->assertNotEmpty($listed);
        $this->assertSame('20251130_123000_la.jpg', $listed[0]['filename'] ?? null);

        // Check BestOfGallery topLiked includes only >0 likes
        $top = $app->bestOfGallery()->topLiked($camera, 5);
        $this->assertNotEmpty($top);
        $this->assertSame(1, $top[0]['likes']);
        $this->assertStringContainsString('123000', $top[0]['filename']);
        $this->assertStringContainsString('testcam?', $top[0]['link'] ?? '');
        $this->assertStringStartsWith('./', $top[0]['link'] ?? '');

        $newerBestTarget = $bestofDir . '/20251201_123000_la.jpg';
        $this->assertTrue(copy($abs, $newerBestTarget));
        $olderYearBestTarget = $bestofDir . '/20241201_123000_la.jpg';
        $this->assertTrue(copy($abs, $olderYearBestTarget));
        $listedAfterNewFile = $app->bestOfGallery()->list($camera, 5);
        $this->assertNotEmpty($listedAfterNewFile);
        $this->assertSame('20251201_123000_la.jpg', $listedAfterNewFile[0]['filename'] ?? null);

        $pageOne = $app->bestOfGallery()->page($camera, 1, 1);
        $this->assertSame(3, $pageOne['total'] ?? null);
        $this->assertSame(3, $pageOne['total_pages'] ?? null);
        $this->assertSame('20251201_123000_la.jpg', $pageOne['items'][0]['filename'] ?? null);

        $pageTwo = $app->bestOfGallery()->page($camera, 2, 1);
        $this->assertSame(3, $pageTwo['total'] ?? null);
        $this->assertSame(3, $pageTwo['total_pages'] ?? null);
        $this->assertSame('20251130_123000_la.jpg', $pageTwo['items'][0]['filename'] ?? null);

        $years = $app->bestOfGallery()->years($camera);
        $this->assertCount(2, $years);
        $this->assertSame(2025, $years[0]['year'] ?? null);
        $this->assertSame(2, $years[0]['count'] ?? null);
        $this->assertSame(2024, $years[1]['year'] ?? null);
        $this->assertSame(1, $years[1]['count'] ?? null);

        $page2025 = $app->bestOfGallery()->page($camera, 1, 5, 2025);
        $this->assertSame(2, $page2025['total'] ?? null);
        $this->assertSame('20251201_123000_la.jpg', $page2025['items'][0]['filename'] ?? null);

        $page2024 = $app->bestOfGallery()->page($camera, 1, 5, 2024);
        $this->assertSame(1, $page2024['total'] ?? null);
        $this->assertSame('20241201_123000_la.jpg', $page2024['items'][0]['filename'] ?? null);

        $this->assertTrue(unlink($variantAbs));
        $topAfterMissingCache = $app->bestOfGallery()->topLiked($camera, 5);
        $this->assertNotEmpty($topAfterMissingCache);
        $this->assertStringContainsString('bestof/testcam/20251130_123000_la.jpg', $topAfterMissingCache[0]['url'] ?? '');
    }

    private function makeApp(): App
    {
        $cfg = [
            'timezone' => 'UTC',
            'camera_config_path' => $this->configFile,
            'storage_path' => $this->root . '/storage',
            'archive_path' => $this->root . '/storage/archive',
            'state_path' => $this->root . '/state',
            'log_path' => $this->root . '/log',
            'info_path' => $this->root . '/info',
            'cache_path' => $this->root . '/cache',
            'base_url' => '',
        ];
        return new App($cfg);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                if (!unlink($path)) {
                    fwrite(STDERR, "Unable to remove test file: {$path}\n");
                }
            }
        }
        if (!rmdir($dir)) {
            fwrite(STDERR, "Unable to remove test dir: {$dir}\n");
        }
    }
}
