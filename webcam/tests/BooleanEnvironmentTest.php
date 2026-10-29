<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataConnection;
use Webcam\Infrastructure\Storage\CameraDataSchemaRepository;
use Webcam\Support\BooleanEnvironment;

require_once __DIR__ . '/../config/camera_store.php';

final class BooleanEnvironmentTest extends TestCase
{
  private array $previousEnv;
  private array $previousArgv;
  private mixed $previousWarning;
  private string $root;

  protected function setUp(): void
  {
    foreach (['WEBCAM_ALLOW_SAMPLE_CONFIG', 'WEBCAM_CAMERA_DB_READONLY'] as $name) {
      $this->previousEnv[$name] = getenv($name);
    }
    $this->previousArgv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['boolean-test'];
    $this->previousWarning = $GLOBALS['webcam_sample_config_warning_emitted'] ?? null;
    $GLOBALS['webcam_sample_config_warning_emitted'] = true;
    $this->root = sys_get_temp_dir() . '/webcam-boolean-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
  }

  protected function tearDown(): void
  {
    foreach ($this->previousEnv as $name => $value) {
      putenv($value === false ? $name : $name . '=' . $value);
    }
    $_SERVER['argv'] = $this->previousArgv;
    if ($this->previousWarning === null) {
      unset($GLOBALS['webcam_sample_config_warning_emitted']);
    } else {
      $GLOBALS['webcam_sample_config_warning_emitted'] = $this->previousWarning;
    }
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
  }

  public static function switches(): iterable
  {
    foreach (['1', 'true', 'yes', 'on', ' TRUE ', "\tOn\n"] as $value) {
      yield 'true:' . $value => [$value, true];
    }
    foreach ([null, '', ' ', '0', 'false', 'no', 'off', ' FALSE ', "\tOff\n"] as $value) {
      yield 'false:' . ($value ?? 'unset') => [$value, false];
    }
  }

  public static function invalidSwitches(): iterable
  {
    foreach (['2', '-1', 'enabled', 'truthy', '00', '0.0', 'null', 'secret-value'] as $value) {
      yield $value => [$value];
    }
  }

  #[DataProvider('switches')]
  public function testSamplePermission(string|null $value, bool $expected): void
  {
    putenv($value === null ? 'WEBCAM_ALLOW_SAMPLE_CONFIG' : 'WEBCAM_ALLOW_SAMPLE_CONFIG=' . $value);
    self::assertSame($expected, BooleanEnvironment::read('WEBCAM_ALLOW_SAMPLE_CONFIG'));
    if (!$expected) {
      $this->expectException(RuntimeException::class);
      $this->expectExceptionMessage('Refusing to use the repository sample DB');
    }
    webcam_camera_store_assert_db_path_allowed(__DIR__ . '/../database/webcams.sqlite');
  }

  #[DataProvider('invalidSwitches')]
  public function testInvalidSamplePermission(string $value): void
  {
    putenv('WEBCAM_ALLOW_SAMPLE_CONFIG=' . $value);
    try {
      webcam_camera_store_assert_db_path_allowed(__DIR__ . '/../database/webcams.sqlite');
      self::fail('Invalid sample permission accepted');
    } catch (RuntimeException $error) {
      self::assertStringContainsString('WEBCAM_ALLOW_SAMPLE_CONFIG must be a boolean', $error->getMessage());
      self::assertStringNotContainsString($value, $error->getMessage());
    }
  }

  #[DataProvider('switches')]
  public function testDatabaseMode(string|null $value, bool $expected): void
  {
    putenv($value === null ? 'WEBCAM_CAMERA_DB_READONLY' : 'WEBCAM_CAMERA_DB_READONLY=' . $value);
    $path = $this->root . '/camera.sqlite';
    $seed = new PDO('sqlite:' . $path);
    $seed->exec('CREATE TABLE boolean_probe (value INTEGER)');
    $seed = null;
    $pdo = $this->connection($path)->pdo();
    self::assertSame((int) $expected, (int) $pdo->query('PRAGMA query_only')->fetchColumn());
    if ($expected) {
      $this->expectException(PDOException::class);
    }
    $pdo->exec('INSERT INTO boolean_probe VALUES (1)');
    self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM boolean_probe')->fetchColumn());
  }

  #[DataProvider('invalidSwitches')]
  public function testInvalidDatabaseModeHasNoDatabaseEffects(string $value): void
  {
    putenv('WEBCAM_CAMERA_DB_READONLY=' . $value);
    $path = $this->root . '/not-created/camera.sqlite';
    try {
      $this->connection($path)->pdo();
      self::fail('Invalid database mode accepted');
    } catch (RuntimeException $error) {
      self::assertStringContainsString('WEBCAM_CAMERA_DB_READONLY must be a boolean', $error->getMessage());
      self::assertStringNotContainsString($value, $error->getMessage());
      self::assertDirectoryDoesNotExist(dirname($path));
    }
  }

  public function testExplicitOverridesRemainAuthoritative(): void
  {
    putenv('WEBCAM_CAMERA_DB_READONLY=invalid');
    $path = $this->root . '/camera.sqlite';
    self::assertSame(0, (int) $this->connection($path, false)->pdo()->query('PRAGMA query_only')->fetchColumn());
    self::assertSame(1, (int) $this->connection($path, true)->pdo()->query('PRAGMA query_only')->fetchColumn());
  }

  public function testUnsetDefaultAndHelpRemainAvailable(): void
  {
    putenv('WEBCAM_ALLOW_SAMPLE_CONFIG');
    self::assertTrue(BooleanEnvironment::read('WEBCAM_ALLOW_SAMPLE_CONFIG', true));
    $_SERVER['argv'] = ['config_dump.php', '--help'];
    webcam_camera_store_assert_db_path_allowed(__DIR__ . '/../database/webcams.sqlite');
    self::assertFalse(BooleanEnvironment::read('WEBCAM_ALLOW_SAMPLE_CONFIG'));
  }

  private function connection(string $path, ?bool $override = null): CameraDataConnection
  {
    file_put_contents($this->root . '/cameras.php', '<?php return [];');
    $config = ['camera_config_path' => $this->root . '/cameras.php'];
    foreach (['storage', 'archive', 'state', 'log', 'info', 'cache', 'camera_db'] as $name) {
      $config[$name . '_path'] = $this->root . '/' . $name;
    }
    return new CameraDataConnection(
      new App($config), new Camera([
        'id' => 'boolean-test', 'slug' => 'boolean-test', 'name' => 'Boolean test',
        'title' => 'Boolean test', 'location' => [], 'mode' => 'passive-upload',
        'capture' => [], 'storage' => [],
      ]),
      new CameraDataSchemaRepository(), $path, $override
    );
  }
}
