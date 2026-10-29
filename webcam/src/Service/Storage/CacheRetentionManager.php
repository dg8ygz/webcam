<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Entfernt abgelaufene Cache-Tage ausschließlich nach Archiv-Gegenprüfung.
 *
 * Der Manager arbeitet fail-closed: Fehlt der Archivmount oder ist auch nur
 * eine stabile Variante nicht bestätigt, bleibt der komplette Tag erhalten.
 * Ein kleiner, pro Kamera gesperrter Tagesstatus verhindert wiederholte teure
 * Prüfungen durch parallele Captures.
 */
final class CacheRetentionManager
{
  public function __construct(private readonly App $app) {}

  public function run(Camera $camera, string $cacheRoot, int $cutoffTimestamp): void
  {
    // Pilotabnahme: auch den historischen Bielefeld-Bestand nicht loeschen.
    // Abschalten neuer Pilotuploads hebt diesen Schutz nicht automatisch auf.
    if ($this->app->deferredImages()->enabled($camera)
      || ($this->app->config('deferred_image_root') && is_dir(rtrim($this->app->config('deferred_image_root'), '/') . '/' . $camera->slug()))) {
      $this->app->logger()->info('deferred image retention report only',
        ['camera' => $camera->slug()] + $this->app->deferredImages()->retentionReport($camera, time()));
      return;
    }
    if (!$this->claimDailyRun($camera)) {
      return;
    }
    $this->purge($camera, $cacheRoot, $cutoffTimestamp);
  }

  private function purge(Camera $camera, string $cacheRoot, int $cutoffTimestamp): void
  {
    if (!is_dir($cacheRoot)) {
      return;
    }
    try {
      (new ArchiveMountGuard($this->app))->assertAvailable('cache retention');
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('cache retention archive mount guard failed', [
        'camera' => $camera->slug(),
        'archive_dir' => $this->app->paths()->archivePath(),
        'reason' => $exception->getMessage(),
      ]);
      return;
    }

    $verifier = new ArchiveCompletenessVerifier($this->app);
    $scanStarted = microtime(true);
    $days = $this->dayDirectories($cacheRoot, $cutoffTimestamp);
    $this->app->logger()->info('cache retention expired day scan', [
      'camera' => $camera->slug(),
      'candidate_days' => count($days),
      'elapsed_ms' => (int) round((microtime(true) - $scanStarted) * 1000),
    ]);
    foreach ($days as $relativeDay => $cacheDay) {
      if (!$this->contentsAreOlderThan($cacheDay, $cutoffTimestamp)) {
        continue;
      }
      $archiveDay = $this->app->paths()->archivePath($camera->slug() . '/' . $relativeDay);
      $verification = $verifier->verifyDay($cacheDay, $archiveDay);
      if (!$verification['complete']) {
        $this->app->logger()->warning('cache retention archive verification failed', [
          'camera' => $camera->slug(),
          'day' => str_replace('/', '-', $relativeDay),
          'cache_dir' => $cacheDay,
          'archive_dir' => $archiveDay,
          'reason' => $verification['reason'],
        ]);
        continue;
      }
      if (!$this->removeTree($cacheDay)) {
        $this->app->logger()->warning('cache retention verified day delete failed', [
          'camera' => $camera->slug(),
          'day' => str_replace('/', '-', $relativeDay),
          'path' => $cacheDay,
        ]);
        continue;
      }
      $this->app->logger()->info('cache retention removed verified archive day', [
        'camera' => $camera->slug(),
        'day' => str_replace('/', '-', $relativeDay),
        'captures' => $verification['captures'],
      ]);
    }
    $this->purgeLooseFiles($camera, $cacheRoot, $cutoffTimestamp);
  }

