<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Filesystem\CameraStoragePathResolver;
use Webcam\Infrastructure\Filesystem\CurrentImageDirectory;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Prüft neue Bilddaten und veröffentlicht alle daraus entstehenden Dateien.
 *
 * Dazu gehören Original, Größenvarianten, aktuelle Live-Kopien, EXIF-/Wetter-
 * Metadaten und der passende Datensatz in der Kamera-SQLite. Der Service bündelt
 * damit bewusst die schreibenden Nebenwirkungen einer neuen Aufnahme.
 *
 * Zum Lesen: storeSnapshotSource fuehrt durch den Speicherablauf. Zuerst
 * geplanten Slot und Aufnahmezeit trennen, dann das Bild vollstaendig einlesen.
 * Nach der Pruefung auf unveraenderten Bildinhalt folgen Original, verschiedene
 * Bildgroessen und EXIF-Daten (z.B. ISO/Belichtungszeit). Die aktuellen URLs
 * schuetzt CurrentImagePublisher davor, durch alte Uploads zurueckzuspringen.
 * Zum Abschluss werden Bildpfade und Metadaten in SQLite eingetragen.
 *
 * Sidecars sind Begleitdateien neben dem JPEG, etwa fuer EXIF oder Wetter.
 * Atomar bedeutet hier: eine fertige Datei in einem Schritt sichtbar machen.
 * Alle Dateien und die Datenbank zusammen sind KEINE einzelne Transaktion;
 * Fehler koennen eine Wiederholung und Reparatur des Uploads erfordern.
 * Die zeitgesteuerte Cache-Bereinigung laeuft separat, nicht in dieser Klasse.
 */
final class ImageStorage
{
  private readonly CameraStoragePathResolver $storagePaths;

  public function __construct(private readonly App $app)
  {
    $this->storagePaths = $app->cameraStoragePaths();
  }

  /**
   * @return array{
   *   original:string,
   *   variants:array<int,string>,
   *   named_variants:array<string,string>,
   *   timestamp:int,
   *   slot_timestamp:int,
   *   captured_timestamp:int,
   *   relative_path:string,
   *   exif:array<string,string>,
   *   exif_path:?string,
   *   exif_relative:?string,
   *   luminance:?float,
   *   duplicate:bool
   * }
   */
  public function storeSnapshot(
    Camera $camera,
    string $binary,
    ?int $timestamp = null,
    ?int $slotTimestamp = null
  ): array
  {
    return $this->storeSnapshotSource($camera, $binary, null, $timestamp, $slotTimestamp);
  }

