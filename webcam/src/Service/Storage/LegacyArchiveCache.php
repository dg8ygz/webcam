<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Verwaltet ausschließlich den optionalen JSON-Cache historischer Archive.
 *
 * Dieser Cache ist ein explizites Legacy-/Diagnosewerkzeug und keine normale
 * Timeline-Datenquelle. Die Klasse bündelt Dateizugriff, Frischeprüfung,
 * Rebuild und Admin-Status. Das eigentliche JSON-Format übersetzt
 * LegacyArchiveCacheCodec; ArchiveBrowser orchestriert nur noch Datenquellen.
 */
final class LegacyArchiveCache
{
  private const VERSION = 1;
  private readonly LegacyArchiveCacheCodec $codec;

  public function __construct(
    private readonly App $app,
    private readonly ArchiveFilesystemReader $filesystemReader,
    private readonly ArchiveStoragePaths $storagePaths
  ) {
    // Der Codec ist ein reiner Formatübersetzer. Er wird hier zentral erzeugt,
    // damit Aufrufer nur den Cache-Dienst kennen müssen.
    $this->codec = new LegacyArchiveCacheCodec($app, $storagePaths);
  }

  public function isEnabled(): bool
  {
    return !empty($this->settings()['enabled']);
  }

  /**
   * Liest eine Jahresdatei und liefert normale Timeline-Einträge.
   *
   * null bedeutet bewusst „Cache nicht nutzbar“: deaktiviert, Datei fehlt oder
   * Format ist ungültig. Der ArchiveBrowser kann dann auf SQLite/Dateisystem
   * zurückfallen, ohne Cache-Fehler als leere Historie misszuverstehen.
   *
   * @return list<array<string,mixed>>|null
   */
  public function load(Camera $camera, int $year): ?array
  {
    if (!$this->isEnabled()) {
      return null;
    }
    $file = $this->fileForYear($camera, $year, ensureDir: false);
    if (!is_file($file)) {
      return null;
    }
    $payload = $this->readPayload($file, $camera);
    if ($payload === null || ($payload['version'] ?? null) !== self::VERSION) {
      return null;
    }
    $entries = $payload['entries'] ?? null;
    if (!is_array($entries)) {
      return null;
    }

    // Frühe Cache-Versionen speicherten bereits hydrierte Varianten. Beim
    // nächsten Lesen werden sie einmalig in das kompakte Format überführt.
    if ($this->codec->containsExpandedLegacyEntries($entries)) {
      $this->write($camera, $year, $entries);
      $entries = $this->codec->compress($entries);
    }
    return $this->codec->hydrate($camera, $entries);
  }

  /**
   * Schreibt ein vollständiges Cache-Jahr atomar.
   *
   * Der Cache bleibt optional: Auf einem Read-only-Deployment wird das
   * Schreiben übersprungen, während die eigentliche Timeline weiterarbeitet.
   *
   * @param list<array<string,mixed>> $entries
   */
  public function write(Camera $camera, int $year, array $entries): void
  {
    $file = $this->fileForYear($camera, $year);
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
      // Read-only Deployments dürfen ohne Archive-Cache weiterarbeiten.
      return;
    }
    $payload = [
      'version' => self::VERSION,
      'camera' => $camera->slug(),
      'year' => $year,
      'generated_at' => date(DATE_ATOM),
      'entries' => $this->codec->compress($entries),
    ];
    if (!\webcam_atomic_write_json($file, $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) {
      $this->app->logger()->warning('archive cache write failed', ['path' => $file, 'camera' => $camera->slug()]);
    }
    $this->removeLegacyFile($camera);
  }

