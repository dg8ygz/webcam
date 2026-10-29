<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use RuntimeException;

/** One expiring, rate-limited request per camera; no global trigger. */
final class ManualCaptureQueue
{
  public function __construct(private readonly string $directory) {}

  /** Merkt nur diese Kamera vor; Pending oder weniger als 30s Abstand ergeben 409. */
  public function request(string $slug, ?int $now = null): void
  {
    $now ??= time();
    $this->locked($slug, function (array &$state) use ($now): void {
      if ($this->isPending($state, $now) || $now - ($state['requested_at'] ?? 0) < 30) {
        throw new RuntimeException('Aufnahme bereits vorgemerkt oder 30-Sekunden-Pause aktiv', 409);
      }
      $state = ['requested_at' => $now, 'pending' => true];
    });
  }

  /** Prueft den Ablauf unter Kameralock, ohne den Auftrag zu verbrauchen. */
  public function pending(string $slug, ?int $now = null): bool
  {
    return $this->locked($slug, fn(array &$state): bool => $this->isPending($state, $now ?? time()));
  }

  /** Callback returns true once dispatched/attempted, false to retain. */
  public function consume(string $slug, callable $dispatch, ?int $now = null): bool
  {
    $now ??= time();
    return $this->locked($slug, function (array &$state) use ($dispatch, $now): bool {
      if (!$this->isPending($state, $now) || !$dispatch($now)) return false;
      $state['pending'] = false;
      return true;
    });
  }

  /** Zukunftsmarker und mindestens fuenf Minuten alte Auftraege nicht ausfuehren. */
  private function isPending(array $state, int $now): bool
  {
    $age = $now - ($state['requested_at'] ?? 0);
    return ($state['pending'] ?? false) === true && $age >= 0 && $age < 300;
  }

  /**
   * Haelt Lesen, Callback und Schreiben unter einem stabilen Kameralock.
   * Auch der Dispatch-Callback bleibt darin, damit zwei Polls nicht doppelt starten.
   */
  private function locked(string $slug, callable $operation): mixed
  {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 128) {
      throw new RuntimeException('Ungueltige Kamera', 400);
    }
    if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
      throw new RuntimeException('Aufnahme-Verzeichnis nicht verfuegbar');
    }
    $handle = @fopen($this->directory . '/' . $slug . '.json', 'c+');
    if ($handle === false) throw new RuntimeException('Aufnahmestatus nicht verfuegbar');
    try {
      if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('Aufnahme wird gerade bearbeitet', 409);
      $raw = stream_get_contents($handle, 1025);
      $state = $raw === '' ? [] : json_decode((string) $raw, true);
      if (!is_array($state) || ($state !== [] && (!is_int($state['requested_at'] ?? null) || !is_bool($state['pending'] ?? null)))) {
        throw new RuntimeException('Aufnahmestatus ist ungueltig');
      }
      $before = $state;
      $result = $operation($state);
      if ($state !== $before) {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
          throw new RuntimeException('Aufnahmestatus nicht speicherbar');
        }
      }
      return $result;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }
}
