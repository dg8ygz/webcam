<?php
declare(strict_types=1);
// Isolated policy/decoder/bridge contract. No live /etc writes, GPIO or camera.
require dirname(__DIR__).'/scripts/camera-rpi-privacy.php';
$checks=0;
function check(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function reject(callable $call,string $message): void { try { $call(); } catch (Throwable) { check(true,$message); return; } check(false,$message); }
$c=CapturePrivacy::DEFAULTS;
check(CapturePrivacy::window($c,null,time())['allowed'],'default unchanged');
foreach (['sunrise_offset'=>721,'sunset_offset'=>-721,'dark_enabled'=>'false','minimum_percent'=>NAN,'timezone'=>'bogus','surprise'=>1] as $key=>$value) {
    $bad=$c; $bad[$key]=$value; reject(fn()=>CapturePrivacy::validate($bad),'reject '.$key);
}
$c['mode']='solar'; $c['source_server']='dev';
$p=['latitude'=>52.0,'longitude'=>8.5,'timezone'=>'Europe/Berlin'];
$day=new DateTimeImmutable('2026-09-13 12:00:00',new DateTimeZone('Europe/Berlin'));
check(!CapturePrivacy::window($c,null,$day->getTimestamp())['allowed'],'no guessed location');
check(CapturePrivacy::position(['latitude'=>0,'longitude'=>0,'timezone'=>'UTC']),'zero coordinates valid');
$sun=date_sun_info($day->getTimestamp(),52.0,8.5);
foreach ([-90,0,90] as $rise) foreach ([-90,0,90] as $set) {
    $c['sunrise_offset']=$rise; $c['sunset_offset']=$set;
    $from=$sun['sunrise']+60*$rise; $until=$sun['sunset']+60*$set;
    check(!CapturePrivacy::window($c,$p,$from-1)['allowed'],'before start');
    check(CapturePrivacy::window($c,$p,$from)['allowed'],'start inclusive');
    check(CapturePrivacy::window($c,$p,$until-1)['allowed'],'before end');
    check(!CapturePrivacy::window($c,$p,$until)['allowed'],'end exclusive');
}
$c['sunrise_offset']=720; $c['sunset_offset']=-720;
check(!CapturePrivacy::window($c,$p,$day->getTimestamp())['allowed'],'reversed solar window closed');
$c['sunrise_offset']=$c['sunset_offset']=0;
foreach (['2026-06-21','2026-12-21'] as $date) {
    check(!CapturePrivacy::window($c,['latitude'=>89,'longitude'=>0,'timezone'=>'UTC'],strtotime($date.' 12:00 UTC'))['allowed'],'polar boolean fails closed');
}
$c['mode']='fixed'; $c['start']='22:00'; $c['end']='06:00';
foreach (['2026-03-29 03:15'=>true,'2026-10-25 03:15'=>true,'2026-10-25 12:00'=>false] as $date=>$expected) {
    check(CapturePrivacy::window($c,null,(new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin')))->getTimestamp())['allowed']===$expected,'DST overnight '.$date);
}
$root=sys_get_temp_dir().'/capture-privacy-test-'.bin2hex(random_bytes(6));
mkdir($root,0700); mkdir($root.'/run',0700); mkdir($root.'/servers',0700);
putenv('CAMERA_RPI_PRIVACY_CONFIG='.$root.'/privacy.json');
putenv('CAMERA_RPI_PRIVACY_RUNTIME='.$root.'/run');
putenv('CAMERA_RPI_CONFIG_DIR='.$root.'/servers');
$app=new PrivacyApp();
try {
    file_put_contents($root.'/servers/dev.conf',"server_base_url=https://example.invalid/webcam\ncamera_slug=example\nkey_file=/not/read\n");
    $identity=$app->identity('dev');
    $header='X-Camera-Position: '.base64_encode(json_encode(['protocol'=>'camera-rpi-position-v1']+$p))."\r\n";
    file_put_contents($root.'/headers',$header);
    $app->receive('dev',$root.'/headers',$identity);
    $c=CapturePrivacy::DEFAULTS; $c['mode']='solar'; $c['source_server']='dev';
    ob_start(); $app->save(json_encode(['config'=>$c,'revision'=>'missing'])); ob_end_clean();
    [$doc,$rev]=$app->load();
    check($doc['position']['position']==$p,'imports saved coordinates (JSON may encode integral floats as integers)');
    reject(fn()=>$app->save(json_encode(['config'=>$c,'revision'=>'missing'])),'CAS rejects stale form');
    check($app->position($doc,time()+86401)===null,'24h expiry');
    file_put_contents($root.'/headers',''); $app->receive('dev',$root.'/headers',$identity);
    check($app->position($doc,time())===null,'missing header overrides saved snapshot');
    file_put_contents($root.'/headers',$header); $app->receive('dev',$root.'/headers',$identity);
    file_put_contents($root.'/servers/dev.conf',"server_base_url=https://other.invalid\ncamera_slug=example\n");
    check($app->position($doc,time())===null,'URL change invalidates stale position');
    reject(fn()=>$app->receive('dev',$root.'/headers',$identity),'in-flight old identity rejected');
    // Strict JPEG checks plus reproducible real GD pixels, not mocked brightness.
    $c=CapturePrivacy::DEFAULTS; $c['dark_enabled']=true; $c['minimum_percent']=30.0; $c['hysteresis_percent']=5.0;
    ob_start(); $app->save(json_encode(['config'=>$c,'revision'=>$rev])); ob_end_clean();
    foreach ([0=>false,80=>false,100=>true,255=>true] as $gray=>$expected) {
        $im=imagecreatetruecolor(128,64); imagefill($im,0,0,imagecolorallocate($im,$gray,$gray,$gray)); imagejpeg($im,$root.'/image.jpg',100); imagedestroy($im);
        ob_start(); $accepted=$app->inspect($root.'/image.jpg'); ob_end_clean();
        check($accepted===$expected,'threshold/hysteresis '.$gray);
    }
    $im=imagecreatetruecolor(128,64); imagefilledrectangle($im,64,0,127,63,imagecolorallocate($im,255,255,255)); imagejpeg($im,$root.'/image.jpg',100); imagedestroy($im);
    check(CapturePrivacy::brightness($root.'/image.jpg',['x'=>0,'y'=>0,'width'=>50,'height'=>100])<1,'ROI excludes bright window');
    check(CapturePrivacy::brightness($root.'/image.jpg',['x'=>50,'y'=>0,'width'=>50,'height'=>100])>99,'ROI right');
    file_put_contents($root.'/bad.jpg','not a jpeg'); reject(fn()=>CapturePrivacy::brightness($root.'/bad.jpg',$c['region']),'corrupt image rejected');
    echo "OK: $checks capture privacy checks; solar/offsets/DST/polar/missing position/CAS/expiry/ROI/hysteresis; no hardware\n";
} finally {
    // Only files created in our random, private test directory.
    foreach (['run','servers'] as $sub) { foreach (glob($root.'/'.$sub.'/*') ?: [] as $f) unlink($f); rmdir($root.'/'.$sub); }
    foreach (glob($root.'/*') ?: [] as $f) unlink($f); rmdir($root);
}
