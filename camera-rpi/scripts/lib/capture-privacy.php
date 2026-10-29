<?php
declare(strict_types=1);

/** Device-wide capture policy. No GPIO, camera, network or persistent writes here.
 * Solar events use PHP date_sun_info (not twilight); offsets are elapsed minutes.
 * Boolean polar-day/night results are deliberately NOT treated as timestamps.
 */
final class CapturePrivacy
{
    public const DEFAULTS = [
        'mode'=>'off', 'source_server'=>'', 'timezone'=>'Europe/Berlin',
        'start'=>'08:00', 'end'=>'18:00', 'sunrise_offset'=>0, 'sunset_offset'=>0,
        'dark_enabled'=>false, 'minimum_percent'=>10.0, 'hysteresis_percent'=>0.0,
        'region'=>['x'=>0, 'y'=>0, 'width'=>100, 'height'=>100],
    ];

    /** Strict input boundary, also when disabled: no coercion of false/NaN/unknown fields. */
    public static function validate(array $c): array
    {
        if (array_diff_key($c,self::DEFAULTS) || array_diff_key(self::DEFAULTS,$c)) { throw new InvalidArgumentException('Unvollständige Aufnahmeoptionen.'); }
        if (!in_array($c['mode'],['off','fixed','solar'],true)
            || !is_string($c['source_server']) || !preg_match('/^(?:[a-z0-9][a-z0-9_-]{0,63})?$/D',$c['source_server'])
            || !is_string($c['timezone']) || !in_array($c['timezone'],DateTimeZone::listIdentifiers(),true)) {
            throw new InvalidArgumentException('Aufnahmemodus, Standortquelle oder Zeitzone ungültig.');
        }
        foreach (['start','end'] as $key) {
            if (!is_string($c[$key]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$c[$key])) { throw new InvalidArgumentException('Uhrzeit ungültig.'); }
        }
        if ($c['mode']==='fixed' && $c['start']===$c['end']) { throw new InvalidArgumentException('Anfang und Ende dürfen nicht gleich sein.'); }
        foreach (['sunrise_offset','sunset_offset'] as $key) {
            if (!is_int($c[$key]) || abs($c[$key])>720) { throw new InvalidArgumentException('Sonnenversatz muss zwischen −720 und +720 Minuten liegen.'); }
        }
        if (!is_bool($c['dark_enabled'])) { throw new InvalidArgumentException('Helligkeitsfilter ungültig.'); }
        foreach (['minimum_percent','hysteresis_percent'] as $key) {
            if ((!is_float($c[$key]) && !is_int($c[$key])) || !is_finite((float)$c[$key]) || $c[$key]<0 || $c[$key]>100) { throw new InvalidArgumentException('Helligkeit muss zwischen 0 und 100 liegen.'); }
        }
        if ($c['minimum_percent']+$c['hysteresis_percent']>100) { throw new InvalidArgumentException('Schwelle plus Hysterese darf 100 nicht übersteigen.'); }
        if (!is_array($c['region']) || array_diff_key($c['region'],self::DEFAULTS['region']) || count($c['region'])!==4) { throw new InvalidArgumentException('Bildregion ungültig.'); }
        foreach ($c['region'] as $v) {
            if (!is_int($v) || $v<0 || $v>100) { throw new InvalidArgumentException('Bildregion erwartet ganze Prozentwerte.'); }
        }
        $r=$c['region'];
        if ($r['width']<1 || $r['height']<1 || $r['x']+$r['width']>100 || $r['y']+$r['height']>100) { throw new InvalidArgumentException('Bildregion liegt außerhalb des Bildes.'); }
        return $c;
    }

