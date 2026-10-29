<?php
declare(strict_types=1);

/** Device-wide header allocation; 40-pin default for legacy storage validation.
 * BCM numbering is authoritative. Physical pin numbers are display metadata.
 * Power/ground and HAT identification GPIO0/1 are deliberately not selectable.
 * This schema reserves inputs AND outputs together, regardless of direction.
 */
final class CameraGpioConfig
{
    public const HEADER = [
        2=>3, 3=>5, 4=>7, 5=>29, 6=>31, 7=>26, 8=>24, 9=>21,
        10=>19, 11=>23, 12=>32, 13=>33, 14=>8, 15=>10, 16=>36,
        17=>11, 18=>12, 19=>35, 20=>38, 21=>40, 22=>15, 23=>16,
        24=>18, 25=>22, 26=>37, 27=>13,
    ];
    public const LABELS = [
        'dht'=>'DHT22', 'heater'=>'Heizung', 'fan'=>'Lüfter-PWM',
        'camera_power'=>'Kamerastrom', 'focus'=>'Fokusfreigabe (HIGH = Fokus)',
    ];
    // Historical climate/DHT wiring; camera supply is optional, never guessed.
    public const DEFAULTS = ['dht'=>4, 'heater'=>5, 'fan'=>27, 'camera_power'=>null, 'focus'=>null];

    /** Require a complete typed map, never coerce strings/booleans into pins.
     * The backend repeats exclusivity checks even with JavaScript disabled.
     */
    public static function validate(mixed $values, ?array $header=null): array
    {
        // Existing four-role profiles/snapshots keep the focus output disabled.
        if (is_array($values) && !array_key_exists('focus',$values)) { $values['focus']=null; }
        if (!is_array($values) || count($values)!==count(self::DEFAULTS)
            || array_diff_key($values,self::DEFAULTS)) {
            throw new InvalidArgumentException('GPIO: fehlende oder unbekannte Felder.');
        }
        $header ??= self::HEADER;
        $used = [];
        foreach (self::LABELS as $role=>$label) {
            $pin = $values[$role];
            if ($pin===null && in_array($role,['camera_power','focus'],true)) { continue; }
            if (!is_int($pin) || !array_key_exists($pin,$header)) {
                throw new InvalidArgumentException($label.': GPIO ist auf diesem Header nicht verfügbar.');
            }
            if (isset($used[$pin])) {
                throw new InvalidArgumentException('GPIO '.$pin.' ist bereits in Benutzung: '
                    .$used[$pin].' und '.$label.'.');
            }
            $used[$pin]=$label;
        }
        return $values;
    }

    /** Canonical labels are shared by the web form and command-line reporting. */
    public static function pinLabel(int $pin): string
    {
        if (!isset(self::HEADER[$pin])) { throw new InvalidArgumentException('Ungültiger GPIO.'); }
        return 'GPIO '.$pin.' · Pin '.self::HEADER[$pin];
    }

    /** Runtime consumers use the boot snapshot, NEVER a pending admin edit.
     * Legacy installations without the optional profile retain their old pins.
     * An enrolled installation without a boot snapshot fails closed.
     */
    public static function effective(): array
    {
        $pins=self::snapshot();
        $desired=getenv('CAMERA_RPI_GPIO_CONFIG') ?: '/etc/camera-rpi/gpio.json';
        // Only enrolled devices adopt board restrictions. Unenrolled legacy
        // installations retain their existing fixed-pin behaviour.
        if (file_exists($desired)) { return self::validate($pins,CameraGpioBoard::allowedHeader()); }
        return $pins;
    }

    /** Read saved active pins for display even when moved to another board.
     * Hardware consumers must use effective(), which checks the current board.
     */
    public static function snapshot(): array
    {
        $active=getenv('CAMERA_RPI_GPIO_ACTIVE') ?: '/run/camera-rpi-gpio/active.json';
        $desired=getenv('CAMERA_RPI_GPIO_CONFIG') ?: '/etc/camera-rpi/gpio.json';
        $raw=ClimateApp::read($active);
        if ($raw!==null) { return self::validate(ClimateApp::json($raw)); }
        if (file_exists($desired)) { throw new RuntimeException('GPIO-Bootkonfiguration fehlt; Neustart/Setup prüfen.'); }
        return self::DEFAULTS;
    }
}

