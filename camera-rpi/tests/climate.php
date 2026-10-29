<?php
declare(strict_types=1);
/** Isolated PHP acceptance; no GPIO, IIO, live configuration, services or keys. */
require_once __DIR__.'/../scripts/camera-rpi-climate.php';
$checks=0;
function ok(bool $value, string $label): void { global $checks; $checks++; if (!$value) { throw new RuntimeException('FAIL: '.$label); } }
function rejects(callable $fn, string $label): void { try {$fn();} catch (Throwable) {ok(true,$label);return;} ok(false,$label); }
function sample(int $stamp=1000, string|float $t=20, string|float $h=90, string $state='ok'): array
{ return ['updated_epoch'=>(string)$stamp,'temperature_c'=>(string)$t,'humidity_percent'=>(string)$h,'sensor_state'=>$state]; }
function control(array $options=[]): ClimateController
{ return new ClimateController(array_replace(ClimateConfig::DEFAULTS,['mode'=>'simulate','average_samples'=>1],$options)); }
function waitFor(callable $fn, string $label, float $seconds=4): void
{ $until=microtime(true)+$seconds; do {if($fn()){ok(true,$label);return;}usleep(50000);}while(microtime(true)<$until);ok(false,$label); }
function closeProcess(mixed $p): void
{ if(is_resource($p)){proc_terminate($p);$until=microtime(true)+2;while(proc_get_status($p)['running']&&microtime(true)<$until){usleep(10000);}if(proc_get_status($p)['running'])proc_terminate($p,9);proc_close($p);} }
function removeTree(string $dir): void
{ foreach(new FilesystemIterator($dir) as $p){if($p->isDir()&&!$p->isLink())removeTree($p->getPathname());else unlink($p->getPathname());}rmdir($dir); }

// Validator rejects bool/string coercion, incomplete and duplicate JSON keys.
ok(ClimateConfig::validate(ClimateConfig::DEFAULTS)===ClimateConfig::DEFAULTS,'defaults');
foreach(ClimateConfig::DEFAULTS as $key=>$value) {
    foreach([null,true,false,[],INF,NAN,'1','false'] as $bad) {
        rejects(fn()=>ClimateConfig::validate(array_replace(ClimateConfig::DEFAULTS,[$key=>$bad])),"invalid $key");
    }
}
foreach([[],['mode'=>'off'],ClimateConfig::DEFAULTS+['extra'=>1],ClimateConfig::DEFAULTS+['max_temperature_c'=>35.0],
    ClimateConfig::DEFAULTS+['max_heat_seconds'=>900],
    array_replace(ClimateConfig::DEFAULTS,['fan_min_percent'=>90])] as $bad) { rejects(fn()=>ClimateConfig::validate($bad),'invalid schema/limits'); }
// Existing private files keep their settings, but retired fields never reappear
// in GET/new saves. Reading compatibility does not weaken unknown-field checks.
$legacyConfig=ClimateConfig::DEFAULTS+['max_temperature_c'=>35.0,'max_heat_seconds'=>900];
ok(ClimateConfig::fromStored($legacyConfig)===ClimateConfig::DEFAULTS,'retired stored limits discarded');
ok(ClimateConfig::fromStored(ClimateConfig::DEFAULTS)===ClimateConfig::DEFAULTS,'current stored schema');
ok(ClimateConfig::fromStored(array_replace($legacyConfig,['summer_c'=>45.0]))['summer_c']===45.0,'no hidden relation to retired temperature cap');
rejects(fn()=>ClimateConfig::fromStored($legacyConfig+['extra'=>1]),'stored unknown key rejected');
foreach(['max_temperature_c','max_heat_seconds'] as $key) {
    foreach([null,true,[],NAN,INF,'900',-1] as $bad) {
        rejects(fn()=>ClimateConfig::fromStored(array_replace($legacyConfig,[$key=>$bad])),'invalid retired stored value');
    }
}
foreach(['{"mode":"off","mode":"auto"}','{"mode":"off","m\\u006fde":"auto"}',
    '{"config":{"mode":"off"},"config":{"mode":"auto"}}','{"x":NaN}','{"x":Infinity}'] as $raw) {
    rejects(fn()=>ClimateApp::json($raw),'duplicate/nonfinite JSON');
}
ok(ClimateApp::json(json_encode(['x'=>'a "b" : c','y'=>2], JSON_THROW_ON_ERROR))['y']===2,'escaped string tokenization');

