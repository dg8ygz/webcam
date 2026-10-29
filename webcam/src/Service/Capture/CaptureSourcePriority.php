<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Domain\Enums\CameraMode;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Security\SecretRedactor;

/**
 * Entscheidet, ob Pull-Snapshot oder ein externer Upload ein Bild liefern darf.
 *
 * Eine Pull-Kamera hat grundsätzlich Vorrang. Erst nach der im Admin
 * eingestellten Zahl aufeinanderfolgender Pull-Fehler werden externe Uploads
 * als Rückfallquelle akzeptiert. Der Zustand liegt bewusst im Runtime-State
 * und nicht in der Kamera-DB: Er beschreibt die aktuelle Erreichbarkeit der
 * Quelle und keine Bildmetadaten.
 *
 * Pull und Upload verwenden denselben Kameralock. Dadurch kann ein Upload nicht
 * genau während eines wieder erfolgreichen Pull-Versuchs dazwischenlaufen.
 */
final class CaptureSourcePriority
{
  private const LOCK_WAIT_MICROSECONDS = 100000;
  private const LOCK_TIMEOUT_SECONDS = 5;
  private const COMMAND_DECISION_LOCK_TIMEOUT_SECONDS = 0.25;

  public function __construct(private readonly App $app) {}

  /**
   * Führt einen Pull-Versuch aus und aktualisiert anschließend den Fehlerzähler.
   *
   * @param callable():CaptureResult $capture
   */
  public function runPull(Camera $camera, callable $capture): CaptureResult
  {
    $lock = $this->acquireLock($camera);
    if ($lock === null) {
      throw new RuntimeException('Capture source priority lock unavailable');
    }

    try {
      $state = $this->readState($camera);
      try {
        $result = $capture();
      } catch (Throwable $exception) {
        $this->recordPullFailure($camera, $state, $exception->getMessage());
        if ($exception instanceof RuntimeException) {
          throw $exception;
        }
        throw new RuntimeException($exception->getMessage(), 0, $exception);
      }

      // Eine erwartete Publikationsverzoegerung ist weder Pull-Fehler noch
      // Genesung: vorhandenen Fehler-/Fallback-Stand unveraendert lassen.
      if ($result->pending) {
        return $result;
      }
      if ($result->successful) {
        $this->recordPullSuccess($camera, $state);
      } else {
        $this->recordPullFailure($camera, $state, $result->message);
      }
      return $result;
    } finally {
      $this->releaseLock($lock);
    }
  }

  /**
   * Prüft externe Uploads vor Größenprüfung, JPEG-Dekodierung und Speicherung.
   *
   * Ein ignorierter Upload erhält absichtlich HTTP 200: Der sendende Helper darf
   * seine lokale Spool-Datei entfernen, obwohl das Bild wegen des gesunden
   * Pull-Pfads nicht in den Bildbestand übernommen wurde.
   *
   * @param callable():array{status:int,payload:array<string,mixed>} $ingest
   * @return array{status:int,payload:array<string,mixed>}
   */
  public function runExternalUpload(Camera $camera, callable $ingest): array
  {
    if ($camera->mode() !== CameraMode::PullSnapshot) {
      $this->clearState($camera);
      return $ingest();
    }

    $lock = $this->acquireLock($camera);
    if ($lock === null) {
      return [
        'status' => 503,
        'payload' => ['ok' => false, 'error' => 'capture source lock unavailable'],
      ];
    }

    try {
      $state = $this->readState($camera);
      $failures = (int) $state['consecutive_pull_failures'];
      $threshold = $this->fallbackThreshold($camera);

      // Der Schwellenwert 0 deaktiviert den Fallback vollständig. Sonst muss
      // die Schwelle erreicht sein, bevor der externe Bildweg wieder frei ist.
      if ($threshold === 0 || $failures < $threshold) {
        return [
          'status' => 200,
          'payload' => [
            'ok' => true,
            'accepted' => false,
            'ignored' => true,
            'reason' => 'pull_snapshot_primary',
            'pull_failures' => $failures,
            'fallback_after_failures' => $threshold,
          ],
        ];
      }

      $result = $ingest();
      if (($result['payload']['ok'] ?? false) === true) {
        $result['payload']['fallback'] = true;
        $result['payload']['pull_failures'] = $failures;
      }
      return $result;
    } finally {
      $this->releaseLock($lock);
    }
  }

