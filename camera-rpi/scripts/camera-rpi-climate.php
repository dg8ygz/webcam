#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Device-wide enclosure supervisor + fixed admin/capture CLI.
 * Pure PHP control, separate native PWM actuator; NO Python and NO DHT reads.
 * Persistent writes only for deliberate configuration saves; all runtime in /run.
 */
$climateLib = is_file(__DIR__.'/lib/climate-controller.php') ? __DIR__.'/lib' : __DIR__;
require_once $climateLib.'/climate-controller.php';
require_once $climateLib.'/climate-gpio.php';
require_once $climateLib.'/climate-history.php';
require_once $climateLib.'/gpio-config.php';

/** A normal atomic rename crossed our lstat/open snapshot. */
final class ClimateSnapshotReplaced extends RuntimeException {}

final class ClimateApp
{
    public readonly string $configFile;
    public readonly string $runtime;
    public readonly string $statusRoot;
    public readonly string $pwmBinary;
    private bool $stopping = false;
    private ?array $lastConfig = null;
    private ?ClimateHistoryRecorder $history = null;
    private bool $historyFailed = false;
    public function __construct()
    {
        $this->configFile = getenv('CAMERA_RPI_CLIMATE_CONFIG') ?: '/etc/camera-rpi/climate.json';
        $this->runtime = getenv('CAMERA_RPI_CLIMATE_RUNTIME') ?: '/run/camera-rpi-climate';
        $this->statusRoot = getenv('CAMERA_RPI_STATUS_ROOT') ?: '/run/camera-rpi-status';
        $this->pwmBinary = getenv('CAMERA_RPI_PWM_BIN') ?: '/usr/local/libexec/camera-rpi-pwm';
    }

    /** Bounded regular files in root-owned directories; reject final symlinks.
     * Check inode after open too. No arbitrary path is accepted from web input.
     */
    public static function read(string $path, int $limit = 8192): ?string
    {
        clearstatcache(true,$path);
        $before = @lstat($path);
        if ($before === false) { return null; }
        if (($before['mode'] & 0170000) !== 0100000) { throw new RuntimeException('Keine regulaere Datei'); }
        $f = @fopen($path,'rb');
        if ($f === false) { throw new RuntimeException('Datei nicht lesbar'); }
        try {
            $after = fstat($f);
            if ($after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev']) { throw new ClimateSnapshotReplaced('Datei wurde ersetzt'); }
            $data = stream_get_contents($f,$limit+1);
            if ($data === false || strlen($data)>$limit) { throw new RuntimeException('Datei zu gross'); }
            return $data;
        } finally { fclose($f); }
    }

    /** Atomic replace; fsync only SD configuration, never the RAM control loop. */
    public static function atomic(string $path, string $data, bool $public = false, bool $durable = false): void
    {
        $tmp = tempnam(dirname($path),'.climate-');
        if ($tmp === false || dirname($tmp) !== dirname($path)) { throw new RuntimeException('Temporaere Datei nicht anlegbar'); }
        try {
            $f = fopen($tmp,'wb');
            if ($f === false) { throw new RuntimeException('Datei nicht schreibbar'); }
            try {
                if (fwrite($f,$data) !== strlen($data) || !fflush($f) || ($durable && !fsync($f))) { throw new RuntimeException('Speichern fehlgeschlagen'); }
                if (!chmod($tmp,$public ? 0640 : 0600)) { throw new RuntimeException('Dateirechte fehlgeschlagen'); }
                $group = getenv('CAMERA_RPI_STATUS_GROUP');
                if ($public && $group !== '' && !chgrp($tmp,$group === false ? 'www-data' : $group)) { throw new RuntimeException('Statusgruppe nicht setzbar'); }
            } finally { fclose($f); }
            if (!rename($tmp,$path)) { throw new RuntimeException('Atomarer Austausch fehlgeschlagen'); }
            if ($durable) {
                $dir = fopen(dirname($path),'r');
                if ($dir === false) { throw new RuntimeException('Verzeichnis nicht synchronisierbar'); }
                try { if (!fsync($dir)) { throw new RuntimeException('Verzeichnis-fsync fehlgeschlagen'); } }
                finally { fclose($dir); }
            }
        } finally { if (file_exists($tmp)) { unlink($tmp); } }
    }

    /** PHP JSON decoder plus duplicate-member detection BEFORE accepting data.
     * Token scan runs only on syntactically valid JSON (depth <=16, <=8192 bytes).
     * Decoded key names catch escaped duplicates such as mode / m\u006fde too.
     */
    public static function json(string $raw): array
    {
        if (strlen($raw)>8192) { throw new InvalidArgumentException('JSON zu gross'); }
        $value = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new InvalidArgumentException('JSON-Objekt erwartet'); }
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]/s',$raw,$matches);
        $stack = [];
        foreach ($matches[0] as $i=>$token) {
            if ($token === '{' || $token === '[') { $stack[] = []; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif ($token[0] === '"' && ($matches[0][$i+1] ?? '') === ':') {
                $key = json_decode($token,true,2,JSON_THROW_ON_ERROR);
                $level = count($stack)-1;
                if (isset($stack[$level][$key])) { throw new InvalidArgumentException('Doppeltes JSON-Feld'); }
                $stack[$level][$key] = true;
            }
        }
        return $value;
    }

