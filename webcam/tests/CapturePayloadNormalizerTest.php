<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Admin\CapturePayloadNormalizer;

/**
 * Vertragstests für die Eingabegrenze des Admin-Capture-Editors.
 *
 * Die Tests benutzen einen echten CameraDataStore nur für dessen kanonische
 * Pfadnormalisierung. Es werden hier bewusst keine Datenbankzeilen verändert.
 */
final class CapturePayloadNormalizerTest extends TestCase
{
  private string $root;
  private CameraDataStore $store;
  private CapturePayloadNormalizer $normalizer;

  protected function setUp(): void
  {
    parent::setUp();
    $this->root = TestTempDir::ensure() . '/capture-normalizer-' . bin2hex(random_bytes(5));
    $app = $this->createApp($this->root);
    $this->store = new CameraDataStore($app, $app->cameraOrFail('testcam'));
    $this->normalizer = new CapturePayloadNormalizer();
  }

  public function testNormalizesAllSupportedCaptureFieldsAndLists(): void
  {
    $hash = str_repeat('A', 64);
    $result = $this->normalizer->normalizeUpdatePayload([
      'captured_ts' => '1788100000',
      'relative_path' => 'cache\\testcam\\2026\\08\\30\\image.jpg',
      'luminance' => '42.5',
      'hash_sha256' => ' ' . $hash . ' ',
      'likes_count' => -4,
      'ignored' => 'never reaches SQLite',
      'variants' => [[
        'variant' => 'la',
        'relative_path' => 'cache/testcam/2026/08/30/image_la.jpg',
        'width' => '1600',
        'height' => 900,
        'size_bytes' => '12345',
        'mtime' => '1788100001',
      ]],
      'exif' => [[
        'tag' => ' ISO ',
        'value' => '200',
        'sort_order' => '3',
      ]],
      'weather' => [[
        'key' => 'temperature',
        'value' => ' 18,2 °C ',
        'position' => '2',
      ]],
    ], $this->store, 'testcam');

    self::assertSame(1788100000, $result['captured_ts']);
    self::assertSame('cache/testcam/2026/08/30/image.jpg', $result['relative_path']);
    self::assertSame(42.5, $result['luminance']);
    self::assertSame($hash, $result['hash_sha256']);
    self::assertSame(0, $result['likes_count']);
    self::assertArrayNotHasKey('ignored', $result);
    self::assertSame(1600, $result['variants'][0]['width']);
    self::assertSame('ISO', $result['exif'][0]['tag']);
    self::assertSame('18,2 °C', $result['weather'][0]['value']);
  }

  public function testOmittedListsRemainOmittedButExplicitEmptyListsRemainUpdates(): void
  {
    $partial = $this->normalizer->normalizeUpdatePayload(
      ['likes_count' => 2],
      $this->store,
      'testcam'
    );
    self::assertSame(['likes_count' => 2], $partial);

    $clear = $this->normalizer->normalizeUpdatePayload([
      'variants' => [],
      'exif' => [],
      'weather' => [],
    ], $this->store, 'testcam');
    self::assertSame(['variants' => [], 'exif' => [], 'weather' => []], $clear);
  }

  public function testRejectsUnsafeOrStructurallyInvalidInput(): void
  {
    $invalidPayloads = [
      [['captured_ts' => -1], 'captured_ts must be >= 0'],
      [['relative_path' => '/etc/passwd'], 'relative_path must be a relative path'],
      [['relative_path' => 'cache/other/image.jpg'], 'relative_path must include camera slug'],
      [['relative_path' => 'cache/testcam/../secret.jpg'], "relative_path must not contain '..'"],
      [['hash_sha256' => 'not-a-hash'], 'hash_sha256 must be a 64 char hex string'],
      [['variants' => 'invalid'], 'variants must be an array'],
      [['variants' => [['variant' => 'la', 'relative_path' => 'other/image.jpg']]], 'must include camera slug'],
      [['exif' => [['tag' => '', 'value' => 'x']]], 'exif[0].tag is required'],
      [['exif' => [['tag' => 'x', 'value' => str_repeat('x', 2049)]]], 'exif[0].value exceeds max length'],
      [['weather' => [['value' => ' ']]], 'weather[0].value is required'],
    ];

    foreach ($invalidPayloads as [$payload, $message]) {
      try {
        $this->normalizer->normalizeUpdatePayload($payload, $this->store, 'testcam');
        self::fail('Expected invalid payload to be rejected: ' . json_encode($payload));
      } catch (InvalidArgumentException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
  }

  private function createApp(string $root): App
  {
    $configPath = $root . '/cameras.php';
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
      self::fail('Unable to create test root');
    }
    $cameras = [[
      'id' => 'testcam',
      'slug' => 'testcam',
      'name' => 'Testcam',
      'title' => 'Testcam',
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600],
      'storage' => [
        'base_path' => $root . '/storage/testcam',
        'current_dir' => 'current',
        'archive_dir' => 'archive',
        'best_of_dir' => 'bestof',
      ],
      'location' => [],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [],
      'weather_commands' => [],
    ]];
    file_put_contents($configPath, '<?php return ' . var_export($cameras, true) . ';');

    return new App([
      'timezone' => 'UTC',
      'camera_config_path' => $configPath,
      'storage_path' => $root . '/storage',
      'archive_path' => $root . '/storage/archive',
      'state_path' => $root . '/state',
      'log_path' => $root . '/log',
      'info_path' => $root . '/info',
      'cache_path' => $root . '/cache',
      'camera_db_path' => $root . '/camera-db',
      'base_url' => '',
    ]);
  }
}
