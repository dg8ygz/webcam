<?php
declare(strict_types=1);
/** Real native process/IPC/PWM loop, explicit no-GPIO test backend. */
require_once __DIR__.'/../scripts/lib/climate-gpio.php';
$binary=$argv[1];$stage=$argv[2];$checks=0;
function check(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function startNative(string $label):array{global $binary,$stage;$pipes=[];$p=proc_open([$binary,'--test'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',"$stage/$label.log",'w']],$pipes);stream_set_timeout($pipes[1],4);return [$p,$pipes];}
function setNative(array $pipes,string $s):string|false{fwrite($pipes[0],$s."\n");fflush($pipes[0]);return fgets($pipes[1]);}
function finishNative($p,array $pipes):int{foreach($pipes as $pipe)fclose($pipe);return proc_close($p);}
// Diagnostics may follow the final GPIO update. Inspect the last LEVEL record,
// not the last log line; logging intentionally happens AFTER safe shutdown.
function lastLevel(string $file):string{preg_match_all('/^LEVEL ([0-3])$/m',file_get_contents($file),$rows);return $rows[1]===[]?'':end($rows[1]);}
[$p,$pipes]=startNative('pwm');
check(setNative($pipes,'SET 1 500')==="OK 1 500\n",'set ACK');usleep(250000);
check(setNative($pipes,'SET 1 500')==="OK 1 500\n",'same-duty heartbeat');
check(setNative($pipes,'SET 0 0')==="OK 0 0\n",'off ACK');
check(finishNative($p,$pipes)===0,'EOF safe exit');
$log=file_get_contents("$stage/pwm.log");check(str_starts_with($log,"LEVEL 1\n")&&str_ends_with($log,"LEVEL 1\n"),'safe startup + final level');
check(substr_count($log,"LEVEL 2\n")>10&&substr_count($log,"LEVEL 0\n")>10,'real 100Hz phase loop exercised');
foreach(['SET 1 0','SET 0 1001','SET 2 10','SET 0 -1','SET 0 1;id','SET 0 999999999999999999999999999999999'] as $bad){
    [$p,$pipes]=startNative('invalid');setNative($pipes,$bad);check(finishNative($p,$pipes)!==0,'invalid command rejected');
    check(str_ends_with(file_get_contents("$stage/invalid.log"),"LEVEL 1\n"),'invalid input leaves off');
}
[$p,$pipes]=startNative('watchdog');check(setNative($pipes,'SET 1 1000')==="OK 1 1000\n",'watchdog initial on');
$start=microtime(true);check(fgets($pipes[1])===false,'watchdog closes stream independently');
check(microtime(true)-$start>=2.8&&microtime(true)-$start<4,'watchdog three seconds');
check(finishNative($p,$pipes)!==0,'watchdog nonzero exit');check(lastLevel("$stage/watchdog.log")==='1','watchdog final off');
[$p,$pipes]=startNative('signal');setNative($pipes,'SET 1 1000');proc_terminate($p,SIGTERM);
check(fgets($pipes[1])===false,'TERM closes');finishNative($p,$pipes);
check(str_ends_with(file_get_contents("$stage/signal.log"),"LEVEL 1\n"),'TERM final off');
$outputs=new ClimateOutputs($binary,true);$outputs->apply(true,50);$outputs->apply(false,0);$outputs->close();check(true,'real PHP adapter roundtrip');
$outputs=new ClimateOutputs($binary,true,18,19);$outputs->apply(false,25);$outputs->close();check(true,'configured pins adapter roundtrip');
foreach ([['--test','5','5'],['--test','1','27'],['--test','5','28'],['--test','05','27'],['--test','5','false'],['--power','28']] as $args) {
    $pipes=[];
    $p=proc_open(array_merge([$binary],$args),[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    check(proc_close($p)===2,'invalid pin arguments rejected before claim');
}
echo "OK: $checks native PWM/IPC checks, EOF/TERM/3s watchdog/invalid input; test GPIO only\n";
