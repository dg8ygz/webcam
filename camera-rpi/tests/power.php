<?php
declare(strict_types=1);
require __DIR__.'/../scripts/camera-rpi-power.php';
[$script,$binary,$dir]=$argv; $checks=0; $p=null; $pipes=[];
function check(bool $ok,string $label):void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
function rejects(callable $fn,string $label):void { try {$fn();} catch(Throwable) {check(true,$label);return;} check(false,$label); }
$socket=$dir.'/power.sock';
putenv('CAMERA_RPI_POWER_SOCKET='.$socket);
putenv('CAMERA_RPI_POWER_CONFIG='.$dir.'/power.json');
putenv('CAMERA_RPI_GPIO_CONFIG='.$dir.'/not-configured.json');
putenv('CAMERA_RPI_GPIO_ACTIVE='.$dir.'/active.json');
putenv('CAMERA_RPI_OPERATION_LOCK='.$dir.'/operation.lock');
putenv('CAMERA_RPI_LSUSB_BIN='.$dir.'/lsusb');
$pins=CameraGpioConfig::DEFAULTS; $pins['camera_power']=6;
file_put_contents($dir.'/active.json',json_encode($pins));
$usb=function(string $output,int $exit=0)use($dir):void {
    file_put_contents($dir.'/usb.txt',$output);
    file_put_contents($dir.'/lsusb',"#!/bin/sh\ncat ".escapeshellarg($dir.'/usb.txt')."\nexit $exit\n");chmod($dir.'/lsusb',0700);
};
try {
    $p=proc_open([$binary,'--test-power','6',$socket],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/power.log','w']],$pipes);
    for($i=0;$i<100 && !file_exists($socket);++$i) usleep(10000);
    check(file_exists($socket),'native socket created');
    check((fileperms($socket)&0777)===0600,'socket private');
    $app=new CameraPowerApp();
    check($app->command('STATUS')==='ON','startup on');
    foreach(["Bus 001 Device 004: ID 04a9:32e1 Canon, Inc. Canon Digital Camera\n", "Bus 007 Device 023: ID 0000:0000 Canon Other\n"] as $line) {
        $usb($line);check($app->status()['usb_state']==='on','Canon text ignores bus/device/id');
    }
    $app->set('OFF'); check($app->command('STATUS')==='OFF','explicit off ACK');
    check($app->status()['usb_state']==='on','USB remains authoritative despite GPIO OFF');
    $usb('Bus 001 Device 001: Linux Foundation root hub');
    check($app->status()['usb_state']==='off','missing Canon is off');
    $app->set('ON');check($app->command('STATUS')==='ON','explicit on ACK');
    check($app->status()['usb_state']==='off','GPIO ON is not USB detection');
    $app->set('ON');check(substr_count(file_get_contents($dir.'/power.log'),'POWER 1')===2,'repeated ON has no pulse');
    $usb('Canon',1);check($app->status()['usb_state']==='unknown','failed USB query never green/red');
    check(CameraPowerApp::usbState(str_repeat('a',16385),0)==='unknown','bounded USB output');
    $held=fopen($dir.'/operation.lock','ce');flock($held,LOCK_EX);
    rejects(fn()=>$app->set('OFF'),'capture lock excludes switching');
    check($app->command('STATUS')==='ON','busy rejection did not change output');
    fclose($held);
    $pins['camera_power']=null;file_put_contents($dir.'/active.json',json_encode($pins));
    rejects(fn()=>$app->set('OFF'),'no configured active pin rejected');
    check(!$app->status()['can_control'],'no pin disables controls');
    rejects(fn()=>$app->command('OFF; reboot'),'only fixed commands');
    foreach(["OFF\0\n","OFF\nON\n","BOGUS\n","RESET 9\n","RESET 301\n","RESET -1\n","RESET 120\0\n","RESET 120\nOFF\n"] as $bad) {
        $s=stream_socket_client('unix://'.$socket);stream_set_timeout($s,2);fwrite($s,$bad);
        check(fgets($s)==="ERROR\n",'native invalid command rejected');fclose($s);
        check($app->command('STATUS')==='ON','malformed command no effect');
    }
    // An idle/stalled socket client times out; the explicit supply state stays.
    $s=stream_socket_client('unix://'.$socket);stream_set_timeout($s,2);
    check(fgets($s)==="ERROR\n",'idle client bounded');fclose($s);
    check($app->command('STATUS')==='ON','idle client does not power off');
    putenv('CAMERA_RPI_RECOVERY_RUNTIME='.$dir.'/recovery');
    $pins['camera_power']=6;file_put_contents($dir.'/active.json',json_encode($pins));
    $state=$dir.'/recovery/state.json';
    check($app->settings()['reset_off_seconds']===45,'missing config preserves legacy duration');
    $before=file_get_contents($dir.'/power.log');
    rejects(fn()=>$app->saveSettings('{"reset_off_seconds":301,"revision":"missing"}'),'oversized duration rejected');
    rejects(fn()=>$app->saveSettings('{"reset_off_seconds":"120","revision":"missing"}'),'string not integer');
    rejects(fn()=>$app->saveSettings('{"reset_off_seconds":120,"reset_off_seconds":60,"revision":"missing"}'),'duplicate config key rejected');
    $app->saveSettings('{"reset_off_seconds":120,"revision":"missing"}');
    check($app->settings()['reset_off_seconds']===120,'configured 120 seconds');
    rejects(fn()=>$app->saveSettings('{"reset_off_seconds":45,"revision":"missing"}'),'stale form rejected');
    check((fileperms($dir.'/power.json')&0777)===0600,'configuration private');
    check(file_get_contents($dir.'/power.log')===$before,'saving has no power side effect');
    $app->recovery('ready');
    $app->recovery('failed');$app->recovery('failed');
    check($app->command('STATUS')==='ON','two failures do not reset');
    $app->recovery('failed');
    check($app->command('STATUS')==='RESET','third failure triggers reset');
    rejects(fn()=>$app->recovery('ready'),'capture blocked during reset');
    rejects(fn()=>$app->command('RESET'),'duplicate reset refused');
    $snapshot=json_decode(file_get_contents($state),true);
    check($snapshot['ready_at']-$snapshot['last_reset']===140,'120 off + 15 startup + 5 guard');
    usleep(800000);
    check($app->command('STATUS')==='RESET','120 second duration not old 45 second reset');
    check(str_contains(file_get_contents($dir.'/power.log'),'OFF for 120s'),'selected duration logged');
    $app->saveSettings(json_encode(['reset_off_seconds'=>10,'revision'=>$app->settings()['revision']]));
    check($app->command('STATUS')==='RESET','config save does not shorten running reset');
    usleep(700000);
    check($app->command('STATUS')==='ON','owner restores power without worker');
    rejects(fn()=>$app->recovery('ready'),'warmup survives owner restart');
    $s=json_decode(file_get_contents($state),true);$s['ready_at']=0;
    file_put_contents($state,json_encode($s));
    $app->recovery('ready');
    for($i=0;$i<5;++$i)$app->recovery('failed');
    check(json_decode(file_get_contents($state),true)['resets']===1,'cooldown shared between calls');
    $s=json_decode(file_get_contents($state),true);$s['last_reset']=1;$s['resets']=3;
    file_put_contents($state,json_encode($s));
    $app->recovery('failed');
    check($app->command('STATUS')==='ON','maximum three resets without success');
    $app->recovery('ok');
    check(json_decode(file_get_contents($state),true)['resets']===0,'valid capture restores budget');
    $app->set('OFF');
    for($i=0;$i<3;++$i)$app->recovery('failed');
    rejects(fn()=>$app->recovery('ready'),'manual off blocks capture');
    rejects(fn()=>$app->command('RESET'),'manual off cannot be reset');
    check($app->command('STATUS')==='OFF','manual off preserved');
    $app->set('ON');$app->command('RESET');$app->set('OFF');usleep(800000);
    check($app->command('STATUS')==='OFF','manual off cancels reset timer');
    $app->set('ON');$app->command('RESET');
    proc_terminate($p);$exit=proc_close($p);$p=null;
    check($exit===0,'clean signal exit');
    check(!file_exists($socket),'socket removed on stop');
    check(str_ends_with(file_get_contents($dir.'/power.log'),"POWER 1\n"),'shutdown during reset restores supply');
    check(!$app->status()['can_control'],'stopped owner disables buttons');
    echo "OK: $checks camera power native/USB/locking checks; simulated GPIO only\n";
} finally { if(is_resource($p)) {proc_terminate($p);proc_close($p);} }
