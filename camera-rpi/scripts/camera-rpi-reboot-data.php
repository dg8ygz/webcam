#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Bounded shutdown checkpoint, separate from once-daily archives.
 * Copy only completed images/pool metadata and two history files. Never copy
 * keys, pending capture/focus commands, current status, locks or GPIO state.
 * The shell owns shutdown ordering, the operation lock and root RW/RO window.
 */
final class CameraRebootData
{
    public const MAX_BYTES=192*1024*1024;
    public const MAX_FILES=4096;
    public function __construct(private string $runtime, private string $spool, private string $archive) {}

    private static function regular(string $path): array
    {
        clearstatcache(true,$path);
        $s=@lstat($path);
        if (!$s || ($s['mode']&0170000)!==0100000) { throw new RuntimeException('Keine regulaere Datei: '.$path); }
        return $s;
    }
    private static function directory(string $path): void
    {
        clearstatcache(true,$path);
        $s=@lstat($path);
        if (!$s || ($s['mode']&0170000)!==0040000) { throw new RuntimeException('Kein regulaeres Verzeichnis: '.$path); }
    }
    private static function mkdir(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            if (!mkdir($path,0700)) { throw new RuntimeException('Verzeichnis nicht anlegbar: '.$path); }
        }
        self::directory($path);
    }
    private static function syncDir(string $path): void
    {
        $f=fopen($path,'r');
        if (!$f) { throw new RuntimeException('Verzeichnis nicht lesbar'); }
        try { if (!fsync($f)) { throw new RuntimeException('Verzeichnis-fsync fehlgeschlagen'); } }
        finally { fclose($f); }
    }
    /** Strict fixed namespace also checked when restoring the manifest. */
    public static function allowed(string $name): bool
    {
        if (in_array($name,['history.tsv','climate-history.tsv','journal.log'],true)) { return true; }
        $id='[a-z0-9][a-z0-9_-]{0,63}'; $serial='[0-9]{10}';
        return preg_match('~^spool/(?:'.$id.'/images/'.$serial.'\.jpg(?:\.legacy-stamp|\.weather\.json)?|'
            .'\.capture-pool/(?:latest|'.$serial.'/(?:image\.jpg|stamp|captured|weather\.json|pending/'.$id.')))\z~D',$name)===1;
    }
    /** Walk no symlinks, special files or unbounded trees, even in ignored paths. */
    private static function walk(string $root, string $prefix='', int $depth=0): array
    {
        if ($depth>6) { throw new RuntimeException('Verzeichnis zu tief'); }
        self::directory($root); $result=[];
        foreach (new DirectoryIterator($root) as $f) {
            if ($f->isDot()) { continue; }
            if ($f->isLink()) { throw new RuntimeException('Symlink im Snapshot abgelehnt'); }
            $name=$prefix.$f->getFilename();
            if ($f->isDir()) { $result+=self::walk($f->getPathname(),$name.'/',$depth+1); }
            elseif ($f->isFile()) { $result[$name]=$f->getPathname(); }
            else { throw new RuntimeException('Spezialdatei im Snapshot abgelehnt'); }
            if (count($result)>self::MAX_FILES) { throw new RuntimeException('Zu viele Dateien'); }
        }
        ksort($result,SORT_STRING); return $result;
    }
    /** Stream data; preserve hardlink identity so fan-out never multiplies RAM. */
    private static function copy(string $source, string $target, bool $durable): void
    {
        $s=self::regular($source); $in=fopen($source,'rb'); $out=fopen($target,'xb');
        if (!$in || !$out) {
            if (is_resource($in)) { fclose($in); } if (is_resource($out)) { fclose($out); }
            throw new RuntimeException('Snapshot-Datei nicht kopierbar');
        }
        try {
            $opened=fstat($in);
            if ($opened['ino']!==$s['ino'] || $opened['dev']!==$s['dev']
                || stream_copy_to_stream($in,$out,self::MAX_BYTES+1)!==$s['size']
                || !fflush($out) || ($durable && !fsync($out))) {
                throw new RuntimeException('Datei geaendert oder Schreiben fehlgeschlagen');
            }
        } finally { fclose($in); fclose($out); }
    }
    private static function parents(string $base,string $name): void
    {
        $parts=explode('/',dirname($name));
        foreach ($parts as $part) { if ($part==='.') { continue; } $base.='/'.$part; self::mkdir($base); }
    }
    private static function write(string $path,string $data,bool $durable): void
    {
        $f=fopen($path,'xb');
        if (!$f) { throw new RuntimeException('Snapshot-Metadaten nicht schreibbar'); }
        try {
            if (fwrite($f,$data)!==strlen($data) || !fflush($f) || ($durable&&!fsync($f))) {
                throw new RuntimeException('Snapshot-Metadaten unvollstaendig');
            }
        } finally { fclose($f); }
    }
    /** Delete only a checked private generation after its successor is durable.
     * Unknown files/directories in the archive are not retention candidates.
     */
    private static function removeTree(string $path): void
    {
        self::directory($path);
        foreach (new DirectoryIterator($path) as $f) {
            if ($f->isDot()) { continue; }
            if ($f->isLink()) { throw new RuntimeException('Symlink bei Checkpoint-Aufbewahrung'); }
            if ($f->isDir()) { self::removeTree($f->getPathname()); }
            elseif ($f->isFile()) { if (!unlink($f->getPathname())) { throw new RuntimeException('Alte Checkpoint-Datei nicht entfernbar'); } }
            else { throw new RuntimeException('Spezialdatei bei Checkpoint-Aufbewahrung'); }
        }
        if (!rmdir($path)) { throw new RuntimeException('Altes Checkpoint-Verzeichnis nicht entfernbar'); }
    }
    /** Current history is saved AGAIN even when today's daily files exist.
     * Empty spool is a meaningful new state; never reuse yesterday's queue.
     */
    public function save(string $boot, ?string $journal=null): string
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D',$boot)) { throw new RuntimeException('Ungueltige Boot-ID'); }
        self::directory($this->runtime); self::directory($this->spool);
        self::mkdir($this->archive);
        $files=[];
        foreach (['history.tsv','climate-history.tsv'] as $name) {
            if (file_exists($this->runtime.'/'.$name) || is_link($this->runtime.'/'.$name)) {
                self::regular($this->runtime.'/'.$name); $files[$name]=$this->runtime.'/'.$name;
            }
        }
        foreach (self::walk($this->spool,'spool/') as $name=>$path) {
            if (self::allowed($name)) { $files[$name]=$path; }
        }
        if ($journal!==null) { self::regular($journal); $files['journal.log']=$journal; }
        $bytes=0;$seen=[];
        foreach ($files as $path) {
            $s=self::regular($path); $key=$s['dev'].':'.$s['ino'];
            if (!isset($seen[$key])) { $bytes+=$s['size']; $seen[$key]=true; }
        }
        if ($bytes>self::MAX_BYTES || count($files)>self::MAX_FILES) { throw new RuntimeException('Checkpoint-Limit ueberschritten'); }
        if (disk_free_space($this->archive)<$bytes+8*1024*1024) { throw new RuntimeException('SD-Platz fuer Checkpoint fehlt'); }
        $previous=null;
        if (file_exists($this->archive.'/current') || is_link($this->archive.'/current')) {
            self::regular($this->archive.'/current');
            $previous=trim((string)file_get_contents($this->archive.'/current',false,null,0,128));
            if (!preg_match('/^checkpoint-[a-f0-9]{16}$/D',$previous)) { throw new RuntimeException('Alter Checkpoint-Zeiger ungueltig'); }
        }
        $generation='checkpoint-'.bin2hex(random_bytes(8));
        $stage=$this->archive.'/.'.$generation; self::mkdir($stage);
        $manifest=['version'=>1,'boot'=>$boot,'saved_epoch'=>time(),'files'=>[]]; $links=[];
        foreach ($files as $name=>$path) {
            self::parents($stage,$name); $s=self::regular($path); $key=$s['dev'].':'.$s['ino'];
            $link=$links[$key]??null;
            if ($link!==null) {
                if (!link($stage.'/'.$link,$stage.'/'.$name)) { throw new RuntimeException('Hardlink fehlgeschlagen'); }
            } else { self::copy($path,$stage.'/'.$name,true); $links[$key]=$name; }
            $manifest['files'][$name]=['bytes'=>$s['size'],'sha256'=>hash_file('sha256',$stage.'/'.$name),'link'=>$link];
        }
        self::write($stage.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)."\n",true);
        // Flush every directory containing new entries before publishing pointer.
        $dirs=[$stage]; foreach (array_keys($files) as $name) {
            $p=dirname($stage.'/'.$name);
            while ($p!==$stage) { $dirs[]=$p; $p=dirname($p); }
        }
        foreach (array_unique($dirs) as $dir) { self::syncDir($dir); }
        if (!rename($stage,$this->archive.'/'.$generation)) { throw new RuntimeException('Checkpoint nicht publizierbar'); }
        self::syncDir($this->archive);
        $pointer=$this->archive.'/.current-'.bin2hex(random_bytes(8));
        self::write($pointer,$generation."\n",true);
        if (is_link($this->archive.'/current') || !rename($pointer,$this->archive.'/current')) { throw new RuntimeException('Checkpoint-Zeiger ungueltig'); }
        self::syncDir($this->archive);
        // Two successful shutdown generations, not a new unbounded SD history.
        foreach (new DirectoryIterator($this->archive) as $f) {
            if (preg_match('/^checkpoint-[a-f0-9]{16}$/D',$f->getFilename())
                && !in_array($f->getFilename(),[$generation,$previous],true)) { self::removeTree($f->getPathname()); }
        }
        self::syncDir($this->archive);
        echo 'Reboot-Checkpoint: '.count($files).' Dateien, '.$bytes.' Bytes, '.$generation."\n";
        return $generation;
    }
    /** Validate the ENTIRE immutable generation before touching RAM. Logs stay
     * on SD for diagnosis. No current statuses or captured commands are replayed.
     */
    public function restore(string $boot): void
    {
        if (!file_exists($this->archive.'/current') && !is_link($this->archive.'/current')) { echo "Kein Reboot-Checkpoint vorhanden.\n"; return; }
        self::directory($this->archive);
        self::regular($this->archive.'/current');
        $name=trim((string)file_get_contents($this->archive.'/current',false,null,0,128));
        if (!preg_match('/^checkpoint-[a-f0-9]{16}$/D',$name)) { throw new RuntimeException('Checkpoint-Name ungueltig'); }
        $source=$this->archive.'/'.$name; self::directory($source);
        $s=self::regular($source.'/manifest.json');
        if ($s['size']>2*1024*1024) { throw new RuntimeException('Manifest zu gross'); }
        $m=json_decode((string)file_get_contents($source.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        if (($m['version']??null)!==1 || !is_string($m['boot']??null) || !is_array($m['files']??null)
            || count($m['files'])>self::MAX_FILES) { throw new RuntimeException('Checkpoint-Manifest ungueltig'); }
        if ($m['boot']===$boot) { echo "Checkpoint aus laufendem Boot; keine Wiederherstellung.\n"; return; }
        $bytes=0;$spoolBytes=0;$historyBytes=0;$unique=[];
        foreach ($m['files'] as $path=>$meta) {
            if (!self::allowed($path) || !is_array($meta) || !is_int($meta['bytes']??null)
                || $meta['bytes']<0 || !preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']??'')
                || !array_key_exists('link',$meta)) { throw new RuntimeException('Checkpoint-Dateieintrag ungueltig'); }
            // Check parent directories as well as final files; no archive traversal.
            $p=dirname($source.'/'.$path); while ($p!==$source) { self::directory($p); $p=dirname($p); }
            $s=self::regular($source.'/'.$path);
            if ($s['size']!==$meta['bytes'] || hash_file('sha256',$source.'/'.$path)!==$meta['sha256']) { throw new RuntimeException('Checkpoint-Pruefsumme falsch'); }
            $link=$meta['link'];
            if ($link!==null && (!is_string($link) || !isset($unique[$link]) || $m['files'][$link]['sha256']!==$meta['sha256'])) {
                throw new RuntimeException('Checkpoint-Hardlink ungueltig');
            }
            if ($link===null) {
                $bytes+=$meta['bytes']; $unique[$path]=true;
                if (str_starts_with($path,'spool/')) { $spoolBytes+=$meta['bytes']; }
                elseif ($path!=='journal.log') { $historyBytes+=$meta['bytes']; }
            }
        }
        if ($bytes>self::MAX_BYTES) { throw new RuntimeException('Checkpoint zu gross'); }
        self::directory($this->runtime); self::directory($this->spool);
        if (self::walk($this->spool)) { throw new RuntimeException('RAM-Spool nicht leer; keine Ueberschreibung'); }
        if (disk_free_space($this->spool)<$spoolBytes+4*1024*1024
            || disk_free_space($this->runtime)<$historyBytes+4*1024*1024) { throw new RuntimeException('RAM-Platz fuer Checkpoint fehlt'); }
        foreach (['history.tsv','climate-history.tsv'] as $history) {
            if (isset($m['files'][$history]) && (file_exists($this->runtime.'/'.$history)||is_link($this->runtime.'/'.$history))) {
                throw new RuntimeException('RAM-Verlauf existiert; keine Ueberschreibung');
            }
        }
        $destinations=[];
        foreach ($m['files'] as $path=>$meta) {
            if ($path==='journal.log') { continue; }
            $base=str_starts_with($path,'spool/') ? $this->spool : $this->runtime;
            $relative=str_starts_with($path,'spool/') ? substr($path,6) : $path;
            self::parents($base,$relative); $target=$base.'/'.$relative;
            if ($meta['link']!==null && isset($destinations[$meta['link']])) {
                if (!link($destinations[$meta['link']],$target)) { throw new RuntimeException('RAM-Hardlink fehlgeschlagen'); }
            } else { self::copy($source.'/'.$path,$target,false); }
            if ($base===$this->runtime && (!chmod($target,0640) || !chgrp($target,'www-data'))) {
                throw new RuntimeException('RAM-Verlauf-Berechtigungen nicht gesetzt');
            }
            $destinations[$path]=$target;
        }
        echo 'Reboot-Checkpoint restauriert: '.count($destinations)." Dateien; keine aktuellen Stellwerte/Befehle.\n";
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    umask(0077);
    try {
        if (count($argv)!==2 || !in_array($argv[1],['save','restore'],true)) { throw new InvalidArgumentException('save|restore erwartet'); }
        $app=new CameraRebootData('/run/camera-rpi-status','/var/lib/camera-rpi/spool','/var/lib/camera-rpi/reboot-backup');
        $boot=trim((string)file_get_contents('/proc/sys/kernel/random/boot_id'));
        if ($argv[1]==='save') { $app->save($boot,is_file('/run/camera-rpi-reboot/journal.log')?'/run/camera-rpi-reboot/journal.log':null); }
        else { $app->restore($boot); }
    } catch (Throwable $e) { fwrite(STDERR,'Reboot-Sicherung: '.$e->getMessage()."\n"); exit(1); }
}