ok(abs(ClimateController::dewPoint(20,50)-9.27)<.03,'Magnus 20/50');
ok(abs(ClimateController::dewPoint(-10,80)+12.78)<.1,'Magnus negative');
ok(ClimateController::dewPoint(20,0)===null,'zero humidity');
foreach (['off','fault','capture_pause','warming'] as $state) {
    $c=control(['mode'=>$state==='off'?'off':'simulate','average_samples'=>3]);
    if (in_array($state,['fault','capture_pause'],true)) { $c->fault='capture_timeout'; }
    $r=$c->tick(sample(h:50),1000,0,$state==='capture_pause');
    ok($r['state']===$state&&$r['measurement_source']==='current'&&abs($r['dew_point_c']-9.3)<.01,
        'fresh diagnostic dew point during '.$state);
    ok(!$r['heater']&&($state==='fault'||$r['fan_percent']==0),'diagnostics never authorize outputs');
    $r=$c->tick(sample(800,h:50),1000,0,$state==='capture_pause');
    ok(!array_key_exists('dew_point_c',$r),'no stale diagnostic fallback during '.$state);
}
ok(control()->tick(sample(h:0),1000,0)['state']==='idle','zero humidity idle');
$c=control(['average_samples'=>3]);
for($i=0;$i<10;$i++)ok($c->tick(sample(),1000,$i)['state']==='warming','snapshot never fills buffer');
$c->tick(sample(1030),1030,30);
ok($c->tick(sample(1060),1060,60)['heater'],'paired warmup');
$c->tick(sample(1090,state:'unavailable'),1090,90);
ok($c->tick(sample(1120),1120,120)['state']==='heating','paired buffer survives brief invalid sample');
$c=control();$c->tick(sample(),1000,0);
ok($c->tick(sample(1030,h:40),1030,30)['heater'],'off delay starts');
ok($c->tick(sample(1089,h:40),1089,89)['heater'],'off delay holds');
ok(!$c->tick(sample(1090,h:40),1090,90)['heater'],'off after continuous safe gap');
foreach([[],sample(state:'unavailable'),sample(t:'NaN'),sample(h:101),sample(1200),sample(800)] as $bad) {
    $r=control()->tick($bad,1000,0);ok(!$r['heater']&&$r['fan_percent']==100,'bad sample failoff');
}
$c=control();$c->tick(sample(),1000,0);ok($c->tick(sample(),1000,121)['state']==='sensor_error','frozen monotonic sample');
foreach([35,40,60,80] as $temperature) {
    $c=control();$r=$c->tick(sample(t:$temperature),1000,0);
    ok($r['state']==='summer'&&$r['fault']===''&&!$r['heater']&&$r['fan_percent']==75,'summer heat is normal, not a latched trip');
    $r=$c->tick(sample(1030,t:$temperature),1030,30,true);
    ok($r['state']==='capture_pause'&&$r['fan_percent']==0&&$r['fault']==='','warm air does not block capture');
    $r=$c->tick(sample(1060,t:29),1060,60);
    ok($r['heater']&&$r['fault']==='','automatic dew protection after summer, no reset');
}
$c=control(['average_samples'=>7]);$r=$c->tick(sample(t:40),1000,0);
ok($r['state']==='warming'&&$r['fan_percent']==75&&$r['fault']==='','hot warmup uses summer PWM without fault');
// Two simulated days of fresh measurements, no real waits and no GPIO. This
// covers 900s and the old maximum while retaining humidity-based continuous heat.
$c=control();$continuous=true;
for($elapsed=0;$elapsed<=172800;$elapsed+=30) {
    $r=$c->tick(sample(1000+$elapsed),1000+$elapsed,$elapsed);
    $continuous=$continuous&&$r['heater']&&$r['fan_percent']==90&&$r['fault']==='';
}
ok($continuous,'48h continuous dew demand without heating timeout');
ok($c->tick(sample(173830,h:40),173830,172830)['heater'],'long heat still observes off delay');
ok(!$c->tick(sample(173890,h:40),173890,172890)['heater'],'long heat ends after safe dew gap and delay');
$c=control();$c->fault='capture_timeout';
$paused=$c->tick([],1001,1,true);
ok($paused['state']==='capture_pause'&&!$paused['heater']&&$paused['fan_percent']==0
    &&$paused['fault']==='capture_timeout','new capture can pause a latched timeout without sensor or clearing fault');
