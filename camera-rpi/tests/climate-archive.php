<?php
declare(strict_types=1);
/** Isolated archive and shell integration tests; temporary files and fake
 * mount/archive commands only. No live backup, GPIO, service or DHT access.
 */
require_once __DIR__.'/../scripts/camera-rpi-climate-archive.php';
$checks=0;
function check(bool $ok,string $label): void { global $checks;$checks++;if (!$ok) throw new RuntimeException('FAIL: '.$label); }
function fails(callable $call,string $label): void { try {$call();}catch(Throwable){check(true,$label);return;}check(false,$label); }
function clearTree(string $path): void {
    foreach(new FilesystemIterator($path) as $file) {
        if ($file->isDir()&&!$file->isLink())clearTree($file->getPathname());else unlink($file->getPathname());
    }
    rmdir($path);
}
$stage=sys_get_temp_dir().'/climate-archive-test-'.bin2hex(random_bytes(8));mkdir($stage,0700);
$runtime=$stage.'/run';$archive=$stage.'/sd';$controller=$stage.'/controller';
mkdir($runtime,0700);mkdir($controller,0700);
$history=$runtime.'/climate-history.tsv';$now=1789218000;
$app=new ClimateHistoryArchive($runtime,$archive,$controller,posix_getgid());
$row=function(int $epoch,array $changes=[]) {
    return ClimateHistory::snapshot(array_replace(['updated_epoch'=>$epoch,'mode'=>'auto','outputs'=>'gpio',
        'state'=>'heating','dew_point_c'=>17.4,'dew_gap_c'=>2.6,'fan_percent'=>85,'heater'=>'on'],$changes));
};
$line=fn(int $epoch,array $changes=[])=>ClimateHistory::encode($row($epoch,$changes));
$files=fn()=>glob($archive.'/climate-*.tsv')?:[];
ob_start();
try {
    $app->restore($now);
    check(!file_exists($history)&&!file_exists($archive),'no old archive does not invent data or create SD directory');
    file_put_contents($history,$line($now-30));
    $app->backup($now);$first=$files()[0];
    check(file_get_contents($first)===file_get_contents($history),'exact schema roundtrip backup');
    check((fileperms($first)&0777)===0600&&(fileperms($archive)&0777)===0700,'private SD snapshot');
    clearstatcache(true,$first);$before=stat($first);
    $app->backup($now+30);clearstatcache(true,$first);$after=stat($first);
    check($before['ino']===$after['ino']&&$before['mtime']===$after['mtime'],'same-day backup never rewrites');
    unlink($history);file_put_contents($runtime.'/current.status','existing sensor state');
    file_put_contents($runtime.'/climate.status','existing regulator state');
    $app->restore($now);
    check(file_get_contents($history)===file_get_contents($first),'restore history after reboot');
    check((fileperms($history)&0777)===0640&&filegroup($history)===posix_getgid(),'restored file readable only by selected web group');
    check(file_get_contents($runtime.'/current.status')==='existing sensor state'
        &&file_get_contents($runtime.'/climate.status')==='existing regulator state','restore does not touch current statuses');
    check(!file_exists($controller.'/fault')&&!file_exists($controller.'/capture.request'),'no control state restored');
    file_put_contents($history,$line($now-30,['fan_percent'=>55]));
    $app->restore($now);
    check(ClimateHistory::read($history,$now)[0]['pwm']===55.0,'live point wins duplicate timestamp');
    $lock=fopen($controller.'/daemon.lock','ce');flock($lock,LOCK_EX);
    $unchanged=file_get_contents($history);$app->restore($now);
    check(file_get_contents($history)===$unchanged,'active controller locks out manual restore');
    fclose($lock);
    file_put_contents($history,"bad\n");$app->backup($now+86400);
    check(count($files())===1,'invalid live data does not erase backups');
    unlink($history);$app->backup($now+86400);
    check(count($files())===1,'missing live history preserves backups');
    file_put_contents($history,$line($now-30));file_put_contents($first,'');
    fails(fn()=>$app->backup($now),'invalid existing daily snapshot is not overwritten');
    check(file_get_contents($first)==='','corrupt snapshot preserved for diagnosis');
    unlink($first);symlink($history,$first);
    fails(fn()=>$app->backup($now),'symlink daily destination refused');unlink($first);
    symlink($runtime.'/current.status',$stage.'/link');
    unlink($history);symlink($stage.'/link',$history);
    fails(fn()=>$app->backup($now),'symlink runtime source refused');unlink($history);
    file_put_contents($history,str_repeat('x',ClimateHistory::MAX_BYTES+1));
    fails(fn()=>$app->backup($now),'oversized source refused');unlink($history);

    // Old sensor-only archives remain untouched; no migration/conversion required.
    file_put_contents($archive.'/sensor-20260912.tsv',"$now\t23.6\t59.6\n");
    file_put_contents($archive.'/notes.txt','operator');$app->restore($now);
    check(!file_exists($history),'sensor-only backups restore no fake climate history');
    file_put_contents($archive.'/climate-20260911.tsv',$line($now-172801).$line($now-60).$line($now+30));
    file_put_contents($archive.'/climate-20260912.tsv',$line($now-60,['fan_percent'=>60]).$line($now-30));
    $app->restore($now);$rows=ClimateHistory::read($history,$now);
    check(count($rows)===2&&$rows[0]['epoch']===$now-60&&$rows[0]['pwm']===60.0,'48h limit, no future data, newer snapshot wins');
    check(file_get_contents($archive.'/sensor-20260912.tsv')==="$now\t23.6\t59.6\n",'sensor archive bytes unchanged');
    foreach($files() as $file)unlink($file);

    // Berlin calendar date, including the day change while UTC still says 12th.
    $berlin=(new DateTimeImmutable('2026-09-12T21:59:00+00:00'))->getTimestamp();
    file_put_contents($history,$line($berlin));$app->backup($berlin);$app->backup($berlin+360);
    check(array_map('basename',$files())===['climate-20260912.tsv','climate-20260913.tsv'],'Berlin midnight uses next daily file');
    foreach($files() as $file)unlink($file);
    for($day=0;$day<32;$day++) {
        $stamp=$now+$day*86400;file_put_contents($history,$line($stamp));$app->backup($stamp);
    }
    check(count($files())===30,'retain last 30 successful climate snapshots');
    check(file_get_contents($archive.'/notes.txt')==='operator'&&is_file($archive.'/sensor-20260912.tsv'),'retention ignores notes and sensor namespace');
    foreach($files() as $file)unlink($file);

    // Maximum history stays bounded after merging multiple overlapping archives.
    $seed='';for($i=0;$i<ClimateHistory::MAX_ROWS;$i++)$seed.=$line($now-ClimateHistory::MAX_ROWS*10+$i*10);
    file_put_contents($archive.'/climate-20260911.tsv',$seed);
    file_put_contents($history,$line($now));$app->restore($now);
    $rows=ClimateHistory::read($history,$now);
    check(count($rows)===ClimateHistory::MAX_ROWS&&$rows[array_key_last($rows)]['epoch']===$now,'merged maximum bounded and newest retained');
    check(filesize($history)<ClimateHistory::MAX_BYTES,'restored history below 1 MiB');

    // Exercise the actual wrapper control flow with private command fixtures.
    $bin=$stage.'/bin';mkdir($bin,0700);
    $fake=$bin.'/archive';
    file_put_contents($fake,"#!/bin/bash\nprintf '%s\\n' \"\$1\" >> \"\$CASE_LOG\"\n[[ \$FAIL_ARCHIVE != \"\$1\" ]]\n");chmod($fake,0755);
    foreach(['findmnt'=>"printf '%s\\n' \"\$CASE_ROOT_OPTIONS\"",'mount'=>"printf 'mount %s\\n' \"\$*\" >> \"\$CASE_LOG\"\n[[ \$FAIL_RO != 1 || \"\$*\" != '-o remount,ro /' ]]",'sync'=>"echo sync >> \"\$CASE_LOG\""] as $name=>$body) {
        file_put_contents($bin.'/'.$name,"#!/bin/bash\n$body\n");chmod($bin.'/'.$name,0755);
    }
    $wrapper=file_get_contents(__DIR__.'/../scripts/camera-rpi-sensor-backup');
    $wrapper=str_replace('[[ "$EUID" == 0 ]] || exit 1','# root guard omitted only in this isolated fixture',$wrapper);
    $wrapper=str_replace('/run/lock/camera-rpi.operation.lock',$stage.'/operation.lock',$wrapper);
    $wrapper=str_replace('/usr/bin/python3 -B /usr/local/libexec/camera-rpi-sensor-archive.py backup',escapeshellarg($fake).' sensor',$wrapper);
    $wrapper=str_replace('/usr/bin/php -d opcache.enable_cli=0 -d opcache.file_cache= /usr/local/libexec/camera-rpi-climate-archive.php backup',escapeshellarg($fake).' climate',$wrapper);
    $wrapper=str_replace('/usr/local/libexec/camera-rpi-climate-archive.php',$fake,$wrapper);
    file_put_contents($stage.'/wrapper',$wrapper);
    foreach([['ro','',0],['ro','sensor',1],['ro','climate',1],['rw','',0]] as [$options,$failure,$expected]) {
        $log=$stage.'/case.log';file_put_contents($log,'');$pipes=[];
        $env=getenv();$env['PATH']=$bin.':'.$env['PATH'];$env['CASE_LOG']=$log;
        $env['CASE_ROOT_OPTIONS']=$options;$env['FAIL_ARCHIVE']=$failure;$env['FAIL_RO']='0';
        $p=proc_open(['/bin/bash',$stage.'/wrapper'],[0=>['file','/dev/null','r'],1=>['file',$stage.'/stdout','w'],2=>['file',$stage.'/stderr','w']],$pipes,null,$env);
        check(proc_close($p)===$expected,'combined wrapper exit status '.$options.'/'.$failure);
        $events=file_get_contents($log);
        check(substr_count($events,"sensor\n")===1&&substr_count($events,"climate\n")===1,'both archives attempted despite one failing');
        check($options==='ro' ? substr_count($events,'mount -o remount,rw /')===1&&substr_count($events,'mount -o remount,ro /')===1 : !str_contains($events,'mount'),'single shared RO/RW window');
    }
    $env['CASE_ROOT_OPTIONS']='ro';$env['FAIL_RO']='1';$env['FAIL_ARCHIVE']='';
    $p=proc_open(['/bin/bash',$stage.'/wrapper'],[0=>['file','/dev/null','r'],1=>['file',$stage.'/stdout','w'],2=>['file',$stage.'/stderr','w']],$pipes,null,$env);
    check(proc_close($p)===1&&str_contains(file_get_contents($stage.'/stderr'),'KRITISCH'),'RO restoration failure remains failure');
    $restore=file_get_contents(__DIR__.'/../scripts/camera-rpi-history-restore');
    $restore=str_replace('/usr/bin/python3 -B /usr/local/libexec/camera-rpi-sensor-archive.py restore',escapeshellarg($fake).' sensor',$restore);
    $restore=str_replace('/usr/bin/php -d opcache.enable_cli=0 -d opcache.file_cache= /usr/local/libexec/camera-rpi-climate-archive.php restore',escapeshellarg($fake).' climate',$restore);
    $restore=str_replace('/usr/local/libexec/camera-rpi-climate-archive.php',$fake,$restore);
    file_put_contents($stage.'/restore',$restore);
    foreach(['','sensor','climate'] as $failure) {
        file_put_contents($log,'');$env['FAIL_ARCHIVE']=$failure;
        $p=proc_open(['/bin/bash',$stage.'/restore'],[0=>['file','/dev/null','r'],1=>['file',$stage.'/stdout','w'],2=>['file',$stage.'/stderr','w']],$pipes,null,$env);
        check(proc_close($p)===($failure===''?0:1),'restore wrapper combines error status');
        check(file_get_contents($log)==="sensor\nclimate\n",'restore attempts both independent archives, no remount');
    }
    $unit=file_get_contents(__DIR__.'/../systemd/camera-rpi-sensor-restore.service');
    check(str_contains($unit,'Before=camera-rpi-sensor.service camera-rpi-climate.service'),'boot restoration precedes both writers');
    check(str_contains(file_get_contents(__DIR__.'/../systemd/camera-rpi-tmpfiles.conf'),'d /run/camera-rpi-climate 0700 root root -'),'boot creates shared controller lock directory');
} finally { ob_end_clean();clearTree($stage); }
echo "OK: $checks climate archive checks; daily/retention/restore/locks/RO/error paths, no live services or GPIO\n";
