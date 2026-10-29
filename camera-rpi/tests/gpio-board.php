<?php
declare(strict_types=1);
// Documented DT identities, independent of host/kernel, no physical pin reads.
require_once __DIR__.'/../scripts/lib/gpio-config.php';
$checks=0;
function boardCheck(bool $value,string $label):void {
    global $checks; ++$checks; if (!$value) throw new RuntimeException($label);
}
foreach (['model-a-plus','model-b-plus','2-model-b','3-model-b','3-model-b-plus',
    '3-model-a-plus','4-model-b','400','model-zero','model-zero-w','model-zero-2-w'] as $name) {
    $b=CameraGpioBoard::identify("Test\0","raspberrypi,$name\0brcm,bcm2835\0",'00c03115');
    boardCheck($b['supported'] && $b['header_pins']===40,'known 40 pin '.$name);
    boardCheck($b['header']===CameraGpioConfig::HEADER,'exact full mapping');
    boardCheck($b['revision']==='00c03115' && $b['model']==='Test','display values');
}
$rev2=[2=>3,3=>5,4=>7,7=>26,8=>24,9=>21,10=>19,11=>23,14=>8,15=>10,
    17=>11,18=>12,22=>15,23=>16,24=>18,25=>22,27=>13];
foreach (['model-a','model-b-rev2'] as $name) {
    $b=CameraGpioBoard::identify('old',"raspberrypi,$name\0");
    boardCheck($b['header']===$rev2 && $b['supported'] && $b['header_pins']===26,'26 rev2 exact pins');
    boardCheck(!isset($b['header'][5],$b['header'][26]),'no pins from 40 pin extension');
}
$early=$rev2; unset($early[2],$early[3],$early[27]); $early[21]=13; ksort($early);
$b=CameraGpioBoard::identify('old',"raspberrypi,model-b\0");
boardCheck($b['header']===$early && $b['header_pins']===26,'rev1 pin13 GPIO21');
foreach (['5-model-b','500'] as $name) {
    $b=CameraGpioBoard::identify('RP1',"raspberrypi,$name\0");
    boardCheck(!$b['supported'] && $b['header_pins']===40 && str_contains($b['reason'],'RP1'),'RP1 recognized not enabled');
}
foreach (['compute-module','3-compute-module','3-plus-compute-module','4-compute-module','4s-compute-module','5-compute-module'] as $name) {
    $b=CameraGpioBoard::identify('CM',"raspberrypi,$name\0");
    boardCheck(!$b['supported'] && $b['header']===[] && $b['header_pins']===null,'carrier not assumed');
}
foreach (['','brcm,bcm2835',"raspberrypi,future\0", "raspberrypi,4-model-b\0raspberrypi,model-b\0"] as $compat) {
    $b=CameraGpioBoard::identify('Raspberry Pi 4 Model B',$compat);
    boardCheck(!$b['supported'] && $b['header']===[],'unknown/ambiguous never inferred from model text');
}
echo "OK: $checks board/header checks; DT identities, 26 Rev1/Rev2, 40, RP1/CM/unknown; no GPIO\n";