  /** Nicht datierte Ableitungen bleiben mtime-basiert; Overlays sind geschützt. */
  private function purgeLooseFiles(Camera $camera, string $cacheRoot, int $cutoffTimestamp): void
  {
    foreach ($this->looseFiles($cacheRoot) as $item) {
      $path = $item->getPathname();
      if ($item->isDir()) {
        try {
          $hasEntries = (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS))->valid();
        } catch (UnexpectedValueException $exception) {
          $this->app->logger()->warning('cache dir cleanup failed', ['path' => $path, 'error' => $exception->getMessage()]);
          continue;
        }
        if (!$hasEntries && !rmdir($path)) {
          $this->app->logger()->warning('cache dir cleanup failed', ['path' => $path]);
        }
      } elseif (($item->getMTime() ?: PHP_INT_MAX) < $cutoffTimestamp && !unlink($path)) {
        $this->app->logger()->warning('cache file cleanup failed', ['path' => $path]);
      }
    }
  }

  /**
   * Ausschluss VOR getChildren(): Ein continue im CHILD_FIRST-Durchlauf kommt
   * zu spaet und liest trotz Loeschschutz jeden Eintrag des gesamten Caches.
   * Datierte Baeume prueft ausschliesslich purge() mit Archiv-Gegenpruefung.
   */
  private function looseFiles(string $cacheRoot): RecursiveIteratorIterator
  {
    $prefixLength = strlen(rtrim($cacheRoot, DIRECTORY_SEPARATOR)) + 1;
    $filtered = new RecursiveCallbackFilterIterator(
      new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
      static function (\SplFileInfo $item) use ($prefixLength): bool {
        $relative = substr($item->getPathname(), $prefixLength);
        $first = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
        return $first !== 'overlays' && $first !== 'current'
          && preg_match('/^\d{4}$/D', $first) !== 1
          && !$item->isLink();
      }
    );
    return new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::CHILD_FIRST);
  }

  /** @return array<string,string> */
  private function dayDirectories(string $cacheRoot, int $cutoffTimestamp): array
  {
    // Do not enumerate files in the retained 180-day window over SMB. A day
    // must have ended before the cutoff in the configured local timezone.
    // File mtimes and the complete archive proof remain mandatory afterwards.
    $cutoffDay = (new DateTimeImmutable('@' . $cutoffTimestamp))
      ->setTimezone(new DateTimeZone((string) $this->app->config('timezone', 'UTC')))
      ->format('Y/m/d');
    $root = rtrim($cacheRoot, DIRECTORY_SEPARATOR);
    $days = [];
    foreach (glob($root . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [] as $yearPath) {
      $year = basename($yearPath);
      if ($year > substr($cutoffDay, 0, 4) || is_link($yearPath)) {
        continue;
      }
      foreach (glob($yearPath . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $monthPath) {
        $month = basename($monthPath);
        if ($year . '/' . $month > substr($cutoffDay, 0, 7) || is_link($monthPath)) {
          continue;
        }
        foreach (glob($monthPath . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $path) {
          $day = basename($path);
          $relative = $year . '/' . $month . '/' . $day;
          if ($relative < $cutoffDay && checkdate((int) $month, (int) $day, (int) $year) && !is_link($path)) {
            $days[$relative] = $path;
          }
        }
      }
    }
    ksort($days);
    return $days;
  }

  private function contentsAreOlderThan(string $directory, int $cutoffTimestamp): bool
  {
    $hasFiles = false;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $item) {
      if (!$item->isFile()) {
        continue;
      }
      $hasFiles = true;
      if (($item->getMTime() ?: PHP_INT_MAX) >= $cutoffTimestamp) {
        return false;
      }
    }
    return $hasFiles;
  }

  private function removeTree(string $path): bool
  {
    if (is_file($path) || is_link($path)) {
      return @unlink($path);
    }
    foreach (new \DirectoryIterator($path) as $entry) {
      if (!$entry->isDot() && !$this->removeTree($entry->getPathname())) {
        return false;
      }
    }
    return @rmdir($path);
  }

  private function claimDailyRun(Camera $camera): bool
  {
    try {
      $stateDirectory = $this->app->paths()->statePath('cache-retention');
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('cache retention state path unavailable', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return false;
    }
    if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory)) {
      $this->app->logger()->warning('cache retention state dir create failed', ['camera' => $camera->slug(), 'dir' => $stateDirectory]);
      return false;
    }
    $stateFile = $stateDirectory . DIRECTORY_SEPARATOR . $camera->slug() . '.json';
    $handle = @fopen($stateFile, 'c+');
    if (!is_resource($handle)) {
      $this->app->logger()->warning('cache retention state open failed', ['camera' => $camera->slug(), 'path' => $stateFile]);
      return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
      fclose($handle);
      return false;
    }
    try {
      rewind($handle);
      $state = json_decode((string) stream_get_contents($handle), true);
      $today = (new DateTimeImmutable('now', new DateTimeZone((string) $this->app->config('timezone', 'UTC'))))->format('Y-m-d');
      if (is_array($state) && ($state['last_run_date'] ?? null) === $today) {
        return false;
      }
      $payload = json_encode(['camera' => $camera->slug(), 'last_run_date' => $today, 'last_run_at' => time()], JSON_UNESCAPED_SLASHES);
      if (!is_string($payload)) {
        return false;
      }
      ftruncate($handle, 0);
      rewind($handle);
      if (fwrite($handle, $payload . "\n") === false) {
        $this->app->logger()->warning('cache retention state write failed', ['camera' => $camera->slug(), 'path' => $stateFile]);
        return false;
      }
      fflush($handle);
      return true;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }
}