ok($c->tick([],1002,2)['state']==='fault','timeout remains visible after capture');
rejects(fn()=> $c->reset(sample(800),1030),'stale reset rejected');
$c->reset(sample(1030,t:40),1030);
ok($c->tick(sample(1030,t:40),1030,30)['state']==='summer','technical fault reset allowed with fresh warm air');
$c=control();ok($c->tick(sample(t:30),1000,0)['state']==='summer','summer');
ok($c->tick(sample(1030,t:29.5),1030,30)['state']==='summer','summer hysteresis');
ok($c->tick(sample(1060,t:29),1060,60,true)['fan_percent']==0,'capture pause');
$f=new ClimateFanStartup();
foreach([[0,0,0],[30,1,100],[40,2.9,100],[40,3,40],[65,3.1,65],[20,3.2,20],[0,4,0],[30,5,100],[0,5.1,0],[50,5.2,100],[60,7.2,60]] as [$target,$mono,$want]) {
    ok($f->apply($target,$mono)==$want,'boost/running change/stop');
}
$c=control(['fan_max_percent'=>80]);$f=new ClimateFanStartup();
$r=$c->tick(sample(h:95),1000,0);ok($f->apply($r['fan_percent'],0)==100,'config edit initial boost');
ok($f->apply($c->tick(sample(h:95),1000,2)['fan_percent'],2)==80,'config old target');
$c->configure(array_replace($c->config,['fan_max_percent'=>100]));
ok($f->apply($c->tick(sample(h:95),1000,2.1)['fan_percent'],2.1)==95,'config running target changes directly');

// Real filesystem + actual supervisor + inherited capture lock, confined to temp.
$root=sys_get_temp_dir().'/camera-climate-php-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['run','status','servers','keys'] as $name)mkdir($root.'/'.$name,0700);
foreach(['CAMERA_RPI_CLIMATE_CONFIG'=>$root.'/climate.json','CAMERA_RPI_CLIMATE_RUNTIME'=>$root.'/run',
    'CAMERA_RPI_STATUS_ROOT'=>$root.'/status','CAMERA_RPI_STATUS_GROUP'=>'','CAMERA_RPI_ADMIN_TEST_MODE'=>'1',
    'CAMERA_RPI_CLIMATE_HELPER'=>realpath(__DIR__.'/../scripts/camera-rpi-climate.php'),
    'CAMERA_RPI_CONFIG_DIR'=>$root.'/servers','CAMERA_RPI_KEY_DIR'=>$root.'/keys','CAMERA_RPI_OPERATION_LOCK'=>$root.'/operation.lock',
    'CAMERA_RPI_GPIO_CONFIG'=>$root.'/gpio-missing.json','CAMERA_RPI_GPIO_ACTIVE'=>$root.'/gpio-active-missing.json',
    'CAMERA_RPI_PRIVACY_CONFIG'=>$root.'/privacy-missing.json',
    'CAMERA_RPI_PWM_BIN'=>$root.'/NONEXISTENT-no-hardware'] as $k=>$v)putenv("$k=$v");
$app=new ClimateApp();$daemon=null;$capture=null;
$notify=socket_create(AF_UNIX,SOCK_DGRAM,0);
socket_bind($notify,$root.'/notify.sock');socket_set_nonblock($notify);
putenv('NOTIFY_SOCKET='.$root.'/notify.sock');
$start=function()use(&$daemon,$root){$pipes=[];$daemon=proc_open([PHP_BINARY,__DIR__.'/../scripts/camera-rpi-climate.php','run'],
    [0=>['file','/dev/null','r'],1=>['file',$root.'/daemon.log','a'],2=>['file',$root.'/daemon.log','a']],$pipes);};