  public function isFresh(Camera $camera, int $year): bool
  {
    $file = $this->fileForYear($camera, $year, ensureDir: false);
    if (!is_file($file)) {
      return false;
    }
    $mtime = filemtime($file);
    if ($mtime === false) {
      return false;
    }
    $isRecent = (time() - $mtime) < 3600;

    // Ein zeitlich frisches JSON kann trotzdem hinter der letzten Aufnahme
    // liegen. In diesem Fall erzwingt der Aufrufer einen Neuaufbau.
    $lastCaptureTs = $this->lastCaptureTimestamp($camera);
    if ($lastCaptureTs !== null && (int) gmdate('Y', $lastCaptureTs) === $year) {
      $maxCachedTs = $this->maxTimestamp($file);
      $captureInterval = (int) ($camera->capture()['interval_seconds'] ?? 600);
      $tolerance = max(60, $captureInterval);
      if ($maxCachedTs === null || $lastCaptureTs > ($maxCachedTs + $tolerance)) {
        return false;
      }
    }
    return $isRecent;
  }

  /**
   * Sucht im kompakten Cache per binärer Suche den nächsten Zeitstempel.
   *
   * @return array<string,mixed>|null
   */
  public function nearest(Camera $camera, int $year, int $targetTs): ?array
  {
    if (!$this->isEnabled()) {
      return null;
    }
    $file = $this->fileForYear($camera, $year, ensureDir: false);
    if (!is_file($file)) {
      return null;
    }
    $payload = $this->readPayload($file, $camera);
    if ($payload === null || ($payload['version'] ?? null) !== self::VERSION) {
      return null;
    }
    $entries = $payload['entries'] ?? null;
    if (!is_array($entries) || $entries === []) {
      return null;
    }

    $bestIndex = $this->nearestIndex($entries, $targetTs);
    if ($bestIndex === null || !isset($entries[$bestIndex])) {
      return null;
    }
    $hydrated = $this->codec->hydrate($camera, [$entries[$bestIndex]]);
    return $hydrated[0] ?? null;
  }

  /**
   * Bestimmt den Listenindex mit dem kleinsten Zeitabstand.
   *
   * Reguläre Cache-Dateien sind chronologisch sortiert; dort genügt eine
   * binäre Suche plus Vergleich der beiden Nachbarn. Für beschädigte oder sehr
   * alte unsortierte Formate bleibt eine lineare, aber sichere Gegenprobe.
   *
   * @param list<array<string,mixed>> $entries
   */
  private function nearestIndex(array $entries, int $targetTs): ?int
  {
    $extractTimestamp = static function ($entry): ?int {
      if (!is_array($entry)) {
        return null;
      }
      $timestamp = $entry['ts'] ?? $entry['timestamp'] ?? null;
      return is_numeric($timestamp) ? (int) $timestamp : null;
    };

    $count = count($entries);
    $firstTs = $extractTimestamp($entries[0]);
    $lastTs = $extractTimestamp($entries[$count - 1]);
    if ($firstTs === null || $lastTs === null || $firstTs > $lastTs) {
      return $this->nearestIndexLinear($entries, $targetTs, $extractTimestamp);
    }

    $low = 0;
    $high = $count - 1;
    while ($low <= $high) {
      $middle = intdiv($low + $high, 2);
      $middleTs = $extractTimestamp($entries[$middle]);
      if ($middleTs === null) {
        // Ein ungültiger Eintrag hebt die Sortiergarantie auf. Die lineare
        // Suche ist für diesen seltenen Alt-/Fehlerfall verlässlicher.
        return $this->nearestIndexLinear($entries, $targetTs, $extractTimestamp);
      }
      if ($middleTs === $targetTs) {
        return $middle;
      }
      if ($middleTs < $targetTs) {
        $low = $middle + 1;
      } else {
        $high = $middle - 1;
      }
    }

    // Nach der Suche liegen high und low links/rechts vom Ziel. Nur diese
    // beiden Kandidaten können bei sortierten Daten der nächste Treffer sein.
    return $this->nearestCandidate([$high, $low], $entries, $targetTs, $extractTimestamp);
  }

  /**
   * Sichere Ersatzsuche für alte oder unvollständige Cache-Dateien.
   *
   * @param list<array<string,mixed>> $entries
   * @param callable(mixed):?int $extractTimestamp
   */
  private function nearestIndexLinear(array $entries, int $targetTs, callable $extractTimestamp): ?int
  {
    return $this->nearestCandidate(array_keys($entries), $entries, $targetTs, $extractTimestamp);
  }

