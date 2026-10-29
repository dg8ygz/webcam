<?php
declare(strict_types=1);

namespace Webcam\Service\Upload;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Webcam\App;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Capture\CaptureSourcePriority;
use Webcam\Service\Storage\ImageStorage;

/**
 * Gemeinsame Annahme fuer klassische Uploads und Uploads in kleinen Teilen.
 * Beide Wege muenden hier in dieselbe Bildpruefung und Speicherung. So gelten
 * fuer Wetterdaten, Status, Fehler und Wiederholungen dieselben Regeln.
 *
 * Slotzeit = geplanter Bildtermin; capturedAt = vom Pi gemeldete Aufnahmezeit.
 * Bei aelteren Clients fehlt capturedAt; dann bleibt die bisherige Zeitzuordnung.
 * Deduplizierung bedeutet: denselben Inhalt fuer denselben Slot bei einem
 * erneuten Upload nicht als zweite Aufnahme speichern. Fehlende Pflichtdateien
 * muessen trotzdem wiederhergestellt werden, bevor Erfolg gemeldet wird.
 * Davon zu unterscheiden ist ein unveraendertes Bild in einem NEUEN Slot:
 * ImageStorage erkennt dieses als moegliches stehendes Kamerabild.
 */
final class UploadIngestor
{
  public const ABSOLUTE_MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

  private readonly CaptureSourcePriority $sourcePriority;

  public function __construct(private readonly App $app)
  {
    $this->sourcePriority = new CaptureSourcePriority($app);
  }

