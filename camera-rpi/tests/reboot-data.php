<?php
declare(strict_types=1);
// Real files, hardlinks, integrity failures and two logical boots; no services,
// camera, GPIO, mounts or writes outside the private fixture directory.
require_once __DIR__.'/../scripts/camera-rpi-reboot-data.php';
$stage=sys_get_temp_dir().'/camera-reboot-test-'.bin2hex(random_bytes(8)); mkdir($stage,0700);
umask(0077);$checks=0;
function checkR(bool $ok,string $name):void { global $checks; ++$checks;if(!$ok)throw new RuntimeException($name); }
function failR(callable $f,string $name):void {try{$f();}catch(Throwable){checkR(true,$name);return;}checkR(false,$name);}
function removeR(string $p):void {foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())removeR($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
foreach(['live','spool','next','nextspool'] as $d)mkdir($stage.'/'.$d,0700);
$bootA='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';$bootB='bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
$app=new CameraRebootData($stage.'/live',$stage.'/spool',$stage.'/sd');
$restore=new CameraRebootData($stage.'/next',$stage.'/nextspool',$stage.'/sd');
ob_start();
try {
    $restore->restore($bootB);checkR(!is_dir($stage.'/sd'),'no checkpoint does not write SD');
    file_put_contents($stage.'/live/history.tsv',"1789300000\t20.1\t80.2\n");
    file_put_contents($stage.'/live/climate-history.tsv',"1789300000\tauto\theating\tgpio\t16.5\t3.6\t80.2\t1\n");
    file_put_contents($stage.'/live/current.status','do not replay');
    file_put_contents($stage.'/journal','diagnostic journal');
    $pool=$stage.'/spool/.capture-pool/1789300000';mkdir($pool.'/pending',0700,true);
    mkdir($stage.'/spool/one/images',0700,true);mkdir($stage.'/spool/two/images',0700,true);
    mkdir($stage.'/spool/one/requests',0700,true);
    file_put_contents($pool.'/image.jpg',"\xff\xd8".str_repeat('JPEG',1000)."\xff\xd9");
    foreach(['one','two'] as $id){touch($pool.'/pending/'.$id);link($pool.'/image.jpg',$stage.'/spool/'.$id.'/images/1789300000.jpg');}
    file_put_contents($pool.'/stamp',"20260913_1340\n");file_put_contents($pool.'/captured',"1789300002\n");
    file_put_contents($stage.'/spool/.capture-pool/latest',"1789300000\n");
    file_put_contents($stage.'/spool/one/images/1789300000.jpg.legacy-stamp',"20260913_1340\n");
    file_put_contents($stage.'/spool/one/images/1789300001.jpg.part','incomplete');
    file_put_contents($stage.'/spool/one/requests/1789300001.request','capture');
    $first=$app->save($bootA,$stage.'/journal');
    checkR(fileperms($stage.'/sd')%512===0700,'private backup directory');
    $m=json_decode(file_get_contents($stage.'/sd/'.$first.'/manifest.json'),true);
    checkR(!isset($m['files']['current.status']),'no current control state');
    checkR(!isset($m['files']['spool/one/requests/1789300001.request']),'no old capture requests');
    checkR(!isset($m['files']['spool/one/images/1789300001.jpg.part']),'no incomplete JPEG');
    checkR(isset($m['files']['journal.log']),'journal retained');
    $restore->restore($bootA);checkR(!file_exists($stage.'/next/history.tsv'),'same-boot restore is no-op');
    $restore->restore($bootB);
    checkR(file_get_contents($stage.'/next/history.tsv')===file_get_contents($stage.'/live/history.tsv'),'sensor roundtrip');
    checkR(file_get_contents($stage.'/next/climate-history.tsv')===file_get_contents($stage.'/live/climate-history.tsv'),'climate roundtrip');
    checkR(!file_exists($stage.'/next/journal.log'),'logs not reinjected');
    $master=stat($stage.'/nextspool/.capture-pool/1789300000/image.jpg');
    checkR(stat($stage.'/nextspool/one/images/1789300000.jpg')['ino']===$master['ino'],'one shares master');
    checkR(stat($stage.'/nextspool/two/images/1789300000.jpg')['ino']===$master['ino'],'two shares master');
    checkR(file_get_contents($stage.'/nextspool/one/images/1789300000.jpg.legacy-stamp')==="20260913_1340\n",'upload stamp retained');
    failR(fn()=>$restore->restore($bootB),'never overwrite live spool/history');
    // A second shutdown on the SAME day must include newly collected rows.
    file_put_contents($stage.'/live/history.tsv',"1789300300\t20.2\t81.0\n",FILE_APPEND);
    $second=$app->save($bootA);checkR($second!==$first,'new generation on same day');
    checkR(str_contains(file_get_contents($stage.'/sd/'.$second.'/history.tsv'),'1789300300'),'same day new row saved');
    // Empty queue replaces a previously saved queue, preventing normal reboot replay.
    removeR($stage.'/spool');mkdir($stage.'/spool',0700);
    $third=$app->save($bootB);
    checkR(count(glob($stage.'/sd/checkpoint-*'))===2&&!file_exists($stage.'/sd/'.$first),'retain current and predecessor');
    $thirdM=json_decode(file_get_contents($stage.'/sd/'.$third.'/manifest.json'),true);
    checkR(count(array_filter(array_keys($thirdM['files']),fn($n)=>str_starts_with($n,'spool/')))===0,'empty queue saved');
    removeR($stage.'/next');mkdir($stage.'/next',0700);removeR($stage.'/nextspool');mkdir($stage.'/nextspool',0700);
    $restore->restore($bootA);checkR(count(scandir($stage.'/nextspool'))===2,'no resurrection of older images');
    // Corruption rejected before touching destination; invalid paths never extracted.
    removeR($stage.'/next');mkdir($stage.'/next',0700);
    file_put_contents($stage.'/sd/'.$third.'/history.tsv','corrupt');
    failR(fn()=>$restore->restore($bootA),'hash mismatch');checkR(count(scandir($stage.'/next'))===2,'integrity failure leaves RAM untouched');
    foreach(['../escape','spool/one/../../etc/key','spool/one/requests/123.request','current.status','spool/.capture-pool/.build/image.jpg'] as $bad){checkR(!CameraRebootData::allowed($bad),'reject '.$bad);}
    symlink('/etc/passwd',$stage.'/spool/link');failR(fn()=>$app->save($bootA),'symlink rejected');unlink($stage.'/spool/link');
    $pointer=file_get_contents($stage.'/sd/current');
    $f=fopen($stage.'/live/history.tsv','wb');ftruncate($f,CameraRebootData::MAX_BYTES+1);fclose($f);
    failR(fn()=>$app->save($bootA),'oversize rejected');checkR(file_get_contents($stage.'/sd/current')===$pointer,'failed save retains pointer');
    ob_end_clean();echo "OK: $checks reboot checkpoint checks; fresh same-day history, hardlinks, queues, corruption, retention; no services/GPIO\n";
} finally {if(ob_get_level())ob_end_clean();removeR($stage);}
