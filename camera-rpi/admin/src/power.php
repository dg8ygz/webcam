<?php
declare(strict_types=1);
function camera_power_view(): array
{
    try {
        return json_decode(bridge_call(['action'=>'power_status'])['stdout'],true,16,JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['usb_state'=>'unknown','can_control'=>false,'pin'=>null,'detail'=>$e->getMessage()];
    }
}
function camera_power_label(string $state): string
{
    return match ($state) {
        'on'=>'Kamera an – Canon über USB erkannt.',
        'off'=>'Kamera aus – keine Canon über USB erkannt.',
        default=>'Kamerastatus unbekannt – USB-Abfrage fehlgeschlagen.',
    };
}

/** Both form parsing and the root helper validate the bounded integer. */
function save_power_form(): void
{
    $raw=post_text('reset_off_seconds',3);
    if (!preg_match('/^[1-9][0-9]{1,2}$/D',$raw) || (int)$raw>300) {
        throw new RuntimeException('Bitte 10 bis 300 ganze Sekunden angeben.');
    }
    bridge_call(['action'=>'power_save','power_json'=>json_encode([
        'reset_off_seconds'=>(int)$raw,'revision'=>post_text('power_revision',64),
    ],JSON_THROW_ON_ERROR)]);
}
