#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Local camera supply and bounded recovery. USB indication is deliberately
 * independent of GPIO ACK. Recovery callers hold the device operation lock. */
require_once __DIR__.'/camera-rpi-climate.php';

final class CameraPowerApp
{
    /** Persistent device-wide setting. Missing file preserves the old 45s
     * behaviour; invalid files must never silently shorten a requested reset. */
    public function settings(): array
    {
        $path=getenv('CAMERA_RPI_POWER_CONFIG') ?: '/etc/camera-rpi/power.json';
        for ($i=0;;++$i) {
            try { $raw=ClimateApp::read($path); break; }
            catch (ClimateSnapshotReplaced $e) { if ($i>=2) { throw $e; } }
        }
        $c=$raw===null ? ['reset_off_seconds'=>45] : ClimateApp::json($raw);
        if (array_keys($c)!==['reset_off_seconds'] || !is_int($c['reset_off_seconds'])
            || $c['reset_off_seconds']<10 || $c['reset_off_seconds']>300) {
            throw new RuntimeException('Reset-Ausschaltzeit muss 10 bis 300 ganze Sekunden betragen.');
        }
        return $c+['revision'=>$raw===null ? 'missing' : hash('sha256',$raw)];
    }
    /** Called within the bridge's operation lock and RO/RW write window.
     * Saving does not switch GPIO or change the deadline of an active reset. */
    public function saveSettings(string $raw): void
    {
        $p=ClimateApp::json($raw);
        if (array_keys($p)!==['reset_off_seconds','revision'] || !is_int($p['reset_off_seconds'])
            || $p['reset_off_seconds']<10 || $p['reset_off_seconds']>300 || !is_string($p['revision'])) {
            throw new InvalidArgumentException('Reset-Ausschaltzeit muss 10 bis 300 ganze Sekunden betragen.');
        }
        $old=$this->settings();
        if (!hash_equals($old['revision'],$p['revision'])) { throw new RuntimeException('Reset-Einstellung inzwischen geändert; Seite neu laden.'); }
        $path=getenv('CAMERA_RPI_POWER_CONFIG') ?: '/etc/camera-rpi/power.json';
        if ($old['revision']!=='missing') { ClimateApp::atomic($path.'.previous',ClimateApp::read($path),durable:true); }
        ClimateApp::atomic($path,json_encode(['reset_off_seconds'=>$p['reset_off_seconds']],JSON_THROW_ON_ERROR)."\n",durable:true);
    }
    public static function usbState(string $output, int $exit): string
    {
        if ($exit!==0 || strlen($output)>16384) { return 'unknown'; }
        return str_contains($output,'Canon') ? 'on' : 'off';
    }
    public function usb(): string
    {
        $binary=getenv('CAMERA_RPI_LSUSB_BIN') ?: '/usr/bin/lsusb';
        $p=proc_open(['/usr/bin/timeout','--kill-after=1s','3s',$binary],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        if (!is_resource($p)) { return 'unknown'; }
        $output=stream_get_contents($pipes[1],16385); fclose($pipes[1]);
        return self::usbState(is_string($output)?$output:'',proc_close($p));
    }
    public function command(string $command, ?int $offSeconds=null): string
    {
        if (!in_array($command,['ON','OFF','STATUS','RESET'],true)) { throw new InvalidArgumentException('Unbekannter Strombefehl.'); }
        if ($offSeconds!==null && ($command!=='RESET' || $offSeconds<10 || $offSeconds>300)) { throw new InvalidArgumentException('Ungültige Resetdauer.'); }
        $wire=$command.($offSeconds===null ? '' : ' '.$offSeconds)."\n";
        $path=getenv('CAMERA_RPI_POWER_SOCKET') ?: '/run/camera-rpi-power/control.sock';
        $socket=@stream_socket_client('unix://'.$path,$error,$message,1);
        if (!$socket) { throw new RuntimeException('Kamerastrom-Dienst nicht erreichbar.'); }
        try {
            stream_set_timeout($socket,2);
            if (fwrite($socket,$wire)!==strlen($wire)) { throw new RuntimeException('Strombefehl nicht uebertragen.'); }
            $reply=fgets($socket,16);
            if (!in_array($reply,["ON\n","OFF\n","RESET\n"],true) || ($command!=='STATUS' && $reply!==$command."\n")) {
                throw new RuntimeException('GPIO-Schaltung nicht bestaetigt. USB-Status pruefen.');
            }
            return trim($reply);
        } finally { fclose($socket); }
    }
    public function status(): array
    {
        $usb=$this->usb(); $pin=null; $available=false; $detail='';
        try {
            $pin=CameraGpioConfig::effective()['camera_power'];
            if ($pin===null) { $detail='Kein Kamerastrom-GPIO im aktiven Boot eingerichtet.'; }
            else { $this->command('STATUS'); $available=true; }
        } catch (Throwable $e) { $detail=$e->getMessage(); }
        try { $settings=$this->settings(); }
        catch (Throwable $e) { $settings=['error'=>$e->getMessage()]; }
        return ['settings'=>$settings,'usb_state'=>$usb,'can_control'=>$available,'pin'=>$pin,'detail'=>$detail];
    }
    public function set(string $command): void
    {
        if (!in_array($command,['ON','OFF'],true)) { throw new InvalidArgumentException('Unbekannter Strombefehl.'); }
        $lockPath=getenv('CAMERA_RPI_OPERATION_LOCK') ?: '/run/lock/camera-rpi.operation.lock';
        $lock=fopen($lockPath,'ce');
        if (!$lock) { throw new RuntimeException('Kamerasperre nicht verfuegbar.'); }
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) { throw new RuntimeException('Kamera beschaeftigt (Aufnahme oder Wartung). Bitte danach erneut schalten.'); }
            if (CameraGpioConfig::effective()['camera_power']===null) {
                throw new RuntimeException('Kein Kamerastrom-GPIO im aktiven Boot eingerichtet.');
            }
            $this->command($command);
        } finally { fclose($lock); }
    }
    /** Worker-only calls, serialized by its existing operation.lock. State is
     * shared across targets and survives worker/power-owner restarts in RAM.
     * Count physical gphoto attempts, never uploads or preflight failures. */
    public function recovery(string $event): void
    {
        if (!in_array($event,['ready','failed','ok'],true)) { throw new InvalidArgumentException('Recovery event'); }
        if (CameraGpioConfig::effective()['camera_power']===null) { return; }
        $dir=getenv('CAMERA_RPI_RECOVERY_RUNTIME') ?: '/run/camera-rpi-recovery';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true))) { throw new RuntimeException('Recovery-Verzeichnis ungueltig'); }
        $path=$dir.'/state.json';
        if (is_link($path)) { throw new RuntimeException('Recovery-Zustand ungueltig'); }
        $s=['failures'=>0,'resets'=>0,'last_reset'=>0,'ready_at'=>0];
        if (file_exists($path)) {
            $s=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
            foreach (['failures','resets','last_reset','ready_at'] as $key) {
                if (!is_int($s[$key]??null) || $s[$key]<0) { throw new RuntimeException('Recovery-Zustand ungueltig'); }
            }
        }
        $now=(int)explode('.',file_get_contents('/proc/uptime'))[0];
        $store=function()use(&$s,$dir,$path):void {
            $tmp=tempnam($dir,'.state-');
            if ($tmp===false) { throw new RuntimeException('Recovery-Zustand nicht speicherbar'); }
            try {
                if (file_put_contents($tmp,json_encode($s,JSON_THROW_ON_ERROR)."\n")===false || !chmod($tmp,0600) || !rename($tmp,$path)) {
                    throw new RuntimeException('Recovery-Zustand nicht speicherbar');
                }
            } finally { if (file_exists($tmp)) { unlink($tmp); } }
        };
        if ($event==='ready') {
            if ($now<$s['ready_at'] || $this->command('STATUS')!=='ON') {
                throw new RuntimeException('Kamera ausgeschaltet oder automatische Wiederherstellung aktiv; keine Aufnahme');
            }
            return;
        }
        if ($event==='ok') {
            $s['failures']=0; $s['resets']=0; $s['ready_at']=0;
            $store(); return;
        }
        $s['failures']=min(1000000,$s['failures']+1);
        $store();
        if ($s['failures']<3) { return; }
        if ($s['resets']>=3 || ($s['last_reset']>0 && $now-$s['last_reset']<1800)) { return; }
        if ($this->command('STATUS')!=='ON') { return; }
        // Reserve the budget BEFORE sending RESET. Lost ACKs cannot cause a
        // second reset, nor a worker death leave the camera powered off.
        $offSeconds=$this->settings()['reset_off_seconds'];
        ++$s['resets']; $s['failures']=0; $s['last_reset']=$now; $s['ready_at']=$now+$offSeconds+15+5;
        $store();
        $this->command('RESET',$offSeconds);
        echo "Kamera-Recovery: 3 Aufnahmefehler; Strom {$offSeconds}s AUS, 15s Anlauf; Reset {$s['resets']}/3, Sperrzeit 1800s.\n";
    }
    public function main(array $argv): void
    {
        if (posix_geteuid()!==0) { throw new RuntimeException('Root erforderlich.'); }
        switch ($argv[1] ?? '') {
            case 'capture_ready': $this->recovery('ready'); break;
            case 'capture_failed': $this->recovery('failed'); break;
            case 'capture_ok': $this->recovery('ok'); break;
            case 'power_save': $this->saveSettings(stream_get_contents(STDIN,8193)); echo "OK\tReset-Einstellung gespeichert.\n"; break;
            case 'power_status': echo json_encode($this->status(),JSON_THROW_ON_ERROR)."\n"; break;
            case 'power_on': $this->set('ON'); echo "OK\tKamerastrom eingeschaltet.\n"; break;
            case 'power_off': $this->set('OFF'); echo "OK\tKamerastrom ausgeschaltet.\n"; break;
            default: throw new InvalidArgumentException('Unbekannte Aktion.');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    umask(0077);
    try { (new CameraPowerApp())->main($argv); }
    catch (Throwable $e) { fwrite(STDERR,"ERROR\t".$e->getMessage()."\n"); exit(1); }
}
