<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\ArchiveEntryImageResolver;

final class ArchiveEntryImageResolverTest extends TestCase
{
  private string $root;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/webcam-entry-resolver-' . bin2hex(random_bytes(4));
    mkdir($this->root . '/cache/demo', 0777, true);
    file_put_contents($this->root . '/cache/demo/existing.jpg', 'jpeg-placeholder');
  }

  protected function tearDown(): void
  {
    @unlink($this->root . '/cache/demo/existing.jpg');
    @rmdir($this->root . '/cache/demo');
    @rmdir($this->root . '/cache');
    @rmdir($this->root);
  }

  public function testResolveSkipsMissingPreferredVariantAndUsesExistingFile(): void
  {
    $resolver = new ArchiveEntryImageResolver($this->root);
    $resolved = $resolver->resolve([
      'variants' => [
        'sm' => ['url' => 'cache/demo/missing.jpg', 'relative_path' => 'cache/demo/missing.jpg'],
        'la' => ['url' => 'cache/demo/existing.jpg?v=1', 'relative_path' => 'cache/demo/existing.jpg'],
      ],
    ], ['sm', 'la']);

    $this->assertSame([
      'url' => 'cache/demo/existing.jpg?v=1',
      'relative_path' => 'cache/demo/existing.jpg',
      'variant' => 'la',
    ], $resolved);
  }

  public function testResolveAcceptsExternalUrlWithoutFilesystemLookup(): void
  {
    $resolver = new ArchiveEntryImageResolver($this->root);
    $resolved = $resolver->resolve([
      'variants' => [
        'remote' => ['url' => 'https://example.invalid/image.jpg'],
      ],
    ], ['remote']);

    $this->assertSame('remote', $resolved['variant'] ?? null);
    $this->assertSame('https://example.invalid/image.jpg', $resolved['url'] ?? null);
  }

  public function testResolveSupportsLegacyWebcamPrefix(): void
  {
    $resolver = new ArchiveEntryImageResolver($this->root);
    $resolved = $resolver->resolve([
      'variants' => [
        'legacy' => [
          'url' => 'webcam/cache/demo/existing.jpg',
          'relative_path' => 'webcam/cache/demo/existing.jpg',
        ],
      ],
    ], ['legacy']);

    $this->assertSame('legacy', $resolved['variant'] ?? null);
  }
}
