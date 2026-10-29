<?php
declare(strict_types=1);
require __DIR__.'/../scripts/lib/video-stream.php';
$d=sys_get_temp_dir().'/camera-source-'.bin2hex(random_bytes(8));mkdir($d,0700);
$p=$d.'/source.conf';putenv('CAMERA_RPI_SOURCE_CONFIG='.$p);putenv('CAMERA_RPI_ADMIN_TEST_MODE=1');
$n=0;
function check(bool $v):void {global $n; $n++;if(!$v)throw new RuntimeException('source test '.$n);}
function invalid(callable $f):void {try{$f();}catch(RuntimeException $e){check(true);return;}check(false);}
try {
 check(CameraSource::load()===null);
 $raw=file_get_contents(__DIR__.'/../config/camera-source.example.conf');file_put_contents($p,$raw);chmod($p,0600);
 $c=CameraSource::load();check($c['source']==='rtsp');
 check($c['gphoto_capture_mode']==='eos-legacy');
 $missing=$c;unset($missing['gphoto_capture_mode']);
 check(CameraSource::validate($missing)['gphoto_capture_mode']==='eos-legacy');
 $direct=$c;$direct['gphoto_capture_mode']='standard';
 check(CameraSource::validate($direct)['gphoto_capture_mode']==='standard');
 // Same startup contract on hardened PHP CLI without $_SERVER.
 foreach (['GPC','EGPCS'] as $order) {
  $proc=proc_open([PHP_BINARY,'-d','variables_order='.$order,__DIR__.'/../scripts/lib/camera-source.php','shell'],
   [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
  fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0 && $err==='');
  $fields=explode("\n",rtrim($out,"\n"));check(count($fields)===6);
  check($fields[0]==='rtsp' && json_decode($fields[5],true)===$c);
 }
 foreach (["source=v4l2\n","unexpected=x\n"] as $bad){file_put_contents($p,$raw.$bad);invalid(fn()=>CameraSource::load());}
 file_put_contents($p,$raw);chmod($p,0644);invalid(fn()=>CameraSource::load());chmod($p,0600);
 $c['rtsp_url']='rtsp://alice:secret@192.0.2.110:554/11';
 $args=CameraVideo::encoder(['source_profile'=>$c],$d,'aaaaaaaaaaaaaaaa');
 check(!str_contains(json_encode($args),'secret'));check(in_array('pipe:0',$args,true));
 check(str_contains(CameraSource::input($c),'alice:secret@'));check(str_contains(CameraSource::input($c),'rtsp_transport tcp'));
 foreach (["rtsp://host/x'",'file:///etc/passwd',"rtsp://host/x\noption bad value"] as $bad){$broken=$c;$broken['rtsp_url']=$bad;invalid(fn()=>CameraSource::validate($broken));}
 $c['source']='v4l2';$args=CameraVideo::encoder(['source_profile'=>$c],$d,'aaaaaaaaaaaaaaaa');
 check(in_array($c['video_device'],$args,true));check(!in_array('pipe:0',$args,true));
 $c['source']='gphoto2';$c['gphoto_capture_mode']='eos-legacy';check(CameraSource::validate($c)['gphoto_capture_mode']==='eos-legacy');
 invalid(fn()=>CameraVideo::encoder(['source_profile'=>$c],$d,'aaaaaaaaaaaaaaaa'));
 $bad=$c;$bad['gphoto_capture_mode']='shell-command';invalid(fn()=>CameraSource::validate($bad));
 // Real FFmpeg failure: unreachable TCP endpoint must end and remove partials.
 $c['source']='rtsp';$c['rtsp_url']='rtsp://127.0.0.1:1/11';$start=microtime(true);
 invalid(fn()=>CameraSource::capture($c,$d.'/failed.jpg'));
 check(microtime(true)-$start<10);check(!file_exists($d.'/failed.jpg'));
 echo "OK: $n shared source contracts, private stdin, USB selection and failed RTSP cleanup\n";
}finally{foreach(glob($d.'/*') as $f)unlink($f);rmdir($d);}
