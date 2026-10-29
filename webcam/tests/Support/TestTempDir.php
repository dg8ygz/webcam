<?php
declare(strict_types=1);

final class TestTempDir
{
  private static ?string $path = null;
  private static bool $cleanupRegistered = false;

  public static function ensure(): string
  {
    if (self::$path !== null) {
      return self::$path;
    }

    $root = self::preferredRoot();
    self::pruneStale($root);

    $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'webcam-tests-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('Unable to create test temp dir: ' . $path);
    }

    self::$path = $path;
    self::export($path);

    return $path;
  }

  public static function registerShutdownCleanup(): void
  {
    if (self::$cleanupRegistered) {
      return;
    }
    self::$cleanupRegistered = true;
    register_shutdown_function([self::class, 'cleanup']);
  }

  public static function cleanup(): void
  {
    if (self::$path === null) {
      return;
    }
    self::rrmdir(self::$path);
    self::$path = null;
  }

  private static function preferredRoot(): string
  {
    $override = getenv('WEBCAM_TEST_TMP_ROOT');
    if (is_string($override) && self::isWritableDirectory($override)) {
      return rtrim($override, DIRECTORY_SEPARATOR);
    }

    foreach (['/dev/shm', sys_get_temp_dir()] as $candidate) {
      if (self::isWritableDirectory($candidate)) {
        return rtrim($candidate, DIRECTORY_SEPARATOR);
      }
    }

    throw new RuntimeException('No writable temp root available for tests');
  }

  private static function isWritableDirectory(string $path): bool
  {
    return $path !== '' && is_dir($path) && is_writable($path);
  }

  private static function export(string $path): void
  {
    foreach (['TMPDIR', 'TMP', 'TEMP'] as $name) {
      putenv($name . '=' . $path);
      $_ENV[$name] = $path;
      $_SERVER[$name] = $path;
    }

    $cameraDbPath = $path . DIRECTORY_SEPARATOR . 'camera-db';
    putenv('WEBCAM_CAMERA_DB_PATH=' . $cameraDbPath);
    $_ENV['WEBCAM_CAMERA_DB_PATH'] = $cameraDbPath;
    $_SERVER['WEBCAM_CAMERA_DB_PATH'] = $cameraDbPath;
  }

  private static function pruneStale(string $root): void
  {
    $cutoff = time() - 86400;
    foreach (glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'webcam-tests-*') ?: [] as $path) {
      if (!is_dir($path)) {
        continue;
      }
      $mtime = filemtime($path);
      if ($mtime !== false && $mtime > $cutoff) {
        continue;
      }
      self::rrmdir($path);
    }
  }

  private static function rrmdir(string $dir): void
  {
    if (!is_dir($dir)) {
      return;
    }

    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . DIRECTORY_SEPARATOR . $item;
      if (is_dir($path) && !is_link($path)) {
        self::rrmdir($path);
        continue;
      }
      @unlink($path);
    }

    @rmdir($dir);
  }
}
