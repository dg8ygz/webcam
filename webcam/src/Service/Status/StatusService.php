<?php
declare(strict_types=1);

namespace Webcam\Service\Status;

use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Liest und aktualisiert den kurzfristigen Betriebsstatus einer Kamera.
 *
 * Der Status unter var/state beschreibt zum Beispiel den letzten Erfolg, Fehler
 * und aktuelle Wetter-/EXIF-Hinweise. Dauerhafte Capture-Metadaten bleiben in
 * der Kamera-SQLite; diese Statusdatei ist eine operative Momentaufnahme.
 */
final class StatusService
{
  private readonly CameraStatusStore $store;
  private readonly StatusWeatherReader $weather;

  public function __construct(App $app)
  {
    $this->store = new CameraStatusStore($app);
    $this->weather = new StatusWeatherReader($app);
  }

  // Liest die persistierte Momentaufnahme und ergänzt anschließend nur die
  // Wetterdaten, die nicht selbst Bestandteil der State-Datei sind.
  public function state(Camera $camera): array
  {
    $state = $this->store->read($camera);
    if ($state === null) {
      return $this->store->defaultState($camera);
    }
    if (empty($state['last_weather_checked_at'])
      && ($state['last_weather_data'] === '' || $state['last_weather_data'] === null)
      && empty($state['last_weather_path'])) {
      $fallback = $this->weather->currentWeather($camera);
      if ($fallback !== null) {
        $state['last_weather_path'] = $fallback['path'];
        $state['last_weather_at'] = $fallback['timestamp'];
        $state['last_weather_data'] = $this->weather->truncate($fallback['data']);
      }
    }

    $state['weather_quality'] = $this->weather->qualitySummary($camera);
    if (($state['weather_quality']['status'] ?? 'ok') === 'error') {
      $state['last_weather_data'] = '';
      $state['last_weather_path'] = null;
    }
    $redacted = SecretRedactor::redactValue($state);
    return is_array($redacted) ? $redacted : $this->store->defaultState($camera);
  }

