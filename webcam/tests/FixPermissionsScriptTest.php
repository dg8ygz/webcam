<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bin/fix_permissions.php';

final class FixPermissionsScriptTest extends TestCase
{
  public function testResolveTargetOwnershipPrefersConfiguredOwnerOverDetectedPathOwner(): void
  {
    $root = sys_get_temp_dir() . '/webcam-fix-perms-' . bin2hex(random_bytes(6));
    $cameraDbDir = $root . '/camera-db';
    $cacheDir = $root . '/cache';
    $configDb = $root . '/webcams.sqlite';

    mkdir($cameraDbDir, 0775, true);
    mkdir($cacheDir, 0775, true);
    file_put_contents($configDb, '');

    $paths = new class($cameraDbDir, $cacheDir) {
      public function __construct(
        private readonly string $cameraDbDir,
        private readonly string $cacheDir
      ) {
      }

      public function cameraDbPath(string $suffix = ''): string
      {
        return $suffix === ''
          ? $this->cameraDbDir
          : rtrim($this->cameraDbDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR);
      }

      public function cachePath(string $suffix = ''): string
      {
        return $suffix === ''
          ? $this->cacheDir
          : rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR);
      }
    };

    $app = new class($paths, $configDb) {
      public function __construct(
        private readonly object $paths,
        private readonly string $configDb
      ) {
      }

      public function config(string $key, mixed $default = null): mixed
      {
        return match ($key) {
          'storage_owner_user' => 'www-data',
          'storage_owner_group' => 'www-data',
          'camera_config_db' => $this->configDb,
          default => $default,
        };
      }

      public function paths(): object
      {
        return $this->paths;
      }
    };

    try {
      putenv('WEBCAM_STORAGE_USER');
      putenv('WEBCAM_STORAGE_GROUP');

      [$user, $group] = resolveTargetOwnership($app);

      $this->assertSame('www-data', $user);
      $this->assertSame('www-data', $group);
    } finally {
      @unlink($configDb);
      @rmdir($cameraDbDir);
      @rmdir($cacheDir);
      @rmdir($root);
    }
  }

  public function testBuildPermissionFixPathsIncludesLiveCurrentDirectories(): void
  {
    $app = require __DIR__ . '/../bootstrap.php';

    $paths = buildPermissionFixPaths($app);
    $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $camera = $app->cameras()->all()[0] ?? null;

    $this->assertNotNull($camera);
    $this->assertContains(
      rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $camera->slug() . DIRECTORY_SEPARATOR . 'current',
      $paths
    );
    $this->assertContains($app->paths()->storagePath('current'), $paths);
    $this->assertNotContains($app->paths()->cameraDbPath(), $paths);
    $this->assertNotContains(
      realpath(__DIR__ . '/../database/migrations') ?: dirname(__DIR__) . '/database/migrations',
      $paths
    );
  }

  public function testPrivatePermissionPathsSeparateConfigAndCameraDatabases(): void
  {
    $paths = new class {
      public function cameraDbPath(): string
      {
        return '/srv/webcam-private/camera-db';
      }
    };
    $app = new class($paths) {
      public function __construct(private readonly object $paths) {}

      public function config(string $key, mixed $default = null): mixed
      {
        return $key === 'camera_config_db'
          ? '/srv/webcam-private/webcams.sqlite'
          : $default;
      }

      public function paths(): object
      {
        return $this->paths;
      }
    };

    $privatePaths = buildPrivatePermissionPaths($app);

    $this->assertContains('/srv/webcam-private/webcams.sqlite', $privatePaths);
    $this->assertContains('/srv/webcam-private/camera-db', $privatePaths);
    $this->assertSame(['/srv/webcam-private'], buildPrivatePermissionRootPaths($app));
  }

  public function testPrivateRootRequiresACommonDedicatedParent(): void
  {
    $paths = new class {
      public function cameraDbPath(): string
      {
        return '/var/lib/webcam/camera-db';
      }
    };
    $app = new class($paths) {
      public function __construct(private readonly object $paths) {}

      public function config(string $key, mixed $default = null): mixed
      {
        return $key === 'camera_config_db'
          ? '/var/www/html/webcam/database/webcams.sqlite'
          : $default;
      }

      public function paths(): object
      {
        return $this->paths;
      }
    };

    $this->assertSame([], buildPrivatePermissionRootPaths($app));
  }

  public function testEnsureExactPathsDoesNotChangeChildren(): void
  {
    $root = sys_get_temp_dir() . '/webcam-fix-perms-exact-' . bin2hex(random_bytes(6));
    $child = $root . '/child.txt';
    mkdir($root, 0755, true);
    file_put_contents($child, 'unchanged');
    chmod($root, 0755);
    chmod($child, 0644);
    $GLOBALS['logFile'] = $root . '/permission-fix.log';
    $GLOBALS['verbose'] = false;

    try {
      $result = ensureExactPaths(
        [$root],
        posix_geteuid(),
        posix_getegid(),
        02770,
        0660
      );

      clearstatcache(true, $root);
      clearstatcache(true, $child);
      $this->assertSame(02770, fileperms($root) & 07777);
      $this->assertSame(0644, fileperms($child) & 07777);
      $this->assertSame(1, $result['modes']);
      $this->assertSame(0, $result['errors']);
    } finally {
      @unlink($GLOBALS['logFile']);
      @unlink($child);
      @rmdir($root);
    }
  }

  public function testRecognizesOnlyMountManagedCifsTrees(): void
  {
    $mountInfo = implode("\n", [
      '44 29 0:39 / /srv rw,relatime - ext4 /dev/sda1 rw',
      '115 44 0:58 / /srv/webcam rw,relatime - cifs //server/webcam rw,file_mode=0664,dir_mode=0775,nounix',
      '220 115 0:59 / /srv/webcam/storage/archive ro,relatime - cifs //server/archive rw,file_mode=0444,dir_mode=0555,nounix',
    ]);

    $this->assertTrue(isMountManagedPermissionTree('/srv/webcam/cache', $mountInfo));
    $this->assertTrue(isMountManagedPermissionTree('/srv/webcam/storage/archive/cam', $mountInfo));
    $this->assertFalse(isMountManagedPermissionTree('/srv/local', $mountInfo));
    $this->assertFalse(isMountManagedPermissionTree('relative/path', $mountInfo));
  }

  public function testDbBackupDirectoryIsSkippedWhenExistingDirectoryIsReadOnly(): void
  {
    $result = shouldScanDbBackupDirectory(
      '/archive/camera/db_backups',
      static fn (string $path): bool => true,
      static fn (string $path): bool => false
    );

    $this->assertFalse($result);
  }

  public function testDbBackupDirectoryIsScannedWhenExistingDirectoryIsWritable(): void
  {
    $result = shouldScanDbBackupDirectory(
      '/archive/camera/db_backups',
      static fn (string $path): bool => true,
      static fn (string $path): bool => true
    );

    $this->assertTrue($result);
  }

  public function testMissingDbBackupDirectoryIsNotScanned(): void
  {
    $result = shouldScanDbBackupDirectory(
      '/archive/camera/db_backups',
      static fn (string $path): bool => false,
      static function (string $path): bool {
        throw new RuntimeException('Writable check must not run for a missing directory.');
      }
    );

    $this->assertFalse($result);
  }
}
