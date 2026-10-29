<?php
declare(strict_types=1);
require __DIR__.'/../scripts/lib/climate-controller.php';
require __DIR__.'/../scripts/lib/climate-history.php';
$checks=0;
function check(bool $ok,string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
$config=array_replace(ClimateConfig::DEFAULTS,['mode'=>'manual80']);
$controller=new ClimateController($config);
foreach ([[],['sensor_state'=>'error'],['updated_epoch'=>'1000','sensor_state'=>'ok','temperature_c'=>'70','humidity_percent'=>'2']] as $fields) {
    $out=$controller->tick($fields,1000,1);
    check($out['heater']===true && $out['fan_percent']==80 && $out['state']==='heating','fixed demand independent of DHT');
}
$out=$controller->tick([],1000,2,true);
check(!$out['heater'] && $out['fan_percent']==0 && $out['state']==='capture_pause','pause before USB still switches off');
$out=$controller->tick([],1001,3,false);
check($out['heater'] && $out['fan_percent']==80,'returns to 80 after capture');
$fan=new ClimateFanStartup();
check($fan->apply(80,0)===100.0,'startup boost retained');
check($fan->apply(80,2)===80.0,'boost ends at 80');
foreach (['hardware_error','capture_timeout','config_error'] as $fault) {
    $controller->fault=$fault;
    $out=$controller->tick([],1002,4);
    check(!$out['heater'] && $out['state']==='fault','technical fault still switches heater off');
}
$controller->fault='capture_timeout';
$out=$controller->tick([],1003,5,true);
check(!$out['heater'] && $out['fan_percent']==0,'new capture accepted after old capture timeout');
$controller->reset([],1004);
check($controller->tick([],1004,6)['heater']===true,'manual reset does not require ignored sensor');
$controller->configure(array_replace($config,['mode'=>'off']));
$out=$controller->tick([],1005,7);
check(!$out['heater'] && $out['fan_percent']==0,'off overrides fixed mode');
$controller->configure(array_replace($config,['mode'=>'auto']));
$out=$controller->tick([],1006,8);
check(!$out['heater'] && $out['state']==='sensor_error','auto still rejects missing DHT');
$controller->configure($config);
$out=$controller->tick([],time(),9);
$row=ClimateHistory::snapshot(['updated_epoch'=>time(),'mode'=>'manual80','outputs'=>'gpio']
    + array_replace($out,['heater'=>'on']));
check($row!==null,'manual mode can be archived');
echo "OK: $checks fixed-mode checks; no GPIO\n";