  // Einen erfolgreich gespeicherten Bildzugang im Betriebsstatus vermerken.
  // Die Sperre verhindert gleichzeitiges Aendern derselben Statusdatei.
  // Eine Nachlieferung eines aelteren Slots darf den neuesten Status nicht
  // zuruecksetzen. Aufnahmezeit und heutige Erfolgs-/Ankunftszeit getrennt halten:
  // Ein gerade empfangenes Foto kann schon deutlich aelter sein.
  public function recordSuccess(
    Camera $camera,
    string $note = '',
    ?string $imagePath = null,
    ?array $exif = null,
    ?string $exifPath = null,
    ?string $exifRelative = null,
    ?float $luminance = null,
    ?string $weatherPath = null,
    ?string $weatherData = null,
    ?int $capturedAt = null,
    ?int $slot = null
  ): void
  {
    $note = SecretRedactor::redact($note);
    $imagePath = $this->store->normalizePath($imagePath);
    $exifPath = $this->store->normalizePath($exifPath);
    $exifRelative = $this->store->normalizePath($exifRelative);
    $weatherPath = $this->store->normalizePath($weatherPath);

    $this->store->withLock($camera, function () use (
      $camera,
      $note,
      $imagePath,
      $exif,
      $exifPath,
      $exifRelative,
      $luminance,
      $weatherPath,
      $weatherData,
      $capturedAt,
      $slot
    ): void {
      $state = $this->state($camera);
      $now = time();
      if ($slot !== null && $slot < (int) ($state['last_image_slot'] ?? 0)) { return; }
      if ($slot !== null) { $state['last_image_slot'] = $slot; }
      $state['last_capture_at'] = $capturedAt ?? $now;
      $state['last_capture_note'] = $note;
      $state['last_capture_path'] = $imagePath;
      $state['last_success_at'] = $now;
      $state['last_success_note'] = $note;
      $state['duplicate_active'] = false;
      $state['consecutive_failures'] = 0;
      $state['total_captures'] = ($state['total_captures'] ?? 0) + 1;
      $state['last_exif_path'] = $exifPath;
      $state['last_exif_relative'] = $exifRelative;
      $state['last_luminance'] = $luminance;
      // Ein explizit leeres Ergebnis bedeutet: Wetter geprueft, aber ungueltig.
      // Alte Current-Sidecars duerfen diese Entscheidung beim Lesen nicht aufheben.
      if ($weatherData !== null || $weatherPath !== null) {
        $state['last_weather_checked_at'] = $now;
      }
      if ($weatherPath !== null) {
        $state['last_weather_path'] = $weatherPath;
        $state['last_weather_at'] = $now;
      } elseif ($weatherData === '') {
        $state['last_weather_path'] = null;
        $state['last_weather_at'] = null;
      }
      if ($weatherData !== null) {
        $state['last_weather_data'] = $this->weather->truncate($weatherData);
      }
      if ($exif !== null) {
        $filtered = [];
        foreach ($exif as $key => $value) {
          if (!is_string($key)) {
            continue;
          }
          if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
          } elseif (!is_scalar($value)) {
            continue;
          }
          $filtered[$key] = (string) $value;
        }
        $state['last_exif'] = $filtered;
      }
      if (!empty($state['last_reset_at'])) {
        $state['successes_since_reset'] = ($state['successes_since_reset'] ?? 0) + 1;
      }
      $this->store->appendEvent($camera, $state, 'success', $note, $imagePath);
      $this->store->write($camera, $state);
    });
  }

  /**
   * Vermerkt ein byte-identisches, verworfenes Bild als roten Betriebsfehler.
   * Der letzte echte Erfolg und dessen Bildpfad bleiben dabei unverändert.
   */
  public function recordDuplicate(
    Camera $camera,
    string $note,
    ?string $weatherPath = null,
    ?string $weatherData = null
  ): void {
    $note = SecretRedactor::redact($note);
    $weatherPath = $this->store->normalizePath($weatherPath);
    $this->store->withLock($camera, function () use ($camera, $note, $weatherPath, $weatherData): void {
      $state = $this->state($camera);
      $now = time();
      $state['last_capture_at'] = $now;
      $state['last_capture_note'] = $note;
      $state['last_failure_at'] = $now;
      $state['last_failure_note'] = $note;
      $state['last_duplicate_at'] = $now;
      $state['last_duplicate_note'] = $note;
      $state['duplicate_active'] = true;
      $state['consecutive_failures'] = ($state['consecutive_failures'] ?? 0) + 1;
      $state['total_failures'] = ($state['total_failures'] ?? 0) + 1;
      if ($weatherData !== null || $weatherPath !== null) {
        $state['last_weather_checked_at'] = $now;
      }
      if ($weatherPath !== null) {
        $state['last_weather_path'] = $weatherPath;
        $state['last_weather_at'] = $now;
      } elseif ($weatherData === '') {
        $state['last_weather_path'] = null;
        $state['last_weather_at'] = null;
      }
      if ($weatherData !== null) {
        $state['last_weather_data'] = $this->weather->truncate($weatherData);
      }
      $this->store->appendEvent($camera, $state, 'failure', $note);
      $this->store->write($camera, $state);
    });
  }

  // Erhöht die zusammenhängende Fehlerzahl und merkt Zeit/Grund für Watchdog und UI.
  public function recordFailure(Camera $camera, string $note = ''): void
  {
    $note = SecretRedactor::redact($note);
    $this->store->withLock($camera, function () use ($camera, $note): void {
      $state = $this->state($camera);
      $now = time();
      $state['last_capture_at'] = $now;
      $state['last_failure_at'] = $now;
      $state['last_failure_note'] = $note;
      $state['consecutive_failures'] = ($state['consecutive_failures'] ?? 0) + 1;
      $state['total_failures'] = ($state['total_failures'] ?? 0) + 1;
      $this->store->appendEvent($camera, $state, 'failure', $note);
      $this->store->write($camera, $state);
    });
  }

  // Protokolliert einen tatsächlich ausgeführten Reset samt Zähler und Ursache.
  public function recordReset(Camera $camera, string $reason): void
  {
    $reason = SecretRedactor::redact($reason);
    $this->store->withLock($camera, function () use ($camera, $reason): void {
      $state = $this->state($camera);
      $now = time();
      $state['last_reset_at'] = $now;
      $state['last_reset_reason'] = $reason;
      $state['resets_total'] = ($state['resets_total'] ?? 0) + 1;
      $state['consecutive_failures'] = 0;
      $state['successes_since_reset'] = 0;
      $this->store->appendEvent($camera, $state, 'reset', $reason);
      $this->store->write($camera, $state);
    });
  }

  // Prüft Fehlerschwelle und Mindestabstand zum letzten Reset, ohne selbst einen
  // externen Reset-Befehl auszuführen.
  public function shouldTriggerReset(Camera $camera): bool
  {
    $watchdog = $camera->status()['watchdog'] ?? null;
    if (!is_array($watchdog) || empty($watchdog['enabled'])) {
      return false;
    }
    $state = $this->state($camera);
    $failures = (int)($state['consecutive_failures'] ?? 0);
    $threshold = (int)($watchdog['max_consecutive_failures'] ?? 3);
    if ($failures < $threshold) {
      return false;
    }

    $lastResetAt = $state['last_reset_at'] ?? null;
    if ($lastResetAt) {
      $cooldownSeconds = (int)($watchdog['cooldown']['min_seconds_after_reset'] ?? 0);
      if ($cooldownSeconds > 0 && (time() - (int)$lastResetAt) < $cooldownSeconds) {
        return false;
      }

      $successesRequired = (int)($watchdog['cooldown']['min_successes_after_reset'] ?? 0);
      $successesSinceReset = (int)($state['successes_since_reset'] ?? 0);
      if ($successesRequired > 0 && $successesSinceReset < $successesRequired) {
        return false;
      }
    }
    return true;
  }

}