    public function loadConfig(bool $allowPrevious = false): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $raw = self::read($this->configFile);
                return $this->lastConfig = $raw === null ? [ClimateConfig::DEFAULTS,'missing']
                    : [ClimateConfig::fromStored(self::json($raw)),hash('sha256',$raw)];
            } catch (ClimateSnapshotReplaced $e) {
                // Atomic admin publication is not an actuator failure. Retry
                // only this race; malformed/unreadable files remain errors.
                if ($attempt < 2) { usleep(1000); continue; }
                if ($allowPrevious && $this->lastConfig !== null) { return $this->lastConfig; }
                throw $e;
            }
        }
        throw new LogicException('Unreachable configuration read');
    }

    /** Root updater only, under its global write lock. Strip retired keys once,
     * preserve all other settings/mode and save the exact prior bytes as backup.
     * Normal daemon reads never call this method or write configuration to SD.
     */
    public function migrateRetiredSettings(): void
    {
        [$config,$revision] = $this->loadConfig();
        if ($revision === 'missing') { return; }
        $stored = self::json(self::read($this->configFile));
        if (!array_key_exists('max_temperature_c',$stored) && !array_key_exists('max_heat_seconds',$stored)) { return; }
        $this->save(json_encode(['config'=>$config,'revision'=>$revision],JSON_THROW_ON_ERROR));
    }

    /** Existing sensor snapshot only; malformed or duplicate fields invalidate it. */
    public static function fields(string $file): array
    {
        try {
            $raw = self::read($file);
            if ($raw === null) { return []; }
            $fields = [];
            foreach (explode("\n",rtrim($raw,"\n")) as $line) {
                $pair = explode('=',$line,2);
                if (count($pair)!==2 || isset($fields[$pair[0]])) { return []; }
                $fields[$pair[0]]=$pair[1];
            }
            return $fields;
        } catch (Throwable) { return []; }
    }

    /** Admin saves under the existing global RO lock; own lock also serializes CLI users. */
    public function save(string $raw): void
    {
        $payload = self::json($raw);
        if (count($payload)!==2 || array_diff_key($payload,['config'=>1,'revision'=>1])) { throw new InvalidArgumentException('Ungueltiges Speicherformat'); }
        $candidate = ClimateConfig::validate($payload['config']);
        $lock = fopen($this->runtime.'/config.lock','ce');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new RuntimeException('Konfigurationssperre fehlt'); }
        try {
            [,$revision] = $this->loadConfig();
            if ($payload['revision'] !== $revision) { throw new RuntimeException('Klima-Konfiguration wurde inzwischen geaendert; Seite neu laden'); }
            if (in_array($candidate['mode'],['auto','manual80'],true) && !is_executable($this->pwmBinary)) { throw new RuntimeException('Nativer PWM-Helfer fehlt; Simulation bleibt nutzbar'); }
            if ($revision!=='missing') { self::atomic($this->configFile.'.previous', self::read($this->configFile), durable:true); }
            self::atomic($this->configFile,json_encode($candidate,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",durable:true);
        } finally { fclose($lock); }
        echo "OK\tKlima-Konfiguration gespeichert; Uebernahme durch den laufenden Regler\n";
    }

    /** Capture owns an inherited flock until its bounded camera process exits.
     * A fresh nonce must be ACKed within 8s. Timeout leaves its spool job intact.
     */
    public function pause(int $seconds): void
    {
        if (self::read($this->configFile)===null) { return; }
        if ($seconds<5 || $seconds>1840) { throw new InvalidArgumentException('Ungueltige Pausendauer'); }
        $token=bin2hex(random_bytes(16));
        self::atomic($this->runtime.'/capture.request',json_encode(['token'=>$token,'until'=>hrtime(true)/1e9+$seconds],JSON_THROW_ON_ERROR));
        $deadline=hrtime(true)/1e9+8;
        while (hrtime(true)/1e9<$deadline) {
            if (self::read($this->runtime.'/capture.ack',64)===$token) { return; }
            usleep(100000);
        }
        throw new RuntimeException('Keine sichere Aufnahmepause bestaetigt; Auftrag bleibt vorgemerkt');
    }

    /** Lock determines whether a request is live; nonce prevents stale acknowledgements. */
    private function captureRequest(mixed $lock): array
    {
        if (flock($lock,LOCK_EX|LOCK_NB)) { flock($lock,LOCK_UN); return ['',0,false]; }
        // The worker publishes by atomic rename after taking capture.lock.
        // A replacement between lstat/open is not a malformed pause or timeout.
        // Retry only that race, bounded; all validation failures still trip.
        for ($attempt=0; ; $attempt++) {
            try {
                $raw=self::read($this->runtime.'/capture.request');
                break;
            } catch (ClimateSnapshotReplaced $e) {
                if ($attempt>=2) { throw $e; }
            }
        }
        if ($raw===null) { return ['',0,true]; }
        $r=self::json($raw);
        if (count($r)!==2 || !is_string($r['token']??null) || !preg_match('/^[a-f0-9]{32}$/D',$r['token'])
            || (!is_int($r['until']??null) && !is_float($r['until']??null)) || !is_finite((float)$r['until'])) { throw new RuntimeException('Ungueltige Aufnahmepause'); }
        return [$r['token'],$r['until'],true];
    }

    /** A state change or 2s heartbeat writes bounded public RAM status, never SD. */
    private function publish(array $result, string $mode, bool $hardware): void
    {
        $state=['updated_epoch'=>time(),'mode'=>$mode,'outputs'=>$hardware?'gpio':'none']+$result;
        $state['heater']=($result['heater']??false)?'on':'off';
        $text='';
        foreach ($state as $k=>$v) { $text.=$k.'='.($v??'')."\n"; }
        self::atomic($this->statusRoot.'/climate.status',$text,public:true);
        // History is observational: an unavailable chart file must not disable
        // regulation or the capture ACK. Report only failure/recovery transitions.
        try {
            $this->history ??= new ClimateHistoryRecorder($this->statusRoot.'/climate-history.tsv',
                fn(string $data)=>self::atomic($this->statusRoot.'/climate-history.tsv',$data,public:true));
            // Atomic() throws on every failed filesystem operation. Suppress
            // duplicate PHP warnings here; the transition message below suffices.
            @$this->history->record($state,hrtime(true)/1e9);
            if ($this->historyFailed) { fwrite(STDERR,"Klima-Verlauf wieder verfuegbar\n"); }
            $this->historyFailed=false;
        } catch (Throwable) {
            if (!$this->historyFailed) { fwrite(STDERR,"Klima-Verlauf nicht schreibbar; Regelung bleibt aktiv\n"); }
            $this->historyFailed=true;
        }
    }

    /** Notify only after a completed control cycle, not from a background timer. */
    private static function watchdog(): void
    {
        $address=getenv('NOTIFY_SOCKET');
        if (!$address) { return; }
        if ($address[0]==='@') { $address="\0".substr($address,1); }
        $socket=socket_create(AF_UNIX,SOCK_DGRAM,0);
        if ($socket===false) { throw new RuntimeException('Watchdog-Socket fehlt'); }
        try {
            if (socket_sendto($socket,'WATCHDOG=1',10,0,$address)!==10) { throw new RuntimeException('Watchdog nicht erreichbar'); }
        } finally { socket_close($socket); }
    }

    /** One device-wide loop. Signal cleanup and native 3s watchdog are independent.
     * OFF/simulation NEVER create the GPIO process. Faults survive daemon restarts
     * in RAM; explicit acknowledgement needs fresh valid samples, not cool air.
     */
    public function run(): void
    {
        foreach ([$this->runtime,$this->statusRoot] as $dir) { if (!is_dir($dir) && !mkdir($dir,0750,true)) { throw new RuntimeException('Laufzeitverzeichnis fehlt'); } }
        chmod($this->runtime,0700);
        $daemon=fopen($this->runtime.'/daemon.lock','ce');
        if (!$daemon || !flock($daemon,LOCK_EX|LOCK_NB)) { throw new RuntimeException('Klima-Regler laeuft bereits'); }
        $capture=fopen($this->runtime.'/capture.lock','ce');
        if (!$capture) { throw new RuntimeException('Capture-Sperre fehlt'); }
        $hardware = null;
        $controller = null;
        $revision = null;
        $lastPublished = 0;
        $lastState = '';
        $lastAck = '';
        $activeToken = '';
        $hardwareFailed = false;
        $fan = new ClimateFanStartup();
        $fault = self::read($this->runtime.'/fault', 128) ?? '';
        // Retired thermal/time trips must not keep an upgraded controller locked.
        // All still-supported technical faults retain their acknowledgement rule.
        if (in_array($fault,['overtemperature','heat_timeout'],true)) { $fault=''; }
        if (!in_array($fault,['','hardware_error','capture_timeout','config_error'],true)) { $fault='config_error'; }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM,fn()=> $this->stopping=true);
        pcntl_signal(SIGINT,fn()=> $this->stopping=true);
        try {
            while (!$this->stopping) {
                $mono = hrtime(true) / 1e9;
                $wall = time();
                $token = '';
                $resetResult = null;
                try {
                    // Settings are atomically replaced. Preserve running timers
                    // and the startup pulse when just editing the target PWM.
                    [$config, $currentRevision] = $this->loadConfig(true);
                    if ($currentRevision !== $revision) {
                        if ($controller) {
                            $controller->configure($config);
                        } else {
                            $controller = new ClimateController($config, $fault);
                        }
                        $revision = $currentRevision;
                    }
                    $readings = self::fields($this->statusRoot.'/current.status');
                    if (($resetRequest = self::read($this->runtime.'/reset.request', 256)) !== null) {
                        unlink($this->runtime.'/reset.request');
                        try {
                            // Keep compatibility with an already queued old CLI
                            // request during upgrade. New callers get a nonce-
                            // correlated reply after this complete control cycle.
                            if (trim($resetRequest) !== 'reset') {
                                $request = self::json($resetRequest);
                                if (!is_string($request['token'] ?? null)
                                    || !preg_match('/^[a-f0-9]{32}$/D', $request['token'])) {
                                    throw new RuntimeException('Ungueltige Quittierung');
                                }
                                $resetResult = ['token'=>$request['token'], 'ok'=>false, 'message'=>''];
                                $until = $request['until'] ?? null;
                                if ((!is_int($until) && !is_float($until)) || !is_finite((float)$until)
                                    || $until <= $mono || $until > $mono + 3) {
                                    throw new RuntimeException('Quittierungsanfrage abgelaufen');
                                }
                            }
                            $controller->reset($readings, $wall);
                            $fault = '';
                            $hardwareFailed = false;
                            if ($resetResult !== null) { $resetResult['ok'] = true; }
                        } catch (Throwable $e) {
                            if ($resetResult !== null) { $resetResult['message'] = $e->getMessage(); }
                        }
                    }
                    // Lock acquisition precedes nonce publication. Ignore an old
                    // nonce in that short gap, but latch an expired active pause.
                    try {
                        [$token, $until, $busy] = $this->captureRequest($capture);
                        if ($busy && !($mono < $until && $until <= $mono + 1850)) {
                            if ($token !== '' && $token === $activeToken) {
                                $controller->fault = 'capture_timeout';
                            }
                            $token = '';
                        }
                        if (!$busy) {
                            $activeToken = '';
                        } elseif ($token !== '') {
                            $activeToken = $token;
                        }
                    } catch (Throwable) {
                        $controller->fault = 'capture_timeout';
                        $busy = true;
                    }

                    // Only a live, validated nonce/deadline can pause outputs.
                    // A held lock with an absent/expired/malformed request must
                    // not override a latched timeout or create a capture ACK.
                    $result = $controller->tick($readings, $wall, $mono, $busy && $token !== '');
                    $fault = $controller->fault;
                    $result['fan_target_percent'] = $result['fan_percent'];
                    $result['fan_percent'] = $fan->apply($result['fan_percent'], $mono);
                    if (in_array($config['mode'], ['auto','manual80'], true)) {
                        if ($hardwareFailed) {
                            throw new RuntimeException('GPIO-Fehler bleibt bis Quittierung gesperrt');
                        }
                        if ($hardware===null) {
                            $pins=CameraGpioConfig::effective();
                            $hardware=new ClimateOutputs($this->pwmBinary,false,$pins['heater'],$pins['fan']);
                        }
                        $hardware->apply($result['heater'], $result['fan_percent']);
                    } elseif ($hardware) {
                        $hardware->close();
                        $hardware = null;
                    }
                    // Only applied outputs-off permits capture. Sensor failure
                    // and an old capture timeout do not require a climate reset;
                    // a failed actuator write still reaches the no-ACK catch.
                    $ack = (!$result['heater'] && $result['fan_percent'] == 0) ? $token : '';
                } catch (Throwable $e) {
                    if ($hardware) {
                        $hardware->close();
                        $hardware = null;
                    }
                    $hardwareFailed = true;
                    $fault = $revision === null ? 'config_error' : 'hardware_error';
                    if ($controller) { $controller->fault = $fault; }
                    $result = [
                        'state' => 'fault',
                        'message' => 'Reglerfehler; Abschaltung versucht, Hardware pruefen: '.get_class($e),
                        'heater' => false,
                        'fan_percent' => 0,
                        'fault' => $fault,
                    ];
                    $ack = '';
                    $fan = new ClimateFanStartup();
                }

                // Persist only changed fault/ACK state in RAM. Public status is
                // a low-rate snapshot; logs record transitions, not every tick.
                if ($fault !== '') {
                    if (self::read($this->runtime.'/fault', 128) !== $fault) {
                        self::atomic($this->runtime.'/fault', $fault);
                    }
                } elseif (self::read($this->runtime.'/fault', 128) !== null) {
                    unlink($this->runtime.'/fault');
                }
                if ($ack !== $lastAck) {
                    self::atomic($this->runtime.'/capture.ack', $ack);
                    $lastAck = $ack;
                }
                $mode = $controller?->config['mode'] ?? 'off';
                $identity = $mode.' / '.$result['state'].' / '.$result['message'];
                if ($identity !== $lastState) { echo 'Klima: '.$identity."\n"; }
                if ($resetResult !== null || $identity !== $lastState || $mono - $lastPublished >= 2) {
                    $this->publish($result, $mode, $hardware !== null);
                    $lastPublished = $mono;
                }
                $lastState = $identity;
                if ($resetResult !== null) {
                    if ($resetResult['ok'] && $fault !== '') {
                        $resetResult['ok'] = false;
                        $resetResult['message'] = 'Stoerung weiterhin aktiv: '.$fault;
                    }
                    self::atomic($this->runtime.'/reset.result', json_encode($resetResult, JSON_THROW_ON_ERROR));
                }
                self::watchdog();
                usleep(250000);
            }
        } finally {
            if ($hardware) { $hardware->close(); }
            self::atomic($this->runtime.'/capture.ack','');
            $this->publish(['state'=>'stopped','heater'=>false,'fan_percent'=>0,'message'=>'Regler beendet; kein physischer Rueckmeldekontakt'],'off',false);
            fclose($capture); fclose($daemon);
        }
    }

    /** Bounded request/reply; concurrent browser requests cannot steal replies.
     * No operation.lock: the controller must remain reachable during capture.
     */
    public function reset(): void
    {
        $lock = fopen($this->runtime.'/reset.lock', 'ce');
        if (!$lock) { throw new RuntimeException('Quittierung nicht verfuegbar'); }
        try {
            if (!flock($lock, LOCK_EX|LOCK_NB)) { throw new RuntimeException('Eine Quittierung laeuft bereits'); }
            $daemon = fopen($this->runtime.'/daemon.lock', 'ce');
            if (!$daemon) { throw new RuntimeException('Klimadienst nicht erreichbar'); }
            try {
                if (flock($daemon, LOCK_EX|LOCK_NB)) { throw new RuntimeException('Klimadienst laeuft nicht; keine Quittierung ausgefuehrt'); }
            } finally { fclose($daemon); }
            $token = bin2hex(random_bytes(16));
            $until = hrtime(true)/1e9 + 2;
            self::atomic($this->runtime.'/reset.request', json_encode(['token'=>$token,'until'=>$until], JSON_THROW_ON_ERROR));
            do {
                $raw = self::read($this->runtime.'/reset.result');
                $reply = $raw === null ? [] : self::json($raw);
                if (($reply['token'] ?? '') === $token) {
                    if (($reply['ok'] ?? false) !== true) { throw new RuntimeException($reply['message'] ?: 'Quittierung abgelehnt'); }
                    echo "OK\tKlima-Stoerung quittiert; Regler baut den Messpuffer neu auf\n";
                    return;
                }
                usleep(50000);
            } while (hrtime(true)/1e9 < $until + 1);
            throw new RuntimeException('Keine Bestaetigung vom Klimadienst; aktuellen Reglerstatus pruefen');
        } finally { fclose($lock); }
    }

    /** Fixed CLI verbs only; no user-defined files, commands, pins or network endpoint. */
    public function main(array $args): void
    {
        $action=$args[1]??'';
        if (in_array($action,['run','save','reset','pause'],true) && posix_geteuid()!==0 && getenv('CAMERA_RPI_ADMIN_TEST_MODE')!=='1') { throw new RuntimeException('Root erforderlich'); }
        if ($action!=='pause' && count($args)!==2) { throw new InvalidArgumentException('Ungueltige Argumente'); }
        switch ($action) {
            case 'run': $this->run(); break;
            case 'get': [$c,$r]=$this->loadConfig(); echo json_encode(['config'=>$c,'revision'=>$r,'limits'=>ClimateConfig::LIMITS,'status'=>(object)self::fields($this->statusRoot.'/climate.status')],JSON_THROW_ON_ERROR)."\n"; break;
            case 'check': $this->loadConfig(); echo "Klima-Konfiguration ist gueltig; keine GPIO-Aktion\n"; break;
            case 'save': $this->save(stream_get_contents(STDIN,8193)); break;
            case 'reset': $this->reset(); break;
            case 'pause':
                if (count($args)!==4 || $args[2]!=='--seconds' || !preg_match('/^[0-9]{1,4}$/D',$args[3])) { throw new InvalidArgumentException('pause --seconds N erwartet'); }
                $this->pause((int)$args[3]); break;
            case 'health':
                [$c]=$this->loadConfig(); $s=self::fields($this->statusRoot.'/climate.status');
                $age=time()-(int)($s['updated_epoch']??0);
                if ($age<0 || $age>10 || ($s['mode']??'')!==$c['mode'] || in_array($s['state']??'',['fault','stopped'],true)) { throw new RuntimeException('Klima-Regler fehlt, ist veraltet oder meldet Stoerung'); }
                echo 'Klima: '.($s['state']??'unknown')."\n";
                if (in_array($s['state']??'',['sensor_error','warming'],true)) { exit(1); } break;
            default: throw new InvalidArgumentException('Erlaubt: run/get/save/check/reset/pause/health');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    umask(0077);
    try { (new ClimateApp())->main($argv); }
    catch (Throwable $e) { fwrite(STDERR,"ERROR\t".$e->getMessage()."\n"); exit(1); }
}
