<?php
declare(strict_types=1);
/** Isolated history/geometry checks. No GPIO, DHT reader or installed file. */
require_once __DIR__.'/../scripts/lib/climate-history.php';
$checks=0;
function check(bool $condition,string $label): void {
    global $checks;$checks++;if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
}
$root=sys_get_temp_dir().'/camera-climate-history-'.bin2hex(random_bytes(8));mkdir($root,0700);
$path=$root.'/history.tsv';$now=time();$writes=0;
$base=['updated_epoch'=>$now,'mode'=>'simulate','state'=>'heating','outputs'=>'none',
    'dew_point_c'=>17.4,'dew_gap_c'=>2.6,'fan_percent'=>100,'heater'=>'on'];
$point=fn(int $time,array $changes=[])=>array_replace($base,['updated_epoch'=>$time],$changes);
$replace=function(string $data)use($path,&$writes){$writes++;file_put_contents($path,$data);};
try {
    check(ClimateHistory::read($path,$now)===[],'missing history stays absent');
    check(!file_exists($path),'read never creates a file');
    $row=ClimateHistory::snapshot($base);
    check(ClimateHistory::decode(ClimateHistory::encode($row))===$row,'TSV roundtrip');
    foreach(['NaN','INF','<script>',true,[],101,-1] as $invalid) {
        check(ClimateHistory::snapshot($point($now,['fan_percent'=>$invalid]))['pwm']===null,'invalid PWM is unknown');
    }
    $empty=ClimateHistory::snapshot($point($now,['dew_point_c'=>'','dew_gap_c'=>'','heater'=>'']));
    check($empty['dew']===null&&$empty['gap']===null&&$empty['heater']===null,'unknown never coerced to zero');
    foreach(["bad\n",str_repeat('a',129),"$now\tauto\theating\tgpio\t10\t5\t101\t1\n",
        "$now\tx\theating\tgpio\t10\t5\t50\t1\n"] as $invalid) {
        check(ClimateHistory::decode($invalid)===null,'malformed row rejected');
    }
    $recorder=new ClimateHistoryRecorder($path,$replace);
    check($recorder->record($base,0),'first sample records');
    check(!$recorder->record($base,.2)&&$writes===1,'same second heartbeat does not write');
    check(!$recorder->record($point($now+1),1)&&$writes===1,'unchanged heartbeat throttled');
    check($recorder->record($point($now+2,['fan_percent'=>90]),2),'boost end records immediately');
    check(!$recorder->record($point($now+31,['fan_percent'=>90]),31),'30s interval uses last event');
    check($recorder->record($point($now+32,['fan_percent'=>90]),32),'periodic sample');
    check($recorder->record($point($now+33,['state'=>'capture_pause','fan_percent'=>0,'heater'=>'off']),33),'capture pause records immediately');
    check(count(ClimateHistory::read($path,$now+33))===4,'bounded event count');
    $restart=new ClimateHistoryRecorder($path,$replace);
    $restart->record($point($now+34,['mode'=>'auto','outputs'=>'gpio']),0);
    check(count(ClimateHistory::read($path,$now+34))===5,'history survives service restart');
    $restart->record($point($now+10),1);
    $rows=ClimateHistory::read($path,$now+10);
    check(count($rows)===3&&$rows[2]['epoch']===$now+10,'clock rollback drops contradictory future records');

    // Irregular time spacing, step edges and gaps: no straight line may imply
    // that an unobserved output stayed on through a service outage or mode change.
    $rows=[];
    foreach([[0,[]],[2,['fan_percent'=>90]],[32,[]]] as [$offset,$changes]) {
        $rows[]=ClimateHistory::snapshot($point($now+$offset,$changes));
    }
    $line=ClimateHistoryChart::series($rows,'pwm',0,100,48,228,true);
    check(count($line)===1&&str_contains($line[0]['d'],'H117.5 V66.0'),'real-time spacing and step PWM');
    $rows[]=ClimateHistory::snapshot($point($now+100));
    check(count(ClimateHistoryChart::series($rows,'dew',0,20))===2,'missing interval creates gap');
    $rows[]=ClimateHistory::snapshot($point($now+110,['mode'=>'auto','outputs'=>'gpio']));
    check(count(ClimateHistoryChart::series($rows,'dew',0,20))===3,'simulation/auto split');
    $rows[]=ClimateHistory::snapshot($point($now+120,['mode'=>'auto','outputs'=>'gpio','dew_point_c'=>'']));
    $rows[]=ClimateHistory::snapshot($point($now+130,['mode'=>'auto','outputs'=>'gpio']));
    check(count(ClimateHistoryChart::series($rows,'dew',0,20))===4,'unknown metric creates gap');
    $rows[]=ClimateHistory::snapshot($point($now+140,['state'=>'stopped']));
    check(count(ClimateHistoryChart::series($rows,'heater',0,1))===3,'stopped service is not extrapolated');

    // Display masking never mutates raw samples or hides genuine long/error PWM.
    $samples=[];
    foreach ([[0,['fan_percent'=>80]],[30,['state'=>'capture_pause','fan_percent'=>0,'heater'=>'off']],
        [60,['state'=>'capture_pause','fan_percent'=>0,'heater'=>'off']],[65,[]],
        [67,['fan_percent'=>80]],[97,['fan_percent'=>0,'heater'=>'off','state'=>'idle']]] as [$t,$changes]) {
        $samples[]=ClimateHistory::snapshot($point($now+$t,$changes));
    }
    $raw=serialize($samples);$display=ClimateHistoryChart::displayRows($samples);
    check(serialize($samples)===$raw,'display does not mutate source');
    check($display[1]['pwm']===null&&$display[2]['heater']===null,'capture outputs masked');
    check($display[3]['pwm']===null&&$display[3]['heater']===1,'boost masks PWM only');
    check($display[4]['pwm']===80.0&&$display[5]['heater']===0,'regular outputs retained');
    check(array_column($display,'dew')===array_column($samples,'dew'),'dew unaffected');
    check(array_column($display,'gap')===array_column($samples,'gap'),'gap unaffected');
    check(count(ClimateHistoryChart::series($display,'pwm',0,100))===2,'mask gives gap rather than invented bridge');
    foreach (['sensor_error','fault','stopped'] as $state) {
        $error=[ClimateHistory::snapshot($point($now,['state'=>$state])),ClimateHistory::snapshot($point($now+2,['state'=>$state,'fan_percent'=>80]))];
        check(ClimateHistoryChart::displayRows($error)===$error,'error pulse remains visible');
    }
    foreach ([['dt'=>4],['mode'=>'auto'],['outputs'=>'gpio'],['fan_percent'=>0],['fan_percent'=>100],['fan_percent'=>'']] as $change) {
        $pulse=[ClimateHistory::snapshot($point($now)),ClimateHistory::snapshot($point($now+($change['dt']??2),array_replace(['fan_percent'=>80],$change)))];
        array_unshift($pulse,ClimateHistory::snapshot($point($now-1,['state'=>'capture_pause','fan_percent'=>0,'heater'=>'off'])));
        check(ClimateHistoryChart::displayRows($pulse)[1]['pwm']===100.0,'ambiguous sustained/mode/unknown post-capture pulse not hidden');
    }
    check(ClimateHistoryChart::displayRows([$row])===[$row],'open pulse not hidden');
    $pulse=[ClimateHistory::snapshot($point($now)),ClimateHistory::snapshot($point($now+1)),ClimateHistory::snapshot($point($now+2,['fan_percent'=>80]))];
    check(ClimateHistoryChart::displayRows($pulse)===$pulse,'ordinary short spike at history boundary remains');
    array_unshift($pulse,ClimateHistory::snapshot($point($now-1,['state'=>'capture_pause','fan_percent'=>0,'heater'=>'off'])));
    check(array_column(ClimateHistoryChart::displayRows($pulse),'pwm')===[null,null,null,80.0],'multi-record post-capture boost masked');
    check(ClimateHistoryChart::displayRows([])===[],'empty display');

    // Seed a maximum-size history once, then measure one trim/write/read/render.
    // No quadratic thousands-of-updates setup and no real-time waiting.
    $seed='';
    for($i=0;$i<ClimateHistory::MAX_ROWS;$i++) {
        $seed.=ClimateHistory::encode(ClimateHistory::snapshot($point($now-ClimateHistory::MAX_ROWS*20+$i*20)));
    }
    file_put_contents($path,$seed);
    $start=hrtime(true);
    $full=new ClimateHistoryRecorder($path,$replace);$full->record($base,0);
    $rows=ClimateHistory::read($path,$now);
    foreach(['dew','gap','pwm','heater'] as $key)ClimateHistoryChart::series($rows,$key,0,100);
    $elapsed=(hrtime(true)-$start)/1e6;
    check(count($rows)===ClimateHistory::MAX_ROWS,'row ceiling');
    check(filesize($path)<=ClimateHistory::MAX_BYTES,'file byte ceiling');
    $full->record($point($now+ClimateHistory::HOURS*3600+1),ClimateHistory::HOURS*3600+1);
    check(count(ClimateHistory::read($path,$now+ClimateHistory::HOURS*3600+1))===1,'48h retention');
    symlink($path,$root.'/link');
    check(ClimateHistory::read($root.'/link',$now+200000)===[],'symlink rejected');
    file_put_contents($path,str_repeat('x',ClimateHistory::MAX_BYTES+1));
    check(ClimateHistory::read($path,$now)===[],'oversized file rejected');
    printf("OK: %d climate history checks; max-history trim/write/read/4-series %.1f ms, peak %.1f MiB; no GPIO\n",
        $checks,$elapsed,memory_get_peak_usage(true)/1048576);
} finally {
    foreach(new FilesystemIterator($root) as $file)unlink($file->getPathname());
    rmdir($root);
}