  /**
   * Vergleicht eine begrenzte Indexmenge und liefert den engsten Treffer.
   *
   * @param list<int> $indices
   * @param list<array<string,mixed>> $entries
   * @param callable(mixed):?int $extractTimestamp
   */
  private function nearestCandidate(
    array $indices,
    array $entries,
    int $targetTs,
    callable $extractTimestamp
  ): ?int {
    $bestIndex = null;
    $bestDiff = PHP_INT_MAX;
    foreach (array_unique($indices) as $index) {
      if ($index < 0 || $index >= count($entries)) {
        continue;
      }
      $timestamp = $extractTimestamp($entries[$index]);
      if ($timestamp === null) {
        continue;
      }
      $diff = abs($targetTs - $timestamp);
      if ($diff < $bestDiff) {
        $bestDiff = $diff;
        $bestIndex = (int) $index;
      }
    }
    return $bestIndex;
  }

  /**
   * Baut den Cache nur für ausdrücklich gewählte beziehungsweise vorhandene
   * Jahre neu. Normale Timeline-Requests rufen diese Methode nicht auf.
   *
   * @param list<int>|null $years
   * @param callable|null $progress function(string $slug, int $year, string $stage, ?string $context = null): void
   * @return array<string,mixed>
   */
  public function rebuild(Camera $camera, ?array $years = null, ?callable $progress = null): array
  {
    $basePath = $this->storagePaths->baseRoot($camera);
    $currentRoot = $this->storagePaths->join(
      $basePath,
      $camera->storageCurrentDir() . '/' . $camera->slug()
    );
    $archiveRoot = $this->storagePaths->join(
      $basePath,
      $camera->storageArchiveDir() . '/' . $camera->slug()
    );
    try {
      $cacheRoot = $this->app->paths()->cachePath($camera->slug());
    } catch (\Throwable) {
      $cacheRoot = $currentRoot;
    }

    $availableYears = $this->filesystemReader->availableYears([$cacheRoot, $currentRoot, $archiveRoot]);
    $targetYears = $years === null
      ? $availableYears
      : array_values(array_intersect($availableYears, array_map('intval', $years)));
    $result = [
      'version' => self::VERSION,
      'camera' => $camera->slug(),
      'generated_at' => date(DATE_ATOM),
      'years' => $availableYears,
      'built_years' => [],
    ];

    foreach ($targetYears as $year) {
      if ($progress !== null) {
        $progress($camera->slug(), (int) $year, 'start', null);
      }
      $entries = $this->filesystemReader->entriesForYear(
        $camera,
        (int) $year,
        (string) $cacheRoot,
        $currentRoot,
        $archiveRoot,
        $progress
      );
      $this->write($camera, (int) $year, $entries);
      $result['built_years'][] = (int) $year;
      if ($progress !== null) {
        $progress($camera->slug(), (int) $year, 'done', (string) count($entries));
      }
    }
    return $result;
  }

  /**
   * Beschreibt ausschließlich den optionalen Legacy-Cache für die Admin-Anzeige.
   *
   * @return array<string,mixed>
   */
  public function status(?Camera $camera = null): array
  {
    $root = $camera ? $this->cameraDirPath($camera) : $this->root();
    $settings = $this->settings();
    $state = $this->readState($camera?->slug());
    $lastBuilt = $camera
      ? ($settings['last_built_at'][$camera->slug()] ?? null)
      : ($settings['last_built_at_all'] ?? $settings['last_built_at'] ?? null);
    if ($lastBuilt === null && is_array($state)) {
      $lastBuilt = $state['finished_at'] ?? null;
    }
    if ($lastBuilt === null && $camera !== null) {
      $lastBuilt = $this->latestMtime($root);
    }
    return [
      'enabled' => !empty($settings['enabled']),
      'root' => $root,
      'size_bytes' => $this->directorySize($root),
      'scope' => $camera?->slug() ?? 'all',
      'last_built_at' => $lastBuilt,
      'rebuild_state' => $state,
    ];
  }

