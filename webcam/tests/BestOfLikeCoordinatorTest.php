<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Interaction\BestOfLikeCoordinator;
use Webcam\Service\Interaction\BestOfSourceResolver;

require_once __DIR__ . '/../bootstrap.php';

final class BestOfLikeCoordinatorTest extends TestCase
{
  private string $root;
  private App $app;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/wcam-bestof-coordinator-' . bin2hex(random_bytes(6));
    $configPath = $this->root . '/cameras.php';
    $this->assertTrue(mkdir($this->root, 0777, true) || is_dir($this->root));
    file_put_contents($configPath, '<?php return ' . var_export([$this->cameraConfig()], true) . ';');
    $this->app = new App([
      'timezone' => 'UTC',
      'camera_config_path' => $configPath,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'base_url' => '',
    ]);
  }

  protected function tearDown(): void
  {
    $this->removeTree($this->root);
  }

  public function testSameSecondPublicationCannotReuseListAndOldCachesExpire(): void
  {
    $camera = $this->app->cameraOrFail('testcam');
    $cache = new \Webcam\Service\Storage\BestOfGalleryCache($this->app);
    $catalog = new \Webcam\Service\Storage\BestOfFileCatalog($this->app, $cache);
    $dir = $this->root . '/bestof'; mkdir($dir);
    file_put_contents($dir.'/20261008_120000_la.jpg','fixture');
    self::assertCount(1, $catalog->orderedBasenames($camera, $dir));
    file_put_contents($dir.'/20261008_121000_la.jpg','fixture');
    self::assertCount(2, $catalog->orderedBasenames($camera, $dir));
    // External imports/restored directory timestamps still have bounded staleness.
    $cache->writeBasenames($camera, 'stable-fixture', ['old.jpg']);
    $file = $this->app->paths()->statePath('bestof-gallery/testcam/list.json');
    $payload = json_decode(file_get_contents($file),true);
    $payload['generated_at'] = date(DATE_ATOM,time()-61);
    file_put_contents($file,json_encode($payload));
    self::assertNull($cache->loadBasenames($camera,'stable-fixture'));
  }

  public function testKeepsPublishedImageAfterSuccessfulLike(): void
  {
    [$source, $target] = $this->paths();
    $coordinator = new BestOfLikeCoordinator($this->app);

    $publication = $coordinator->execute(
      $this->app->cameraOrFail('testcam'),
      $source,
      static fn(): array => ['ok' => true, 'likes_count' => 1]
    );

    $this->assertTrue($publication['created']);
    $this->assertFileExists($target);
    $this->assertSame('JPEG-DATA', file_get_contents($target));
    $this->assertSame(0664, fileperms($target) & 0777);
  }

  public function testRemovesNewImageWhenLikeIsRejected(): void
  {
    [$source, $target] = $this->paths();
    $coordinator = new BestOfLikeCoordinator($this->app);

    $publication = $coordinator->execute(
      $this->app->cameraOrFail('testcam'),
      $source,
      static fn(): array => ['ok' => false, 'code' => 'rate_limited']
    );

    $this->assertFalse($publication['created']);
    $this->assertFileDoesNotExist($target);
  }

  public function testKeepsPreexistingImageWhenLikeIsRejected(): void
  {
    [$source, $target] = $this->paths();
    $this->assertTrue(mkdir(dirname($target), 0777, true) || is_dir(dirname($target)));
    file_put_contents($target, 'EXISTING');
    $coordinator = new BestOfLikeCoordinator($this->app);

    $publication = $coordinator->execute(
      $this->app->cameraOrFail('testcam'),
      $source,
      static fn(): array => ['ok' => false, 'code' => 'duplicate_image']
    );

    $this->assertFalse($publication['created']);
    $this->assertSame('EXISTING', file_get_contents($target));
  }

  public function testKeepsNewImageWhenDuplicateRepairsMissingBestOfFile(): void
  {
    [$source, $target] = $this->paths();
    $coordinator = new BestOfLikeCoordinator($this->app);

    $publication = $coordinator->execute(
      $this->app->cameraOrFail('testcam'),
      $source,
      static fn(): array => ['ok' => false, 'code' => 'duplicate_image']
    );

    $this->assertTrue($publication['created']);
    $this->assertFileExists($target);
    $this->assertSame('JPEG-DATA', file_get_contents($target));
  }

  public function testRemovesNewImageWhenLikeThrows(): void
  {
    [$source, $target] = $this->paths();
    $coordinator = new BestOfLikeCoordinator($this->app);

    try {
      $coordinator->execute(
        $this->app->cameraOrFail('testcam'),
        $source,
        static function (): array {
          throw new RuntimeException('database unavailable');
        }
      );
      $this->fail('Expected operation failure');
    } catch (RuntimeException $exception) {
      $this->assertSame('database unavailable', $exception->getMessage());
    }

    $this->assertFileDoesNotExist($target);
  }

  public function testSourceResolverRejectsNestedForeignCameraPath(): void
  {
    $resolver = new BestOfSourceResolver($this->app);

    self::assertFalse($resolver->belongsToCamera(
      'cache/other/testcam/2026/08/31/image.jpg',
      'testcam'
    ));
    self::assertTrue($resolver->belongsToCamera(
      'cache/testcam/2026/08/31/image.jpg',
      'testcam'
    ));
  }

  public function testSourceResolverMapsBestOfImageBackToCachedCapture(): void
  {
    $fileName = '20260831_235000_la.jpg';
    $cacheFile = $this->root . '/cache/testcam/2026/08/31/' . $fileName;
    $this->assertTrue(mkdir(dirname($cacheFile), 0777, true) || is_dir(dirname($cacheFile)));
    file_put_contents($cacheFile, 'JPEG-DATA');

    $resolver = new BestOfSourceResolver($this->app);
    $mapped = $resolver->mapBestOfToCapture(
      $this->app->cameraOrFail('testcam'),
      'bestof/testcam/' . $fileName
    );
    $resolved = $resolver->resolveSource($this->app->cameraOrFail('testcam'), $mapped);

    self::assertSame('cache/testcam/2026/08/31/' . $fileName, $mapped);
    self::assertSame($cacheFile, $resolved['source_path'] ?? null);
  }

  /** @return array{string,string} */
  private function paths(): array
  {
    $source = $this->root . '/source/image.jpg';
    $target = $this->root . '/storage/bestof/testcam/image.jpg';
    $this->assertTrue(mkdir(dirname($source), 0777, true) || is_dir(dirname($source)));
    file_put_contents($source, 'JPEG-DATA');
    return [$source, $target];
  }

  /** @return array<string,mixed> */
  private function cameraConfig(): array
  {
    return [
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'testcam',
      'title' => 'Testcam',
      'short_title' => 'Testcam',
      'description' => '',
      'location' => ['latitude' => 0, 'longitude' => 0],
      'tags' => [],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
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
    ];
  }

  private function removeTree(string $path): void
  {
    if (!is_dir($path)) {
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $child = $path . DIRECTORY_SEPARATOR . $entry;
      if (is_dir($child) && !is_link($child)) {
        $this->removeTree($child);
      } else {
        unlink($child);
      }
    }
    rmdir($path);
  }
}
