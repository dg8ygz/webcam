#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Root-only GPIO profile bridge. Saving changes ONLY the desired document.
 * Boot publishes a RAM snapshot after finding the configured DHT node.
 * Saving cannot switch hardware. Explicit supply commands use the separate
 * camera-rpi-power.php bridge and the already running native pin owner.
 */
require_once __DIR__.'/camera-rpi-climate.php';

final class CameraGpioApp
{
    public string $config;
    public string $active;
    public function __construct()
    {
        $this->config=getenv('CAMERA_RPI_GPIO_CONFIG') ?: '/etc/camera-rpi/gpio.json';
        $this->active=getenv('CAMERA_RPI_GPIO_ACTIVE') ?: '/run/camera-rpi-gpio/active.json';
    }
    public function load(): array
    {
        $raw=ClimateApp::read($this->config);
        if ($raw===null) { throw new RuntimeException('GPIO-Profil noch nicht eingerichtet.'); }
        return [CameraGpioConfig::validate(ClimateApp::json($raw)),hash('sha256',$raw)];
    }
    public function save(string $raw): void
    {
        $data=ClimateApp::json($raw);
        if (count($data)!==2 || array_diff_key($data,['pins'=>1,'revision'=>1])) {
            throw new InvalidArgumentException('Ungültiges GPIO-Speicherformat.');
        }
        $pins=CameraGpioConfig::validate($data['pins'],CameraGpioBoard::allowedHeader());
        $lock=fopen(dirname($this->active).'/config.lock','ce');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new RuntimeException('GPIO-Konfigurationssperre fehlt.'); }
        try {
            [,$revision]=$this->load();
            if ($revision!==$data['revision']) { throw new RuntimeException('GPIO-Konfiguration inzwischen geändert; Seite neu laden.'); }
            ClimateApp::atomic($this->config.'.previous',ClimateApp::read($this->config),durable:true);
            ClimateApp::atomic($this->config,json_encode($pins,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",durable:true);
        } finally { fclose($lock); }
        echo "OK\tGPIO-Belegung gespeichert; Übernahme erst beim nächsten Neustart.\n";
    }
    /** Inspect device-tree GPIO cells, not the unstable iio:deviceN numbering
     * or the node's possibly hexadecimal suffix. Never read sensor values here.
     */
    public static function sensor(int $pin): ?string
    {
        foreach (glob('/sys/bus/iio/devices/iio:device*') ?: [] as $device) {
            $name=@file_get_contents($device.'/name');
            if (!is_string($name) || !preg_match('/^dht11(?:@.*)?\s*$/D',$name)) { continue; }
            $cells=@file_get_contents($device.'/of_node/gpios');
            if (is_string($cells) && strlen($cells)>=12 && unpack('Npin',substr($cells,4,4))['pin']===$pin) {
                return $device;
            }
        }
        return null;
    }
    private static function run(array $argv): void
    {
        $p=proc_open($argv,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
        if (!is_resource($p) || proc_close($p)!==0) { throw new RuntimeException('GPIO-Bootschritt fehlgeschlagen.'); }
    }
    /** A simple service is started before PHP/native initialization finishes.
     * ExecStartPost waits for an actual STATUS reply before dependent workers
     * may start. STATUS never changes a pin or extends the focus HIGH lease.
     */
    public static function ready(string $role, float $timeout=10.0): void
    {
        $pins=CameraGpioConfig::effective();
        $key=$role==='focus' ? 'focus' : 'camera_power';
        if ($pins[$key]===null) { return; }
        $path=$role==='focus'
            ? (getenv('CAMERA_RPI_FOCUS_SOCKET') ?: '/run/camera-rpi-focus/control.sock')
            : (getenv('CAMERA_RPI_POWER_SOCKET') ?: '/run/camera-rpi-power/control.sock');
        $expected=$role==='focus' ? "OFF\n" : "ON\n";
        $deadline=hrtime(true)+(int)($timeout*1e9);
        do {
            $socket=@stream_socket_client('unix://'.$path,$error,$message,0.2);
            if ($socket) {
                try {
                    stream_set_timeout($socket,0,200000);
                    $written=@fwrite($socket,"STATUS\n");
                    $reply=$written===7 ? @fgets($socket,16) : false;
                    if ($reply===$expected) { return; }
                } finally { fclose($socket); }
            }
            usleep(100000);
        } while (hrtime(true)<$deadline);
        throw new RuntimeException('GPIO-'.$role.': kein bestaetigter sicherer Startzustand innerhalb des Zeitlimits.');
    }
    public function boot(): void
    {
        // Re-starting the oneshot in the same boot must not apply pending edits.
        if (file_exists($this->active)) { CameraGpioConfig::effective(); return; }
        [$pins]=$this->load();
        CameraGpioConfig::validate($pins,CameraGpioBoard::allowedHeader());
        if (self::sensor($pins['dht'])===null) {
            self::run(['/usr/bin/timeout','--kill-after=2','15','/usr/bin/dtoverlay','dht11','gpiopin='.$pins['dht']]);
        }
        for ($n=0;$n<20 && self::sensor($pins['dht'])===null;$n++) { usleep(100000); }
        if (self::sensor($pins['dht'])===null) { throw new RuntimeException('DHT-GPIO nicht verfügbar oder bereits in Benutzung.'); }
        ClimateApp::atomic($this->active,json_encode($pins,JSON_THROW_ON_ERROR)."\n");
    }
    public function main(array $argv): void
    {
        $action=$argv[1] ?? '';
        if (in_array($action,['save','boot','power','focus','ready-focus','ready-power'],true) && posix_geteuid()!==0) { throw new RuntimeException('Root erforderlich.'); }
        switch ($action) {
            case 'get':
                [$pins,$revision]=$this->load();
                $board=CameraGpioBoard::detect();
                $active=CameraGpioConfig::snapshot();
                echo json_encode(['pins'=>$pins,'revision'=>$revision,'active'=>$active,
                    'pending'=>$pins!=$active,'labels'=>CameraGpioConfig::LABELS,
                    'header'=>$board['header'],'board'=>$board],JSON_THROW_ON_ERROR)."\n"; break;
            case 'save': $this->save(stream_get_contents(STDIN,8193)); break;
            case 'boot': $this->boot(); break;
            case 'ready-focus': self::ready('focus'); break;
            case 'ready-power': self::ready('power'); break;
            case 'sensor':
                $pins=CameraGpioConfig::effective();
                $device=self::sensor($pins['dht']);
                if ($device===null) { throw new RuntimeException('Konfigurierter DHT nicht gefunden.'); }
                echo $device."\n"; break;
            case 'power':
                $pins=CameraGpioConfig::effective();
                if ($pins['camera_power']===null) { return; }
                // Persistent owner; explicit ON/OFF via private root socket.
                pcntl_exec('/usr/local/libexec/camera-rpi-pwm',['--power',(string)$pins['camera_power'],'/run/camera-rpi-power/control.sock']);
                throw new RuntimeException('Kamerastrom-Helfer konnte nicht gestartet werden.');
            case 'focus':
                $pins=CameraGpioConfig::effective();
                if ($pins['focus']===null) { return; }
                pcntl_exec('/usr/local/libexec/camera-rpi-pwm',['--focus',(string)$pins['focus'],'/run/camera-rpi-focus/control.sock']);
                throw new RuntimeException('Fokus-GPIO-Helfer konnte nicht gestartet werden.');
            default: throw new InvalidArgumentException('Erlaubt: get/save/boot/sensor/power/focus/ready-focus/ready-power');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    umask(0077);
    try { (new CameraGpioApp())->main($argv); }
    catch (Throwable $e) { fwrite(STDERR,"ERROR\t".$e->getMessage()."\n"); exit(1); }
}
