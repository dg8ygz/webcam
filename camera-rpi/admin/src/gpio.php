<?php
declare(strict_types=1);

/** Parse typed form data only. Root repeats membership/uniqueness/CAS. */
function save_gpio_form(): void
{
    $pins=[];
    foreach (['dht','heater','fan','camera_power','focus'] as $role) {
        $raw=post_text('gpio_'.$role,2);
        if (in_array($role,['camera_power','focus'],true) && $raw==='') { $pins[$role]=null; continue; }
        if (!preg_match('/^[1-9][0-9]?$/D',$raw)) { throw new RuntimeException('Ungültiger GPIO.'); }
        $pins[$role]=(int)$raw;
    }
    bridge_call(['action'=>'gpio_save','gpio_json'=>json_encode([
        'pins'=>$pins,'revision'=>post_text('gpio_revision',64),
    ],JSON_THROW_ON_ERROR)]);
}
function gpio_view(): array
{
    try {
        $reply=bridge_call(['action'=>'gpio_get']);
        $data=json_decode($reply['stdout'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['pins'],$data['active'],$data['header'],$data['labels'],$data['revision'])) {
            throw new RuntimeException('Unvollständige GPIO-Antwort.');
        }
        return $data;
    } catch (Throwable $e) { return ['error'=>$e->getMessage()]; }
}
