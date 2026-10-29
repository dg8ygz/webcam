<?php
declare(strict_types=1);
/** Pure deadline/schema tests; no real systemd, DHT or GPIO commands. */
require_once __DIR__.'/../ops/fix-dht-restore-timer.php';
$checks=0;
function timerCheck(bool $ok,string $name): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($name);}
function timerFails(callable $fn,string $name): void {try{$fn();}catch(Throwable){timerCheck(true,$name);return;}timerCheck(false,$name);}
$activation=65911882709;$deadline=152371882709;
timerCheck(DhtRestoreTimer::deadline($activation,$activation,83000000000)===$deadline,'original deadline');
timerCheck(DhtRestoreTimer::deadline($activation,$activation,90000000000)===$deadline,'later maintenance cannot postpone');
timerFails(fn()=>DhtRestoreTimer::deadline(0,0,83000000000),'missing activation');
timerFails(fn()=>DhtRestoreTimer::deadline($activation,$activation+6000000,83000000000),'wrong trial');
timerFails(fn()=>DhtRestoreTimer::deadline($activation,$activation,1),'future activation');
timerFails(fn()=>DhtRestoreTimer::deadline($activation,$activation,$deadline-1000000),'imminent restoration');
timerFails(fn()=>DhtRestoreTimer::deadline($activation,$activation,$deadline+1),'expired deadline');
$dropin=DhtRestoreTimer::dropin($deadline);
timerCheck(str_contains($dropin,"OnActiveSec=\nOnBootSec=152371.882709s\n"),'reset old expressions, exact monotonic deadline');
timerCheck(!str_contains($dropin,'OnCalendar='),'no wallclock/zone-dependent schedule');
timerCheck(str_contains($dropin,'RandomizedDelaySec=0'),'no extra random delay');
$values=DhtRestoreTimer::timerValue('a(stt) 1 "OnBootUSec" 152371882709 152371882709');
DhtRestoreTimer::assertFixed($values,$deadline);timerCheck(true,'verify effective D-Bus deadline');
timerFails(fn()=>DhtRestoreTimer::assertFixed(['OnBootUSec',$deadline,$deadline+1],$deadline),'wrong next firing');
timerFails(fn()=>DhtRestoreTimer::assertFixed(['OnActiveUSec',86460000000,166471869134],$deadline),'drifted timer refused');
timerFails(fn()=>DhtRestoreTimer::timerValue('a(stt) 2 "OnBootUSec" 1 1 "OnActiveUSec" 1 1'),'multiple expressions refused');
timerFails(fn()=>DhtRestoreTimer::timerValue('bad'),'malformed D-Bus response');
timerCheck(DhtRestoreTimer::timerValue('a(stt) 1 "OnActiveUSec" 86460000000 166471869134')[1]===DhtRestoreTimer::DELAY_US,'live baseline recognized');
$source=file_get_contents(__DIR__.'/../ops/fix-dht-restore-timer.php');
timerCheck(!preg_match('/\[\x27\/usr\/bin\/systemctl\x27,\x27(?:start|stop|restart)\x27/',$source),'no systemctl start/stop/restart');
$runner=file_get_contents(__DIR__.'/../ops/dht-driver-debug/run-diagnostic.sh');
timerCheck(str_contains($runner,'--on-boot="$RESTORE_DEADLINE"')&&!str_contains($runner,'--on-active='),'future diagnostic bundles use fixed boot deadline');
timerCheck(str_contains($runner,'$clock = hrtime();'),'future runner uses monotonic clock rather than wallclock');
timerCheck(str_contains($runner,'$clock[0] + (int)$argv[1], intdiv($clock[1], 1000)'),'deadline avoids microsecond integer overflow on 32-bit PHP');
echo "OK: $checks DHT fallback timer checks; no live services or GPIO\n";