  /**
   * @param array<string,mixed> $server
   * @param array<string,mixed> $post
   * @param array<string,mixed> $get
   * @return array{status:int,payload:array<string,mixed>}|null null bedeutet erlaubt
   */
  public function authenticationError(
    Camera $camera,
    array $server,
    array $post,
    array $get,
    bool $allowPostKey = true
  ): ?array {
    $slug = $camera->slug();
    if (isset($get['key'])) {
      $this->log($slug, 'query_key_rejected');
      return ['status' => 400, 'payload' => ['ok' => false, 'error' => 'query key not allowed']];
    }

    $key = '';
    if (isset($server['HTTP_X_UPLOAD_KEY']) && is_string($server['HTTP_X_UPLOAD_KEY'])) {
      $key = trim($server['HTTP_X_UPLOAD_KEY']);
    } elseif ($allowPostKey && isset($post['key']) && is_string($post['key'])) {
      $key = trim($post['key']);
    }
    $expected = (string) ($camera->capture()['upload_key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $key)) {
      $this->log($slug, 'forbidden');
      return ['status' => 403, 'payload' => ['ok' => false, 'error' => 'forbidden']];
    }
    return null;
  }

  /**
   * @return array{status:int,payload:array<string,mixed>}
   */
  public function ingest(Camera $camera, string $binary, int $timestamp): array
  {
    return $this->ingestWithSourcePriority(
      $camera,
      fn(): array => $this->ingestBinary($camera, $binary, $timestamp)
    );
  }

  /**
   * Prüft und speichert In-Memory-Uploads erst nach der Quellenentscheidung.
   * Dadurch kosten bewusst ignorierte Active-Uploads weder Hashing noch GD.
   *
   * @return array{status:int,payload:array<string,mixed>}
   */
  private function ingestBinary(Camera $camera, string $binary, int $timestamp): array
  {
    $slug = $camera->slug();
    $bytes = strlen($binary);
    if ($binary === '' || $bytes > $this->maxUploadBytes()) {
      $this->log($slug, $binary === '' ? 'empty upload' : 'file too large', ['bytes' => $bytes]);
      return ['status' => $binary === '' ? 400 : 413, 'payload' => ['ok' => false, 'error' => $binary === '' ? 'empty upload' : 'file too large']];
    }

    return $this->ingestPrepared(
      $camera,
      $timestamp,
      $bytes,
      hash('sha256', $binary),
      static fn(ImageStorage $storage): array => $storage->storeSnapshot($camera, $binary, $timestamp),
      null,
      static fn(string $target): bool => AtomicFileWriter::write($target, $binary)
    );
  }

  /**
   * Verarbeitet eine bereits lokal gespeicherte Uploaddatei ohne sie zuvor als
   * vollständigen PHP-String einzulesen. Das ist der Sollweg für Legacy- und
   * Chunk-Uploads; GD dekodiert das JPEG direkt aus der Datei.
   *
   * @return array{status:int,payload:array<string,mixed>}
   */
  public function ingestFile(Camera $camera, string $path, int $timestamp, mixed $localWeather = null, ?int $capturedAt = null): array
  {
    return $this->ingestWithSourcePriority(
      $camera,
      fn(): array => $this->ingestPreparedFile($camera, $path, $timestamp, $localWeather, $capturedAt)
    );
  }

  /**
   * Wendet die gemeinsame Pull-/Upload-Priorität an und protokolliert sichtbar,
   * ob der Upload ignoriert oder als freigegebener Fallback verarbeitet wurde.
   *
   * @param callable():array{status:int,payload:array<string,mixed>} $ingest
   * @return array{status:int,payload:array<string,mixed>}
   */
  private function ingestWithSourcePriority(Camera $camera, callable $ingest): array
  {
    $result = $this->sourcePriority->runExternalUpload($camera, $ingest);
    $payload = $result['payload'];
    if (($payload['ignored'] ?? false) === true) {
      $this->log($camera->slug(), 'ignored_pull_snapshot_primary', [
        'pull_failures' => $payload['pull_failures'] ?? 0,
        'fallback_after_failures' => $payload['fallback_after_failures'] ?? 0,
      ]);
    } elseif (($payload['fallback'] ?? false) === true) {
      $this->log($camera->slug(), 'active_upload_fallback', [
        'pull_failures' => $payload['pull_failures'] ?? 0,
      ]);
    }
    return $result;
  }

  /**
   * Prüft eine lokale Uploaddatei erst, nachdem ihre Quelle freigegeben wurde.
   * Chunk-Dateien können so als bestätigter No-op aus dem Spool verschwinden.
   *
   * @return array{status:int,payload:array<string,mixed>}
   */
  private function ingestPreparedFile(Camera $camera, string $path, int $timestamp, mixed $localWeather = null, ?int $capturedAt = null): array
  {
    $slug = $camera->slug();
    clearstatcache(true, $path);
    $bytes = is_file($path) && is_readable($path) ? filesize($path) : false;
    if ($bytes === false || $bytes <= 0) {
      $this->log($slug, 'upload file unreadable', ['path' => basename($path)]);
      return ['status' => 400, 'payload' => ['ok' => false, 'error' => 'upload file unreadable']];
    }
    if ($bytes > $this->maxUploadBytes()) {
      $this->log($slug, 'file too large', ['bytes' => $bytes]);
      return ['status' => 413, 'payload' => ['ok' => false, 'error' => 'file too large']];
    }
    $uploadHash = hash_file('sha256', $path);
    if (!is_string($uploadHash)) {
      $this->log($slug, 'upload hash failed', ['path' => basename($path)]);
      return ['status' => 500, 'payload' => ['ok' => false, 'error' => 'upload file unreadable']];
    }

    return $this->ingestPrepared(
      $camera,
      $timestamp,
      (int) $bytes,
      $uploadHash,
      fn(ImageStorage $storage): array => $storage->storeSnapshotFile(
        $camera,
        $path,
        $capturedAt ?? $timestamp,
        $this->alignTimestampToUploadSlot($camera, $timestamp),
        $uploadHash
      ),
      $localWeather,
      static fn(string $target): bool => AtomicFileWriter::copy($path, $target, 0664),
      $capturedAt
    );
  }

  /**
   * Gemeinsamer fachlicher Ablauf nach Größen- und Hashprüfung.
   *
   * @param callable(ImageStorage):array<string,mixed> $storeSnapshot
   * @return array{status:int,payload:array<string,mixed>}
   */
  private function ingestPrepared(
    Camera $camera,
    int $timestamp,
    int $bytes,
    string $uploadHash,
    callable $storeSnapshot,
    mixed $localWeather = null,
    ?callable $restoreOriginal = null,
    ?int $capturedAt = null
  ): array {
    $slug = $camera->slug();
    $slotTimestamp = $this->alignTimestampToUploadSlot($camera, $timestamp);

    // Fuer denselben Slot darf nur ein Upload gleichzeitig pruefen/schreiben.
    // Dafuer den Slot einer von 64 festen Sperrdateien je Kamera zuordnen.
    // Verschiedene Slots koennen dieselbe Sperre teilen und warten dann nur
    // nacheinander; so entstehen nicht unbegrenzt viele Sperrdateien.
    // Ohne Sperre nichts speichern. Der Sender kann denselben Upload wiederholen.
    $dedupeLock = $this->acquireDedupeLock($slug, $slotTimestamp);
    if ($dedupeLock === null) {
      $this->log($slug, 'dedupe_lock_unavailable', ['slot_timestamp' => $slotTimestamp]);
      return ['status' => 503, 'payload' => ['ok' => false, 'error' => 'upload lock unavailable']];
    }

    try {
      // Wiederholung desselben Inhalts und Slots: keinen zweiten DB-Eintrag
      // erzeugen. Original pruefen und gegebenenfalls aktuelle Bildkopien
      // reparieren. Historische Wetter-/EXIF-Daten dabei nicht neu berechnen.
      $existing = $this->findExistingCapture($camera, $slotTimestamp);
      if (
        $existing !== null
        && !\Webcam\Service\Storage\DeferredImages::isPath((string) ($existing['relative_path'] ?? ''))
        // Bei aktiver Bearbeitung nie ungefilterte Uploadbytes als Original reparieren.
        && !($camera->capture()['image_processing']['enabled'] ?? false)
        && !\Webcam\Service\Storage\StrombergPrivacyCrop::required($slug, $slotTimestamp)
        && isset($existing['hash_sha256'], $existing['relative_path'])
        && is_string($existing['hash_sha256'])
        && hash_equals($existing['hash_sha256'], $uploadHash)
        && is_string($existing['relative_path'])
        && $existing['relative_path'] !== ''
      ) {
        $discardedDuplicate = !empty($existing['is_duplicate']);
        if (!$discardedDuplicate && !$this->ensureOriginal($camera, $slotTimestamp, $existing['relative_path'], $uploadHash, $bytes, $restoreOriginal)) {
          return ['status' => 503, 'payload' => ['ok' => false, 'error' => 'stored original unavailable']];
        }
        if (!$discardedDuplicate) { (new ImageStorage($this->app))->repairCurrent($camera, $existing); }
        $payload = [
          'ok' => true,
          'duplicate' => true,
          'timestamp' => $slotTimestamp,
          'slot_timestamp' => $slotTimestamp,
          'path' => $discardedDuplicate ? null : $existing['relative_path'],
        ];
        $this->log($slug, 'duplicate_noop', [
          'path' => $discardedDuplicate ? null : $existing['relative_path'],
          'bytes' => $bytes,
          'slot_timestamp' => $slotTimestamp,
        ]);
        return ['status' => 200, 'payload' => $payload];
      }

      // Erst jetzt entstehen Bilddateien und der zugehörige Kamera-DB-Eintrag.
      // Der Callback vereinheitlicht Datei- und In-Memory-Quellen, ohne den
      // speicherschonenden Dateipfad wieder in einen großen String umzuwandeln.
      $storage = new ImageStorage($this->app);
      $result = $storeSnapshot($storage);
      // Pilot-Replays koennen eine zuvor abgebrochene Current-Publikation
      // repariert haben. Dann Wetter und Status ebenfalls abschliessen,
      // statt bereits nach dem vorhandenen DB-Eintrag Erfolg zu melden.
      $weatherResult = null;
      try {
        // Wetter ist eine nachgelagerte Ergänzung. Ein Fehler wird sichtbar
        // geloggt, macht das bereits korrekt gespeicherte Kamerabild aber nicht
        // nachträglich zu einem fehlgeschlagenen Upload.
        $weatherBase = $result['metadata_base_path'] ?? $result['original'] ?? null;
        $dir = $weatherBase ? dirname($weatherBase) : null;
        $file = $weatherBase ? pathinfo($weatherBase, PATHINFO_FILENAME) : null;
        if ($dir && $file) {
          $weatherResult = $this->app->weatherRunner()->capture($camera, $dir, $file);
          if ($weatherResult === null) {
            // Der Sensor-Fallback prueft nochmals, ob Wetterscripte konfiguriert
            // sind: deren Fehler darf nicht still durch DHT-Werte verdeckt werden.
            $weatherResult = \Webcam\Service\Weather\LocalSensorWeather::store($camera, $localWeather, $capturedAt ?? $timestamp, $dir, $file);
          }
          if ($weatherResult && isset($weatherResult['path'])) {
            $storage->mirrorWeatherSidecar($camera, $weatherResult['path']);
          }
        }
      } catch (Throwable $e) {
        $this->app->logger()->warning('weather capture failed (upload)', [
          'camera' => $slug,
          'error' => $e->getMessage(),
        ]);
      }

      $isDuplicate = !empty($result['duplicate']);
      if (!$isDuplicate && ($result['current_published'] ?? true)) {
        try {
          $decision = (new \Webcam\Service\Capture\AutoIsoController($this->app->paths()->statePath('auto-iso')))
            ->observe($camera, $result['exposure_values'] ?? [], $result['luminance'] ?? null,
              (int) ($result['captured_timestamp'] ?? $timestamp));
          if ($decision !== null) $this->app->logger()->info('auto iso evaluated', ['camera' => $slug] + $decision);
        } catch (Throwable $error) {
          // Die ISO-Regelung ist eine Folgeaktion. Ihr Fehler darf ein bereits
          // gespeichertes Bild nicht nachtraeglich als Uploadfehler ausweisen.
          $this->app->logger()->warning('auto iso unavailable', ['camera' => $slug, 'error' => $error->getMessage()]);
        }
        $this->app->statusService()->recordSuccess(
          $camera,
          'upload',
          $result['status_image_path'] ?? $result['original'] ?? null,
          $result['exif'] ?? null,
          $result['exif_path'] ?? null,
          $result['exif_relative'] ?? null,
          $result['luminance'] ?? null,
          $weatherResult['path'] ?? null,
          // Ein gepruefter, fehlgeschlagener Wetterabruf muss alte Livewerte
          // leeren. null wuerde im Status die vorherigen Werte beibehalten.
          $weatherResult['data'] ?? ($camera->weatherCommands() ? '' : null),
          (int) ($result['captured_timestamp'] ?? $timestamp),
          $slotTimestamp
        );
      }

      if (!empty($weatherResult['data'])) {
        $lines = array_values(array_filter(
          array_map('trim', preg_split('/\r?\n/', (string) $weatherResult['data']) ?: []),
          static fn(string $line): bool => $line !== ''
        ));
        if ($lines) {
          $slotTs = (int) ($result['slot_timestamp'] ?? $timestamp);
          $storage->storeWeatherLines($camera, $slotTs, $lines);
        }
      }
      if ($isDuplicate) {
        $this->app->statusService()->recordDuplicate(
          $camera,
          sprintf('Duplicate upload discarded [SLOT:%d]', (int) ($result['slot_timestamp'] ?? $slotTimestamp)),
          $weatherResult['path'] ?? null,
          $weatherResult['data'] ?? ($camera->weatherCommands() ? '' : null),
          (int) ($result['captured_timestamp'] ?? $timestamp),
          $slotTimestamp
        );
      }

      $payload = [
        'ok' => true,
        'duplicate' => $isDuplicate || !empty($result['replay']),
        'timestamp' => $result['timestamp'] ?? $timestamp,
        'slot_timestamp' => $result['slot_timestamp'] ?? null,
        'path' => $isDuplicate ? null : ($result['relative_path'] ?? null),
      ];
      $this->log($slug, $isDuplicate ? 'duplicate_discarded' : (!empty($result['replay']) ? 'duplicate_noop' : 'ok'), [
        'path' => $isDuplicate ? null : ($result['relative_path'] ?? null),
        'bytes' => $bytes,
        'slot_timestamp' => $slotTimestamp,
        'captured_timestamp' => $result['captured_timestamp'] ?? $timestamp,
        'capture_time_reported' => $capturedAt !== null,
      ]);
      return ['status' => 200, 'payload' => $payload];
    } catch (\Webcam\Service\Storage\ImmutableImageSlotConflict $e) {
      // Kein ACK: Der Client muss genau dieses abgelehnte Bild gesondert
      // aufbewahren und darf anschliessend neuere Aufnahmen weiter senden.
      // Zeit und Inhalt binden die Antwort an den konkreten Upload.
      $this->log($slug, 'immutable_slot_conflict', ['slot_timestamp' => $slotTimestamp, 'timestamp' => $timestamp]);
      return ['status' => 409, 'payload' => ['ok' => false, 'error' => 'immutable_slot_conflict',
        'timestamp' => $timestamp, 'slot_timestamp' => $slotTimestamp, 'sha256' => $uploadHash]];
    } catch (\Webcam\Service\Storage\InvalidSnapshotException $e) {
      $this->log($slug, 'invalid_image');
      return ['status' => 422, 'payload' => ['ok' => false, 'error' => 'invalid_image']];
    } catch (\Webcam\Service\Storage\SnapshotResourceLimitException $e) {
      $this->log($slug, 'image_resource_limit', ['error' => $e->getMessage()]);
      return ['status' => 422, 'payload' => ['ok' => false, 'error' => $e->getMessage()]];
    } catch (Throwable $e) {
      $this->app->logger()->error('upload failed', ['camera' => $slug, 'error' => $e->getMessage()]);
      $this->log($slug, 'exception', ['error' => $e->getMessage()]);
      return ['status' => 500, 'payload' => ['ok' => false, 'error' => 'store failed']];
    } finally {
      $this->releaseDedupeLock($dedupeLock);
    }
  }

  public function maxUploadBytes(): int
  {
    return \Webcam\Service\Storage\SnapshotResourceLimits::maxBytes($this->app);
  }

  /**
   * Optional gemeldete Aufnahmezeit pruefen. Alte Clients und bereits vor dem
   * Update wartende Bilder haben dieses Feld nicht: null ist dafuer erlaubt.
   * Ein geliefertes, aber ungueltiges Datum hingegen nicht still ersetzen.
   * Die Pruefung begrenzt Format/Zeitbereich; sie beweist keine korrekte Pi-Uhr.
   */
  public function parseCapturedAt(mixed $value): ?int
  {
    if ($value === null) { return null; }
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{9}$/D', (string) $value)
        || (int) $value < 946684800 || (int) $value > time() + 30) {
      throw new \InvalidArgumentException('Invalid physical capture timestamp');
    }
    return (int) $value;
  }