$status=fn()=>ClimateApp::fields($root.'/status/climate.status');
try {
    [$config,$rev]=$app->loadConfig();ok($rev==='missing','missing config off');
    $save=function($c,$r)use($app){ob_start();try{$app->save(json_encode(['config'=>$c,'revision'=>$r],JSON_THROW_ON_ERROR));}finally{ob_end_clean();}};
    $save($config,$rev);$before=file_get_contents($app->configFile);
    [$config,$rev]=$app->loadConfig();$config['mode']='simulate';$config['average_samples']=1;$save($config,$rev);
    ok(file_get_contents($app->configFile.'.previous')===$before,'config backup exact');
    ok((fileperms($app->configFile)&0777)===0600,'private mode');
    rejects(fn()=> $save($config,$rev),'CAS rejects stale');
    [$config,$rev]=$app->loadConfig();rejects(fn()=> $save(array_replace($config,['mode'=>'auto']),$rev),'auto still requires installed PWM binary');
    $oldRaw=json_encode($config+['max_temperature_c'=>35.0,'max_heat_seconds'=>900],JSON_THROW_ON_ERROR);
    ClimateApp::atomic($app->configFile,$oldRaw);
    [$loaded,$rev]=$app->loadConfig();
    ok($loaded===$config&&$rev===hash('sha256',$oldRaw),'stored migration keeps settings and raw CAS revision');
    ok(file_get_contents($app->configFile)===$oldRaw,'legacy config read never writes SD');
    ob_start();try{$app->migrateRetiredSettings();}finally{ob_end_clean();}
    ok(file_get_contents($app->configFile.'.previous')===$oldRaw,'legacy config retained in backup on save');
    ok(!array_key_exists('max_heat_seconds',ClimateApp::json(file_get_contents($app->configFile)))
        && !array_key_exists('max_temperature_c',ClimateApp::json(file_get_contents($app->configFile))),'save drops retired keys');
    $migrated=file_get_contents($app->configFile);$app->migrateRetiredSettings();
    ok(file_get_contents($app->configFile)===$migrated&&file_get_contents($app->configFile.'.previous')===$oldRaw,'repeat migration leaves files and backup unchanged');
    symlink($app->configFile,$root.'/link');rejects(fn()=>ClimateApp::read($root.'/link'),'symlink refused');
    $publish=function(float $temp=20,float $hum=90,string $state='ok')use($root){$text='';foreach(sample(time(),$temp,$hum,$state) as $k=>$v)$text.="$k=$v\n";ClimateApp::atomic($root.'/status/current.status',$text);};
    $publish();$start();
    waitFor(fn()=>($status()['heater']??'')==='on','real simulated daemon heats');
    waitFor(function()use($notify){$message='';$peer='';return @socket_recvfrom($notify,$message,64,0,$peer)>0&&$message==='WATCHDOG=1';},'systemd heartbeat is sent');
    ok(($status()['outputs']??'')==='none','simulation never opens absent backend');
    waitFor(fn()=>count(ClimateHistory::read($root.'/status/climate-history.tsv',time()))>0,'real supervisor records RAM history');
    // Exercise the actual supervisor/boost/status path, not only the model:
    // a bad DHT status stops heating but must not start another 100% pulse.
    waitFor(fn()=>($status()['fan_percent']??'')==='90','initial boost finishes');
    $publish(state:'unavailable');
    waitFor(fn()=>($status()['state']??'')==='sensor_error','brief DHT error published');
    ok(($status()['heater']??'')==='off'&&($status()['fan_percent']??'')==='90','daemon holds normal fan demand with heater off');
    $publish();
    waitFor(fn()=>($status()['state']??'')==='heating','daemon recovers after brief DHT error');
    $capture=fopen($root.'/run/capture.lock','ce');flock($capture,LOCK_EX);
    // A broken observational history must not stop the heater-off handshake.
    // The fixture deliberately blocks only history replacement, not live status.
    $historyPath=$root.'/status/climate-history.tsv';
    rename($historyPath,$root.'/history-before.tsv');mkdir($historyPath,0700);
    $app->pause(60);
    waitFor(fn()=>($status()['state']??'')==='capture_pause','pause acknowledged after off');
    waitFor(fn()=>str_contains(file_get_contents($root.'/daemon.log'),'Klima-Verlauf nicht schreibbar'),'history failure reported without blocking ACK');
    rmdir($historyPath);
    closeProcess($daemon);$daemon=null;$start();
    waitFor(fn()=>($status()['state']??'')==='capture_pause','restart retains held capture');
    fclose($capture);$capture=null;
    waitFor(fn()=>($status()['state']??'')==='heating','lock release resumes');
    waitFor(fn()=>count(ClimateHistory::read($historyPath,time()))>0,'history resumes after failed write');
    // Execute the REAL Bash capture helper; its exec descendant must keep the lock.
    $camera=$root.'/fake-camera';
    file_put_contents($camera,"#!/bin/sh\nsleep 0.6\ntest -s \"\$CAMERA_RPI_CLIMATE_RUNTIME/capture.ack\" || exit 91\nprintf done > \"\$CAMERA_TEST_MARKER\"\n");chmod($camera,0755);
    putenv('CAMERA_RPI_GPHOTO_BIN='.$camera);putenv('CAMERA_TEST_MARKER='.$root.'/called');
    // Mock the physical EOS adapter too: the default backend now uses it.
    // This test checks the inherited climate lock, not the shutter protocol.
    putenv('CAMERA_RPI_EOS_HELPER='.$camera);
    $command='source '.escapeshellarg(realpath(__DIR__.'/../scripts/lib/camera-rpi-common.sh')).'; CAPTURE_BACKEND=gphoto2; GPHOTO_TIMEOUT_SECONDS=10; cr_capture_to_file '.escapeshellarg($root.'/image');
    $pipes=[];$p=proc_open(['/bin/bash','-c',$command],[0=>['file','/dev/null','r'],1=>['file',$root.'/capture.log','a'],2=>['file',$root.'/capture.log','a']],$pipes);
    ok(proc_close($p)===0&&is_file($root.'/called'),'real capture exec has inherited ACK lock');
    unlink($root.'/called');
    // A missing supervisor must NEVER let the camera run; no synthetic ACK.
    closeProcess($daemon);$daemon=null;
    $p=proc_open(['/bin/bash','-c',$command],[0=>['file','/dev/null','r'],1=>['file',$root.'/capture.log','a'],2=>['file',$root.'/capture.log','a']],$pipes);
    ok(proc_close($p)!==0&&!is_file($root.'/called'),'missing ACK prevents physical capture');
    $publish(40);ClimateApp::atomic($root.'/run/fault','capture_timeout');$start();
    waitFor(fn()=>($status()['fault']??'')==='capture_timeout','technical daemon fault remains supported');
    closeProcess($daemon);$daemon=null;$start();
    waitFor(fn()=>($status()['fault']??'')==='capture_timeout','technical RAM fault persists across restart');
    // The real capture helper must reach its camera even with no DHT snapshot
    // and a previous capture timeout. No reset or fabricated measurement.
    unlink($root.'/status/current.status');
    rejects(fn()=> $app->reset(),'actual reset reports missing sensor');
    ok(ClimateApp::read($root.'/run/fault')==='capture_timeout','rejected reset preserves fault');
    $p=proc_open(['/bin/bash','-c',$command],[0=>['file','/dev/null','r'],1=>['file',$root.'/capture.log','a'],2=>['file',$root.'/capture.log','a']],$pipes);
    ok(proc_close($p)===0&&is_file($root.'/called'),'missing sensor plus latched timeout does not block capture');
    unlink($root.'/called');
    waitFor(fn()=>($status()['state']??'')==='fault','climate fault resumes after photo');
    ok(ClimateApp::read($root.'/run/fault')==='capture_timeout','photo never acknowledges climate fault');
    // A held lock alone is not permission: expired requests cannot pause the
    // fault or receive an ACK, including a freshly observed expired nonce.
    $capture=fopen($root.'/run/capture.lock','ce');flock($capture,LOCK_EX);
    $expiredToken=str_repeat('a',32);
    ClimateApp::atomic($root.'/run/capture.request',json_encode(['token'=>$expiredToken,'until'=>hrtime(true)/1e9-1],JSON_THROW_ON_ERROR));
    usleep(600000);
    ok(($status()['state']??'')==='fault'&&ClimateApp::read($root.'/run/capture.ack')!==$expiredToken,'expired capture cannot override fault or receive ACK');
    $app->pause(5);
    waitFor(fn()=>($status()['state']??'')==='capture_pause','fresh request after expired lease is admitted');
    waitFor(fn()=>($status()['state']??'')==='fault'&&ClimateApp::read($root.'/run/capture.ack')==='',
        'even a renewed photo lease expires and revokes ACK',7);
    fclose($capture);$capture=null;
    $publish(40);
    ob_start();try { $app->reset(); $resetReply=ob_get_contents(); } finally { ob_end_clean(); }
    ok(str_contains($resetReply,'OK'),'actual reset returns positive daemon confirmation');
    waitFor(fn()=>($status()['fault']??'')===''&&($status()['state']??'')==='summer','explicit technical reset works in summer');
    foreach(['overtemperature','heat_timeout'] as $retired) {
        closeProcess($daemon);$daemon=null;ClimateApp::atomic($root.'/run/fault',$retired);$publish(40);$start();
        waitFor(fn()=>($status()['state']??'')==='summer'&&ClimateApp::read($root.'/run/fault')===null,'retired thermal latch cleared on upgrade');
    }
    // Actual auto-mode IPC with a harmless actuator fixture. Missing DHT plus
    // old timeout is allowed only after OFF is accepted by the actuator; a
    // negative OFF response must never reach the simulated camera.
    closeProcess($daemon);$daemon=null;
    $actuator=$root.'/fake-pwm';
    file_put_contents($actuator,"#!/bin/sh\nwhile read -r verb heater duty; do\n"
        .'if [ "$heater:$duty" = "0:0" ] && [ -e '.escapeshellarg($root.'/reject-off')." ]; then printf 'ERROR\\n'; else printf 'OK %s %s\\n' \"\$heater\" \"\$duty\"; fi\ndone\n");
    chmod($actuator,0755);putenv('CAMERA_RPI_PWM_BIN='.$actuator);
    $autoApp=new ClimateApp();[$config,$rev]=$autoApp->loadConfig();$config['mode']='auto';
    ob_start();try{$autoApp->save(json_encode(['config'=>$config,'revision'=>$rev],JSON_THROW_ON_ERROR));}finally{ob_end_clean();}
    unlink($root.'/status/current.status');ClimateApp::atomic($root.'/run/fault','capture_timeout');$start();
    waitFor(fn()=>($status()['outputs']??'')==='gpio'&&($status()['fault']??'')==='capture_timeout','auto mode uses fixture actuator with missing sensor');
    $p=proc_open(['/bin/bash','-c',$command],[0=>['file','/dev/null','r'],1=>['file',$root.'/capture.log','a'],2=>['file',$root.'/capture.log','a']],$pipes);
    ok(proc_close($p)===0&&is_file($root.'/called'),'auto mode permits sensor-independent capture after actuator OFF');
    unlink($root.'/called');file_put_contents($root.'/reject-off','1');
    $p=proc_open(['/bin/bash','-c',$command],[0=>['file','/dev/null','r'],1=>['file',$root.'/capture.log','a'],2=>['file',$root.'/capture.log','a']],$pipes);
    ok(proc_close($p)!==0&&!is_file($root.'/called'),'failed actuator OFF prevents camera despite sensor-independent policy');
    ok(($status()['fault']??'')==='hardware_error','actuator failure remains visible');
    // Actual root bridge in its documented TEST_MODE, no remount or service call.
    closeProcess($daemon);$daemon=null;[$config,$rev]=$app->loadConfig();$config['dew_margin_c']=4.5;
    rejects(fn()=> $app->reset(),'stopped daemon cannot report successful reset');
    $payload=json_encode(['config'=>$config,'revision'=>$rev],JSON_THROW_ON_ERROR);
    $p=proc_open(['/bin/bash',__DIR__.'/../scripts/admin/camera-rpi-admin'],[0=>['pipe','r'],1=>['file',$root.'/bridge.log','w'],2=>['file',$root.'/bridge.log','a']],$pipes);
    fwrite($pipes[0],"action=climate_save\nclimate_json=$payload\n");fclose($pipes[0]);
    ok(proc_close($p)===0,'actual admin bridge save');ok($app->loadConfig()[0]['dew_margin_c']===4.5,'bridge persisted value');
    // Saving auto needs no second checkbox; no daemon is running here and the
    // harmless executable is only checked for presence, never launched.
    putenv('CAMERA_RPI_PWM_BIN=/usr/bin/true');$autoApp=new ClimateApp();
    [$config,$rev]=$autoApp->loadConfig();$config['mode']='auto';
    ob_start();try{$autoApp->save(json_encode(['config'=>$config,'revision'=>$rev],JSON_THROW_ON_ERROR));}finally{ob_end_clean();}
    ok($autoApp->loadConfig()[0]['mode']==='auto','auto saves with mode selection alone');
} finally {
    if(is_resource($capture))fclose($capture);closeProcess($daemon);socket_close($notify);removeTree($root);
}
echo "OK: $checks PHP climate checks (model, boost, validation, CAS, bridge, daemon, capture locks); no GPIO\n";
