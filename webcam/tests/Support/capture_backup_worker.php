<?php
declare(strict_types=1);

require __DIR__ . '/../../autoload.php';
require __DIR__ . '/BackupTriggerFixture.php';

use Webcam\Service\Storage\CaptureBackupScheduler;
use Webcam\Service\Upload\UploadIngestor;

$root = $argv[1];
$app = BackupTriggerFixture::app($root);
if (($argv[2] ?? '') === 'log') {
  (new UploadIngestor($app))->log('alpha', 'test', ['value' => str_repeat('x', 65536)]);
  exit(0);
}
$scheduler = new CaptureBackupScheduler($app, static function (string $slug, string $token) use ($root): bool {
  file_put_contents($root . '/launches', $slug . ':' . $token . "\n", FILE_APPEND | LOCK_EX);
  usleep(75000);
  return true;
});
$scheduler->schedule($app->cameraOrFail('alpha'));