    /** Validate the server-owned position; 0° is a coordinate, null is missing. */
    public static function position(?array $p): bool
    {
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$limit) {
            $v=$p[$key] ?? null;
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs($v)>$limit) { return false; }
        }
        return is_string($p['timezone'] ?? null) && in_array($p['timezone'],DateTimeZone::listIdentifiers(),true);
    }

    /** Half-open windows [start,end). Adjacent local dates cover offsets across
     * midnight, overnight fixed windows and DST without assuming 86400s days.
     */
    public static function window(array $c, ?array $p, int $now): array
    {
        self::validate($c);
        if ($c['mode']==='off') { return ['allowed'=>true,'message'=>'Zeitfenster ausgeschaltet.','windows'=>[],'timezone'=>$c['timezone']]; }
        if ($now<1577836800) { return ['allowed'=>false,'message'=>'Systemzeit unplausibel.','windows'=>[]]; }
        if ($c['mode']==='solar' && !self::position($p)) {
            return ['allowed'=>false,'message'=>'Für Sonnenaufgang und Sonnenuntergang bitte zuerst den Kamerastandort hinterlegen.','windows'=>[]];
        }
        $tz=$c['mode']==='solar' ? $p['timezone'] : $c['timezone'];
        $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($tz))->setTime(0,0);
        $windows=[];
        foreach ([-1,0,1] as $offset) {
            $date=$day->modify(($offset>=0 ? '+' : '').$offset.' days');
            if ($c['mode']==='solar') {
                // Noon selects the intended solar day even near the date line.
                $sun=date_sun_info($date->setTime(12,0)->getTimestamp(),$p['latitude'],$p['longitude']);
                if (!is_int($sun['sunrise']) || !is_int($sun['sunset'])) { continue; }
                $from=$sun['sunrise']+60*$c['sunrise_offset'];
                $until=$sun['sunset']+60*$c['sunset_offset'];
            } else {
                [$h,$m]=array_map('intval',explode(':',$c['start'])); $from=$date->setTime($h,$m)->getTimestamp();
                [$h,$m]=array_map('intval',explode(':',$c['end']));
                $until=($c['end']<$c['start'] ? $date->modify('+1 day') : $date)->setTime($h,$m)->getTimestamp();
            }
            // Reversed solar offsets close the day instead of opening a night window.
            if ($until>$from) { $windows[]=['date'=>$date->format('Y-m-d'),'from'=>$from,'until'=>$until]; }
        }
        $allowed=false;
        foreach ($windows as $w) { $allowed=$allowed || ($now>=$w['from'] && $now<$w['until']); }
        return ['allowed'=>$allowed,'message'=>$allowed ? 'Aufnahmezeit freigegeben.' : 'Aufnahme gesperrt: außerhalb des Aufnahmefensters (oder kein gültiges Sonnenereignis).',
            'windows'=>$windows,'timezone'=>$tz];
    }

    /** Mean display luminance of a 64×64 resampled ROI, not ambient lux.
     * Check dimensions BEFORE GD decode; never persist preview/thumbnail pixels.
     */
    public static function brightness(string $path, array $region): float
    {
        if (!extension_loaded('gd')) { throw new RuntimeException('Helligkeitsprüfung benötigt PHP-GD.'); }
        $size=@getimagesize($path);
        if (!$size || $size[2]!==IMAGETYPE_JPEG || $size[0]>8192 || $size[1]>8192 || $size[0]*$size[1]>40000000
            || filesize($path)>26214400) { throw new RuntimeException('JPEG für Helligkeitsprüfung ungültig oder zu groß.'); }
        $source=@imagecreatefromjpeg($path);
        if (!$source) { throw new RuntimeException('JPEG nicht dekodierbar.'); }
        $small=imagecreatetruecolor(64,64);
        try {
            if (!imagecopyresampled($small,$source,0,0,(int)floor($size[0]*$region['x']/100),(int)floor($size[1]*$region['y']/100),64,64,
                max(1,(int)floor($size[0]*$region['width']/100)),max(1,(int)floor($size[1]*$region['height']/100)))) { throw new RuntimeException('Bildregion nicht lesbar.'); }
            $sum=0.0;
            for ($y=0;$y<64;$y++) for ($x=0;$x<64;$x++) {
                $rgb=imagecolorat($small,$x,$y);
                $sum+=0.2126*(($rgb>>16)&255)+0.7152*(($rgb>>8)&255)+0.0722*($rgb&255);
            }
            return $sum/(4096*255)*100;
        } finally { imagedestroy($source); imagedestroy($small); }
    }
}
