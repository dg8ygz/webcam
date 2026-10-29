<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use DirectoryIterator;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Eng begrenzter Legacy-Fallback für Archiveinträge, die nicht aus der
 * Kamera-DB geliefert werden können. Die Klasse scannt nur angeforderte
 * Jahres-/Tagespfade und übergibt Dupe-/Wetterregeln an den DB-Reader.
 */
final class ArchiveFilesystemReader
{
  public function __construct(
    private readonly App $app,
    private readonly TimelineMapper $timelineMapper,
    private readonly ArchiveDatabaseReader $databaseReader
  ) {
  }

  /**
   * @param list<string> $paths
   * @return list<int>
   */
  public function availableYears(array $paths): array
  {
    $years = [];
    foreach ($paths as $path) {
      if (!is_dir($path)) {
        continue;
      }
      foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && preg_match('/^\d{4}$/', $entry->getFilename())) {
          $years[] = (int) $entry->getFilename();
        }
      }
    }
    $years = array_values(array_unique($years));
    sort($years);
    return $years;
  }

  /**
   * @param list<string> $roots
   * @return list<int>
   */
  public function availableMonths(array $roots, int $year): array
  {
    $months = [];
    $yearName = sprintf('%04d', $year);
    foreach ($roots as $root) {
      $yearPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $yearName;
      if (!is_dir($yearPath)) {
        continue;
      }
      foreach (new DirectoryIterator($yearPath) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && preg_match('/^(0[1-9]|1[0-2])$/', $entry->getFilename())) {
          $months[] = (int) $entry->getFilename();
        }
      }
    }
    $months = array_values(array_unique($months));
    sort($months);
    return $months;
  }

  /** @return list<array<string,mixed>> */
  public function entriesForYear(
    Camera $camera,
    int $yearFilter,
    string $cacheRoot = '',
    string $currentRoot = '',
    string $archiveRoot = '',
    ?callable $progress = null,
    ?int $rangeFrom = null,
    ?int $rangeTo = null
  ): array {
    $entriesMap = [];
    $tz = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    $cachePrefix = 'cache/' . $camera->slug();
    $legacyCurrentPrefix = $camera->storageCurrentDir() . '/' . $camera->slug();
    $archivePrefix = $camera->storageArchiveDir() . '/' . $camera->slug();

    $sources = [
      [$cacheRoot, $cachePrefix, '', 'collect-cache'],
      [$currentRoot, $legacyCurrentPrefix, '', 'collect-current'],
      [$archiveRoot, $archivePrefix, $publicBaseArchive, 'collect-archive'],
    ];
    foreach ($sources as [$root, $prefix, $publicBase, $stage]) {
      if ($root === '' || !is_dir($root)) {
        continue;
      }
      if ($progress) {
        $progress($camera->slug(), $yearFilter, $stage, $root);
      }
      $this->collectSnapshots(
        $root,
        $prefix,
        $publicBase,
        $tz,
        $entriesMap,
        $yearFilter,
        $progress,
        $camera->slug(),
        $rangeFrom,
        $rangeTo
      );
    }

    $entries = $this->databaseReader->excludeDuplicates($camera, $this->finalizeEntries($entriesMap));
    return $this->databaseReader->suppressFlaggedWeather($camera, $entries);
  }

  /** @return list<array<string,mixed>> */
  public function entriesForDay(
    Camera $camera,
    int $year,
    int $month,
    int $day,
    string $cacheRoot,
    string $currentRoot,
    string $archiveRoot
  ): array {
    $entriesMap = [];
    $tz = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    $daySuffix = sprintf('%04d/%02d/%02d', $year, $month, $day);
    $relativeSuffix = sprintf('%04d/%02d/%02d/', $year, $month, $day);
    $sources = [
      [$cacheRoot, 'cache/' . $camera->slug(), ''],
      [$currentRoot, $camera->storageCurrentDir() . '/' . $camera->slug(), ''],
      [$archiveRoot, $camera->storageArchiveDir() . '/' . $camera->slug(), $publicBaseArchive],
    ];

    foreach ($sources as [$root, $prefix, $publicBase]) {
      if ($root === '') {
        continue;
      }
      $dayPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $daySuffix;
      if (!is_dir($dayPath)) {
        continue;
      }
      $this->collectFilesFromDayPath(
        $dayPath,
        $prefix . '/' . $relativeSuffix,
        $prefix,
        $publicBase,
        $tz,
        $entriesMap,
        $year,
        $month,
        $day
      );
    }

    $entries = $this->databaseReader->excludeDuplicates($camera, $this->finalizeEntries($entriesMap));
    return $this->databaseReader->suppressFlaggedWeather($camera, $entries);
  }

  /** @param array<string,array<string,mixed>> $entries */
  private function collectSnapshots(
    string $rootPath,
    string $prefix,
    string $publicBase,
    DateTimeZone $tz,
    array &$entries,
    ?int $yearFilter,
    ?callable $progress = null,
    ?string $slug = null,
    ?int $rangeFrom = null,
    ?int $rangeTo = null
  ): void {
    if (!is_dir($rootPath)) {
      return;
    }

    $yearDirectories = [];
    if ($yearFilter !== null) {
      $yearName = sprintf('%04d', $yearFilter);
      $candidate = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $yearName;
      if (is_dir($candidate)) {
        $yearDirectories[$yearName] = $candidate;
      }
    } else {
      foreach (new DirectoryIterator($rootPath) as $yearEntry) {
        if (!$yearEntry->isDot() && $yearEntry->isDir() && preg_match('/^\d{4}$/', $yearEntry->getFilename())) {
          $yearDirectories[$yearEntry->getFilename()] = $yearEntry->getPathname();
        }
      }
    }
    ksort($yearDirectories);

    foreach ($yearDirectories as $yearName => $yearPath) {
      $monthFloor = $rangeFrom !== null
        ? (int) (new DateTimeImmutable('@' . $rangeFrom))->setTimezone($tz)->format('Ym')
        : null;
      $dayFloor = $rangeFrom !== null
        ? (int) (new DateTimeImmutable('@' . $rangeFrom))->setTimezone($tz)->format('Ymd')
        : null;
      $monthCeil = $rangeTo !== null
        ? (int) (new DateTimeImmutable('@' . $rangeTo))->setTimezone($tz)->format('Ym')
        : null;
      $dayCeil = $rangeTo !== null
        ? (int) (new DateTimeImmutable('@' . $rangeTo))->setTimezone($tz)->format('Ymd')
        : null;

      foreach (new DirectoryIterator($yearPath) as $monthEntry) {
        if ($monthEntry->isDot() || !$monthEntry->isDir() || !preg_match('/^\d{2}$/', $monthEntry->getFilename())) {
          continue;
        }
        $monthName = $monthEntry->getFilename();
        $monthValue = (int) ($yearName . $monthName);
        if (($monthFloor !== null && $monthValue < $monthFloor) || ($monthCeil !== null && $monthValue > $monthCeil)) {
          continue;
        }
        if ($progress && $slug) {
          $progress($slug, (int) $yearName, 'month', $monthName);
        }
        foreach (new DirectoryIterator($monthEntry->getPathname()) as $dayEntry) {
          if ($dayEntry->isDot() || !$dayEntry->isDir() || !preg_match('/^\d{2}$/', $dayEntry->getFilename())) {
            continue;
          }
          $dayName = $dayEntry->getFilename();
          $dayValue = (int) ($yearName . $monthName . $dayName);
          if (($dayFloor !== null && $dayValue < $dayFloor) || ($dayCeil !== null && $dayValue > $dayCeil)) {
            continue;
          }
          $this->collectFilesFromDayPath(
            $dayEntry->getPathname(),
            $prefix . '/' . $yearName . '/' . $monthName . '/' . $dayName . '/',
            $prefix,
            $publicBase,
            $tz,
            $entries,
            (int) $yearName,
            (int) $monthName,
            (int) $dayName
          );
        }
      }
    }
  }

  /** @param array<string,array<string,mixed>> $entries */
  private function collectFilesFromDayPath(
    string $dayPath,
    string $relativeRoot,
    string $sourcePrefix,
    string $publicBase,
    DateTimeZone $tz,
    array &$entries,
    int $year,
    int $month,
    int $day
  ): void {
    foreach (new DirectoryIterator($dayPath) as $fileEntry) {
      if ($fileEntry->isDot() || $fileEntry->isDir()) {
        continue;
      }
      $filename = $fileEntry->getFilename();
      $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
      if (!in_array($extension, ['jpg', 'jpeg', 'txt'], true)) {
        continue;
      }
      $components = $this->parseCaptureName(
        (string) pathinfo($filename, PATHINFO_FILENAME),
        $extension,
        $year,
        $month,
        $day,
        $tz
      );
      if ($components === null) {
        continue;
      }

      $key = $components['key'];
      $entries[$key] ??= [
        'timestamp' => $components['timestamp'],
        'year' => $year,
        'month' => $month,
        'day' => $day,
        'time' => $components['time'],
        'time_label' => $components['time_label'],
        'datetime_label' => $components['datetime_label'],
        'datetime_iso' => $components['datetime_iso'],
        'source' => $sourcePrefix,
        'variants' => [],
        'relative_root' => $relativeRoot,
        'exif_path' => null,
        'exif_relative' => null,
        'weather_path' => null,
        'weather_relative' => null,
      ];

      if (!$components['is_image']) {
        if ($components['variant'] === 'ex') {
          $entries[$key]['exif_path'] = $fileEntry->getPathname();
          $entries[$key]['exif_relative'] = $relativeRoot . $filename;
        } elseif ($components['variant'] === 'wx') {
          $entries[$key]['weather_path'] = $fileEntry->getPathname();
          $entries[$key]['weather_relative'] = $relativeRoot . $filename;
        }
        continue;
      }

      $fullPath = $fileEntry->getPathname();
      if (!is_readable($fullPath) || $fileEntry->getSize() <= 0) {
        continue;
      }
      $relativePath = $relativeRoot . $filename;
      $url = ($publicBase === '' ? '' : $publicBase . '/') . $relativePath;
      $url = preg_replace('#/camera\.php/#', '/', $url) ?? $url;
      $entries[$key]['variants'][$this->normalizeVariant($components['variant'])] = [
        'relative_path' => $relativePath,
        'url' => $url,
        'filename' => $filename,
      ];
    }
  }

  /**
   * @param array<string,array<string,mixed>> $entriesMap
   * @return list<array<string,mixed>>
   */
  private function finalizeEntries(array $entriesMap): array
  {
    foreach ($entriesMap as &$entry) {
      $preferred = $this->timelineMapper->preferredVariant(array_keys($entry['variants']));
      if ($preferred !== null) {
        $entry['primary_variant'] = $preferred;
        $variantInfo = $entry['variants'][$preferred];
        $entry['relative_path'] = $variantInfo['relative_path'];
        $entry['preview_url'] = $variantInfo['url'];
        $entry['url'] = $variantInfo['url'];
      } else {
        $entry['primary_variant'] = null;
        $entry['relative_path'] = null;
        $entry['preview_url'] = null;
        $entry['url'] = null;
      }
      unset($entry['relative_root']);
    }
    unset($entry);

    $entries = array_values($entriesMap);
    usort($entries, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return $entries;
  }

  /** @return array<string,mixed>|null */
  private function parseCaptureName(
    string $name,
    string $extension,
    int $year,
    int $month,
    int $day,
    DateTimeZone $tz
  ): ?array {
    $extension = strtolower($extension);
    $isImage = in_array($extension, ['jpg', 'jpeg'], true);
    $variant = '';

    if (preg_match('/^(?<date>\d{8})_(?<time>\d{6})(?:_utc(?<epoch>[1-9]\d{0,10}))?(?:_(?<variant>[a-z0-9-]+))?$/i', $name, $matches)) {
      $variant = strtolower($matches['variant'] ?? '');
      $date = CaptureFilename::date($matches['date'] . $matches['time'], $tz, $matches['epoch'] ?? '');
      if (!$date) {
        return null;
      }
      $timestamp = $date->getTimestamp();
      $timeValue = $matches['time'];
    } elseif (preg_match('/^(?<time>\d{4})(?:(?<seconds>\d{2}))?(?:_utc(?<epoch>[1-9]\d{0,10}))?(?:_(?<variant>[a-z0-9-]+))?$/i', $name, $matches)) {
      $variant = strtolower($matches['variant'] ?? '');
      $hour = (int) substr($matches['time'], 0, 2);
      $minute = (int) substr($matches['time'], 2, 2);
      $second = isset($matches['seconds']) && $matches['seconds'] !== '' ? (int) $matches['seconds'] : 0;
      if ($hour > 23 || $minute > 59 || $second > 59) {
        return null;
      }
      $timeValue = sprintf('%02d%02d%02d', $hour, $minute, $second);
      $date = CaptureFilename::date(sprintf('%04d%02d%02d', $year, $month, $day) . $timeValue,
        $tz, $matches['epoch'] ?? '');
      if (!$date) {
        return null;
      }
      $timestamp = $date->getTimestamp();
    } else {
      return null;
    }

    if (!$isImage && $variant !== 'ex') {
      return null;
    }
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
    return [
      'key' => (string) $timestamp,
      'timestamp' => $timestamp,
      'time' => $timeValue,
      'time_label' => substr($timeValue, 0, 2) . ':' . substr($timeValue, 2, 2),
      'datetime_label' => $date->format('d.m.Y H:i'),
      'datetime_iso' => $date->format(DATE_ATOM),
      'variant' => $variant ?: 'original',
      'is_image' => $isImage,
    ];
  }

  private function normalizeVariant(string $variant): string
  {
    return match (strtolower($variant)) {
      '', 'orig', 'original' => 'original',
      'sm' => 'sm',
      'lm' => 'lm',
      'la' => 'la',
      'hu' => 'hu',
      'full' => 'full',
      default => strtolower($variant),
    };
  }
}
