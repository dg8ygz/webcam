<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;
use Webcam\Infrastructure\Filesystem\PathResolver;

/**
 * Sichert die zwei historisch erlaubten Bedeutungen von storage.base_path ab.
 *
 * Die Tests verwenden absichtlich keine echten Projektverzeichnisse. Dadurch
 * prüfen sie nur die Pfadregeln und können weder DEV- noch Archivdaten ändern.
 */
final class CameraStoragePathResolverTest extends TestCase
{
    private CameraStoragePathResolver $resolver;

    protected function setUp(): void
    {
        $paths = new PathResolver(
            '/srv/webcam/storage',
            '/srv/webcam/archive',
            '/srv/webcam/state',
            '/srv/webcam/log',
            '/srv/webcam/info',
            '/srv/webcam/cache',
        );
        $this->resolver = new CameraStoragePathResolver($paths);
    }

    public function testRelativeConfiguredPathIsAnchoredAtProjectRoot(): void
    {
        $camera = $this->camera('storage/demo');

        $this->assertSame('/srv/webcam/storage/demo', $this->resolver->configuredPath($camera));
        $this->assertSame('/srv/webcam/storage', $this->resolver->baseRoot($camera));
    }

    public function testAbsolutePathWithCameraSlugIsReducedToSharedRoot(): void
    {
        $camera = $this->camera('/mnt/cameras/demo/');

        $this->assertSame('/mnt/cameras/demo', $this->resolver->configuredPath($camera));
        $this->assertSame('/mnt/cameras', $this->resolver->baseRoot($camera));
    }

    public function testAbsoluteSharedRootWithoutSlugIsKept(): void
    {
        $camera = $this->camera('/mnt/cameras');

        $this->assertSame('/mnt/cameras', $this->resolver->baseRoot($camera));
        $this->assertSame(
            '/mnt/cameras/Archive/demo/2026/09/01/image.jpg',
            $this->resolver->resolveStorageRelative($camera, 'Archive/demo/2026/09/01/image.jpg'),
        );
    }

    public function testCacheAndUnsafeRelativePathsAreHandledExplicitly(): void
    {
        $camera = $this->camera('storage/demo');

        $this->assertSame(
            '/srv/webcam/cache/demo/current.jpg',
            $this->resolver->resolveStorageRelative($camera, 'cache/demo/current.jpg'),
        );
        $this->assertNull($this->resolver->resolveStorageRelative($camera, '../etc/passwd'));
        $this->assertNull($this->resolver->resolveStorageRelative($camera, 'Current/../../etc/passwd'));
        $this->assertNull($this->resolver->resolveStorageRelative($camera, ''));
    }

    public function testMissingCacheRootKeepsLegacyStorageFallback(): void
    {
        $paths = new PathResolver(
            '/srv/webcam/storage',
            '/srv/webcam/archive',
            '/srv/webcam/state',
            '/srv/webcam/log',
        );
        $resolver = new CameraStoragePathResolver($paths);

        $this->assertSame(
            '/srv/webcam/storage/cache/demo/current.jpg',
            $resolver->resolveStorageRelative($this->camera('storage/demo'), 'cache/demo/current.jpg'),
        );
    }

    private function camera(string $basePath): Camera
    {
        return new Camera([
            'id' => 'demo',
            'slug' => 'demo',
            'name' => 'Demo',
            'title' => 'Demo',
            'location' => [],
            'mode' => 'active-capture',
            'capture' => [],
            'storage' => ['base_path' => $basePath],
            'status' => [],
            'stream' => ['mode' => 'none'],
        ]);
    }
}
