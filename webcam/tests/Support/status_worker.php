<?php
declare(strict_types=1);

use Webcam\App;
use Webcam\Service\Status\StatusService;

$autoload = getenv('WEBCAM_TEST_VENDOR_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
  $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
}
require $autoload;
require_once dirname(__DIR__, 2) . '/src/Support/helpers.php';
spl_autoload_register(static function (string $class): void {
  $prefix = 'Webcam\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $relative = substr($class, strlen($prefix));
  $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

$root = $argv[1] ?? '';
$writes = isset($argv[2]) ? (int) $argv[2] : 0;
if ($root === '' || $writes < 1) {
  fwrite(STDERR, "Usage: status_worker.php <fixture-root> <writes>\n");
  exit(2);
}

$app = new App([
  'timezone' => 'Europe/Berlin',
  'camera_config_path' => $root . '/cameras.php',
  'cache_path' => $root . '/cache',
  'storage_path' => $root . '/storage',
  'archive_path' => $root . '/archive',
  'state_path' => $root . '/state',
  'log_path' => $root . '/log',
  'info_path' => $root . '/info',
  'camera_db_path' => $root . '/camera-db',
]);
$camera = $app->cameraOrFail('demo');
$service = new StatusService($app);
for ($write = 0; $write < $writes; $write++) {
  $service->recordFailure($camera, 'concurrency-test');
}
