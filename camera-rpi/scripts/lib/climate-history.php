<?php
declare(strict_types=1);

/** Shared, bounded RAM history format. No GPIO, sensor reads or SD archive.
 * Rows: epoch, mode, state, outputs, dew °C, gap °C, PWM %, heater 0/1.
 * A dash means unknown, never zero. All consumers use the same validation.
 */
final class ClimateHistory
{
    public const HOURS = 48;
    public const MAX_ROWS = 8192;
    public const MAX_BYTES = 1048576;
    public const PERIOD = 30;
    private const STATES = ['off','warming','idle','heating','summer','sensor_error','capture_pause','fault','stopped'];

    /** Accept the published decimal format only; never coerce text/NaN to zero. */
    private static function decimal(mixed $value, float $min, float $max): ?float
    {
        if (is_bool($value) || !is_scalar($value)
            || !preg_match('/^-?[0-9]{1,3}(?:\.[0-9])?$/D',(string)$value)) { return null; }
        $number = (float)$value;
        return $number >= $min && $number <= $max ? $number : null;
    }

    /** Turn a supervisor snapshot into a strict, independently readable row. */
    public static function snapshot(array $state): array
    {
        $epoch = $state['updated_epoch'] ?? '';
        if (!preg_match('/^[0-9]{10}$/D',(string)$epoch)
            || !in_array($state['mode']??'', ['off','simulate','auto','manual80'], true)
            || !in_array($state['state']??'', self::STATES, true)
            || !in_array($state['outputs']??'', ['none','gpio'], true)) {
            throw new InvalidArgumentException('Ungueltiger Klima-Verlaufspunkt');
        }
        return [
            'epoch'=>(int)$epoch, 'mode'=>$state['mode'], 'state'=>$state['state'], 'outputs'=>$state['outputs'],
            'dew'=>self::decimal($state['dew_point_c']??null, -150, 80),
            'gap'=>self::decimal($state['dew_gap_c']??null, 0, 230),
            'pwm'=>self::decimal($state['fan_percent']??null, 0, 100),
            'heater'=>match ($state['heater']??'') { 'on'=>1, 'off'=>0, default=>null },
        ];
    }

    /** Locale-independent TSV; fixed enums and numbers cannot inject columns. */
    public static function encode(array $row): string
    {
        $values = [$row['epoch'],$row['mode'],$row['state'],$row['outputs']];
        foreach (['dew','gap','pwm'] as $key) {
            $values[] = $row[$key] === null ? '-' : number_format($row[$key],1,'.','');
        }
        $values[] = $row['heater'] === null ? '-' : (string)$row['heater'];
        return implode("\t",$values)."\n";
    }

    /** Invalid rows are not chart data; enforce size before tokenization. */
    public static function decode(string $line): ?array
    {
        if (strlen($line)>128) { return null; }
        $f = explode("\t",rtrim($line,"\r\n"));
        if (count($f)!==8 || !in_array($f[7],['0','1','-'],true)) { return null; }
        foreach ([4=>[-150,80],5=>[0,230],6=>[0,100]] as $i=>[$min,$max]) {
            if ($f[$i]!=='-' && self::decimal($f[$i],$min,$max)===null) { return null; }
        }
        try {
            return self::snapshot(['updated_epoch'=>$f[0],'mode'=>$f[1],'state'=>$f[2],'outputs'=>$f[3],
                'dew_point_c'=>$f[4],'dew_gap_c'=>$f[5],'fan_percent'=>$f[6],
                'heater'=>match($f[7]) { '1'=>'on','0'=>'off',default=>'' }]);
        } catch (InvalidArgumentException) { return null; }
    }

    /** Read-only, bounded regular-file snapshot; never read through a symlink.
     * Atomic publication makes the inode stable, while web requests may race a
     * replacement. No file is created here and stale rows never become current.
     */
    public static function read(string $path, int $now): array
    {
        clearstatcache(true,$path);
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0170000)!==0100000 || $stat['size']>self::MAX_BYTES) { return []; }
        $file = @fopen($path,'rb');
        if (!$file) { return []; }
        try {
            $opened = fstat($file);
            if ($opened['ino']!==$stat['ino'] || $opened['dev']!==$stat['dev']) { return []; }
            $raw = stream_get_contents($file,self::MAX_BYTES+1);
        } finally { fclose($file); }
        if ($raw===false || strlen($raw)>self::MAX_BYTES) { return []; }
        $rows=[];$last=0;
        foreach (array_slice(explode("\n",rtrim($raw,"\n")),-self::MAX_ROWS) as $line) {
            $row=self::decode($line);
            if ($row===null || $row['epoch']<=$last || $row['epoch']>$now || $row['epoch']<$now-self::HOURS*3600) { continue; }
            $rows[]=$row;$last=$row['epoch'];
        }
        return array_slice($rows,-self::MAX_ROWS);
    }
}

/** One recorder per supervisor. Sample every 30s and on output/state changes
 * (including the 2s start boost/capture pause). Replace a bounded RAM file;
 * no periodic fsync, journal spam or additional daemon/timer is required.
 */
