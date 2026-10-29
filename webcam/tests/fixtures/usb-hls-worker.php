<?php
declare(strict_types=1);
require __DIR__ . '/../../autoload.php';
require __DIR__ . '/../../src/Service/Stream/usb_functions.php';

[$script, $root, $mode, $file, $result, $ip, $hold] = $argv;
$start = hrtime(true);
try {
  $usb = new \Webcam\Service\Stream\UsbStream(new \Webcam\Infrastructure\Filesystem\PathResolver(null, null, $root, null));
  if ($mode === 'fetch') {
    $body = $usb->fetch('test', $file);
    $data = ['status' => 200, 'hash' => hash('sha256', $body)];
  } else {
    $app = new \Webcam\App(['state_path' => $root, 'log_path' => $root, 'trusted_proxies' => [],
      'camera_config_path' => $root . '/cameras.php', 'timezone' => 'Europe/Berlin',
      'storage_path' => $root, 'archive_path' => $root, 'cache_path' => $root]);
    $state = new \Webcam\Service\Stream\StreamState($app);
    $_SERVER = ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'concurrent-test', 'REQUEST_METHOD' => $mode === 'prepare' ? 'POST' : 'GET'];
    $_GET = ['file' => $file];
    if (in_array($mode, ['prepare', 'prepare-get'], true)) { $_GET['prepare'] = '1'; }
    $events = [];
    $window = static function (bool $known) use ($hold, $root): array {
      if ($hold === 'hold') {
        touch($root . '/admitted');
        usleep(200000);
      }
      return ['ready' => true, 'duration' => 300, 'message' => ''];
    };
    http_response_code(200);
    ob_start();
    handle_usb_hls_request($usb, $state, 'test', $mode === 'probe', 1, $window,
      static function ($stage, $data) use (&$events): void { $events[] = $stage; });
    $data = ['status' => http_response_code(), 'body' => ob_get_clean(), 'events' => $events,
      'viewers' => $state->viewerCount('test')];
  }
} catch (Throwable $error) {
  $data = ['error' => $error->getMessage()];
}
$data['elapsed'] = (hrtime(true) - $start) / 1e9;
file_put_contents($result, json_encode($data));
