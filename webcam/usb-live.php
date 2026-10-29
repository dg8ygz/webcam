<?php
declare(strict_types=1);

// Compatibility for saved test-player links. The only UI is the normal camera
// page now; fixed local destination prevents arbitrary redirects or slugs.
$app = require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store');
try {
  if (($_GET['camera'] ?? '') !== 'test' || !$app->cameras()->find('test')
    || !\Webcam\Service\Stream\UsbStream::forPaths($app->paths())->registration('test')) {
    http_response_code(404);
    exit;
  }
} catch (\Throwable $error) {
  http_response_code(503);
  exit;
}
header('Location: camera/test?stream=usb', true, 302);
