<?php
declare(strict_types=1);
require __DIR__.'/../scripts/lib/camera-source.php';
$d=sys_get_temp_dir().'/rtsp-diagnostics-'.bin2hex(random_bytes(6));mkdir($d,0700);
$c=['source'=>'rtsp','rtsp_url'=>'rtsp://user%40example:private%21@127.0.0.1:1/test?token=hidden','video_device'=>'/dev/video0','video_width'=>'640','video_height'=>'480','video_warmup_frames'=>'0','video_input_fps'=>'10'];
$n=0;
function check(bool $yes,string $why):void {global $n; ++$n; if (!$yes) throw new RuntimeException($why);}
try {
 $im=imagecreatetruecolor(32,32);imagejpeg($im,$d.'/good.jpg');imagedestroy($im);
 $fake=$d.'/ffmpeg';putenv('CAMERA_RPI_FFMPEG_BIN='.$fake);
 $diagnostic="Connection timed out rtsp://user%40example:private%21@127.0.0.1/test?token=hidden user@example private! token=hidden\n";
 $cases=[
  'missing'=>['',0,'kein JPEG'],
  'empty'=>[': > "$target"',0,'kein JPEG'],
  'garbage'=>['echo garbage > "$target"',0,'ungueltig'],
  'truncated'=>['head -c 300 '.escapeshellarg($d.'/good.jpg').' > "$target"',0,'ungueltig'],
  'error'=>['cp '.escapeshellarg($d.'/good.jpg').' "$target"',7,'exit=7'],
  'good'=>['cp '.escapeshellarg($d.'/good.jpg').' "$target"',0,null],
 ];
 foreach ($cases as $name=>[$write,$code,$expect]) {
  file_put_contents($fake,"#!/bin/bash\ncat >/dev/null\ntarget=\"\${@: -1}\"\n".$write."\nprintf '%s' ".escapeshellarg($diagnostic)." >&2\nexit $code\n");chmod($fake,0700);
  $target=$d.'/'.$name.'.out';$message=null;
  try { CameraSource::capture($c,$target); } catch (RuntimeException $e) { $message=$e->getMessage(); }
  if ($expect===null) {check($message===null && CameraSource::jpegStatus($target)===0,'valid image failed');}
  else {
   check($message!==null && str_contains($message,$expect),$name.' wrong reason: '.$message);
   check(!file_exists($target),$name.' partial retained');
   foreach (['private','user%40example','user@example','hidden'] as $secret) check(!str_contains($message,$secret),'secret leaked');
   check(str_contains($message,'Connection timed out'),'lost ffmpeg cause');
  }
 }
 // A successful stale image must not disguise exit=0 without output.
 copy($d.'/good.jpg',$d.'/stale.jpg');$hash=hash_file('sha256',$d.'/stale.jpg');
 try {CameraSource::capture($c,$d.'/stale.jpg');throw new Exception('accepted stale');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'existiert'),'wrong stale result');}
 check(hash_file('sha256',$d.'/stale.jpg')===$hash,'stale file altered');
 // Drain well beyond pipe capacity; retain bounded, sanitized diagnostic only.
 file_put_contents($fake,"#!/bin/bash\ncat >/dev/null\nfor ((i=0;i<4000;i++)); do printf '%s' ".escapeshellarg($diagnostic)." >&2; done\nexit 9\n");chmod($fake,0700);
 $start=microtime(true);
 try {CameraSource::capture($c,$d.'/large.jpg');throw new Exception('accepted flood');}catch(RuntimeException $e){$message=$e->getMessage();}
 check(microtime(true)-$start<15,'stderr blocked');check(strlen($message)<4300,'stderr not bounded');check(!str_contains($message,'private'),'flood leaked secret');
 echo "OK: $n RTSP failure, JPEG, cleanup, redaction and pipe-drain checks\n";
} finally {foreach(glob($d.'/*') as $f)unlink($f);rmdir($d);}