  /**
   * Entscheidet, ob command.php den externen Helper überhaupt auslösen soll.
   *
   * Nicht-Pull-Kameras behalten ihr bisheriges Verhalten. Bei Pull wird der
   * externe Capture-Befehl erst zusammen mit dem Upload-Fallback freigegeben.
   * Kann der Zustand nicht sicher gelesen werden, gilt fail-closed `false`.
   */
  public function shouldRequestExternalCapture(Camera $camera): bool
  {
    if ($camera->mode() !== CameraMode::PullSnapshot) {
      return true;
    }

    try {
      $lock = $this->acquireLock($camera, self::COMMAND_DECISION_LOCK_TIMEOUT_SECONDS);
    } catch (Throwable $exception) {
      $this->app->logger()->warning('capture source decision failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return false;
    }
    if ($lock === null) {
      return false;
    }

    try {
      $state = $this->readState($camera);
      $threshold = $this->fallbackThreshold($camera);
      return $threshold > 0
        && (int) $state['consecutive_pull_failures'] >= $threshold;
    } finally {
      $this->releaseLock($lock);
    }
  }

  /** Entfernt alten Pull-Zustand, sobald eine Kamera nicht mehr im Pull-Modus läuft. */
  public function clearState(Camera $camera): void
  {
    $path = $this->statePath($camera);
    if (is_file($path) && !@unlink($path)) {
      $this->app->logger()->warning('pull fallback state cleanup failed', [
        'camera' => $camera->slug(),
        'path' => $path,
      ]);
    }
  }

  /** @param array<string,mixed> $state */
  private function recordPullSuccess(Camera $camera, array $state): void
  {
    $state['consecutive_pull_failures'] = 0;
    $state['fallback_active'] = false;
    $state['last_pull_attempt_at'] = time();
    $state['last_pull_success_at'] = time();
    $state['last_pull_error'] = null;
    $this->writeState($camera, $state);
  }

  /** @param array<string,mixed> $state */
  private function recordPullFailure(Camera $camera, array $state, string $message): void
  {
    $failures = max(0, (int) ($state['consecutive_pull_failures'] ?? 0)) + 1;
    $threshold = $this->fallbackThreshold($camera);
    $state['consecutive_pull_failures'] = $failures;
    $state['fallback_active'] = $threshold > 0 && $failures >= $threshold;
    $state['last_pull_attempt_at'] = time();
    $state['last_pull_failure_at'] = time();
    $state['last_pull_error'] = substr(SecretRedactor::redact($message), 0, 500);
    $this->writeState($camera, $state);
  }

  /**
   * Liest nur einen zur aktuellen Capture-Konfiguration passenden Zustand.
   * Alte oder beschädigte Dateien dürfen den Fallback niemals einschalten.
   *
   * @return array<string,mixed>
   */
  private function readState(Camera $camera): array
  {
    $default = $this->defaultState($camera);
    $path = $this->statePath($camera);
    if (!is_file($path)) {
      return $default;
    }

    try {
      $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
      $this->app->logger()->warning('pull fallback state unreadable', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return $default;
    }
    if (!is_array($decoded) || ($decoded['configuration_hash'] ?? '') !== $default['configuration_hash']) {
      return $default;
    }

    // Ein sehr alter Fehlerzustand ist keine Aussage über die heutige Quelle.
    // Nach spätestens zwei Sollintervallen beginnt die Bewertung daher neu.
    $lastAttempt = (int) ($decoded['last_pull_attempt_at'] ?? 0);
    $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
    if ($lastAttempt > 0 && time() - $lastAttempt > max(300, $interval * 2)) {
      return $default;
    }

    return $decoded + $default;
  }

  /** @param array<string,mixed> $state */
  private function writeState(Camera $camera, array $state): void
  {
    $this->ensureStateDirectory();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!AtomicFileWriter::write($this->statePath($camera), $json . "\n", null, 0664)) {
      throw new RuntimeException('Unable to persist pull fallback state');
    }
  }

  /** @return array<string,mixed> */
  private function defaultState(Camera $camera): array
  {
    return [
      'configuration_hash' => $this->configurationHash($camera),
      'consecutive_pull_failures' => 0,
      'fallback_active' => false,
      'last_pull_attempt_at' => null,
      'last_pull_success_at' => null,
      'last_pull_failure_at' => null,
      'last_pull_error' => null,
    ];
  }

  private function configurationHash(Camera $camera): string
  {
    $capture = $camera->capture();
    return hash('sha256', json_encode([
      'mode' => $camera->mode()->value,
      'transport' => $capture['transport'] ?? 'http-get',
      'endpoint' => $capture['endpoint'] ?? '',
      'interval_seconds' => $capture['interval_seconds'] ?? 600,
      'timeout_seconds' => $capture['timeout_seconds'] ?? 10,
      'active_upload_fallback_failures' => $this->fallbackThreshold($camera),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  private function fallbackThreshold(Camera $camera): int
  {
    $value = (int) ($camera->capture()['active_upload_fallback_failures'] ?? 0);
    return max(0, min(100, $value));
  }

  /** @return resource|null */
  private function acquireLock(
    Camera $camera,
    float $timeoutSeconds = self::LOCK_TIMEOUT_SECONDS
  )
  {
    try {
      $this->ensureStateDirectory();
    } catch (Throwable $exception) {
      $this->app->logger()->warning('capture source lock directory unavailable', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return null;
    }
    $handle = @fopen($this->lockPath($camera), 'c');
    if (!is_resource($handle)) {
      return null;
    }

    $deadline = microtime(true) + max(0.0, $timeoutSeconds);
    do {
      if (flock($handle, LOCK_EX | LOCK_NB)) {
        return $handle;
      }
      usleep(self::LOCK_WAIT_MICROSECONDS);
    } while (microtime(true) < $deadline);

    fclose($handle);
    return null;
  }

  /** @param resource $lock */
  private function releaseLock($lock): void
  {
    flock($lock, LOCK_UN);
    fclose($lock);
  }

  private function ensureStateDirectory(): void
  {
    $directory = $this->app->paths()->statePath('pull-fallback');
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
      throw new RuntimeException('Unable to create pull fallback state directory');
    }
  }

  private function statePath(Camera $camera): string
  {
    return $this->app->paths()->statePath('pull-fallback/' . $this->safeSlug($camera) . '.json');
  }

  private function lockPath(Camera $camera): string
  {
    return $this->app->paths()->statePath('pull-fallback/' . $this->safeSlug($camera) . '.lock');
  }

  private function safeSlug(Camera $camera): string
  {
    return preg_replace('/[^a-z0-9-]+/', '-', strtolower($camera->slug())) ?: 'camera';
  }
}
