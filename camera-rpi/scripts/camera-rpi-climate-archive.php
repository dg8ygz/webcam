#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Daily regulator history only: PHP/Shell, no GPIO or sensor access.
 * The existing backup wrapper owns the common lock and RO/RW write window.
 * Separate climate-YYYYMMDD.tsv files leave all sensor archives unchanged.
 */
$historyLib=is_file(__DIR__.'/lib/climate-history.php') ? __DIR__.'/lib' : __DIR__;
require_once $historyLib.'/climate-history.php';

final class ClimateHistoryArchive
{
    public function __construct(
        private string $runtime='/run/camera-rpi-status',
        private string $archive='/var/lib/camera-rpi/sensor-backup',
        private string $controllerRuntime='/run/camera-rpi-climate',
        private string|int $group='www-data',
    ) {}

    /** Only fixed regular files/directories; do not follow a final symlink. */
    private static function stat(string $path, int $type): ?array
    {
        clearstatcache(true,$path);
        $stat=@lstat($path);
        if ($stat===false) { return null; }
        if (($stat['mode'] & 0170000)!==$type) { throw new RuntimeException('Kein erlaubter Dateityp: '.$path); }
        return $stat;
    }

    /** Validation and age limits are shared with the graph and recorder. */
    private static function read(string $path, int $now): array
    {
        $stat=self::stat($path,0100000);
        if ($stat===null) { return []; }
        if ($stat['size']>ClimateHistory::MAX_BYTES || !is_readable($path)) {
            throw new RuntimeException('Verlauf nicht lesbar oder zu gross: '.$path);
        }
        return ClimateHistory::read($path,$now);
    }

    /** Exact regular-file namespace only; sensor backups and notes are untouched. */
    private function archives(): array
    {
        if (self::stat($this->archive,0040000)===null) { return []; }
        $paths=[];
        foreach (new DirectoryIterator($this->archive) as $file) {
            if (preg_match('/^climate-[0-9]{8}\.tsv$/D',$file->getFilename())
                && !$file->isLink() && $file->isFile()) { $paths[]=$file->getPathname(); }
        }
        sort($paths,SORT_STRING);
        return $paths;
    }

    /** fsync only persistent archive metadata, never the runtime history. */
    private static function syncDirectory(string $directory): void
    {
        $file=fopen($directory,'r');
        if (!$file) { throw new RuntimeException('Archivverzeichnis nicht lesbar'); }
        try { if (!fsync($file)) { throw new RuntimeException('Verzeichnis-fsync fehlgeschlagen'); } }
        finally { fclose($file); }
    }

