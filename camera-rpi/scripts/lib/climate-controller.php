<?php
declare(strict_types=1);

/** Pure enclosure control; no files, subprocesses or GPIO/IIO accesses.
 * Wall time checks measurement freshness, monotonic time drives ALL delays.
 * The supervisor supplies paired snapshots from the existing DHT reader.
 */
final class ClimateConfig
{
    public const DEFAULTS = [
        'mode'=>'off', 'dew_margin_c'=>5.0, 'hysteresis_c'=>1.0,
        'off_delay_seconds'=>60, 'summer_c'=>30.0, 'summer_fan_percent'=>75,
        'fan_min_percent'=>30, 'fan_max_percent'=>100,
        'sensor_max_age_seconds'=>120, 'average_samples'=>7,
    ];
    public const LIMITS = [
        'dew_margin_c'=>[1,10], 'hysteresis_c'=>[0.5,5], 'off_delay_seconds'=>[0,300],
        'summer_c'=>[15,45], 'summer_fan_percent'=>[30,100], 'fan_min_percent'=>[10,100],
        'fan_max_percent'=>[10,100],
        'sensor_max_age_seconds'=>[30,180], 'average_samples'=>[1,15],
    ];

    /** Complete typed settings, no truthiness, unknown fields or numeric strings. */
    public static function validate(mixed $values): array
    {
        if (!is_array($values) || count($values) !== count(self::DEFAULTS)
            || array_diff_key($values, self::DEFAULTS)) {
            throw new InvalidArgumentException('Klima: fehlende oder unbekannte Felder');
        }
        if (!in_array($values['mode'], ['off','simulate','auto','manual80'], true)) {
            throw new InvalidArgumentException('Ungueltige Betriebsart');
        }
        foreach (self::LIMITS as $key => [$min, $max]) {
            $v = $values[$key];
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)
                || (is_int(self::DEFAULTS[$key]) && !is_int($v)) || $v < $min || $v > $max) {
                throw new InvalidArgumentException("$key: ungueltiger Wert ausserhalb $min..$max");
            }
        }
        if ($values['fan_min_percent'] > $values['fan_max_percent']
            || $values['summer_fan_percent'] < $values['fan_min_percent']
            || $values['summer_fan_percent'] > $values['fan_max_percent']) {
            throw new InvalidArgumentException('Klima: widerspruechliche Grenzen');
        }
        return $values;
    }

    /** Read compatibility for the initial on-disk schema ONLY. Retired settings
     * are validated then discarded, never applied or accepted by a new save.
     * Reading is write-free; the next explicit save removes them from the file.
     */
    public static function fromStored(array $values): array
    {
        foreach (['max_temperature_c'=>[20,50], 'max_heat_seconds'=>[60,3600]] as $key=>[$min,$max]) {
            if (!array_key_exists($key,$values)) { continue; }
            $v = $values[$key];
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)
                || ($key === 'max_heat_seconds' && !is_int($v)) || $v < $min || $v > $max) {
                throw new InvalidArgumentException('Ungueltiges altes Klima-Feld: '.$key);
            }
            unset($values[$key]);
        }
        return self::validate($values);
    }
}

/** Two seconds at 100% ONLY when starting from zero. No blocking sleep. */
final class ClimateFanStartup
{
    private bool $running = false;
    private float $boostUntil = 0;

    /** Running changes apply immediately; changes during boost do not extend it. */
    public function apply(float $target, float $mono): float
    {
        if (!is_finite($target) || $target < 0 || $target > 100) {
            throw new InvalidArgumentException('Ungueltiger PWM-Sollwert');
        }
        if ($target == 0) {
            $this->running = false;
            $this->boostUntil = 0;
            return 0;
        }
        if (!$this->running) {
            $this->running = true;
            $this->boostUntil = $mono + 2;
        }
        return $mono < $this->boostUntil ? 100 : $target;
    }
}

final class ClimateController
{
    private array $samples = [];
    private ?int $lastStamp = null;
    private ?float $lastNew = null;
    // Normal demand BEFORE the separate two-second startup boost. Neither an
    // outage's 100% fallback nor a capture pause may replace this remembered PWM.
    private float $lastFanTarget = 0;
    private bool $heating = false;
    private ?float $safeSince = null;
    private bool $summer = false;
    private array $measurement = [];

    public function __construct(public array $config, public string $fault = '')
    {
        ClimateConfig::validate($config);
    }

    /** Edits preserve heating state/off delay; only a changed sample window
     * needs a new warmup. Saving a PWM target does not simulate a motor restart.
     */
    public function configure(array $config): void
    {
        ClimateConfig::validate($config);
        if ($config['average_samples'] !== $this->config['average_samples']) { $this->samples = []; }
        $this->config = $config;
    }

