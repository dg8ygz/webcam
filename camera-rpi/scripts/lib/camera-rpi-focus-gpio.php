#!/usr/bin/env php
<?php
declare(strict_types=1);
// Internal worker bridge; caller holds operation.lock. No direct web action.
require_once is_file(__DIR__.'/camera-rpi-climate.php')
    ? __DIR__.'/camera-rpi-climate.php' : __DIR__.'/../camera-rpi-climate.php';

final class CameraFocusGpio
{
    public static function command(string $command): void
    {
        if (!in_array($command,['ON','OFF'],true)) { throw new InvalidArgumentException('Ungültiger Fokus-GPIO-Befehl.'); }
        if (CameraGpioConfig::effective()['focus']===null) { return; }
        $path=getenv('CAMERA_RPI_FOCUS_SOCKET') ?: '/run/camera-rpi-focus/control.sock';
        $socket=@stream_socket_client('unix://'.$path,$error,$message,1);
        if (!$socket) { throw new RuntimeException('Fokus-GPIO-Dienst nicht erreichbar; keine Kameraaktion.'); }
        try {
            stream_set_timeout($socket,2);
            if (fwrite($socket,$command."\n")!==strlen($command)+1 || fgets($socket,16)!==$command."\n") {
                throw new RuntimeException('Fokus-GPIO-Schaltung nicht bestätigt.');
            }
        } finally { fclose($socket); }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    try {
        if (posix_geteuid()!==0) { throw new RuntimeException('Root erforderlich.'); }
        CameraFocusGpio::command($argv[1] ?? '');
    } catch (Throwable $e) { fwrite(STDERR,"ERROR\t".$e->getMessage()."\n"); exit(1); }
}
