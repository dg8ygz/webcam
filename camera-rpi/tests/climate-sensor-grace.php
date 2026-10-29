<?php
declare(strict_types=1);
/** Pure controller + real boost model; simulated clocks, no GPIO or IIO reads. */
require_once __DIR__.'/../scripts/lib/climate-controller.php';
$checks = 0;
function check(bool $ok, string $label): void
{ global $checks; $checks++; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } }
function reading(int $stamp, float $t=20, float $h=90, string $state='ok'): array
{ return ['updated_epoch'=>(string)$stamp, 'temperature_c'=>(string)$t, 'humidity_percent'=>(string)$h, 'sensor_state'=>$state]; }
function controller(array $options=[]): ClimateController
{ return new ClimateController(array_replace(ClimateConfig::DEFAULTS, ['mode'=>'simulate','average_samples'=>1], $options)); }

// Idle, dew protection and summer retain their own targets, never the startup
// boost. The deadline belongs to the last valid pair, not the last error report.
foreach ([[20,40,0],[20,90,90],[35,90,75]] as [$t,$h,$target]) {
    $c=controller(); $f=new ClimateFanStartup();
    $r=$c->tick(reading(1000,$t,$h),1000,0);
    check($r['fan_percent']==$target,'normal demand');
    check($f->apply($r['fan_percent'],0)==($target>0?100:0),'initial startup only');
    foreach ([1,2,30,60,120] as $elapsed) {
        $r=$c->tick(reading(1000+$elapsed,state:'unavailable'),1000+$elapsed,$elapsed);
        check($r['state']==='sensor_error'&&!$r['heater']&&$r['fan_percent']==$target,'short failure holds normal demand');
        check($f->apply($r['fan_percent'],$elapsed)==($elapsed<2&&$target>0?100:$target),'boost expires despite read failure');
        check(!isset($r['dew_point_c']),'failed sample never presented as measured dew point');
    }
    $r=$c->tick(reading(1121,state:'unavailable'),1121,121);
    check(!$r['heater']&&$r['fan_percent']==100,'full fallback only after expiry');
    $r=$c->tick([],1122,122,true);
    check(!$r['heater']&&$r['fan_percent']==0,'capture pause overrides sensor fallback');
}

// Seven valid pairs survive a brief failed reading; invalid or duplicate pairs
// neither fill nor reset the buffer. A long outage requires a complete rebuild.
$c=controller(['average_samples'=>7]);
for ($i=0;$i<7;$i++) { $r=$c->tick(reading(1000+30*$i),1000+30*$i,30*$i); }
check($r['heater'],'seven-pair baseline');
$r=$c->tick([],1210,210);
check(!$r['heater']&&$r['fan_percent']==90,'brief error disables heater');
check($c->tick(reading(1240),1240,240)['heater'],'recovery without unnecessary warmup');
$r=$c->tick([],1361,361);
check($r['fan_percent']==100,'old baseline expired');
check($c->tick(reading(1390),1390,390)['state']==='warming','long outage clears buffer');
for ($i=1;$i<7;$i++) { $r=$c->tick(reading(1390),1390,390+$i); }
check($r['state']==='warming','repeated snapshot cannot rebuild buffer');
for ($i=1;$i<7;$i++) { $r=$c->tick(reading(1390+30*$i),1390+30*$i,390+30*$i); }
check($r['heater'],'seven new pairs rebuild buffer');
$c=controller(['average_samples'=>2]);
$c->tick(reading(1000),1000,0); $c->tick(reading(1030),1030,30);
check($c->tick(reading(1300),1300,300)['state']==='warming','fresh sample after scheduling gap still rebuilds');
$c=controller(['average_samples'=>3]);
$c->tick(reading(1000),1000,0); $c->tick([],1010,10);
$c->tick(reading(1030),1030,30);
check($c->tick(reading(1030),1031,31)['state']==='warming','invalid and repeated pairs do not count');
check($c->tick(reading(1060),1060,60)['heater'],'partial buffer preserved');