  /**
   * Speichert ein JPEG direkt aus einer lokalen Datei. Dadurch muss der
   * Upload-Endpunkt die vollständigen komprimierten Bilddaten nicht zusätzlich
   * als PHP-String halten.
   *
   * @return array<string,mixed>
   */
  public function storeSnapshotFile(
    Camera $camera,
    string $sourcePath,
    ?int $timestamp = null,
    ?int $slotTimestamp = null,
    ?string $verifiedHash = null
  ): array {
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
      throw new RuntimeException('Snapshot source file is not readable');
    }
    return $this->storeSnapshotSource(
      $camera,
      null,
      $sourcePath,
      $timestamp,
      $slotTimestamp,
      $verifiedHash
    );
  }

  /**
   * Gemeinsamer Speicherablauf für In-Memory-Captures und lokale Uploaddateien.
   * Genau eine der beiden Quellen muss gesetzt sein.
   *
   * @return array<string,mixed>
   */
  private function storeSnapshotSource(
    Camera $camera,
    ?string $binary,
    ?string $sourcePath,
    ?int $timestamp,
    ?int $slotTimestamp,
    ?string $verifiedHash = null
  ): array {
    if (($binary === null) === ($sourcePath === null)) {
      throw new RuntimeException('Exactly one snapshot source is required');
    }
    // Ablauf bewusst linear halten:
    // 1. Zeitpunkt/Slot bestimmen
    // 2. Binärdaten vor jedem Dateischreiben als decodierbares Bild prüfen
    // 3. Original + Varianten schreiben
    // 4. Metadaten/Sidecars erzeugen
    // 5. Datenbank und Soll-Spiegel aktualisieren
    $capturedAt = $timestamp ?? time();
    if ($slotTimestamp !== null && $slotTimestamp <= 0) {
      throw new RuntimeException('Explicit snapshot slot must be a positive timestamp');
    }
    $slotTimestamp ??= $this->alignTimestampToSlot($camera, $capturedAt);
    $hash = $sourcePath !== null
      ? (
        is_string($verifiedHash) && preg_match('/^[a-f0-9]{64}$/', $verifiedHash) === 1
          ? $verifiedHash
          : hash_file('sha256', $sourcePath)
      )
      : hash('sha256', (string) $binary);
    if (!is_string($hash)) {
      throw new RuntimeException('Unable to hash snapshot source');
    }
    $tzName = $this->app->config('timezone', 'UTC');
    $date = (new DateTimeImmutable('@' . $slotTimestamp))->setTimezone(new DateTimeZone($tzName));
    $year = $date->format('Y');
    $month = $date->format('m');
    $day = $date->format('d');
    $isCurrentYear = (int) $year === (int) date('Y');
    // Aktuelles Jahr -> cache/<slug>/..., ältere Jahre -> storage/<Archive>/<slug>/...
    $relativePathFs = '';
    $relativePathPublic = '';
    if ($isCurrentYear) {
      $baseRoot = $this->app->paths()->cachePath($camera->slug());
      $relativePathFs = $year . '/' . $month . '/' . $day;
      $relativePathPublic = 'cache/' . $camera->slug() . '/' . $relativePathFs;
    } else {
      $baseRoot = $this->storagePaths->baseRoot($camera);
      $archiveDirName = $camera->storageArchiveDir();
      $relativePathFs = $archiveDirName . '/' . $camera->slug() . '/' . $year . '/' . $month . '/' . $day;
      $relativePathPublic = $relativePathFs;
    }
    $targetDir = $this->storagePaths->join($baseRoot, $relativePathFs);

    $image = $sourcePath !== null
      ? $this->createImageResourceFromFile($sourcePath, $camera)
      : $this->createImageResource((string) $binary, $camera);
    if (!$image) {
      throw new InvalidSnapshotException('Snapshot payload is not a supported image');
    }
    $luminance = $this->computeLuminance($image);
    $deferred = $this->app->deferredImages();
    if ($deferred->handles($camera, $slotTimestamp)) {
      try {
        return (new DeferredCaptureStorage($this->app, $deferred))->store(
          $camera, $image, $binary, $sourcePath, $slotTimestamp, $capturedAt, $hash, $luminance,
          fn(string $original, array $numeric, array $named, ?string $exif) =>
            $this->updateCurrentDirectory($camera, $original, $numeric, $named, $exif, false)
        );
      } finally { imagedestroy($image); }
    }
    $processing = ImageProcessingConfig::normalize($camera->capture()['image_processing'] ?? []);
    $privacyRequired = StrombergPrivacyCrop::required($camera->slug(), $slotTimestamp);
    $privacyBinary = null;
    $processedBinary = null;
    $exifSummary = null;
    if ($processing['enabled'] || $privacyRequired) {
      // Messwerte stammen immer vom Eingang, damit Gamma/Masken die ISO-Regelung
      // nicht beeinflussen. Unbearbeitete JPEGs werden niemals oeffentlich abgelegt.
      $input = $sourcePath;
      $stream = null;
      try {
        if ($input === null) {
          $stream = fopen('php://temp', 'w+b');
          if (!$stream || fwrite($stream, (string) $binary) !== strlen((string) $binary) || !rewind($stream)) {
            throw new RuntimeException('EXIF-Eingangsstream fehlgeschlagen');
          }
          $input = $stream;
        }
        $exifSummary = (new CaptureExifMetadata($this->app))->read($camera, $input, $luminance, $exposureValues);
        $processor = new ImageProcessor();
        if ($privacyRequired) {
          $safe = StrombergPrivacyCrop::apply($image);
          imagedestroy($image);
          $image = $safe;
          $privacyBinary = $processor->jpeg($image);
        }
        $effectiveProcessing = $privacyRequired
          ? StrombergPrivacyCrop::remainingProcessing($processing, imagesx($image)) : $processing;
        $processed = $processor->apply($image, $effectiveProcessing);
        imagedestroy($image);
        $image = $processed;
        $processedBinary = $processor->jpeg($image);
        SnapshotResourceLimits::checkBytes(strlen($processedBinary), $this->app);
        $hash = hash('sha256', $processedBinary);
      } catch (\Throwable $error) {
        imagedestroy($image);
        throw $error;
      } finally {
        if (is_resource($stream)) fclose($stream);
      }
    }
    $variantGenerator = new ImageVariantGenerator($this->app);
    $applyNightDenoise = $variantGenerator->denoiseEnabled($camera, $luminance);

    $filename = CaptureFilename::cache($date);
    $originalPath = $targetDir . DIRECTORY_SEPARATOR . $filename . '.jpg';
    $relativePath = $relativePathPublic . '/' . $filename . '.jpg';

    $store = new CameraDataStore($this->app, $camera);
    $sameSlotReplay = false;
    if ($processing['enabled'] || $privacyRequired) {
      $q = $store->pdo()->prepare('SELECT hash_sha256 FROM captures WHERE slot_ts = ? AND is_duplicate = 0');
      $q->execute([$slotTimestamp]);
      $storedHash = $q->fetchColumn();
      $sameSlotReplay = is_string($storedHash) && hash_equals($storedHash, $hash);
    }
    $previousHash = $store->latestUsableHashBeforeSlot($slotTimestamp);
    if (!$sameSlotReplay && $previousHash !== null && hash_equals($previousHash, $hash)) {
      imagedestroy($image);
      $this->ensureDirectory($targetDir);
      $this->persistCaptureToDatabase(
        $camera,
        $relativePathPublic,
        $filename,
        [],
        [],
        [],
        null,
        $slotTimestamp,
        $capturedAt,
        $hash,
        $luminance,
        true,
        true
      );
      return [
        'original' => $originalPath,
        'variants' => [],
        'named_variants' => [],
        'timestamp' => $slotTimestamp,
        'slot_timestamp' => $slotTimestamp,
        'captured_timestamp' => $capturedAt,
        'relative_path' => $relativePath,
        'exif' => [],
        'exif_path' => null,
        'exif_relative' => null,
        'luminance' => $luminance,
        'duplicate' => true,
      ];
    }

    $this->ensureDirectory($targetDir);
    $originalWritten = $processedBinary !== null
      ? AtomicFileWriter::write($originalPath, $processedBinary, $this->jpegValidator())
      : ($sourcePath !== null
        ? AtomicFileWriter::copy($sourcePath, $originalPath, 0664)
        : AtomicFileWriter::write($originalPath, (string) $binary, $this->jpegValidator()));
    unset($processedBinary);
    if (!$originalWritten) {
      imagedestroy($image);
      throw new RuntimeException("Unable to write snapshot: {$originalPath}");
    }
    $this->applyOwnership($originalPath);

    $variants = $variantGenerator->numeric($camera, $targetDir, $filename, $image, $applyNightDenoise);
    $namedVariants = $variantGenerator->named($image, $targetDir, $filename, $originalPath, $applyNightDenoise);
    if ($image) {
      imagedestroy($image);
    }

    $exifMetadata = new CaptureExifMetadata($this->app);
    $exifSummary ??= $exifMetadata->read($camera, $originalPath, $luminance, $exposureValues);
    $exifPath = null;
    $exifRelative = null;
    $weatherData = null;
    if ($exifSummary) {
      $exifPath = $this->storagePaths->join($baseRoot, $relativePathFs . '/' . $filename . '_ex.txt');
      $exifRelative = $relativePathPublic . '/' . $filename . '_ex.txt';
      $content = $exifMetadata->sidecar($exifSummary);
      if (!AtomicFileWriter::write($exifPath, $content)) {
        $this->app->logger()->warning('exif sidecar write failed', ['path' => $exifPath]);
        $exifPath = null;
        $exifRelative = null;
      }
      $this->applyOwnership($exifPath ?? '');
    }

    $currentPublished = (new CurrentImagePublisher($this->app))->publish($camera, $slotTimestamp,
      function () use ($camera, $originalPath, $variants, $namedVariants, $exifPath, $processing, $binary, $sourcePath, $hash, $privacyBinary): void {
        if ($processing['enabled']) {
          (new ImageProcessingPreview($this->app))->remember($camera, $privacyBinary ?? $binary, $privacyBinary !== null ? null : $sourcePath, $hash);
        }
        $this->updateCurrentDirectory($camera, $originalPath, $variants, $namedVariants, $exifPath);
      });
    $this->writeCacheEntry($camera, $date, $originalPath, $variants);
    $this->mirrorStorageSollLayout($camera, $date, $originalPath, $variants, $namedVariants, $exifPath, $isCurrentYear);
    // Eine erneute Zustellung darf historische Wetter-/Aufnahmezeiten nicht
    // ersetzen. Dateien werden trotzdem repariert, immer aus gefilterten Bytes.
    if (!$sameSlotReplay) $this->persistCaptureToDatabase(
      $camera,
      $relativePathPublic,
      $filename,
      $variants,
      $namedVariants,
      $exifSummary,
      $weatherData,
      $slotTimestamp,
      $capturedAt,
      $hash,
      $luminance,
      false,
      false
    );

    return [
      'replay' => $sameSlotReplay,
      'current_published' => $currentPublished,
      'original' => $originalPath,
      'variants' => $variants,
      'named_variants' => $namedVariants,
      'timestamp' => $slotTimestamp,
      'slot_timestamp' => $slotTimestamp,
      'captured_timestamp' => $capturedAt,
      'relative_path' => $relativePathPublic . '/' . $filename . '.jpg',
      'exif' => $exifSummary,
      'exposure_values' => $exposureValues,
      'exif_path' => $exifPath,
      'exif_relative' => $exifRelative,
      'luminance' => $luminance,
      'duplicate' => false,
    ];
  }

  /**
   * Aktuelle Bildkopien sind Pflicht: Schlaegt das Schreiben fehl, eine
   * Ausnahme melden, damit der Upload nicht faelschlich bestaetigt wird.
   */
  private function copyCurrentRequired(string $source, string $target): void
  {
    if (!AtomicFileWriter::copy($source, $target)) {
      throw new RuntimeException('Current image publication failed: ' . basename($target));
    }
    $this->applyOwnership($target);
  }

  /**
   * Bei erneutem Upload aktuelle Bildkopien aus vorhandenen Dateien reparieren.
   * Der Publisher laesst aeltere Slots aus. Historische EXIF-/Wetterdaten und
   * Datenbankeintraege dabei weder neu erzeugen noch ueberschreiben.
   */
  public function repairCurrent(Camera $camera, array $record): void
  {
    (new CurrentImagePublisher($this->app))->publish($camera, (int) $record['slot_ts'], function () use ($camera, $record): void {
      $original = $this->storagePaths->resolveStorageRelative($camera, $record['relative_path']);
      if ($original === null || !is_file($original)) {
        $date = (new DateTimeImmutable('@' . $record['slot_ts']))->setTimezone(new DateTimeZone($this->app->config('timezone', 'UTC')));
        $original = $this->app->paths()->archivePath($camera->slug() . '/' . $date->format('Y/m/d/') . CaptureFilename::archive($date) . '_hu.jpg');
      }
      $stmt = (new CameraDataStore($this->app, $camera))->pdo()->prepare('SELECT variant, relative_path FROM variants WHERE capture_id = :id');
      $stmt->execute([':id' => $record['id']]);
      $variants = $named = [];
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $path = $this->storagePaths->resolveStorageRelative($camera, $row['relative_path']);
        if ($path === null) { throw new RuntimeException('Current variant path unavailable'); }
        if (ctype_digit((string) $row['variant'])) { $variants[(int) $row['variant']] = $path; }
        else { $named[$row['variant']] = $path; }
      }
      $exif = substr($original, 0, -4) . '_ex.txt';
      $this->updateCurrentDirectory($camera, $original, $variants, $named, is_file($exif) ? $exif : null);
    });
  }

  /**
   * Veröffentlicht die neuesten Dateien unter <slug>/current/.
   *
   * Die datierten Originale bleiben unangetastet; current/ enthält nur die
   * festen Namen, die Browser und ältere Verbraucher als Livebild erwarten.
   *
   * @param array<int,string> $variants
   */
  private function updateCurrentDirectory(
    Camera $camera,
    string $originalPath,
    array $variants,
    array $namedVariants,
    ?string $exifPath,
    bool $cleanupLegacy = true
  ): void {
    // Soll: <slug>/current/
    $projectRoot = (string) $this->app->config('current_project_root', dirname(__DIR__, 3));
    $currentDir = (new CurrentImageDirectory($projectRoot))->prepare($camera->slug());
    $this->applyOwnership($currentDir);
    // Alte Anwendungen erwarten unter raw.jpg die unveraenderten Originalbytes
    // samt EXIF-Kameradaten, nicht eine verkleinerte oder neu komprimierte Version.
    $this->copyCurrentRequired($originalPath, $currentDir . '/raw.jpg');
    $this->copyCurrentRequired($namedVariants['full'] ?? $originalPath, $currentDir . '/full.jpg');
    $this->copyCurrentRequired($namedVariants['hu'] ?? $originalPath, $currentDir . '/hu.jpg');
    foreach ($variants as $width => $path) {
      $this->copyCurrentRequired($path, $currentDir . '/' . $width . '.jpg');
    }
    if (isset($variants[400])) {
      $this->copyCurrentRequired($variants[400], $currentDir . '/current.jpg');
    } else {
      $this->copyCurrentRequired($originalPath, $currentDir . '/current.jpg');
    }
    if ($exifPath) {
      $this->copyCurrentRequired($exifPath, $currentDir . '/current_ex.txt');
      $this->copyCurrentRequired($exifPath, $currentDir . '/ex.txt');
    }

    foreach ($cleanupLegacy ? (glob($currentDir . '/latest_*') ?: []) : [] as $legacy) {
      if (!unlink($legacy)) {
        $this->app->logger()->warning('legacy latest cleanup failed', ['path' => $legacy, 'camera' => $camera->slug()]);
      }
    }
  }

  /**
   * Legt das neue Bild im Rolling-Cache des laufenden Jahres ab.
   *
   * Der kanonische Dateiname enthält Datum, Uhrzeit und Variante. Retention
   * laeuft separat durch bin/cache_retention.php im Wartungsplan.
   *
   * @param array<int,string> $variants
   */
  private function writeCacheEntry(
    Camera $camera,
    DateTimeImmutable $date,
    string $originalPath,
    array $variants
  ): void {
    $paths = $this->app->paths();
    try {
      $cacheRoot = $paths->cachePath($camera->slug());
    } catch (\RuntimeException) {
      return;
    }
    $year = $date->format('Y');
    $month = $date->format('m');
    $day = $date->format('d');
    // Dateinamen im Format YYYYMMDD_HHMMSS_* für Konsistenz mit DB-Pfaden.
    $stamp = CaptureFilename::cache($date);
    $targetDir = $cacheRoot . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $day;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
      $this->app->logger()->warning('cache dir create failed', ['dir' => $targetDir, 'camera' => $camera->slug()]);
      return;
    }

    // Basis: kopiere Original als _full für Nachvollziehbarkeit
    $this->copyIfReadable($originalPath, $targetDir . DIRECTORY_SEPARATOR . $stamp . '_full.jpg');

    // Nummerische Varianten in Canonical-Map falls vorhanden
    foreach ($variants as $width => $path) {
      $suffix = (int) $width;
      $filename = sprintf('%s_%d.jpg', $stamp, $suffix);
      $this->copyIfReadable($path, $targetDir . DIRECTORY_SEPARATOR . $filename);
    }

  }

  /**
   * Spiegelt ältere Aufnahmen in das Soll-Archiv-Layout.
   *
   * Das laufende Jahr bleibt im SSD-Cache. Nur ältere Jahre werden mitsamt
   * Varianten und EXIF in den Archivbereich geschrieben.
   *
   * @param array<int,string> $numericVariants
   * @param array<string,string> $namedVariants
   */
  private function mirrorStorageSollLayout(
    Camera $camera,
    DateTimeImmutable $date,
    string $originalPath,
    array $numericVariants,
    array $namedVariants,
    ?string $exifPath,
    bool $isCurrentYear
  ): void {
    if ($isCurrentYear) {
      // Aktuelles Jahr verbleibt im Cache; keine Spiegelung ins HDD-Archiv
      return;
    }
    try {
      $storageRoot = $this->app->paths()->storagePath($camera->slug());
    } catch (\RuntimeException $exception) {
      return;
    }

    $targetDir = $storageRoot . DIRECTORY_SEPARATOR . $date->format('Y') . DIRECTORY_SEPARATOR . $date->format('m') . DIRECTORY_SEPARATOR . $date->format('d');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
      $this->app->logger()->warning('storage mirror dir create failed', ['dir' => $targetDir, 'camera' => $camera->slug()]);
      return;
    }

    $baseName = pathinfo($originalPath, PATHINFO_BASENAME);
    $this->copyIfReadable($originalPath, $targetDir . DIRECTORY_SEPARATOR . $baseName);

    foreach ($numericVariants as $path) {
      $variantName = pathinfo($path, PATHINFO_BASENAME);
      $this->copyIfReadable($path, $targetDir . DIRECTORY_SEPARATOR . $variantName);
    }

    foreach ($namedVariants as $path) {
      $variantName = pathinfo($path, PATHINFO_BASENAME);
      $this->copyIfReadable($path, $targetDir . DIRECTORY_SEPARATOR . $variantName);
    }

    if ($exifPath) {
      $exifName = pathinfo($exifPath, PATHINFO_BASENAME);
      $this->copyIfReadable($exifPath, $targetDir . DIRECTORY_SEPARATOR . $exifName);
    }
  }

  // Kopiert nur eine lesbare Quelle, übernimmt mtime/Ownership und protokolliert
  // Fehler. Ein fehlendes optionales Spiegelbild stoppt nicht den Hauptcapture.
  private function copyIfReadable(string $source, string $destination): void
  {
    if (!is_readable($source)) {
      return;
    }
    $resolvedSource = realpath($source);
    $resolvedDestination = realpath($destination);
    if ($resolvedSource && $resolvedDestination && $resolvedSource === $resolvedDestination) {
      return;
    }
    if (!AtomicFileWriter::copy($source, $destination)) {
      $this->app->logger()->warning('copy failed', ['source' => $source, 'target' => $destination]);
      return;
    }
    $this->applyOwnership($destination);
  }

  // Passt Gruppe und Besitzer an die Hostkonfiguration an. chown/chgrp-Fehler
  // bleiben als Warnung sichtbar, weil sie auf ein Deploymentproblem hinweisen.
  private function applyOwnership(string $path): void
  {
    if ($path === '' || !file_exists($path)) {
      return;
    }
    $user = $this->app->config('storage_owner_user');
    $group = $this->app->config('storage_owner_group');
    if (is_string($group) && $group !== '') {
      if (!chgrp($path, $group)) {
        $this->app->logger()->warning('chgrp failed', ['path' => $path, 'group' => $group]);
      }
    }
    if (is_string($user) && $user !== '') {
      if (!chown($path, $user)) {
        $this->app->logger()->warning('chown failed', ['path' => $path, 'user' => $user]);
      }
    }
  }

  // Spiegelt die letzte Wetter-Sidecar unter Soll- und Legacy-Dateinamen, damit
  // neue und noch nicht migrierte Leser denselben Messwert erhalten.
  public function mirrorWeatherSidecar(Camera $camera, string $weatherPath): void
  {
    if (!is_file($weatherPath)) {
      return;
    }
    try {
      $liveDir = (new CurrentImageDirectory(dirname(__DIR__, 3)))->prepare($camera->slug());
      $this->copyIfReadable($weatherPath, $liveDir . '/latest_wx.txt');
      $this->copyIfReadable($weatherPath, $liveDir . '/current_wx.txt');
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('weather current dir create failed', ['camera' => $camera->slug()]);
    }
    $baseRoot = $this->storagePaths->baseRoot($camera);
    $currentDirName = $camera->storageCurrentDir();
    $currentDir = $this->storagePaths->join($baseRoot, $currentDirName . '/' . $camera->slug());
    if (!is_dir($currentDir) && !mkdir($currentDir, 0775, true) && !is_dir($currentDir)) {
      $this->app->logger()->warning('weather mirror dir create failed', ['dir' => $currentDir, 'camera' => $camera->slug()]);
      return;
    }
    // Primär: <slug>/current/latest_wx.txt (Soll)
    $this->copyIfReadable($weatherPath, $currentDir . '/latest_wx.txt');
    // Kompatibilität: aktueller Name beibehalten, bis alle Consumer umgestellt sind
    $this->copyIfReadable($weatherPath, $currentDir . '/current_wx.txt');
  }

  /**
   * Ordnet Wetterzeilen einem bereits gespeicherten Capture-Slot zu.
   *
   * DB-Probleme werden geloggt; der Bildcapture selbst bleibt erhalten.
   *
   * @param list<string> $lines
   */
  public function storeWeatherLines(Camera $camera, int $slotTimestamp, array $lines): void
  {
    try {
      $store = new \Webcam\Infrastructure\Storage\CameraDataStore($this->app, $camera);
      $store->upsertWeatherForSlot($slotTimestamp, $lines);
    } catch (\Throwable $exception) {
      $this->app->logger()->warning('camera weather db write failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
    }
  }

  // -------------------------------------------------------------------------
  // Bildprüfung und technische Metadaten.
  // -------------------------------------------------------------------------

  // Dekodiert die Binärdaten mit GD. null kennzeichnet einen ungültigen oder auf
  // diesem Host nicht unterstützten Bildtyp.
  private function createImageResource(string $binary, ?Camera $camera = null): ?\GdImage
  {
    SnapshotResourceLimits::checkBytes(strlen($binary), $this->app);
    SnapshotResourceLimits::checkDimensions(@getimagesizefromstring($binary));
    $image = @imagecreatefromstring($binary);
    if (!$image) {
      $context = [];
      if ($camera !== null) {
        $context['camera'] = $camera->slug();
      }
      $this->app->logger()->warning('imagecreatefromstring failed', $context);
      return null;
    }
    return $image;
  }

  // Uploads liegen bereits als lokale JPEG-Datei vor. GD kann diese Quelle
  // direkt dekodieren, ohne parallel einen zweiten komprimierten PHP-String zu
  // halten. Andere Bildformate bleiben wie bisher sichtbar ungültig.
  private function createImageResourceFromFile(string $path, Camera $camera): ?\GdImage
  {
    $bytes = filesize($path);
    if ($bytes === false) {
      throw new RuntimeException('Snapshot source size unavailable');
    }
    SnapshotResourceLimits::checkBytes($bytes, $this->app);
    SnapshotResourceLimits::checkDimensions(@getimagesize($path));
    $image = @imagecreatefromjpeg($path);
    if (!$image) {
      $this->app->logger()->warning('imagecreatefromjpeg failed', [
        'camera' => $camera->slug(),
        'source' => basename($path),
      ]);
      return null;
    }
    return $image;
  }

  // Berechnet die mittlere Helligkeit über eine kleine Bildkopie. Das spart CPU
  // und reicht für die Entscheidung über die optionale Nacht-Darstellung.
  private function computeLuminance(\GdImage $image): ?float
  {
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width <= 0 || $height <= 0) {
      return null;
    }
    $sampleWidth = min(64, $width);
    $sampleHeight = max(1, (int) round($height * ($sampleWidth / $width)));
    $sample = imagescale($image, $sampleWidth, $sampleHeight);
    if (!$sample) {
      return null;
    }
    $sum = 0.0;
    for ($y = 0; $y < $sampleHeight; $y++) {
      for ($x = 0; $x < $sampleWidth; $x++) {
        $rgb = imagecolorat($sample, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $sum += ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
      }
    }
    imagedestroy($sample);
    $pixels = $sampleWidth * $sampleHeight;
    if ($pixels <= 0) {
      return null;
    }
    $average = $sum / $pixels;
    return round(($average / 255) * 100, 1);
  }

  // -------------------------------------------------------------------------
  // Zeitliche Zuordnung und Cache-Retention.
  // Der reale Aufnahmezeitpunkt bleibt erhalten; der Slot dient nur als stabile
  // Position in Timeline und Datenbank.
  // -------------------------------------------------------------------------

  // Ordnet die reale Aufnahme einem regelmäßigen Intervall-Slot zu. Liegt sie
  // knapp vor der nächsten Grenze, wird sie diesem nächsten Slot zugeordnet.
  private function alignTimestampToSlot(Camera $camera, int $timestamp): int
  {
    $tz = new DateTimeZone($this->app->config('timezone', 'UTC'));
    if ($this->matchesExtraTrigger($camera, $timestamp, $tz)) {
      return $timestamp;
    }
    $interval = (int) ($camera->capture()['interval_seconds'] ?? 600);
    $interval = max(60, $interval);
    $base = intdiv($timestamp, $interval) * $interval;
    if ($timestamp === $base) {
      return $timestamp;
    }
    $next = $base + $interval;
    $earlyThreshold = max(1, min(30, (int) round($interval * 0.05)));
    if ($next - $timestamp <= $earlyThreshold) {
      return $next;
    }
    return $base;
  }

  // Prüft tägliche HH:MM-Zusatzzeiten und einmalige Datumszeiten innerhalb einer
  // kleinen Toleranz, damit geplante Sonderaufnahmen nicht weggeschnappt werden.
  private function matchesExtraTrigger(Camera $camera, int $timestamp, DateTimeZone $tz): bool
  {
    $capture = $camera->capture();
    $entries = $capture['extra_times'] ?? [];
    if (is_string($entries)) {
      $entries = [$entries];
    }
    if (!is_array($entries) || !$entries) {
      return false;
    }
    $tolerance = 90;
    $reference = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
    foreach ($entries as $entry) {
      if (!is_string($entry)) {
        continue;
      }
      $entry = trim($entry);
      if ($entry === '') {
        continue;
      }
      if (preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})$/', $entry, $match)) {
        $due = $reference->setTime((int) $match['hour'], (int) $match['minute'], 0);
        if (abs($due->getTimestamp() - $timestamp) <= $tolerance) {
          return true;
        }
        $prev = $due->sub(new DateInterval('P1D'));
        if (abs($prev->getTimestamp() - $timestamp) <= $tolerance) {
          return true;
        }
        $next = $due->add(new DateInterval('P1D'));
        if (abs($next->getTimestamp() - $timestamp) <= $tolerance) {
          return true;
        }
        continue;
      }
      if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[ T](?<hour>\d{2}):(?<minute>\d{2})$/', $entry, $match)) {
        $due = DateTimeImmutable::createFromFormat(
          'Y-m-d H:i',
          sprintf(
            '%04d-%02d-%02d %02d:%02d',
            (int) $match['year'],
            (int) $match['month'],
            (int) $match['day'],
            (int) $match['hour'],
            (int) $match['minute']
          ),
          $tz
        );
        if ($due && abs($due->getTimestamp() - $timestamp) <= $tolerance) {
          return true;
        }
      }
    }
    return false;
  }

  /** @return callable(string):bool */
  private function jpegValidator(): callable
  {
    return static function (string $path): bool {
      $info = @getimagesize($path);
      return is_array($info) && ($info[2] ?? null) === IMAGETYPE_JPEG;
    };
  }

  // -------------------------------------------------------------------------
  // Datenbankabschluss.
  // Erst nachdem die Bilddateien veröffentlicht sind, werden ihre relativen
  // Pfade und Metadaten als gemeinsamer Capture-Datensatz eingetragen.
  // -------------------------------------------------------------------------

  /**
   * Persistiert die neue Aufnahme in der Kamera-SQLite.
   *
   * Absolute Arbeitsdateien werden dafür in relative öffentliche Pfade
   * übersetzt. Capture, Varianten, EXIF und Wetter gehen gemeinsam an
   * CameraDataStore; ein DB-Fehler wird geloggt, ohne die Bilddatei zu erfinden.
   *
   * @param array<int,string> $numericVariants
   * @param array<string,string> $namedVariants
   * @param array<string,string> $exifSummary
   */
  private function persistCaptureToDatabase(
    Camera $camera,
    string $relativePath,
    string $filename,
    array $numericVariants,
    array $namedVariants,
    array $exifSummary,
    ?string $weatherData,
    int $slotTimestamp,
    int $capturedAt,
    ?string $hash,
    ?float $luminance,
    bool $isDuplicate = false,
    bool $failOnError = false
  ): void {
    try {
      $store = new \Webcam\Infrastructure\Storage\CameraDataStore($this->app, $camera);
    } catch (\Throwable $exception) {
      $this->app->logger()->warning('camera db init failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return;
    }

    $variants = [];
    foreach ($namedVariants as $suffix => $path) {
      $variants[] = [
        'variant' => (string) $suffix,
        'relative_path' => $this->relativePathForStoredFile($camera, $relativePath, $filename . '_' . $suffix . '.jpg'),
        'width' => null,
        'height' => null,
        'size' => is_file($path) ? filesize($path) : null,
        'mtime' => is_file($path) ? filemtime($path) : null,
      ];
    }
    foreach ($numericVariants as $width => $path) {
      $variants[] = [
        'variant' => (string) $width,
        'relative_path' => $this->relativePathForStoredFile($camera, $relativePath, $filename . '_' . $width . '.jpg'),
        'width' => (int) $width,
        'height' => null,
        'size' => is_file($path) ? filesize($path) : null,
        'mtime' => is_file($path) ? filemtime($path) : null,
      ];
    }

    $weatherLines = null;
    if ($weatherData !== null) {
      $weatherLines = array_values(array_filter(
        array_map('trim', preg_split('/\\r?\\n/', $weatherData) ?: []),
        static fn(string $line): bool => $line !== ''
      ));
    }

    try {
      $store->recordCapture([
        'slot_timestamp' => $slotTimestamp,
        'captured_timestamp' => $capturedAt,
        'relative_path' => $relativePath . '/' . $filename . '.jpg',
        'luminance' => $luminance,
        'hash' => $hash,
        'is_duplicate' => $isDuplicate,
        'variants' => $variants,
        'exif' => $exifSummary,
        'weather_lines' => $weatherLines,
      ]);
      (new CaptureBackupScheduler($this->app))->schedule($camera);
    } catch (\Throwable $exception) {
      $this->app->logger()->warning('camera db write failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      if ($failOnError) {
        throw $exception;
      }
    }
  }

  // DB und APIs speichern nur relative Capture-Pfade. Der absolute Hostpfad der
  // gerade geschriebenen Datei darf diese Grenze nicht überschreiten.
  private function relativePathForStoredFile(Camera $camera, string $relativeDir, string $basename): string
  {
    $normalizedDir = trim($relativeDir, '/');
    $path = ($normalizedDir === '') ? $basename : $normalizedDir . '/' . $basename;
    return $path;
  }

  // Erstellt Eltern rekursiv und wendet anschließend die konfigurierte Ownership
  // an. Ein echter mkdir-Fehler bricht den Capture sichtbar ab.
  private function ensureDirectory(string $path): void
  {
    if (is_dir($path)) {
      return;
    }
    $parent = dirname($path);
    if (!is_dir($parent)) {
      $this->ensureDirectory($parent);
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
      throw new \RuntimeException("Unable to create target dir: {$path}");
    }
    $this->applyOwnership($path);
  }

}
