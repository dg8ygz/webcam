<?php
declare(strict_types=1);
require __DIR__.'/../scripts/lib/camera-source.php';
$d=sys_get_temp_dir().'/source-timeout-'.bin2hex(random_bytes(6));mkdir($d,0700);
try {
 file_put_contents($d.'/ffmpeg',"#!/bin/bash\ncat >/dev/null\necho partial > \"\${@: -1}\"\nsleep 45\n");chmod($d.'/ffmpeg',0700);
 putenv('CAMERA_RPI_FFMPEG_BIN='.$d.'/ffmpeg');putenv('CAMERA_RPI_SOURCE_CONFIG='.$d.'/source.conf');
 copy(__DIR__.'/../config/camera-source.example.conf',$d.'/source.conf');chmod($d.'/source.conf',0600);
 $t=microtime(true);$failed=false;
 try{CameraSource::capture(CameraSource::load(),$d.'/partial.jpg');}catch(RuntimeException $e){$failed=true;}
 $elapsed=microtime(true)-$t;
 if(!$failed || $elapsed<29 || $elapsed>35 || file_exists($d.'/partial.jpg'))throw new RuntimeException('Timeout/cleanup failed');
 echo "OK: real 30s process deadline and partial JPEG cleanup\n";
}finally{foreach(glob($d.'/*') as $f)unlink($f);rmdir($d);}
