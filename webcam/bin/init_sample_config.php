#!/usr/bin/env php
<?php
declare(strict_types=1);

// Create a new local example only. Never read/copy a production database.
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    fwrite(STDERR, "Usage: php bin/init_sample_config.php\n");
    exit(2);
}
$root = dirname(__DIR__);
$path = $root . '/database/webcams.sqlite';
if (file_exists($path) || is_link($path)) {
    fwrite(STDERR, "Refusing to overwrite existing sample database\n");
    exit(1);
}
umask(0077);
$temporary = tempnam(dirname($path), '.sample-');
if ($temporary === false) { throw new RuntimeException('Cannot create temporary sample'); }
register_shutdown_function(static function () use ($temporary): void {
    foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
        if (is_file($temporary . $suffix)) { unlink($temporary . $suffix); }
    }
});
putenv('WEBCAM_CONFIG_DB=' . $temporary);
putenv('WEBCAM_ALLOW_SAMPLE_CONFIG=1');
require $root . '/autoload.php';
require $root . '/config/camera_store.php';
$db = new PDO('sqlite:' . $temporary);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents($root . '/database/schema.config.sql'));
$db = null;
webcam_camera_store_save([
    'version' => 0,
    'cameras' => ['testcam' => [
        'id' => 'testcam', 'slug' => 'testcam', 'name' => 'Example camera',
        'title' => 'Example camera', 'tags' => ['Example'],
        'mode' => 'passive-upload',
        'capture' => ['interval_seconds' => 600, 'endpoint' => '', 'upload_key' => bin2hex(random_bytes(32))],
        'storage' => ['base_path' => 'storage', 'current_dir' => 'current', 'archive_dir' => 'archive', 'best_of_dir' => 'bestof'],
        'weather_commands' => [], 'status' => [], 'stream' => ['mode' => 'none'],
        'resolutions' => [150, 320, 400, 640],
        'watchdog' => ['enabled' => false],
    ]],
    'maintenance_scripts' => [],
]);
webcam_camera_store_reset_connections();
if (!link($temporary, $path)) { throw new RuntimeException('Sample target appeared; refusing overwrite'); }
echo "Created private local sample database. No administrator password configured.\n";
