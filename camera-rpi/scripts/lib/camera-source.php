<?php
declare(strict_types=1);
// Die Dienstabsicherung verbietet nachtraeglich erzeugten ausfuehrbaren Code.
// Deshalb den JIT-Beschleuniger fuer regulaere Ausdruecke abschalten;
// die Musterpruefungen funktionieren auch ohne ihn.
ini_set('pcre.jit', '0');

/**
 * Gemeinsame Bildquelle fuer Fotos und Video: RTSP, USB-Video oder gphoto2.
 * Das optionale Profil ist eine private Datei mit Schluessel=Wert-Zeilen.
 * Sie wird geprueft und als Daten gelesen, niemals als Shellskript ausgefuehrt.
 * Fehlt sie, kann der Aufrufer weiterhin die bisherigen Zielprofile benutzen.
 */
final class CameraSource
{
    public static function path(): string { return getenv('CAMERA_RPI_SOURCE_CONFIG') ?: '/etc/camera-rpi/camera-source.conf'; }
    public static function load(): ?array
    {
        $path=self::path(); clearstatcache(true,$path); $s=@lstat($path);
        if ($s===false) { return null; }
        if (($s['mode'] & 0170000)!==0100000 || ($s['mode'] & 0077)!==0
            || ($s['uid']!==0 && getenv('CAMERA_RPI_ADMIN_TEST_MODE')!=='1') || $s['size']>8192) {
            throw new RuntimeException('Quellkonfiguration braucht eine private regulaere Datei (root:root 0600)');
        }
        $raw=file_get_contents($path,false,null,0,8193);
        if ($raw===false || strlen($raw)>8192) { throw new RuntimeException('Quellkonfiguration nicht lesbar'); }
        $values=[];
        foreach (explode("\n",$raw) as $line) {
            $line=trim($line);
            if ($line==='' || str_starts_with($line,'#')) { continue; }
            $pair=explode('=',$line,2);
            if (count($pair)!==2) { throw new RuntimeException('Quellkonfiguration: key=value erwartet'); }
            [$key,$value]=array_map('trim',$pair);
            if (array_key_exists($key,$values)) { throw new RuntimeException('Quellkonfiguration: doppeltes Feld'); }
            $values[$key]=$value;
        }
        return self::validate($values);
    }
    public static function validate(array $c): array
    {
        $mode=$c['gphoto_capture_mode']??'eos-legacy';
        unset($c['gphoto_capture_mode']);
        if (!in_array($mode,['standard','eos-legacy'],true)) { throw new RuntimeException('Ungueltige gphoto2-Aufnahmesequenz'); }
        $keys=['source','rtsp_url','video_device','video_width','video_height','video_warmup_frames','video_input_fps'];
        if (count($c)!==count($keys) || array_diff(array_keys($c),$keys)) { throw new RuntimeException('Quellkonfiguration: fehlende/unbekannte Felder'); }
        foreach ($c as $v) { if (!is_string($v) || preg_match('/[\x00-\x1f\x7f]/',$v)) { throw new RuntimeException('Quellkonfiguration: ungueltiger Wert'); } }
        if (!in_array($c['source'],['rtsp','v4l2','gphoto2'],true)) { throw new RuntimeException('Quelle muss rtsp, v4l2 oder gphoto2 sein'); }
        $url=$c['rtsp_url']; $u=parse_url($url);
        if (($url!=='' || $c['source']==='rtsp') && (strlen($url)>2048 || preg_match('/[\s\x27\\\\]/',$url) || !$u || ($u['scheme']??'')!=='rtsp'
            || !preg_match('/^(?:[a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])$/D',$u['host']??'')
            || isset($u['fragment']) || (isset($u['port']) && ($u['port']<1 || $u['port']>65535)))) {
            throw new RuntimeException('Ungueltige RTSP-Adresse');
        }
        if (!preg_match('~^/dev/(?:video[0-9]+|v4l/by-id/[A-Za-z0-9_.:+-]+-video-index[0-9]+)$~D',$c['video_device'])) {
            throw new RuntimeException('Ungueltiges USB-Geraet');
        }
        foreach (['video_width'=>[16,4096],'video_height'=>[16,2160],'video_warmup_frames'=>[0,120],'video_input_fps'=>[1,30]] as $key=>[$min,$max]) {
            if (!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D',$c[$key]) || (int)$c[$key]<$min || (int)$c[$key]>$max) { throw new RuntimeException('Ungueltige Videoeinstellung: '.$key); }
        }
        $c['gphoto_capture_mode']=$mode;
        return $c;
    }
    /**
     * FFmpeg-Eingabe mit der RTSP-Adresse erzeugen. Die Adresse kann ein
     * Passwort enthalten und wird ueber die Standardeingabe uebergeben,
     * nicht als sichtbares Kommandozeilenargument oder Umgebungsvariable.
     */
    public static function input(array $c): string
    {
        self::validate($c);
        return "ffconcat version 1.0\nfile '".$c['rtsp_url']."'\noption rtsp_transport tcp\noption timeout 5000000\noption threads 2\n";
    }
    public static function rtspArgs(): array
    {
        return ['-threads','2','-protocol_whitelist','pipe,rtsp,tcp','-f','concat','-safe','0','-i','pipe:0'];
    }
    /**
     * Erst ein ruhigeres Folgebild aus dem RTSP-Stream speichern.
     * Jedes I-Bild startet die Zwei-Sekunden-Phase neu. Nach vier Sekunden
     * gibt es auch bei kurzen GOPs/All-I ein Bild; ohne Zeitstempel sofort.
     * Das ist dieselbe Auswahl wie im Server, keine Schaerfe-/Rauschmessung.
     * Die bestehende 30-s-Prozessfrist begrenzt weiterhin den gesamten Abruf.
     */
    public static function rtspSnapshotFilter(): string
    {
        // select prueft jedes decodierte Bild; Register 0 merkt die I-Bildzeit.
        return "select='if(isnan(t),1,"
            . "if(gte(t-start_t,4.000),1,"
            . "if(eq(n,0)+eq(pict_type,I),st(0,t);0,"
            . "gte(t-ld(0),2.000))))'";
    }
    /** 0: vollstaendig lesbar; 1: ungueltig; 2: technisch nicht pruefbar. */
    public static function jpegStatus(string $file): int
    {
        if (!function_exists('imagecreatefromjpeg') || !is_readable($file)) { return 2; }
        $info=@getimagesize($file);
        if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG || $info[0]<1 || $info[1]<1
            || $info[0]>8192 || $info[1]>8192 || $info[0]>intdiv(40000000,$info[1])) { return 1; }
        $warning=false; $unavailable=false;
        // GD kann trotz beschaedigter Bilddaten ein Bild zurueckgeben.
        // Deshalb auch Warnungen erfassen, nicht nur den Rueckgabewert.
        ini_set('gd.jpeg_ignore_warning','0');
        set_error_handler(static function(int $severity, string $message) use (&$warning, &$unavailable): bool {
            $warning=true;
            if (preg_match('/memory|allocat|failed to open stream|read error|input\/output|resource temporarily unavailable/i', $message)) {
                $unavailable=true;
            }
            return true;
        });
        try { $image=imagecreatefromjpeg($file); }
        finally { restore_error_handler(); }
        if ($image!==false) { imagedestroy($image); }
        return $unavailable ? 2 : ($warning || $image===false ? 1 : 0);
    }
    /** Fehlerdetails duerfen auch bei einer defekten Quelle keine Zugangsdaten enthalten. */
    public static function captureDiagnostic(string $raw, array $c): string
    {
        // Ganze URLs entfernen, einschliesslich Zugangsdaten und Query-Token.
        $raw=preg_replace('~[a-z][a-z0-9+.-]*://[^\s]+~i','[URL entfernt]',$raw) ?? '';
        $u=parse_url($c['rtsp_url']);
        $secrets=[];
        foreach (['user','pass','query'] as $key) {
            $value=$u[$key]??'';
            if ($value!=='') { $secrets[]=$value; $secrets[]=rawurldecode($value); }
        }
        usort($secrets,static fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach ($secrets as $secret) {
            if ($secret!=='') { $raw=str_replace($secret,'[entfernt]',$raw); }
        }
        // Keine Terminal-Steuerzeichen; erst NACH Bereinigung begrenzen.
        $raw=preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/','?',$raw) ?? '';
        return substr(trim($raw),0,4096);
    }
    public static function capture(array $c, string $target): void
    {
        self::validate($c);
        if ($c['source']!=='rtsp' || !str_starts_with($target,'/') || is_link($target)) { throw new RuntimeException('Ungueltige RTSP-Aufnahme'); }
        // Ein altes Bild darf keinen fehlgeschlagenen neuen Abruf verdecken.
        if (file_exists($target)) { throw new RuntimeException('RTSP-Zieldatei existiert bereits'); }
        $cmd=['/usr/bin/timeout','--signal=TERM','--kill-after=2s','30s',getenv('CAMERA_RPI_FFMPEG_BIN') ?: '/usr/bin/ffmpeg',
            '-hide_banner','-loglevel','error',...self::rtspArgs(),'-map','0:v:0','-an',
            '-vf',self::rtspSnapshotFilter(),'-fps_mode','passthrough',
            '-frames:v','1','-threads','2','-q:v','2','-f','image2','-update','1','-y',$target];
        $p=proc_open($cmd,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($p)) { throw new RuntimeException('RTSP-Aufnahme konnte nicht starten'); }
        $done=false; $interrupted=false; $valid=false; $stderr=''; $truncated=false;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM,static function() use (&$interrupted){$interrupted=true;});
        pcntl_signal(SIGINT,static function() use (&$interrupted){$interrupted=true;});
        try {
            $input=self::input($c);
            if (fwrite($pipes[0],$input)!==strlen($input)) { throw new RuntimeException('RTSP-Eingang nicht uebertragen'); }
            fclose($pipes[0]); stream_set_blocking($pipes[2],false);
            do {
                $chunk=stream_get_contents($pipes[2],8192);
                if ($chunk!==false) {
                    $remaining=65536-strlen($stderr);
                    if (strlen($chunk)>$remaining) { $truncated=true; }
                    $stderr.=substr($chunk,0,$remaining);
                }
                if (!isset($status) || $status['running']) { $status=proc_get_status($p); }
                if ($interrupted) { throw new RuntimeException('RTSP-Aufnahme abgebrochen'); }
                if (!$status['running'] && feof($pipes[2])) { $done=true; break; }
                usleep(50000);
            } while (true);
            // Eine abgeschnittene Diagnosezeile nicht ausgeben: sie koennte
            // mitten in einem Passwort enden und der Bereinigung entgehen.
            if ($truncated) { $stderr=substr($stderr,0,(int)strrpos($stderr,"\n")); }
            $detail=self::captureDiagnostic($stderr,$c);
            $suffix=$detail==='' ? '' : "\nFFmpeg: ".$detail;
            if ($status['exitcode']!==0) {
                throw new RuntimeException('RTSP-Aufnahme fehlgeschlagen (exit='.$status['exitcode'].')'.$suffix);
            }
            clearstatcache(true,$target);
            if (!is_file($target) || is_link($target) || filesize($target)===0) {
                throw new RuntimeException('RTSP: FFmpeg exit=0, aber kein JPEG erzeugt'.$suffix);
            }
            $jpeg=self::jpegStatus($target);
            if ($jpeg!==0) {
                throw new RuntimeException('RTSP: JPEG '.($jpeg===1 ? 'unvollstaendig/ungueltig' : 'nicht pruefbar').$suffix);
            }
            $valid=true;
        } finally {
            if (!$done) { proc_terminate($p); }
            if (is_resource($pipes[0])) { fclose($pipes[0]); }
            fclose($pipes[2]); proc_close($p);
            if (!$valid) { @unlink($target); }
        }
    }
}
// $argv enthaelt CLI-Argumente auch dann, wenn PHP kein $_SERVER bereitstellt.
// Den Befehlszweig nur beim direkten Skriptaufruf ausfuehren, nicht beim Einbinden.
if (PHP_SAPI==='cli' && realpath($argv[0]??'')===__FILE__) {
    try {
        $action=$argv[1]??'';
        if ($action==='validate-jpeg' && count($argv)===3) {
            // Rueckgabecodes fuer den Worker: 0 = JPEG lesbar, 1 = Inhalt
            // ungueltig/zu gross, 2 = Pruefung technisch nicht moeglich.
            // Fehlendes GD, Speicher-/Lesefehler oder ein Timeout beweisen
            // keinen Bilddefekt und duerfen deshalb keine Quarantaene ausloesen.
            exit(CameraSource::jpegStatus($argv[2]));
        } elseif ($action==='capture'  && count($argv)===3) {
            $raw=stream_get_contents(STDIN,8193);
            $c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            CameraSource::capture($c,$argv[2]);
        } elseif ($action==='shell' && count($argv)===2) {
            $c=CameraSource::load();
            if ($c!==null) { foreach (['source','video_device','video_width','video_height','video_warmup_frames'] as $k) { echo $c[$k],"\n"; } echo json_encode($c,JSON_THROW_ON_ERROR),"\n"; }
        } elseif ($action==='devices' && count($argv)===2) {
            $devices=[];
            $directory='/dev/v4l/by-id';
            if (getenv('CAMERA_RPI_ADMIN_TEST_MODE')==='1') $directory=getenv('CAMERA_RPI_SOURCE_DEVICE_DIR')?:$directory;
            foreach (array_slice(glob($directory.'/*')?:[],0,128) as $entry) {
                $device='/dev/v4l/by-id/'.basename($entry);
                if (preg_match('~^/dev/v4l/by-id/[A-Za-z0-9_.:+-]+-video-index[0-9]+$~D',$device) && is_link($entry) && file_exists($entry)) $devices[]=$device;
            }
            sort($devices); echo json_encode($devices,JSON_THROW_ON_ERROR),"\n";
        } elseif ($action==='get' && count($argv)===2) {
            echo json_encode(CameraSource::load(),JSON_THROW_ON_ERROR),"\n";
        } elseif ($action==='prepare' && count($argv)===3) {
            $c=CameraSource::validate(json_decode(stream_get_contents(STDIN,8193),true,16,JSON_THROW_ON_ERROR));
            $text="# Gemeinsame Quelle fuer alle Foto-Ziele und den Livestream.\n";
            foreach ($c as $key=>$value) { $text.=$key.'='.$value."\n"; }
            foreach (['rtsp','v4l2','gphoto2'] as $choice) { if ($choice!==$c['source']) $text.='# source='.$choice."\n"; }
            if (file_put_contents($argv[2],$text)!==strlen($text) || !chmod($argv[2],0600)) { throw new RuntimeException('Quellenprofil nicht vorbereitet'); }
        } elseif ($action==='check' && count($argv)===2) {
            $c=CameraSource::load();
            echo $c===null ? "Keine gemeinsame Quelle; bestehende Profile gelten\n" : 'Quelle gueltig: '.$c['source']."\n";
        } else { throw new RuntimeException('Erlaubt: check, shell, capture ZIEL'); }
    } catch (Throwable $e) { fwrite(STDERR,"ERROR\t".(($e instanceof RuntimeException) ? $e->getMessage() : 'Ungueltige Quellkonfiguration')."\n"); exit(($action??'')==='validate-jpeg' ? 2 : 1); }
}
