<?php
declare(strict_types=1);
/** Keep an already running 24h DHT trial's fallback deadline across reloads.
 * Only a volatile timer drop-in changes. Never start/stop the trial, sensor,
 * regulator or restore service, and never touch a module or sensor attribute.
 */
final class DhtRestoreTimer
{
    public const DELAY_US = 86460_000000;

    /** Original timer activation plus 24h/60s; refuse an imminent/past restore. */
    public static function deadline(int $activation, int $trialStart, int $now): int
    {
        if ($activation <= 0 || $trialStart <= 0 || $activation > $now
            || abs($activation - $trialStart) > 5_000000) {
            throw new RuntimeException('Timer und laufender Langzeittest passen nicht zusammen.');
        }
        $deadline = $activation + self::DELAY_US;
        if ($deadline - $now < 180_000000) {
            throw new RuntimeException('Rueckstellung steht unmittelbar bevor/ist ueberfaellig; keine Aenderung.');
        }
        return $deadline;
    }

    /** Reset all prior timer expressions, then anchor to this boot, not reload. */
    public static function dropin(int $deadline): string
    {
        if ($deadline <= 0) throw new RuntimeException('Ungueltiger Termin.');
        $seconds = sprintf('%d.%06d', intdiv($deadline, 1_000000), $deadline % 1_000000);
        return "# Temporary DHT fallback: original trial deadline; survives daemon-reload.\n"
            ."[Timer]\nOnActiveSec=\nOnBootSec={$seconds}s\nAccuracySec=1s\nRandomizedDelaySec=0\n";
    }

