<?php
declare(strict_types=1);

/** Form adapter only; root validator imports positions and validates all ranges. */
function save_privacy_form(): void
{
    $c=['mode'=>post_text('privacy_mode',16),'source_server'=>post_text('privacy_source_server',64),
        'timezone'=>post_text('privacy_timezone',64),'start'=>post_text('privacy_start',5),'end'=>post_text('privacy_end',5)];
    foreach (['sunrise_offset','sunset_offset','minimum_percent','hysteresis_percent','x','y','width','height'] as $key) {
        $raw=post_text('privacy_'.$key,12);
        if (!preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/D',$raw)) { throw new RuntimeException('Ungültiger Zahlenwert: '.$key); }
        $value=in_array($key,['minimum_percent','hysteresis_percent'],true) ? (float)$raw : filter_var($raw,FILTER_VALIDATE_INT);
        if ($value===false) { throw new RuntimeException('Ganze Minuten/Prozentwerte erwartet.'); }
        if (in_array($key,['x','y','width','height'],true)) { $c['region'][$key]=$value; } else { $c[$key]=$value; }
    }
    $c['dark_enabled']=isset($_POST['privacy_dark_enabled']);
    bridge_call(['action'=>'privacy_save','privacy_json'=>json_encode(['config'=>$c,'revision'=>post_text('privacy_revision',64)],JSON_THROW_ON_ERROR)]);
}

function privacy_view(): array
{
    try { return json_decode(bridge_call(['action'=>'privacy_get'])['stdout'],true,16,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { return ['error'=>'Aufnahmeschutz noch nicht installiert oder ungültig: '.$e->getMessage()]; }
}
