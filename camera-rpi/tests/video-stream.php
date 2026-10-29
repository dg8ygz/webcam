<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/lib/video-stream.php';
// Pure contracts, no GPIO, service changes, camera access or real credentials.
$checks = 0;
$check = static function (bool $ok) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException('Video contract failed: ' . $checks); } };
$check(CameraVideo::lease('{"protocol":"camera-rpi-video-v1","active":true,"ttl":30}') === 30);
$check(CameraVideo::lease('{"protocol":"camera-rpi-video-v1","active":false,"ttl":0}') === 0);
foreach (['{}', '{"protocol":"camera-rpi-video-v1","active":"false","ttl":30}',
  '{"protocol":"camera-rpi-video-v1","active":true,"ttl":700}',
  '{"protocol":"camera-rpi-video-v1","active":false,"ttl":10}'] as $bad) {
  try { CameraVideo::lease($bad); $check(false); } catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'Freigabe')); }
}
$args = CameraVideo::encoder(['input_fps' => 30, 'device' => '/dev/video0'], '/run/test', str_repeat('a', 16));
$check(str_starts_with($args[array_search('-vf', $args, true)+1], 'fps=3,'));
$check(in_array('delete_segments+temp_file+independent_segments', $args, true));
$check(in_array('1500k', $args, true));
$check($args[array_search('-preset',$args,true)+1]==='veryfast');
$rtspArgs=CameraVideo::encoder(['source_profile'=>['source'=>'rtsp']], '/run/test', str_repeat('b',16));
$check($rtspArgs[array_search('-preset',$rtspArgs,true)+1]==='ultrafast');
$check(in_array('1500k',$rtspArgs,true));
$check(!in_array('-rtsp_transport', $args, true));
foreach (['../key', '/index.m3u8', 'index.m3u8?x', 'seg_aa_000001.ts'] as $bad) { $check(!CameraVideo::validFile($bad)); }
$check(CameraVideo::clock() > 0);
$timeout=new CameraVideoPollException(28,0);
$check(CameraVideo::expiryAfterFailure(130.0,110.0,$timeout)===130.0);
$check(CameraVideo::expiryAfterFailure(130.0,129.0,$timeout)===130.0);
$check(CameraVideo::expiryAfterFailure(130.0,130.0,$timeout)===0.0);
$check(CameraVideo::expiryAfterFailure(0.0,110.0,$timeout)===0.0);
$check(CameraVideo::expiryAfterFailure(130.0,110.0,new CameraVideoPollException(0,503))===130.0);
$check(CameraVideo::expiryAfterFailure(130.0,110.0,new CameraVideoPollException(0,403))===0.0);
$check(CameraVideo::expiryAfterFailure(130.0,110.0,new CameraVideoPollException(60,0))===0.0);
$check(CameraVideo::expiryAfterFailure(130.0,110.0,new RuntimeException('invalid lease'))===0.0);
// Outage: bounded retries instead of a request every two seconds forever.
foreach ([0=>10, 1=>60, 2=>120, 3=>240, 4=>300, PHP_INT_MAX=>300] as $failures=>$delay) {
  $check(CameraVideo::pollDelay($failures, 0.0, 100.0)===$delay);
}
$check(CameraVideo::pollDelay(0, 130.0, 110.0)===10);
$check(CameraVideo::pollDelay(2, 130.0, 110.0)===2);
$check(CameraVideo::pollDelay(4, 130.0, 130.0)===300);
// Recovery clears the failure counter in the daemon and restores normal polling.
$check(CameraVideo::pollDelay(0, 130.0, 130.0)===10);
echo "OK: $checks video contracts; no hardware\n";