    /** Shell-free bounded output; callers supply only fixed commands/validated IDs. */
    private static function command(array $argv): string
    {
        $pipes = [];
        $process = proc_open(array_merge(['/usr/bin/timeout', '20'], $argv),
            [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Kommando konnte nicht starten.');
        $text = stream_get_contents($pipes[1], 65536);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || !is_string($text)) {
            throw new RuntimeException('Kommando fehlgeschlagen: '.implode(' ', $argv));
        }
        return trim($text);
    }

    private static function property(string $unit, string $property): string
    {
        return self::command(['/usr/bin/systemctl','show',$unit,'--value','--property='.$property]);
    }

    private static function pids(): string
    {
        return self::command(['/usr/bin/systemctl','show','camera-rpi-dht-longtest.service',
            'camera-rpi-sensor.service','camera-rpi-climate.service','--property=Id,MainPID,ActiveState,NRestarts']);
    }

    /** Read the exact D-Bus microseconds instead of systemctl's formatted duration. */
    public static function timerValue(string $text): array
    {
        if (!preg_match('/^a\(stt\) 1 "(OnActiveUSec|OnBootUSec)" ([0-9]+) ([0-9]+)$/D', $text, $m)) {
            throw new RuntimeException('Unerwartete Timer-Liste; keine Aenderung.');
        }
        return [$m[1], (int)$m[2], (int)$m[3]];
    }

    private static function values(string $timer): array
    {
        $object = '/org/freedesktop/systemd1/unit/'.str_replace(['-','.'],['_2d','_2e'],$timer);
        return self::timerValue(self::command(['/usr/bin/busctl','get-property',
            'org.freedesktop.systemd1',$object,'org.freedesktop.systemd1.Timer','TimersMonotonic']));
    }

    public static function assertFixed(array $values, int $deadline): void
    {
        if ($values !== ['OnBootUSec', $deadline, $deadline]) {
            throw new RuntimeException('Geladener Rueckstell-Termin entspricht nicht dem festen Termin.');
        }
    }

    /** Atomic RAM-only publication; never follows an existing target symlink. */
    private static function publish(string $path, string $text): void
    {
        $tmp = tempnam(dirname($path), '.dht-deadline-');
        if ($tmp === false) throw new RuntimeException('Keine temporaere Drop-in-Datei.');
        try {
            if (file_put_contents($tmp, $text) !== strlen($text) || !chmod($tmp,0644)
                || !rename($tmp,$path)) throw new RuntimeException('Drop-in konnte nicht gespeichert werden.');
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }

    public static function run(string $mode, string $id): void
    {
        if (!in_array($mode,['--check','--apply','--verify'],true)
            || !preg_match('/^[a-zA-Z0-9]{8}$/D',$id)) throw new RuntimeException('Ungueltiger Aufruf.');
        if ($mode === '--apply' && posix_geteuid() !== 0) throw new RuntimeException('Bitte als root ausfuehren.');
        $timer = 'camera-rpi-dht-driver-restore-'.$id.'.timer';
        $service = substr($timer,0,-6).'.service';
        $run = '/run/camera-rpi-dht-driver.'.$id;
        $lock = null;
        if ($mode === '--apply') {
            $lock = fopen('/run/lock/camera-rpi-dht-timer-update.lock','ce');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Timer-Update laeuft bereits.');
        }
        if (self::property('camera-rpi-dht-longtest.service','ActiveState') !== 'active'
            || self::property($timer,'ActiveState') !== 'active'
            || self::property($service,'ActiveState') !== 'inactive'
            || self::property($timer,'FragmentPath') !== '/run/systemd/transient/'.$timer
            || !is_dir($run) || is_link($run) || fileowner($run) !== 0 || file_exists($run.'/RESTORED')
            || !str_contains(self::property($service,'ExecStart'),
                $run.'/run-diagnostic.sh --restore '.$run.' ;')) {
            throw new RuntimeException('Aktiver Test/Rueckstell-Dienst entspricht nicht dem erwarteten Stand.');
        }
        $deadline = self::deadline((int)self::property($timer,'ActiveEnterTimestampMonotonic'),
            (int)self::property('camera-rpi-dht-longtest.service','ActiveEnterTimestampMonotonic'), intdiv(hrtime(true),1000));
        $values = self::values($timer);
        if ($values[0] === 'OnActiveUSec' && $values[1] !== self::DELAY_US) {
            throw new RuntimeException('Unerwartete Testdauer.');
        }
        if ($values[0] === 'OnBootUSec') self::assertFixed($values,$deadline);
        $content = self::dropin($deadline);
        if ($mode === '--verify') {
            self::assertFixed($values,$deadline);
        } elseif ($mode === '--apply') {
            $dir = '/run/systemd/system/'.$timer.'.d';
            $path = $dir.'/90-camera-rpi-fixed-deadline.conf';
            foreach (['/run/systemd/system',$dir] as $directory) {
                if (is_link($directory) || (file_exists($directory)
                    && (!is_dir($directory) || fileowner($directory)!==0))) {
                    throw new RuntimeException('Unerwarteter Drop-in-Verzeichnistyp/Eigentuemer.');
                }
            }
            if (is_link($path) || (file_exists($path) && (!is_file($path)
                || fileowner($path)!==0 || file_get_contents($path)!==$content))) {
                throw new RuntimeException('Vorhandenes fremdes Drop-in wird nicht ueberschrieben.');
            }
            if (!is_dir($dir) && !mkdir($dir,0755)) throw new RuntimeException('Drop-in-Verzeichnis fehlt.');
            $before = self::pids();
            $old = is_file($path) ? file_get_contents($path) : null;
            $backup = self::command(['/usr/bin/mktemp','-d','/run/camera-rpi-dht-timer-before.XXXXXXXX']);
            $saved = self::command(['/usr/bin/systemctl','cat',$timer]);
            if (file_put_contents($backup.'/timer-before.txt',$saved)!==strlen($saved)
                || file_put_contents($backup.'/deadline-us.txt',(string)$deadline)!==strlen((string)$deadline)) {
                throw new RuntimeException('Vorher-Sicherung fehlgeschlagen; kein Timer veraendert.');
            }
            echo "RAM-Sicherung: $backup\n";
            try {
                self::publish($path,$content);
                self::command(['/usr/bin/systemctl','daemon-reload']);
                self::assertFixed(self::values($timer),$deadline);
                if (self::pids() !== $before || self::property($service,'ActiveState')!=='inactive') {
                    throw new RuntimeException('Dienststatus hat sich waehrend der Pruefung geaendert.');
                }
            } catch (Throwable $error) {
                // Restore only our exact drop-in; never stop the fallback service.
                if ($old === null) { if (is_file($path)) unlink($path); }
                else self::publish($path,$old);
                self::command(['/usr/bin/systemctl','daemon-reload']);
                throw $error;
            }
        }
        $remaining = ($deadline-intdiv(hrtime(true),1000))/1_000000;
        $wall = (new DateTimeImmutable('@'.(string)(int)round(microtime(true)+$remaining)))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s T');
        echo ($mode==='--check'?'Geplanter':'Gepruefter')." fester Rueckstell-Termin: $wall\n";
        echo "Keine Dienste neu gestartet, kein Treiberwechsel.\n";
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    try {
        if ($argc !== 3) throw new RuntimeException('Aufruf: php fix-dht-restore-timer.php --check|--apply|--verify TEST-ID');
        DhtRestoreTimer::run($argv[1],$argv[2]);
    } catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
}