  public function parseTimestamp(mixed $value): int
  {
    if ($value === null) { return time(); }
    if ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) {
      return (int) $value;
    }
    if (is_string($value)) {
      foreach (['Ymd_Hi' => $this->app->config('timezone', 'UTC'), 'Ymd_His' => 'UTC'] as $format => $zone) {
        $pattern = $format === 'Ymd_Hi' ? '/^[0-9]{8}_[0-9]{4}$/D' : '/^[0-9]{8}_[0-9]{6}$/D';
        if (!preg_match($pattern, $value)) { continue; }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone($zone));
        if ($date !== false && $date->format($format) === $value) {
          return $date->getTimestamp();
        }
      }
    }
    throw new \InvalidArgumentException('Invalid capture timestamp');
  }

  /** @param array<string,mixed> $extra */
  public function log(string $slug, string $status, array $extra = []): void
  {
    try {
      $dest = $this->app->paths()->logPath('upload.log');
      $payload = ['status' => $status, 'camera' => $slug ?: '-'] + $extra;
      $line = sprintf("[%s] %s\n", date('c'), json_encode($payload, JSON_UNESCAPED_SLASHES));
      if (file_put_contents($dest, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        $this->app->logger()->warning('upload log write failed', ['path' => $dest]);
      }
    } catch (Throwable $e) {
      $this->app->logger()->warning('upload log exception', ['error' => $e->getMessage()]);
    }
  }

  public static function uploadErrorMessage(int $code): string
  {
    return match ($code) {
      UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file too large',
      UPLOAD_ERR_PARTIAL => 'partial upload',
      UPLOAD_ERR_NO_FILE => 'missing upload',
      UPLOAD_ERR_NO_TMP_DIR => 'no tmp dir',
      UPLOAD_ERR_CANT_WRITE => 'tmp not writable',
      UPLOAD_ERR_EXTENSION => 'blocked by extension',
      default => 'upload error',
    };
  }

  private function alignTimestampToUploadSlot(Camera $camera, int $timestamp): int
  {
    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    if ($this->matchesExtraTrigger($camera, $timestamp, $timezone)) {
      return $timestamp;
    }
    $interval = max(60, (int) ($camera->capture()['interval_seconds'] ?? 600));
    $base = intdiv($timestamp, $interval) * $interval;
    if ($timestamp === $base) {
      return $timestamp;
    }
    $next = $base + $interval;
    $earlyThreshold = max(1, min(30, (int) round($interval * 0.05)));
    return ($next - $timestamp <= $earlyThreshold) ? $next : $base;
  }

  private function matchesExtraTrigger(Camera $camera, int $timestamp, DateTimeZone $timezone): bool
  {
    $entries = $camera->capture()['extra_times'] ?? [];
    if (is_string($entries)) {
      $entries = [$entries];
    }
    if (!is_array($entries) || !$entries) {
      return false;
    }

    $reference = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    foreach ($entries as $entry) {
      if (!is_string($entry) || trim($entry) === '') {
        continue;
      }
      $entry = trim($entry);
      if (preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})$/', $entry, $match)) {
        $due = $reference->setTime((int) $match['hour'], (int) $match['minute'], 0);
        if (
          abs($due->getTimestamp() - $timestamp) <= 90
          || abs($due->sub(new DateInterval('P1D'))->getTimestamp() - $timestamp) <= 90
          || abs($due->add(new DateInterval('P1D'))->getTimestamp() - $timestamp) <= 90
        ) {
          return true;
        }
        continue;
      }
      if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[ T](?<hour>\d{2}):(?<minute>\d{2})$/', $entry, $match)) {
        $due = DateTimeImmutable::createFromFormat(
          'Y-m-d H:i',
          sprintf('%04d-%02d-%02d %02d:%02d', $match['year'], $match['month'], $match['day'], $match['hour'], $match['minute']),
          $timezone
        );
        if ($due && abs($due->getTimestamp() - $timestamp) <= 90) {
          return true;
        }
      }
    }
    return false;
  }

  /** @return resource|null */
  private function acquireDedupeLock(string $slug, int $slotTimestamp)
  {
    try {
      $dir = $this->app->paths()->statePath('upload-dedupe');
      if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $this->app->logger()->warning('upload dedupe dir create failed', ['path' => $dir]);
        return null;
      }
      $safeSlug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?: 'camera';
      $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeSlug . '-bucket-' . (hexdec(substr(hash('sha256', (string) $slotTimestamp), 0, 2)) % 64) . '.lock';
      $handle = @fopen($path, 'c');
      if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
          fclose($handle);
        }
        $this->app->logger()->warning('upload dedupe lock failed', ['path' => $path, 'camera' => $slug]);
        return null;
      }
      return $handle;
    } catch (Throwable $e) {
      $this->app->logger()->warning('upload dedupe lock exception', ['camera' => $slug, 'error' => $e->getMessage()]);
      return null;
    }
  }

  /** @param resource|null $handle */
  private function releaseDedupeLock($handle): void
  {
    if (is_resource($handle)) {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * Das gespeicherte Original anhand von Groesse UND SHA256-Fingerabdruck
   * pruefen. Falls erlaubt, exakt die erneut gesendeten Bytes wiederherstellen.
   * Historische Datenbankeintraege und Begleitdateien bleiben erhalten.
   */
  private function ensureOriginal(Camera $camera, int $slot, string $relative, string $hash, int $bytes, ?callable $restore): bool
  {
    $paths = new CameraStoragePathResolver($this->app->paths());
    $path = $paths->resolveStorageRelative($camera, $relative);
    $matches = static function (?string $file) use ($hash, $bytes): bool {
      if ($file === null) { return false; }
      clearstatcache(true, $file);
      return is_file($file) && !is_link($file) && @filesize($file) === $bytes
        && @hash_file('sha256', $file) === $hash;
    };
    if ($matches($path)) { return true; }
    $date = (new DateTimeImmutable('@' . $slot))->setTimezone(new DateTimeZone($this->app->config('timezone', 'UTC')));
    $archive = $this->app->paths()->archivePath($camera->slug() . '/' . $date->format('Y/m/d/') . \Webcam\Service\Storage\CaptureFilename::archive($date));
    foreach (['_hu.jpg', '_full.jpg'] as $suffix) {
      if ($matches($archive . $suffix)) { return true; }
    }
    // Nur im bereits vorhandenen, exakt erwarteten Cache-Verzeichnis reparieren.
    // Keine abgelaufenen Verzeichnisbaeume neu anlegen und keine beliebigen
    // DB-Pfade oder schreibgeschuetzten Archive als Reparaturziel verwenden.
    $expected = 'cache/' . $camera->slug() . '/' . $date->format('Y/m/d/') . \Webcam\Service\Storage\CaptureFilename::cache($date) . '.jpg';
    if ($relative !== $expected || $path === null || !is_dir(dirname($path)) || is_link($path) || $restore === null) {
      return false;
    }
    return $restore($path) && $matches($path);
  }

  /** @return array{id:int,slot_ts:int,captured_ts:int,relative_path:string,hash_sha256:?string,is_duplicate:bool}|null */
  private function findExistingCapture(Camera $camera, int $slotTimestamp): ?array
  {
    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $statement = $store->pdo()->prepare('SELECT id, slot_ts, captured_ts, relative_path, hash_sha256, is_duplicate FROM captures WHERE slot_ts = :slot LIMIT 1');
      $statement->execute([':slot' => $slotTimestamp]);
      $row = $statement->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        return null;
      }
      return [
        'id' => (int) $row['id'],
        'slot_ts' => (int) $row['slot_ts'],
        'captured_ts' => (int) $row['captured_ts'],
        'relative_path' => (string) ($row['relative_path'] ?? ''),
        'hash_sha256' => isset($row['hash_sha256']) && is_string($row['hash_sha256']) ? $row['hash_sha256'] : null,
        'is_duplicate' => (int) ($row['is_duplicate'] ?? 0) === 1,
      ];
    } catch (Throwable $e) {
      $this->app->logger()->warning('upload dedupe lookup failed', [
        'camera' => $camera->slug(),
        'slot_timestamp' => $slotTimestamp,
        'error' => $e->getMessage(),
      ]);
      return null;
    }
  }
}
