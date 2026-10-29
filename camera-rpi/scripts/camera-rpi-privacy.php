#!/usr/bin/env php
<?php
declare(strict_types=1);

// Workers prohibit executable writable memory. Regex JIT is unnecessary here;
// disable it explicitly instead of weakening systemd MemoryDenyWriteExecute.
ini_set('pcre.jit', '0');

/** Local admin / worker boundary for optional privacy policy. Configuration is
 * changed only via explicit admin save under the existing RO write lock. Poll
 * positions, measurement decisions and hysteresis live exclusively in /run.
 */
require_once __DIR__.'/camera-rpi-climate.php';
require_once (is_file(__DIR__.'/lib/capture-privacy.php') ? __DIR__.'/lib' : __DIR__).'/capture-privacy.php';

final class PrivacyApp
{
    public string $config;
    public string $run;
    public string $servers;
    public function __construct()
    {
        $this->config=getenv('CAMERA_RPI_PRIVACY_CONFIG') ?: '/etc/camera-rpi/privacy.json';
        $this->run=getenv('CAMERA_RPI_PRIVACY_RUNTIME') ?: '/run/camera-rpi-privacy';
        $this->servers=getenv('CAMERA_RPI_CONFIG_DIR') ?: '/etc/camera-rpi/servers';
    }
    private function runtime(): void
    {
        if (is_link($this->run) || (!is_dir($this->run) && !mkdir($this->run,0700,true))) { throw new RuntimeException('Privates RAM-Verzeichnis fehlt.'); }
    }
    public function load(): array
    {
        $raw=ClimateApp::read($this->config);
        if ($raw===null) { return [['config'=>CapturePrivacy::DEFAULTS,'position'=>null],'missing']; }
        $v=ClimateApp::json($raw);
        if (array_keys($v)!==['config','position'] || !is_array($v['config'])) { throw new RuntimeException('Aufnahme-Konfiguration ungültig.'); }
        CapturePrivacy::validate($v['config']);
        return [$v,hash('sha256',$raw)];
    }
    /** Bind the cached position to the configured URL+slug, never to a mutable
     * server name alone. Secret values are neither returned nor logged.
     */
    public function identity(string $id): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$id)) { throw new RuntimeException('Standortquelle fehlt.'); }
        $raw=ClimateApp::read($this->servers.'/'.$id.'.conf');
        if ($raw===null) { throw new RuntimeException('Standort-Zielserver fehlt.'); }
        $fields=[];
        foreach (explode("\n",$raw) as $line) {
            $line=trim($line);
            if (preg_match('/^(server_base_url|camera_slug)=(.*)$/D',$line,$m)) {
                if (isset($fields[$m[1]])) { throw new RuntimeException('Doppelte Zielidentität.'); }
                $fields[$m[1]]=trim($m[2]);
            }
        }
        if (count($fields)!==2) { throw new RuntimeException('Zielidentität unvollständig.'); }
        return hash('sha256',$fields['server_base_url']."\n".$fields['camera_slug']);
    }
    /** Header received only after successful authenticated poll. Missing header
     * is an explicit unavailable position (legacy server), never a guessed site.
     */
    public function receive(string $id, string $headers, string $identity): void
    {
        if (!hash_equals($this->identity($id),$identity)) { throw new RuntimeException('Zielkonfiguration während Poll geändert.'); }
        $raw=ClimateApp::read($headers,32768) ?? '';
        preg_match_all('/^X-Camera-Position: ([A-Za-z0-9+\/=]{1,2048})\r?$/mi',$raw,$m);
        $position=null;
        if (count($m[1])===1) {
            $decoded=base64_decode($m[1][0],true);
            $p=$decoded!==false ? json_decode($decoded,true,8) : null;
            if (is_array($p) && ($p['protocol'] ?? '')==='camera-rpi-position-v1' && CapturePrivacy::position($p)) {
                $position=['latitude'=>$p['latitude'],'longitude'=>$p['longitude'],'timezone'=>$p['timezone']];
            }
        }
        $this->runtime();
        ClimateApp::atomic($this->run.'/position-'.$id.'.json',json_encode(['identity'=>$identity,'received'=>time(),'position'=>$position],JSON_THROW_ON_ERROR));
    }
    /** The last explicitly saved position survives reboot for at most 24h.
     * A newer null/malformed/expired poll never falls back to an old position.
     */
    public function position(array $document, int $now): ?array
    {
        $id=$document['config']['source_server'];
        if ($id==='') { return null; }
        $identity=$this->identity($id);
        $raw=ClimateApp::read($this->run.'/position-'.$id.'.json');
        $p=$raw===null ? $document['position'] : ClimateApp::json($raw);
        if (!is_array($p) || ($p['identity'] ?? '')!==$identity || !is_int($p['received'] ?? null)
            || $p['received']>$now+60 || $p['received']<$now-86400 || !is_array($p['position'] ?? null) || !CapturePrivacy::position($p['position'])) { return null; }
        return $p;
    }
    public function decision(?int $now=null): array
    {
        $now ??= time();
        [$d]=$this->load();
        $p=$d['config']['mode']==='solar' ? $this->position($d,$now) : null;
        return CapturePrivacy::window($d['config'],$p['position'] ?? null,$now);
    }
    /** Small authenticated heartbeat for the new server. A finite deadline
     * lets HLS stop at the boundary even between polls. Old servers ignore it.
     */
    public function report(): string
    {
        $now=time();
        try {
            [$d,$revision]=$this->load(); $decision=$this->decision($now);
            $enabled=$d['config']['mode']!=='off'; $until=$now+86400;
            foreach ($decision['windows'] as $w) {
                if ($now>=$w['from'] && $now<$w['until']) { $until=min($until,$w['until']); }
            }
            $dark=false;
            if ($d['config']['dark_enabled']) {
                $raw=ClimateApp::read($this->run.'/measurement.json'); $m=$raw===null ? [] : ClimateApp::json($raw);
                $dark=($m['revision'] ?? '')===$revision && ($m['accepted'] ?? true)===false
                    && ($m['measured_at'] ?? 0)>=$now-900 && ($m['measured_at'] ?? 0)<=$now;
            }
            $v=['enabled'=>$enabled,'allowed'=>$decision['allowed'],'until'=>$until,'suppressed'=>!$decision['allowed'] || $dark];
        } catch (Throwable) { $v=['enabled'=>true,'allowed'=>false,'until'=>$now,'suppressed'=>true]; }
        return base64_encode(json_encode($v,JSON_THROW_ON_ERROR));
    }
    /** One configuration CAS inside the global admin write window. Imported
     * coordinates are taken from the poll cache, never from submitted form data.
     */
    public function save(string $raw): void
    {
        $p=ClimateApp::json($raw);
        if (array_keys($p)!==['config','revision']) { throw new InvalidArgumentException('Speicherformat ungültig.'); }
        $c=CapturePrivacy::validate($p['config']);
        if ($c['dark_enabled'] && !extension_loaded('gd')) { throw new RuntimeException('Bitte PHP-GD installieren; Helligkeitsprüfung nicht aktiviert.'); }
        $this->runtime();
        $lock=fopen($this->run.'/config.lock','ce');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new RuntimeException('Konfigurationssperre fehlt.'); }
        try {
            [$old,$revision]=$this->load();
            if ($revision!==$p['revision']) { throw new RuntimeException('Aufnahmeoptionen inzwischen geändert; Seite neu laden.'); }
            $doc=['config'=>$c,'position'=>null];
            if ($c['mode']==='solar') {
                $doc['position']=$this->position($doc,time());
                if ($doc['position']===null) { throw new RuntimeException('Kein gültiger Kamerastandort vom gewählten Server (Poll höchstens 24h alt). Standort dort hinterlegen und erneut pollen lassen.'); }
            }
            if ($revision!=='missing') { ClimateApp::atomic($this->config.'.previous',ClimateApp::read($this->config),durable:true); }
            ClimateApp::atomic($this->config,json_encode($doc,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",durable:true);
        } finally { fclose($lock); }
        echo "OK\tAufnahmeoptionen gespeichert.\n";
    }
    public function view(): array
    {
        [$doc,$revision]=$this->load();
        $positions=[];
        foreach (glob($this->servers.'/*.conf') ?: [] as $file) {
            $id=basename($file,'.conf');
            try { $candidate=$doc; $candidate['config']['source_server']=$id; $candidate['position']=null;
                $positions[$id]=$this->position($candidate,time());
            } catch (Throwable) { $positions[$id]=null; }
        }
        try { $decision=$this->decision(); } catch (Throwable $e) { $decision=['allowed'=>false,'message'=>$e->getMessage(),'windows'=>[]]; }
        $raw=ClimateApp::read($this->run.'/measurement.json');
        return ['config'=>$doc['config'],'revision'=>$revision,'positions'=>$positions,'decision'=>$decision,'measurement'=>$raw===null ? null : ClimateApp::json($raw)];
    }
    /** Full-frame data exists only in caller's private tmpfs staging file.
     * Recheck the closing boundary after capture, then measure once before pool
     * publication. Rejected images never reach preview, queues or SD backups.
     */
    public function inspect(string $path): bool
    {
        $decision=$this->decision();
        if (!$decision['allowed']) { echo $decision['message']."\n"; return false; }
        [$d,$revision]=$this->load(); $c=$d['config'];
        if (!$c['dark_enabled']) { return true; }
        $this->runtime();
        $brightness=CapturePrivacy::brightness($path,$c['region']);
        $raw=ClimateApp::read($this->run.'/measurement.json');
        $previous=$raw===null ? [] : ClimateApp::json($raw);
        $recovering=($previous['revision'] ?? '')===$revision && ($previous['accepted'] ?? true)===false;
        $threshold=$c['minimum_percent']+($recovering ? $c['hysteresis_percent'] : 0);
        $accepted=$brightness>=$threshold;
        ClimateApp::atomic($this->run.'/measurement.json',json_encode(['measured_at'=>time(),'percent'=>round($brightness,2),
            'threshold'=>$threshold,'accepted'=>$accepted,'revision'=>$revision],JSON_THROW_ON_ERROR));
        echo sprintf("Helligkeit %.2f %% / Schwelle %.2f %%: %s.\n",$brightness,$threshold,$accepted ? 'freigegeben' : 'verworfen');
        return $accepted;
    }
    public function main(array $argv): int
    {
        if (in_array($argv[1] ?? '',['save','receive'],true) && posix_geteuid()!==0) { throw new RuntimeException('Root erforderlich.'); }
        switch ($argv[1] ?? '') {
            case 'get': echo json_encode($this->view(),JSON_THROW_ON_ERROR)."\n"; return 0;
            case 'save': $this->save(stream_get_contents(STDIN,8193)); return 0;
            case 'identity': echo $this->identity($argv[2] ?? ''); return 0;
            case 'report': echo $this->report(); return 0;
            case 'receive': $this->receive($argv[2] ?? '',$argv[3] ?? '',$argv[4] ?? ''); return 0;
            case 'check': $d=$this->decision(); echo $d['message']."\n"; return $d['allowed'] ? 0 : 3;
            case 'inspect': return $this->inspect($argv[2] ?? '') ? 0 : 3;
            default: throw new InvalidArgumentException('Erlaubt: get/save/identity/receive/check/inspect');
        }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    umask(0077);
    try { exit((new PrivacyApp())->main($argv)); }
    catch (Throwable $e) { fwrite(STDERR,'Aufnahmeschutz: '.$e->getMessage()."\n"); exit(1); }
}