    /** Publish complete bounded TSV. SD snapshots use no-replace link + fsync;
     * RAM restore uses atomic rename. A failed write never truncates its target.
     */
    private function write(string $path, array $rows, bool $durable): void
    {
        $data=implode('',array_map(ClimateHistory::encode(...),$rows));
        if (strlen($data)>ClimateHistory::MAX_BYTES) { throw new RuntimeException('Verlauf zu gross'); }
        $temp=tempnam(dirname($path),'.climate-archive-');
        if ($temp===false || dirname($temp)!==dirname($path)) {
            if ($temp!==false) { unlink($temp); }
            throw new RuntimeException('Keine temporaere Archivdatei');
        }
        try {
            $file=fopen($temp,'wb');
            if (!$file) { throw new RuntimeException('Archivdatei nicht schreibbar'); }
            try {
                if (!chmod($temp,$durable ? 0600 : 0640)
                    || (!$durable && !chgrp($temp,$this->group))
                    || fwrite($file,$data)!==strlen($data) || !fflush($file)
                    || ($durable && !fsync($file))) { throw new RuntimeException('Archivschreiben fehlgeschlagen'); }
            } finally { fclose($file); }
            if ($durable) {
                if (!link($temp,$path)) { throw new RuntimeException('Tagesarchiv existiert bereits oder konnte nicht angelegt werden'); }
                unlink($temp);
                self::syncDirectory(dirname($path));
            } elseif (!rename($temp,$path)) { throw new RuntimeException('RAM-Verlauf nicht ersetzbar'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }

    /** One snapshot per Berlin day. Retain 30 successful climate snapshots only;
     * an empty/invalid live history never replaces or prunes an existing backup.
     */
    public function backup(int $now): void
    {
        self::stat($this->runtime,0040000);
        self::stat($this->archive,0040000);
        $rows=self::read($this->runtime.'/climate-history.tsv',$now);
        if (!$rows) { echo "Kein gueltiger Reglerverlauf; vorhandene Sicherungen bleiben erhalten.\n"; return; }
        $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Ymd');
        if (!is_dir($this->archive)) {
            if (!mkdir($this->archive,0700)) { throw new RuntimeException('Archivverzeichnis fehlt'); }
            self::syncDirectory(dirname($this->archive));
        }
        $path=$this->archive.'/climate-'.$day.'.tsv';
        if (self::stat($path,0100000)!==null) {
            if (!self::read($path,$now)) { throw new RuntimeException('Heutige Regler-Sicherung leer/ungueltig; manuell pruefen'); }
            echo "Reglerverlauf heute bereits gesichert; keine erneuten SD-Schreibzugriffe.\n"; return;
        }
        $this->write($path,$rows,true);
        foreach (array_slice($this->archives(),0,-30) as $old) {
            if (!unlink($old)) { throw new RuntimeException('Altes Reglerarchiv nicht entfernbar'); }
        }
        self::syncDirectory($this->archive);
        echo count($rows)." Regler-Verlaufspunkte dauerhaft gesichert: $path\n";
    }

    /** Merge at boot BEFORE the controller. Its own flock also rejects manual
     * restore while running; never overwrite its cached live history in a race.
     * Restored values are graph data only, never current.status/climate.status,
     * control settings, warmup samples, errors, PWM requests or heater outputs.
     */
    public function restore(int $now): void
    {
        self::stat($this->runtime,0040000);
        if (self::stat($this->controllerRuntime,0040000)===null) {
            throw new RuntimeException('Regler-Laufzeitverzeichnis fehlt; tmpfiles pruefen');
        }
        self::stat($this->controllerRuntime.'/daemon.lock',0100000);
        $lock=fopen($this->controllerRuntime.'/daemon.lock','ce');
        if (!$lock) { throw new RuntimeException('Reglersperre nicht lesbar'); }
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) {
                echo "Regler aktiv; Wiederherstellung uebersprungen, laufender RAM-Verlauf unveraendert.\n"; return;
            }
            $rows=[];
            foreach (array_slice($this->archives(),-30) as $path) {
                try { $incoming=self::read($path,$now); }
                catch (RuntimeException $error) { fwrite(STDERR,'WARNUNG: '.$error->getMessage()."\n"); continue; }
                foreach ($incoming as $row) { $rows[$row['epoch']]=$row; }
                ksort($rows,SORT_NUMERIC);$rows=array_slice($rows,-ClimateHistory::MAX_ROWS,null,true);
            }
            // Existing RAM points win over archives with the same timestamp.
            foreach (self::read($this->runtime.'/climate-history.tsv',$now) as $row) { $rows[$row['epoch']]=$row; }
            ksort($rows,SORT_NUMERIC);$rows=array_values(array_slice($rows,-ClimateHistory::MAX_ROWS,null,true));
            if ($rows) {
                if (!is_dir($this->runtime)) {
                    if (!mkdir($this->runtime,0750,true) || !chgrp($this->runtime,$this->group)) {
                        throw new RuntimeException('RAM-Statusverzeichnis fehlt');
                    }
                }
                $this->write($this->runtime.'/climate-history.tsv',$rows,false);
            }
            echo count($rows)." historische Reglerpunkte im RAM; keine aktuellen Stellwerte wiederhergestellt.\n";
        } finally { fclose($lock); }
    }
}

// Importable by isolated tests; CLI only accepts the two fixed-path operations.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {
        if (count($argv)!==2 || !in_array($argv[1],['backup','restore'],true)) {
            throw new InvalidArgumentException('Aufruf: camera-rpi-climate-archive.php backup|restore');
        }
        (new ClimateHistoryArchive())->{$argv[1]}(time());
    } catch (Throwable $error) { fwrite(STDERR,'FEHLER: '.$error->getMessage()."\n"); exit(1); }
}