// Wall/monotonic time both limit retention. An already aged snapshot never gets
// another 120 seconds; future or out-of-order reports cannot extend the grace.
$c=controller(); $c->tick(reading(1000),1100,500);
check($c->tick([],1100,520)['fan_percent']==90,'cached baseline remaining twenty seconds');
check($c->tick([],1100,521)['fan_percent']==100,'frozen wall does not extend aged baseline');
$c=controller(); $c->tick(reading(1000),1000,0);
check($c->tick(reading(1000),1000,121)['fan_percent']==100,'frozen successful snapshot expires monotonically');
$c=controller(); $c->tick(reading(1000),1000,0);
check($c->tick([],999,1)['fan_percent']==100,'backwards wall clock cannot retain baseline');
$c=controller(); $c->tick(reading(1000),1000,500);
check($c->tick([],1001,499)['fan_percent']==100,'backwards monotonic clock rejected');
$c=controller(); $c->tick(reading(1000),1000,0);
foreach ([[],reading(999),reading(9999),reading(1090,state:'unavailable')] as $bad) {
    $r=$c->tick($bad,1090,90);
    check(!$r['heater']&&$r['fan_percent']==90,'invalid report cannot refresh baseline');
}
check($c->tick([],1121,121)['fan_percent']==100,'invalid reports did not extend deadline');
$c=controller(['sensor_max_age_seconds'=>30]); $c->tick(reading(1000),1000,0);
check($c->tick([],1030,30)['fan_percent']==90&&$c->tick([],1031,31)['fan_percent']==100,'configured shorter grace respected');

// A real restart/off/reset has no trusted remembered target. Capture pauses do
// not erase the target, but starting again from zero still uses the 2s boost.
$c=controller(); check($c->tick([],1000,0)['fan_percent']==100,'cold start has no grace baseline');
$c->tick(reading(1000),1000,0);
$c->configure(array_replace($c->config,['mode'=>'off']));
check($c->tick([],1001,1)['fan_percent']==0,'off stays off');
$c->configure(array_replace($c->config,['mode'=>'simulate']));
check($c->tick([],1002,2)['fan_percent']==100,'off cleared remembered demand');
$c->tick(reading(1030),1030,30); $c->reset(reading(1030),1030);
check($c->tick([],1031,31)['fan_percent']==100,'reset cleared remembered demand');
$c=controller(); $f=new ClimateFanStartup();
$f->apply($c->tick(reading(1000),1000,0)['fan_percent'],0);
check($f->apply($c->tick([],1030,30,true)['fan_percent'],30)==0,'short error during pause stays off');
check($f->apply($c->tick([],1031,31)['fan_percent'],31)==100,'resume from pause starts motor');
check($f->apply($c->tick([],1033,33)['fan_percent'],33)==90,'resume boost returns to held target');
$c->fault='capture_timeout';
$r=$c->tick([],1034,34,true);
check($r['state']==='capture_pause'&&!$r['heater']&&$r['fan_percent']==0
    &&$r['fault']==='capture_timeout','new photo pauses timeout fault without resetting it');
foreach ([[],reading(800),reading(1035,state:'error'),reading(1035,state:'disabled'),reading(1035)] as $fields) {
    foreach (['','capture_timeout'] as $fault) {
        $c=controller();$c->fault=$fault;
        $r=$c->tick($fields,1035,35,true);
        check(!$r['heater']&&$r['fan_percent']==0&&$r['fault']===$fault,'photo outputs off independent of sensor and earlier timeout');
        $r=$c->tick($fields,1036,36);
        check(!$fault||($r['state']==='fault'&&$r['fan_percent']==100),'timeout cooling resumes outside photo');
    }
}
foreach (['hardware_error','config_error'] as $fault) {
    $c=controller();$c->fault=$fault;
    $r=$c->tick([],1035,35,true);
    check($r['state']==='fault'&&!$r['heater']&&$r['fan_percent']==100,'actuator/configuration fault is not a sensor bypass');
}
printf("OK: %d climate sensor-grace checks; simulated clocks, no GPIO/IIO\n", $checks);
