<?php
declare(strict_types=1);
// No GPIO, services or live configuration: exercise every header/role collision.
require __DIR__.'/../scripts/lib/gpio-config.php';
$checks=0;
function check(bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('GPIO validation check failed'); }
    $checks++;
}
function rejected(mixed $value, string $message=''): void {
    try { CameraGpioConfig::validate($value); }
    catch (InvalidArgumentException $e) {
        check($message==='' || str_contains($e->getMessage(),$message)); return;
    }
    throw new RuntimeException('Invalid pin allocation accepted');
}
$defaults=CameraGpioConfig::DEFAULTS;
check(CameraGpioConfig::validate($defaults)===$defaults);
check(CameraGpioConfig::pinLabel(4)==='GPIO 4 · Pin 7');
check(CameraGpioConfig::pinLabel(23)==='GPIO 23 · Pin 16');
check(CameraGpioConfig::validate(array_replace($defaults,['camera_power'=>6]))['camera_power']===6);
foreach (array_keys(CameraGpioConfig::LABELS) as $one) {
    foreach (array_keys(CameraGpioConfig::LABELS) as $two) {
        if ($one===$two) { continue; }
        rejected(array_replace($defaults,[$one=>22,$two=>22]),'bereits in Benutzung');
    }
    foreach ([-1,0,1,28,40,100,'4',4.0,true,false,[],null] as $bad) {
        if ($bad===null && in_array($one,['camera_power','focus'],true)) { continue; }
        rejected(array_replace($defaults,[$one=>$bad]));
    }
}
foreach (CameraGpioConfig::HEADER as $pin=>$physical) {
    $v=$defaults;
    // Other roles use distinct free pins so every offered BCM value is covered.
    $free=array_values(array_diff(array_keys(CameraGpioConfig::HEADER),[$pin]));
    $v['dht']=$pin; $v['heater']=$free[0]; $v['fan']=$free[1];
    check(CameraGpioConfig::validate($v)['dht']===$pin);
    check($physical>=3 && $physical<=40);
}
rejected([]); rejected('4'); rejected($defaults+['unknown'=>13]);
$old=$defaults; unset($old['focus']);
check(CameraGpioConfig::validate($old)===$defaults);
check(CameraGpioConfig::validate(array_replace($defaults,['focus'=>13]))['focus']===13);
$missing=$defaults; unset($missing['fan']); rejected($missing);
printf("OK: %d GPIO allocation checks; no hardware or services\n",$checks);