  private function root(): string
  {
    $path = $this->app->paths()->statePath('archive-cache');
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
      $this->app->logger()->warning('archive cache root create failed', ['dir' => $path]);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
  }

  private function cameraDirPath(Camera $camera): string
  {
    return $this->root() . DIRECTORY_SEPARATOR . $camera->slug();
  }

  private function cameraDir(Camera $camera): string
  {
    $dir = $this->cameraDirPath($camera);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      $this->app->logger()->warning('archive cache dir create failed', ['dir' => $dir, 'camera' => $camera->slug()]);
    }
    return $dir;
  }

  private function fileForYear(Camera $camera, int $year, bool $ensureDir = true): string
  {
    $dir = $ensureDir ? $this->cameraDir($camera) : $this->cameraDirPath($camera);
    return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $year . '.json';
  }

  /** @return array<string,mixed>|null */
  private function readPayload(string $file, Camera $camera): ?array
  {
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
      if ($raw === false) {
        $this->app->logger()->warning('archive cache read failed', ['path' => $file, 'camera' => $camera->slug()]);
      }
      return null;
    }
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : null;
  }

  private function lastCaptureTimestamp(Camera $camera): ?int
  {
    try {
      $stateFile = $this->app->paths()->statePath('cameras/' . $camera->slug() . '.json');
    } catch (\Throwable) {
      return null;
    }
    if (!is_file($stateFile)) {
      return null;
    }
    $raw = file_get_contents($stateFile);
    if ($raw === false || $raw === '') {
      if ($raw === false) {
        $this->app->logger()->warning('archive state read failed', ['path' => $stateFile, 'camera' => $camera->slug()]);
      }
      return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
      return null;
    }
    $timestamp = $data['last_capture_at'] ?? $data['last_success_at'] ?? null;
    return is_numeric($timestamp) ? (int) $timestamp : null;
  }

  private function maxTimestamp(string $cacheFile): ?int
  {
    $raw = file_get_contents($cacheFile);
    if ($raw === false || $raw === '') {
      if ($raw === false) {
        $this->app->logger()->warning('archive cache read failed', ['path' => $cacheFile]);
      }
      return null;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['entries']) || !is_array($payload['entries'])) {
      return null;
    }
    $max = null;
    foreach ($payload['entries'] as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $timestamp = $entry['ts'] ?? $entry['timestamp'] ?? null;
      if (!is_numeric($timestamp)) {
        continue;
      }
      $timestamp = (int) $timestamp;
      if ($max === null || $timestamp > $max) {
        $max = $timestamp;
      }
    }
    return $max;
  }

  private function removeLegacyFile(Camera $camera): void
  {
    $legacy = $this->root() . DIRECTORY_SEPARATOR . $camera->slug() . '.json';
    if (is_file($legacy) && !unlink($legacy)) {
      $this->app->logger()->warning('legacy cache delete failed', ['path' => $legacy, 'camera' => $camera->slug()]);
    }
  }

  /** @return array<string,mixed> */
  private function settings(): array
  {
    $meta = $this->app->camerasMeta();
    return is_array($meta['archive_cache'] ?? null) ? $meta['archive_cache'] : [];
  }

  private function latestMtime(string $dir): ?string
  {
    if (!is_dir($dir)) {
      return null;
    }
    $max = null;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $max = $max === null ? $file->getMTime() : max($max, $file->getMTime());
      }
    }
    return $max ? gmdate('c', (int) $max) : null;
  }

  private function directorySize(string $dir): int
  {
    if (!is_dir($dir)) {
      return 0;
    }
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $size += $file->getSize();
      }
    }
    return $size;
  }

  /** @return array<string,mixed>|null */
  private function readState(?string $slug): ?array
  {
    $file = $slug ? "archive-cache/rebuild_{$slug}.json" : 'archive-cache/rebuild.json';
    $path = $this->app->paths()->statePath($file);
    if (!is_file($path)) {
      return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
      $this->app->logger()->warning('archive cache state read failed', ['path' => $path]);
      return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }
}
