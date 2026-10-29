#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Observe regular sampler output and passive kernel counters only. Never read
 * in_temp_input/in_humidityrelative_input: that would alter the trial itself.
 * All observations stay in the runner's bounded tmpfs evidence directory.
 */
function dhtReadPairs(string $path): ?array
{
    if (!is_file($path) || is_link($path)) return null;
    $raw=file_get_contents($path, false, null, 0, 8193);
    if ($raw===false || strlen($raw)>8192) throw new RuntimeException('Diagnostic snapshot unreadable/oversize');
    $pairs=[];
    foreach (explode("\n",trim($raw)) as $line) {
        if (!str_contains($line,'=')) continue;
        [$key,$value]=explode('=',$line,2);
        $pairs[$key]=$value;
    }
    return $pairs;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')!==__FILE__) return;
ini_set('pcre.jit','0');
$root=$argv[1] ?? '';
$seconds=filter_var($argv[2] ?? '',FILTER_VALIDATE_INT);
if (!preg_match('~^/run/camera-rpi-dht-driver\.[A-Za-z0-9]+$~D',$root)
    || !is_dir($root) || is_link($root) || $seconds===false || $seconds<1 || $seconds>86400) {
    throw new RuntimeException('Invalid observation directory/duration');
}
$out=fopen($root.'/samples.jsonl','ab');
if ($out===false) throw new RuntimeException('Cannot open RAM observations');
// hrtime array avoids integer overflow in 32-bit PHP, just like the timer.
$end=hrtime()[0]+$seconds;
$previous=null;
$keys=array_flip(['updated_epoch','sensor_state','sensor_message','temperature_c','humidity_percent']);
try {
    while (hrtime()[0]<$end) {
        clearstatcache();
        if (filesize($root.'/kernel.jsonl')>8*1024*1024 || fstat($out)['size']>8*1024*1024)
            throw new RuntimeException('8 MiB diagnostic budget exceeded');
        $status=dhtReadPairs('/run/camera-rpi-status/current.status');
        if ($status===null) throw new RuntimeException('Regular sensor status disappeared');
        $status=array_intersect_key($status,$keys);
        if ($status!==$previous) {
            $driver=[];
            // This read-only attribute takes the device mutex for a coherent
            // 64-bit snapshot, but does NOT initiate a wire transaction.
            foreach (glob('/sys/bus/iio/devices/iio:device*/diagnostic_counters') ?: [] as $path)
                $driver[basename(dirname($path))]=dhtReadPairs($path);
            $record=['observed_at'=>date(DATE_ATOM),'status'=>$status,
                'sensor_counters'=>dhtReadPairs('/run/camera-rpi-status/sensor-read-counters.status'),
                'driver_counters'=>$driver];
            $line=json_encode($record,JSON_THROW_ON_ERROR)."\n";
            if (fwrite($out,$line)!==strlen($line) || !fflush($out)) throw new RuntimeException('RAM write failed');
            $previous=$status;
        }
        usleep(500000);
    }
} finally { fclose($out); }