final class ClimateHistoryRecorder
{
    private ?array $rows = null;
    private ?string $lastSignature = null;
    private float $lastMono = -INF;
    private Closure $replace;

    public function __construct(private string $path, callable $replace)
    {
        $this->replace=Closure::fromCallable($replace);
    }

    /** Existing history survives service restarts. Clock rollback discards only
     * future records, never connects the curve across contradictory timestamps.
     */
    public function record(array $state, float $mono): bool
    {
        $row=ClimateHistory::snapshot($state);
        $signature=implode('|',[$row['mode'],$row['state'],$row['outputs'],$row['pwm']??'-',$row['heater']??'-']);
        $this->rows ??= ClimateHistory::read($this->path,$row['epoch']);
        $last=$this->rows ? $this->rows[array_key_last($this->rows)]['epoch'] : 0;
        if ($signature===$this->lastSignature && $mono>=$this->lastMono
            && $mono-$this->lastMono<ClimateHistory::PERIOD && $row['epoch']>=$last) { return false; }
        $rows=array_values(array_filter($this->rows, static fn($old)=>
            $old['epoch']<$row['epoch'] && $old['epoch']>=$row['epoch']-ClimateHistory::HOURS*3600));
        $rows[]=$row;
        $rows=array_slice($rows,-ClimateHistory::MAX_ROWS);
        ($this->replace)(implode('',array_map(ClimateHistory::encode(...),$rows)));
        $this->rows=$rows;$this->lastMono=$mono;$this->lastSignature=$signature;
        return true;
    }
}

/** Pure SVG geometry. Real time on X, explicit gaps for missing data/stopped
 * services, and separate segments when simulation/physical output mode changes.
 */
final class ClimateHistoryChart
{
    /** Display-only mask, also used by hover data. Never feed back into the
     * recorder/archive: capture-off and startup PWM remain in the raw TSV.
     * The old eight-column format has no boost flag. Only hide a bounded 100%
     * pulse immediately after a recorded capture pause, followed within
     * 3s by a positive lower PWM in the same normal state/mode/output context.
     * Sustained 100%, sensor faults, unknown intervals and mode changes stay
     * visible. Null creates an honest gap, not an invented actuator value.
     */
    public static function displayRows(array $rows): array
    {
        $display=$rows;
        $count=count($rows);
        for ($i=0;$i<$count;$i++) {
            if ($rows[$i]['state']==='capture_pause') {
                $display[$i]['pwm']=null;
                $display[$i]['heater']=null;
                $display[$i]['hidden_outputs']=['pwm','heater'];
            }
            $row=$rows[$i];
            if ($row['pwm']!==100.0 || !in_array($row['state'],['heating','summer','warming'],true)) { continue; }
            $previous=$i>0 ? $rows[$i-1] : null;
            if ($previous===null || $previous['state']!=='capture_pause' || $previous['pwm']!==0.0
                || $row['epoch']-$previous['epoch']>45
                || $row['mode']!==$previous['mode'] || $row['outputs']!==$previous['outputs']) { continue; }
            $end=$i+1;
            while ($end<$count && $rows[$end]['pwm']===100.0
                && $rows[$end]['state']===$row['state'] && $rows[$end]['mode']===$row['mode']
                && $rows[$end]['outputs']===$row['outputs']
                && $rows[$end]['epoch']-$row['epoch']<=3) { $end++; }
            if ($end>=$count) { continue; } // Do not guess about a live/open pulse.
            $after=$rows[$end];
            if ($after['epoch']-$row['epoch']>3 || $after['pwm']===null
                || $after['pwm']<=0 || $after['pwm']>=100 || $after['state']!==$row['state']
                || $after['mode']!==$row['mode'] || $after['outputs']!==$row['outputs']) { continue; }
            for ($j=$i;$j<$end;$j++) {
                $display[$j]['pwm']=null;
                $display[$j]['hidden_outputs']=['pwm'];
            }
        }
        return $display;
    }

    public static function series(array $rows, string $key, float $min, float $max,
        float $top=48, float $bottom=228, bool $step=false): array
    {
        if (!$rows || $max<=$min || !in_array($key,['dew','gap','pwm','heater'],true)) { return []; }
        $start=$rows[0]['epoch'];$end=$rows[array_key_last($rows)]['epoch'];
        $span=max(1,$end-$start);$segments=[];$previous=null;
        foreach ($rows as $row) {
            $value=$row[$key];
            if ($value===null || $row['state']==='stopped') { $previous=null;continue; }
            $x=number_format(70+($row['epoch']-$start)/$span*760,1,'.','');
            $y=number_format($bottom-max(0,min(1,($value-$min)/($max-$min)))*($bottom-$top),1,'.','');
            if ($previous===null || $row['epoch']-$previous['epoch']>45
                || $row['mode']!==$previous['mode'] || $row['outputs']!==$previous['outputs']) {
                // A tiny round-capped stroke makes a first single sample visible.
                $segments[]=['mode'=>$row['mode'],'d'=>"M$x $y l0.01 0"];
            } else {
                $segments[array_key_last($segments)]['d'].=$step ? " H$x V$y" : " L$x $y";
            }
            $previous=$row;
        }
        return $segments;
    }
}
