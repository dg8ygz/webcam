<?php
declare(strict_types=1);

final class TestFixtures
{
  private const SAMPLE_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwC4A//Z';
  private static ?int $seedTimestamp = null;

  public static function seed(): void
  {
    if (self::$seedTimestamp !== null) {
      return;
    }
    /** @var \Webcam\App $app */
    $app = require __DIR__ . '/../../bootstrap.php';
    $cameras = $app->cameras()->all();
    if (!$cameras) {
      return;
    }
    $camera = $cameras[0];
    $tz = new DateTimeZone($app->config('timezone', 'UTC'));
    $now = new DateTimeImmutable('now', $tz);
    $dt = $now->setTime(12, 0, 0);
    self::$seedTimestamp = $dt->getTimestamp();

    $filename = $dt->format('Ymd_His') . '_la.jpg';
    $year = $dt->format('Y');
    $month = $dt->format('m');
    $day = $dt->format('d');

    $cacheDir = $app->paths()->cachePath($camera->slug() . '/' . $year . '/' . $month . '/' . $day);
    self::ensureJpeg($cacheDir . DIRECTORY_SEPARATOR . $filename);

    $currentDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $camera->slug() . DIRECTORY_SEPARATOR . 'current';
    self::ensureJpeg($currentDir . DIRECTORY_SEPARATOR . '400.jpg');
    self::ensureJpeg($currentDir . DIRECTORY_SEPARATOR . 'full.jpg');

    $bestOfDir = $app->bestOfDirectory($camera);
    self::ensureJpeg($bestOfDir . DIRECTORY_SEPARATOR . $filename);
  }

  public static function seedTimestamp(): ?int
  {
    return self::$seedTimestamp;
  }

  private static function ensureJpeg(string $path): void
  {
    if (is_file($path)) {
      return;
    }
    $dir = dirname($path);
    // PHPUnit kann parallel/mehrfach in denselben Seed-Pfad laufen; `mkdir()`
    // darf dabei nicht als Warnrauschen enden, solange das Verzeichnis am Ende
    // tatsächlich existiert.
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      error_log('TestFixtures: unable to create dir ' . $dir);
      return;
    }
    $bytes = base64_decode(self::SAMPLE_JPEG_BASE64, true);
    if ($bytes === false) {
      return;
    }
    file_put_contents($path, $bytes);
  }
}
