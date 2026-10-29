<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Findet die Quellslots für einen Kamera-DB-Backfill.
 *
 * Normale Installationen lesen Timeline-Einträge DB-first über ArchiveBrowser.
 * Nur ein ausdrücklich gesetztes --legacy-root aktiviert den rekursiven
 * Dateisystemscanner. Monats-/Tagesfilter und Zeitzone werden an dieser einen
 * Stelle angewendet, damit die CLI keine Archivdetails kennen muss.
 */
final class CameraDatabaseMigrationSource
{
  public function __construct(
    private readonly App $app,
    private readonly ArchiveBrowser $archiveBrowser,
    private readonly CameraDatabaseMigrationPayloadBuilder $payloadBuilder
  ) {
  }

  /** @return list<int> */
  public function availableYears(Camera $camera, ?string $legacyRoot): array
  {
    if ($legacyRoot !== null) {
      return $this->listLegacyYears($legacyRoot, $camera->slug());
    }

    $years = $this->archiveBrowser->listYears($camera);
    $result = array_map('intval', array_merge($years['current'], $years['archive']));
    $result = array_values(array_unique($result));
    sort($result);
    return $result;
  }

  /**
   * @param list<int> $monthsFilter
   * @param list<int> $daysFilter
   * @return list<array<string,mixed>>
   */
  public function loadYearEntries(
    Camera $camera,
    int $year,
    ?string $legacyRoot,
    array $monthsFilter,
    array $daysFilter,
    bool $verbose
  ): array {
    $slug = $camera->slug();
    if ($legacyRoot !== null) {
      if ($verbose) {
        fwrite(STDOUT, "  [INFO] {$slug} {$year}: scan legacy files...\n");
      }
      return $this->collectLegacyEntries(
        $legacyRoot,
        $camera,
        $year,
        $verbose,
        $monthsFilter,
        $daysFilter
      );
    }

    if ($verbose) {
      fwrite(STDOUT, "  [INFO] {$slug} {$year}: lade Timeline...\n");
    }
    $timezone = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $now = new DateTimeImmutable('now', $timezone);
    $currentYear = (int) $now->format('Y');
    $currentMonth = (int) $now->format('m');

    // Vollständig abgeschlossene Jahre können in einer einzigen DB-Abfrage
    // geladen werden. Aktuelle oder explizit gefilterte Monate bleiben bewusst
    // begrenzt, damit kein unnötiger Archivscan entsteht.
    if ($monthsFilter === [] && $year !== $currentYear) {
      $timeline = $this->archiveBrowser->timeline($camera, $year);
      return $timeline['entries'] ?? [];
    }

    $monthsToLoad = $monthsFilter;
    if ($monthsToLoad === []) {
      $monthsToLoad = range(1, $year === $currentYear ? $currentMonth : 12);
    }

    $entries = [];
    foreach ($monthsToLoad as $month) {
      if ($month < 1 || $month > 12) {
        continue;
      }
      if ($year === $currentYear && $month === $currentMonth) {
        if ($verbose) {
          fwrite(STDOUT, sprintf(
            "    [INFO] %s %04d-%02d: lade Tagesranges...\n",
            $slug,
            $year,
            $month
          ));
        }
        foreach ($this->dayRangesForMonth($year, $month, $daysFilter) as [$from, $to, $day]) {
          $timeline = $this->archiveBrowser->timeline($camera, $year, $from, $to);
          array_push($entries, ...($timeline['entries'] ?? []));
          if ($verbose) {
            fwrite(STDOUT, sprintf(
              "      [INFO] %s %04d-%02d-%02d: %d Einträge\n",
              $slug,
              $year,
              $month,
              $day,
              count($timeline['entries'] ?? [])
            ));
          }
        }
        continue;
      }

      [$from, $to] = $this->monthRangeTimestamps($year, $month);
      if ($verbose) {
        fwrite(STDOUT, sprintf(
          "    [INFO] %s %04d-%02d: lade Monatsrange...\n",
          $slug,
          $year,
          $month
        ));
      }
      $timeline = $this->archiveBrowser->timeline($camera, $year, $from, $to);
      foreach (($timeline['entries'] ?? []) as $entry) {
        if ($daysFilter !== [] && !in_array((int) ($entry['day'] ?? 0), $daysFilter, true)) {
          continue;
        }
        $entries[] = $entry;
      }
    }
    return $entries;
  }