    /** Magnus over water, including subzero air; RH=0 has no finite dew point.
     * Uses the original fan.py coefficients but never its unrelated globals.
     * Enclosure AIR dew point is not a measurement of the glass temperature.
     */
    public static function dewPoint(float $temp, float $hum): ?float
    {
        if (!is_finite($temp) || !is_finite($hum) || $temp < -40 || $temp > 80 || $hum < 0 || $hum > 100) {
            throw new InvalidArgumentException('Unplausibles Messpaar');
        }
        if ($hum == 0) { return null; }
        [$a, $b] = $temp >= 0 ? [7.5,237.3] : [7.6,240.7];
        $v = log10($hum / 100) + $a * $temp / ($b + $temp);
        return $b * $v / ($a - $v);
    }

    /** Accept only a fresh complete successful pair; no old history fallback. */
    public static function sample(array $fields, int $wall, int $maxAge): ?array
    {
        $stamp = (string)($fields['updated_epoch'] ?? '');
        $temp = (string)($fields['temperature_c'] ?? '');
        $hum = (string)($fields['humidity_percent'] ?? '');
        if (($fields['sensor_state'] ?? '') !== 'ok' || !preg_match('/^[0-9]{1,12}$/D', $stamp)
            || !preg_match('/^-?[0-9]{1,3}(?:\.[0-9]{1,6})?$/D', $temp)
            || !preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,6})?$/D', $hum)
            || $wall - (int)$stamp < 0 || $wall - (int)$stamp > $maxAge
            || (float)$temp < -40 || (float)$temp > 80 || (float)$hum > 100) {
            return null;
        }
        return [(int)$stamp, (float)$temp, (float)$hum];
    }

    /** Technical faults require fresh valid readings, then a new warmup.
     * Warm summer air is not a fault and must not prevent acknowledgement.
     */
    public function reset(array $fields, int $wall): void
    {
        $s = self::sample($fields, $wall, $this->config['sensor_max_age_seconds']);
        if ($s === null && $this->config['mode'] !== 'manual80') {
            throw new RuntimeException('Quittierung braucht frische gueltige Messwerte');
        }
        $this->fault = '';
        $this->samples = [];
        $this->lastStamp = null;
        $this->lastNew = null;
        $this->lastFanTarget = 0;
        $this->heating = false;
        $this->safeSince = null;
        $this->summer = false;
    }

    /** All exit paths specify safe bounded DEMANDS, not physical feedback. */
    private function output(string $state, string $message, bool $heater = false, float $fan = 0, array $metrics = []): array
    {
        $this->heating = $heater;
        if (!$heater) { $this->safeSince = null; }
        if (in_array($state, ['warming','summer','heating','idle'], true)) {
            $this->lastFanTarget = $fan;
        }
        // Observational values never authorize an output or fill the control
        // buffer. A fault/pause must not hide a fresh, valid measurement.
        if (in_array($state, ['off','fault','capture_pause','warming'], true)) {
            $metrics += $this->measurement;
        }
        return ['state'=>$state,'message'=>$message,'heater'=>$heater,'fan_percent'=>round($fan,1),'fault'=>$this->fault] + $metrics;
    }

    /** Both clocks bound the grace period. Failed status timestamps and repeated
     * successful snapshots never renew it; backwards wall time is not trusted.
     */
    private function lastSampleFresh(int $wall, float $mono): bool
    {
        $age = $this->config['sensor_max_age_seconds'];
        return $this->lastStamp !== null && $this->lastNew !== null
            && $wall >= $this->lastStamp && $wall - $this->lastStamp <= $age
            && $mono >= $this->lastNew && $mono - $this->lastNew <= $age;
    }

    /** A short read failure never permits heating from old measurements. Only
     * the normal fan demand and paired averaging buffer survive until expiry.
     * With no valid baseline (including daemon restart), use the full fallback.
     */
    private function sensorError(int $wall, float $mono, bool $paused): array
    {
        $fresh = $this->lastSampleFresh($wall, $mono);
        if (!$fresh) { $this->samples = []; }
        return $this->output('sensor_error', $fresh
            ? 'Kurzer Sensor-Lesefehler; Heizung aus, letzter Luefter-Sollwert bleibt bis Messwertablauf'
            : 'Keine frische gueltige Messung; Heizung aus, Luefter-Ausfallreaktion',
            fan:$paused ? 0 : ($fresh ? $this->lastFanTarget : 100));
    }

    /** Priority: off, new capture over old pause timeout, technical faults,
     * sample validity, pause, warmup, summer, dew.
     * No thermal trip or heating deadline: dew demand can remain active all day.
     * $paused is a currently valid capture lease, never merely a held lock.
     */
    public function tick(array $fields, int $wall, float $mono, bool $paused = false): array
    {
        $c = $this->config;
        $s = self::sample($fields, $wall, $c['sensor_max_age_seconds']);
        $this->measurement = [];
        if ($s !== null) {
            $dew = self::dewPoint($s[1], $s[2]);
            $this->measurement = ['measurement_source'=>'current', 'measurement_epoch'=>$s[0],
                'dew_point_c'=>$dew === null ? null : round($dew,1),
                'dew_gap_c'=>$dew === null ? null : round($s[1]-$dew,1)];
        }
        if ($c['mode'] === 'off') {
            // A later activation must build its own baseline, not reuse an old
            // running demand or a buffer from before the explicit shutdown.
            $this->samples = [];
            $this->lastStamp = null;
            $this->lastNew = null;
            $this->lastFanTarget = 0;
            $this->summer = false;
            return $this->output('off', 'Regelung ausgeschaltet');
        }
        // An earlier expired capture must not permanently prevent new photos.
        // A new bounded lease can turn both outputs off without a DHT reading
        // or a climate reset. Keep the fault latched for regulation afterwards;
        // the supervisor still requires a successful actuator OFF before ACK.
        if ($paused && $this->fault === 'capture_timeout') {
            return $this->output('capture_pause', 'Aufnahmepause: Heizung und Luefter aus; vorheriger Capture-Timeout bleibt gespeichert');
        }
        if ($this->fault !== '') { return $this->output('fault', 'Sicherheitsabschaltung: '.$this->fault.'; Quittierung erforderlich', fan:100); }
        if ($c['mode'] === 'manual80') {
            if ($paused) {
                return $this->output('capture_pause', 'Aufnahmepause: Heizung und Luefter aus; danach Festbetrieb');
            }
            return $this->output('heating', 'Festbetrieb: Heizung an, Luefter 80%; DHT22 wird nicht zur Regelung verwendet', true, 80);
        }
        if ($s === null || ($this->lastStamp !== null && $s[0] < $this->lastStamp)) {
            return $this->sensorError($wall, $mono, $paused);
        }
        [$stamp,$rawTemp,$rawHum] = $s;
        if ($stamp !== $this->lastStamp) {
            // Also discard an expired buffer when the daemon's first tick after
            // a long scheduling gap already sees a new successful measurement.
            if (!$this->lastSampleFresh($wall, $mono)) { $this->samples = []; }
            $this->samples[] = $s;
            $this->samples = array_slice($this->samples, -$c['average_samples']);
            $this->lastStamp = $stamp;
            // Account for samples already aged when first observed. Receiving a
            // cached status must not buy another full grace period.
            $this->lastNew = $mono - ($wall - $stamp);
        }
        if (!$this->lastSampleFresh($wall, $mono)) {
            return $this->sensorError($wall, $mono, $paused);
        }
        $this->samples = array_values(array_filter($this->samples,
            static fn($row) => $stamp - $row[0] <= $c['sensor_max_age_seconds'] * $c['average_samples']));
        if ($paused) { return $this->output('capture_pause', 'Aufnahmepause: Heizung und Luefter aus'); }
        if (count($this->samples) < $c['average_samples']) {
            return $this->output('warming', 'Warte auf vollstaendigen Puffer frischer Messpaare', fan:$rawTemp >= $c['summer_c'] ? $c['summer_fan_percent'] : 0);
        }
        $temp = array_sum(array_column($this->samples, 1)) / count($this->samples);
        $hum = array_sum(array_column($this->samples, 2)) / count($this->samples);
        $dew = self::dewPoint($temp, $hum);
        $metrics = ['measurement_source'=>'average', 'measurement_epoch'=>$stamp,
            'temperature_c'=>round($temp,1), 'humidity_percent'=>round($hum,1),
            'dew_point_c'=>$dew === null ? null : round($dew,1), 'dew_gap_c'=>$dew === null ? null : round($temp-$dew,1)];
        if ($rawTemp >= $c['summer_c']) { $this->summer = true; }
        elseif ($rawTemp <= $c['summer_c'] - $c['hysteresis_c']) { $this->summer = false; }
        if ($this->summer) { return $this->output('summer', 'Sommerkuehlung ohne Heizung', fan:$c['summer_fan_percent'], metrics:$metrics); }
        $gap = $dew === null ? INF : $temp-$dew;
        $heat = $this->heating;
        if (!$heat && $gap <= $c['dew_margin_c']) {
            $heat = true;
        } elseif ($heat) {
            if ($gap >= $c['dew_margin_c'] + $c['hysteresis_c']) {
                $this->safeSince ??= $mono;
                if ($mono-$this->safeSince >= $c['off_delay_seconds']) { $heat = false; }
            } else { $this->safeSince = null; }
        }
        $fan = $heat ? max($c['fan_min_percent'], min($c['fan_max_percent'], $hum)) : 0;
        return $this->output($heat ? 'heating' : 'idle', $heat ? 'Taupunktschutz aktiv' : 'Ausreichender Taupunktabstand', $heat, $fan, $metrics);
    }
}
