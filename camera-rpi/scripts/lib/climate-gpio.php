<?php
declare(strict_types=1);

/** The only PHP/GPIO boundary: a fixed local native actuator, never a shell.
 * It owns the boot-selected pins, generates PWM and shuts down after 3s silence.
 * The PHP daemon waits for applied-command ACKs; no physical feedback is implied.
 */
final class ClimateOutputs
{
    private mixed $process;
    private array $pipes = [];

    public function __construct(string $binary, bool $test = false, int $heaterPin = 5, int $fanPin = 27)
    {
        if (!is_executable($binary)) { throw new RuntimeException('Nativer PWM-Helfer fehlt'); }
        if ($heaterPin<2 || $heaterPin>27 || $fanPin<2 || $fanPin>27 || $heaterPin===$fanPin) {
            throw new InvalidArgumentException('GPIO für Heizung/Lüfter ungültig oder bereits in Benutzung');
        }
        $this->process = proc_open([$binary, $test ? '--test' : '--run',(string)$heaterPin,(string)$fanPin],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>STDERR], $this->pipes, null, ['PATH'=>'/usr/bin:/bin']);
        if (!is_resource($this->process)) { throw new RuntimeException('PWM-Helfer nicht gestartet'); }
        stream_set_timeout($this->pipes[1], 1);
        try { $this->apply(false,0); }
        catch (Throwable $e) { $this->close(); throw $e; }
    }

    /** Heartbeat on every control cycle, including unchanged output demands. */
    public function apply(bool $heater, float $fan): void
    {
        if (!is_finite($fan) || $fan<0 || $fan>100 || ($heater && $fan==0)) {
            throw new InvalidArgumentException('Ungueltiger GPIO-Sollwert');
        }
        $h = (int)$heater;
        $duty = (int)round($fan*10);
        $command = "SET $h $duty\n";
        if (@fwrite($this->pipes[0], $command) !== strlen($command) || !fflush($this->pipes[0])
            || fgets($this->pipes[1], 64) !== "OK $h $duty\n") {
            throw new RuntimeException('PWM-Helfer bestaetigt keine Ansteuerung');
        }
    }

    /** Close input first; EOF or TERM asks for safe off. Native watchdog is backup. */
    public function close(): void
    {
        if (!is_resource($this->process)) { return; }
        foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_terminate($this->process, SIGTERM);
        $until = microtime(true)+1;
        while (proc_get_status($this->process)['running'] && microtime(true)<$until) { usleep(10000); }
        if (proc_get_status($this->process)['running']) { proc_terminate($this->process, SIGKILL); }
        proc_close($this->process);
        $this->process = null;
    }
}
