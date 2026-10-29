<?php
declare(strict_types=1);
// Temporary files only: exercise the real save/CAS/runtime separation, no GPIO.
require_once __DIR__.'/../scripts/camera-rpi-gpio.php';
$dir=sys_get_temp_dir().'/camera-gpio-test-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
putenv('CAMERA_RPI_GPIO_CONFIG='.$dir.'/gpio.json');
putenv('CAMERA_RPI_GPIO_ACTIVE='.$dir.'/active.json');
putenv('CAMERA_RPI_GPIO_DT_ROOT='.$dir);
file_put_contents($dir.'/model',"Raspberry Pi 4 Model B Rev 1.5\0");
file_put_contents($dir.'/compatible',"raspberrypi,4-model-b\0brcm,bcm2711\0");
$checks=0;
function checkG(bool $v,string $m):void { global $checks; ++$checks; if (!$v) throw new RuntimeException($m); }
function rejectG(callable $f,string $m):void { try {$f();} catch (Throwable $e) {checkG(true,$m); return;} checkG(false,$m); }
try {
    $app=new CameraGpioApp();
    checkG(CameraGpioConfig::effective()===CameraGpioConfig::DEFAULTS,'legacy fallback');
    file_put_contents($app->config,json_encode(CameraGpioConfig::DEFAULTS));
    rejectG(fn()=>CameraGpioConfig::effective(),'enrolled without snapshot fails');
    file_put_contents($app->active,json_encode(CameraGpioConfig::DEFAULTS));
    [$pins,$rev]=$app->load(); $pins['dht']=17;
    ob_start(); $app->save(json_encode(['pins'=>$pins,'revision'=>$rev])); ob_end_clean();
    checkG($app->load()[0]['dht']===17,'desired saved');
    checkG(CameraGpioConfig::effective()['dht']===4,'running pin unchanged');
    $app->boot(); // Existing snapshot: must not run dtoverlay or touch hardware.
    checkG(CameraGpioConfig::effective()['dht']===4,'restart preserves snapshot');
    checkG(json_decode(file_get_contents($app->config.'.previous'),true)['dht']===4,'backup');
    rejectG(fn()=>$app->save(json_encode(['pins'=>$pins,'revision'=>$rev])),'stale rejected');
    [,$rev]=$app->load(); $pins['fan']=17;
    rejectG(fn()=>$app->save(json_encode(['pins'=>$pins,'revision'=>$rev])),'duplicate rejected');
    checkG($app->load()[0]['fan']===27,'invalid save unchanged');
    file_put_contents($dir.'/compatible',"raspberrypi,model-b-rev2\0brcm,bcm2835\0");
    $pins['fan']=27;
    rejectG(fn()=>$app->save(json_encode(['pins'=>$pins,'revision'=>$rev])),'26-pin rejects GPIO5');
    rejectG(fn()=>CameraGpioConfig::effective(),'snapshot invalid on changed header');
    $pins['heater']=17; $pins['dht']=4;
    ob_start(); $app->save(json_encode(['pins'=>$pins,'revision'=>$rev])); ob_end_clean();
    checkG($app->load()[0]===$pins,'valid 26-pin assignment saves without GPIO');
    [,$rev]=$app->load();
    file_put_contents($dir.'/compatible',"raspberrypi,5-model-b\0brcm,bcm2712\0");
    rejectG(fn()=>$app->save(json_encode(['pins'=>$pins,'revision'=>$rev])),'unsupported controller rejects writes');
    ob_start(); $app->main(['gpio','get']); $view=json_decode(ob_get_clean(),true);
    checkG(!$view['board']['supported'] && $view['board']['header_pins']===40,'unsupported board still displayed');
    file_put_contents($dir.'/compatible',"raspberrypi,4-model-b\0brcm,bcm2711\0");
    file_put_contents($app->active,'{}');
    rejectG(fn()=>$app->boot(),'invalid snapshot not replaced');
    echo "OK: $checks GPIO store checks; CAS, rollback copy, pending/restart separation; no GPIO\n";
} finally {
    foreach (glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