/** Device-tree identity, never hostname or cpuinfo's misleading Hardware field.
 * Exact board-compatible names select documented connector layouts. No GPIO
 * requests, sensor reads, shell commands or assumptions about CM carrier boards.
 * Sources and limitations: GPIO.md, section Modell- und Header-Erkennung.
 */
final class CameraGpioBoard
{
    private const FULL = [
        'model-a-plus','model-b-plus','2-model-b','3-model-b','3-model-b-plus',
        '3-model-a-plus','4-model-b','400','model-zero','model-zero-w','model-zero-2-w',
    ];
    public static function identify(string $model,string $compatible,string $revision=''): array
    {
        $names=array_values(array_filter(explode("\0",trim($compatible,"\0\r\n")),
            static fn($name)=>str_starts_with($name,'raspberrypi,')));
        $result=['model'=>trim($model,"\0\r\n ") ?: 'Unbekanntes Modell',
            'revision'=>$revision,'compatible'=>$names[0] ?? '', 'header_pins'=>null,
            'header'=>[], 'supported'=>false,'reason'=>'Modell nicht eindeutig erkannt; GPIO-Konfiguration gesperrt.'];
        if (count($names)!==1) { return $result; }
        $board=substr($names[0],12);
        if (in_array($board,self::FULL,true)) {
            $result['header_pins']=40; $result['header']=CameraGpioConfig::HEADER;
        } elseif (in_array($board,['model-a','model-b','model-b-rev2'],true)) {
            $result['header_pins']=26;
            $result['header']=array_filter(CameraGpioConfig::HEADER,static fn($pin)=>$pin<=26);
            if ($board==='model-b') {
                // Original Rev1 P1: GPIO21 at pin13, GPIO0/1 at pins3/5.
                // GPIO0/1 remain excluded by our reserved-pin policy, not GPIO2/3.
                unset($result['header'][2],$result['header'][3],$result['header'][27]);
                $result['header'][21]=13;
                ksort($result['header']);
            }
        } elseif (in_array($board,['5-model-b','500'],true)) {
            $result['header_pins']=40; $result['header']=CameraGpioConfig::HEADER;
            $result['reason']='Modell erkannt; der RP1-GPIO-Controller wird vom aktuellen PWM-Helfer nicht unterstützt.';
            return $result;
        } elseif (in_array($board,['compute-module','3-compute-module','3-plus-compute-module',
            '4-compute-module','4s-compute-module','5-compute-module','5-compute-module-lite','compute-module-zero'],true)) {
            $result['reason']='Compute Module erkannt: Header hängt von der Trägerplatine ab; keine automatische GPIO-Freigabe.';
            return $result;
        } else { return $result; }
        $result['supported']=true;
        $result['reason']='Header erkannt; Kernel-Treiber und externe Verdrahtung separat prüfen.';
        return $result;
    }

    /** Root-only helper environment can point tests at a private fixture tree.
     * Web forms never supply paths or an alternative identity. Missing device
     * tree fails closed instead of falling back to a guessed 40-pin header.
     */
    public static function detect(): array
    {
        $root=getenv('CAMERA_RPI_GPIO_DT_ROOT') ?: '/sys/firmware/devicetree/base';
        $model=@file_get_contents($root.'/model',false,null,0,1024);
        $compatible=@file_get_contents($root.'/compatible',false,null,0,2048);
        $revision=@file_get_contents($root.'/system/linux,revision',false,null,0,5);
        return self::identify(is_string($model)?$model:'',is_string($compatible)?$compatible:'',
            is_string($revision)&&strlen($revision)===4 ? bin2hex($revision) : '');
    }
    public static function allowedHeader(?array $board=null): array
    {
        $board ??= self::detect();
        if (!$board['supported']) { throw new RuntimeException($board['reason']); }
        return $board['header'];
    }
}