  /** @return array{0:int,1:int} */
  private function monthRangeTimestamps(int $year, int $month): array
  {
    $timezone = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $from = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone);
    $to = $from->modify('last day of this month')->setTime(23, 59, 59);
    return [$from->getTimestamp(), $to->getTimestamp()];
  }

  /**
   * @param list<int> $daysFilter
   * @return list<array{0:int,1:int,2:int}>
   */
  private function dayRangesForMonth(int $year, int $month, array $daysFilter): array
  {
    $timezone = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $timezone);
    $lastDay = (int) $firstDay->format('t');
    $today = new DateTimeImmutable('now', $timezone);
    if ((int) $today->format('Y') === $year && (int) $today->format('m') === $month) {
      $lastDay = min($lastDay, (int) $today->format('d'));
    }

    $ranges = [];
    for ($day = 1; $day <= $lastDay; $day++) {
      if ($daysFilter !== [] && !in_array($day, $daysFilter, true)) {
        continue;
      }
      $current = $firstDay->setDate($year, $month, $day);
      $ranges[] = [
        $current->setTime(0, 0, 0)->getTimestamp(),
        $current->setTime(23, 59, 59)->getTimestamp(),
        $day,
      ];
    }
    return $ranges;
  }

  /** @return list<int> */
  private function listLegacyYears(string $legacyRoot, string $slug): array
  {
    $base = realpath($legacyRoot);
    if ($base === false) {
      return [];
    }
    $path = is_dir($base . DIRECTORY_SEPARATOR . $slug)
      ? $base . DIRECTORY_SEPARATOR . $slug
      : $base;
    $years = [];
    foreach (new \DirectoryIterator($path) as $directory) {
      if ($directory->isDot() || !$directory->isDir()) {
        continue;
      }
      if (preg_match('/^\d{4}$/', $directory->getFilename())) {
        $years[] = (int) $directory->getFilename();
      }
    }
    sort($years);
    return $years;
  }

  /**
   * Expliziter Legacy-Fallback: gruppiert JPEG- und Textdateien nach Slot.
   *
   * @param list<int> $monthsFilter
   * @param list<int> $daysFilter
   * @return list<array<string,mixed>>
   */
  private function collectLegacyEntries(
    string $legacyRoot,
    Camera $camera,
    int $year,
    bool $verbose,
    array $monthsFilter,
    array $daysFilter
  ): array {
    $base = realpath($legacyRoot);
    if ($base === false) {
      return [];
    }
    $slug = $camera->slug();
    $root = is_dir($base . DIRECTORY_SEPARATOR . $slug)
      ? $base . DIRECTORY_SEPARATOR . $slug
      : $base;
    $yearPath = $root . DIRECTORY_SEPARATOR . sprintf('%04d', $year);
    if (!is_dir($yearPath)) {
      return [];
    }

    $timezone = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $relativePrefix = $this->legacyRelativePrefix($camera, $root);
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($yearPath, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY
    );

    $currentMonth = null;
    $bySlot = [];
    foreach ($files as $file) {
      /** @var SplFileInfo $file */
      if (!$file->isFile()) {
        continue;
      }
      $relativePath = substr($file->getPathname(), strlen($root) + 1);
      if (!preg_match(
        '#^(\d{4})/(\d{2})/(\d{2})/(\d{4,6})(?:_utc([1-9]\d{0,10}))?(?:_(\w+))?\.(jpg|jpeg|txt)$#i',
        $relativePath,
        $match
      )) {
        continue;
      }
      [, $yearValue, $monthValue, $dayValue, $timeValue, $epoch, $variant, $extension] = $match;
      if ($monthsFilter !== [] && !in_array((int) $monthValue, $monthsFilter, true)) {
        continue;
      }
      if ($daysFilter !== [] && !in_array((int) $dayValue, $daysFilter, true)) {
        continue;
      }
      if ($verbose && $monthValue !== $currentMonth) {
        $currentMonth = $monthValue;
        fwrite(STDOUT, "    [SCAN] {$slug} {$year}-{$monthValue}\n");
      }

      $date = CaptureFilename::date($yearValue . $monthValue . $dayValue . str_pad($timeValue, 6, '0'), $timezone, $epoch);
      $timestamp = $date?->getTimestamp();
      if ($timestamp === null) {
        continue;
      }

      if (!isset($bySlot[$timestamp])) {
        $bySlot[$timestamp] = [
          'timestamp' => $timestamp,
          'year' => (int) $yearValue,
          'month' => (int) $monthValue,
          'day' => (int) $dayValue,
          'time' => $timeValue,
          'variants' => [],
          'exif' => null,
          'weather_lines' => [],
        ];
      }
      $slot = &$bySlot[$timestamp];
      $variant = strtolower((string) $variant);
      $extension = strtolower($extension);
      $storageRelativePath = str_starts_with($relativePath, $slug . '/')
        ? $relativePath
        : $relativePrefix . '/' . $relativePath;
      $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;

      if ($extension === 'txt' && $variant === 'ex') {
        $slot['exif'] = $this->payloadBuilder->parseExifFile($absolutePath);
        $slot['exif_relative'] = $storageRelativePath;
      } elseif ($extension === 'txt' && in_array($variant, ['wx', 'wxt', 'weather'], true)) {
        $slot['weather_lines'] = $this->payloadBuilder->parseWeatherFile($absolutePath);
        $slot['weather_relative'] = $storageRelativePath;
      } elseif (in_array($extension, ['jpg', 'jpeg'], true)) {
        $variantKey = $variant !== '' ? $variant : 'full';
        $slot['variants'][$variantKey] = [
          'url' => null,
          'relative_path' => $storageRelativePath,
        ];
        if ($variantKey === 'full') {
          $slot['relative_path'] = $storageRelativePath;
        }
      }
      unset($slot);
    }

    $entries = [];
    foreach ($bySlot as $slot) {
      if (empty($slot['variants']) && empty($slot['relative_path'])) {
        continue;
      }
      if (!isset($slot['relative_path'])) {
        foreach (['la', 'lm', 'sm', 'full', 'hu'] as $preferred) {
          if (isset($slot['variants'][$preferred])) {
            $slot['relative_path'] = $slot['variants'][$preferred]['relative_path'];
            break;
          }
        }
      }
      $entries[] = $slot;
    }
    usort($entries, static fn(array $left, array $right): int =>
      ($left['timestamp'] ?? 0) <=> ($right['timestamp'] ?? 0)
    );
    return $entries;
  }

  private function legacyRelativePrefix(Camera $camera, string $root): string
  {
    try {
      $cacheRoot = realpath($this->app->paths()->cachePath($camera->slug()));
      if ($cacheRoot !== false && (
        $root === $cacheRoot
        || str_starts_with($root . DIRECTORY_SEPARATOR, $cacheRoot . DIRECTORY_SEPARATOR)
      )) {
        return 'cache/' . $camera->slug();
      }
    } catch (Throwable) {
      // Ein fremdes Legacy-Root gehört standardmäßig zum Archivpfad.
    }
    return trim($camera->storageArchiveDir(), '/') . '/' . $camera->slug();
  }
}
