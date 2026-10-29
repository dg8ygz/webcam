<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Liest Archive-Metadaten aus der Kamera-DB und hydriert DB-Zeilen zu den von
 * der View erwarteten Archiveinträgen. Dateisystemzugriffe dienen hier nur der
 * Prüfung beziehungsweise Auflösung eines in der DB gespeicherten Bildpfads.
 */
final class ArchiveDatabaseReader
{
  public function __construct(
    private readonly App $app,
    private readonly TimelineMapper $timelineMapper
  ) {
  }

  /** @return list<int>|null */
  public function years(Camera $camera): ?array
  {
    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $years = $store->years();
      return $years ?: [];
    } catch (Throwable) {
      return null;
    }
  }

  /** @param array<int,list<int>> $prefilledMonths */
  public function timeline(
    Camera $camera,
    ?int $yearFilter,
    ?int $fromTs,
    ?int $toTs,
    array $prefilledMonths = []
  ): ?array {
    $unbounded = $fromTs === null && $toTs === null;
    $years = $this->years($camera);
    if ($years === null || $years === []) {
      return null;
    }
    if ($yearFilter === null) {
      $yearFilter = (int) max($years);
    }

    $tz = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $start = $fromTs ?? (new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $yearFilter), $tz))->getTimestamp();
    $end = $toTs ?? (new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $yearFilter), $tz))->getTimestamp();

    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      // Auch ausdrücklich übergebene große Bereiche müssen vor der Hydrierung
      // begrenzt werden. 20.000 Einträge decken einen vollen 31-Tage-Monat bei
      // Drei-Minuten-Intervall ab, verhindern aber einen kompletten Jahrgang im
      // PHP-Speicher. Die DB liefert bei einer Begrenzung die neuesten Zeilen.
      $rowLimit = $unbounded ? $this->unboundedTimelineLimit() : $this->boundedTimelineLimit();
      $rows = $store->capturesForRange($start, $end, $rowLimit);
    } catch (Throwable) {
      return null;
    }
    if ($rows === []) {
      return null;
    }

    $entries = [];
    $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    foreach ($rows as $row) {
      $entry = $this->entryFromRow($camera, $row, $tz, $publicBaseArchive, '');
      if ($entry !== null) {
        $entries[] = $entry;
      }
    }
    if ($entries === []) {
      return null;
    }

    sort($years);
    return $this->timelineMapper->assemble($entries, $years, $yearFilter, $prefilledMonths);
  }

  public function rangeContainsOnlyDuplicates(Camera $camera, int $fromTs, int $toTs): bool
  {
    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      return $store->rangeContainsOnlyDuplicateCaptures($fromTs, $toTs);
    } catch (Throwable) {
      return false;
    }
  }

  /** @return list<array<string,mixed>>|null null means the index is unavailable. */
  public function timelapseEntries(Camera $camera, int $from, int $to, int $limit): ?array
  {
    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $rows = $store->sampledCapturesForRange($from, $to, $limit);
    } catch (Throwable) {
      return null;
    }
    $tz = new DateTimeZone($this->app->config('timezone', 'UTC'));
    $entries = [];
    foreach ($rows as $row) {
      $entry = $this->entryFromRow($camera, $row, $tz);
      if ($entry !== null) { $entries[] = $entry; }
    }
    return $entries;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return list<array<string,mixed>>
   */
  public function excludeDuplicates(Camera $camera, array $entries): array
  {
    if ($entries === []) {
      return [];
    }

    $fromTs = null;
    $toTs = null;
    foreach ($entries as $entry) {
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      if ($timestamp <= 0) {
        continue;
      }
      $fromTs = $fromTs === null ? $timestamp : min($fromTs, $timestamp);
      $toTs = $toTs === null ? $timestamp : max($toTs, $timestamp);
    }
    if ($fromTs === null || $toTs === null) {
      return $entries;
    }

    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $duplicates = $store->duplicateCaptureSlotsForRange($fromTs, $toTs);
    } catch (Throwable) {
      return $entries;
    }
    if ($duplicates === []) {
      return $entries;
    }

    $duplicateSet = array_fill_keys($duplicates, true);
    return array_values(array_filter(
      $entries,
      static fn(array $entry): bool => !isset($duplicateSet[(int) ($entry['timestamp'] ?? 0)])
    ));
  }

  /**
   * @param array<string,mixed> $row
   * @return array<string,mixed>|null
   */
  public function entryFromRow(
    Camera $camera,
    array $row,
    DateTimeZone $tz,
    ?string $publicBaseArchive = null,
    ?string $publicBaseCache = null,
    ?int $singleWidth = null
  ): ?array {
    $ts = (int) ($row['slot_ts'] ?? 0);
    if ($ts <= 0) {
      return null;
    }
    $publicBaseArchive ??= rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    $publicBaseCache ??= '';
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);

    $exifLines = [];
    foreach (is_array($row['exif_rows'] ?? null) ? $row['exif_rows'] : [] as $exifRow) {
      $tag = (string) ($exifRow['tag'] ?? '');
      $value = (string) ($exifRow['value'] ?? '');
      if ($tag !== '' && $value !== '') {
        $exifLines[] = $tag . ' | ' . $value;
      }
    }

    $weatherLines = [];
    foreach (is_array($row['weather_rows'] ?? null) ? $row['weather_rows'] : [] as $weatherRow) {
      $value = (string) ($weatherRow['value'] ?? '');
      if ($value !== '') {
        $weatherLines[] = $value;
      }
    }

    $variants = [];
    $variantRows = is_array($row['variants_rows'] ?? null) ? $row['variants_rows'] : [];
    if ($singleWidth !== null) {
      // Presentation views need one image, not filesystem checks for every size.
      // Prefer the largest size within the budget, then the smallest larger one.
      $rank = static function (array $v) use ($singleWidth): int {
        $variant = (string) ($v['variant'] ?? '');
        $width = (int) ($v['width'] ?? 0);
        if ($width <= 0) {
          $width = is_numeric($variant) ? (int) $variant : (VariantProfiles::CANONICAL_WIDTHS[$variant] ?? 0);
        }
        return $width > 0 && $width <= $singleWidth ? -$width : ($width > 0 ? $width : PHP_INT_MAX);
      };
      usort($variantRows, static fn(array $a, array $b): int => $rank($a) <=> $rank($b));
    }
    foreach ($variantRows as $variantRow) {
      $relativePath = (string) ($variantRow['relative_path'] ?? '');
      if ($relativePath === '') {
        continue;
      }
      $sizeBytes = $variantRow['size_bytes'] ?? null;
      if ($sizeBytes !== null && is_numeric($sizeBytes) && (int) $sizeBytes <= 0) {
        continue;
      }
      $resolvedRelative = $this->resolveVariantRelativePath(
        $camera,
        $relativePath
      );
      if ($resolvedRelative === null) {
        continue;
      }
      $base = str_starts_with($resolvedRelative, 'cache/') ? $publicBaseCache : $publicBaseArchive;
      $url = $base === '' ? $resolvedRelative : $base . '/' . ltrim($resolvedRelative, '/');
      if (DeferredImages::isPath($resolvedRelative)) $url = DeferredImages::url($resolvedRelative);
      $url = $this->stripCameraPhp($url);
      $variant = (string) ($variantRow['variant'] ?? '');
      if ($variant === '') {
        continue;
      }
      $variants[$variant] = [
        'relative_path' => $resolvedRelative,
        'url' => $url,
        'filename' => basename($resolvedRelative),
        'width' => isset($variantRow['width']) && is_numeric($variantRow['width']) ? (int) $variantRow['width'] : null,
        'height' => isset($variantRow['height']) && is_numeric($variantRow['height']) ? (int) $variantRow['height'] : null,
      ];
      if ($singleWidth !== null) { break; }
    }
    if ($variants === []) {
      return null;
    }

    $primary = $this->timelineMapper->preferredVariant(array_keys($variants)) ?? array_key_first($variants);
    if ($primary === null || !isset($variants[$primary])) {
      return null;
    }
    $relativePath = $variants[$primary]['relative_path'];
    $previewUrl = $variants[$primary]['url'];

    return [
      'timestamp' => $ts,
      'year' => (int) $dt->format('Y'),
      'month' => (int) $dt->format('m'),
      'day' => (int) $dt->format('d'),
      'time' => $dt->format('His'),
      'time_label' => $dt->format('H:i'),
      'datetime_label' => $dt->format('d.m.Y H:i'),
      'datetime_iso' => $dt->format(DateTimeInterface::ATOM),
      'source' => 'db',
      'variants' => $variants,
      'exif_path' => null,
      'exif_relative' => null,
      'exif_text' => $exifLines !== [] ? implode("\n", $exifLines) : null,
      'weather_path' => null,
      'weather_relative' => null,
      'weather_text' => $weatherLines !== [] ? implode("\n", $weatherLines) : null,
      'likes_count' => isset($row['likes_count']) ? (int) $row['likes_count'] : 0,
      'relative_path' => $relativePath,
      'preview_url' => $previewUrl,
      'url' => $previewUrl,
      'primary_variant' => $primary,
    ];
  }

  public function resolveVariantRelativePath(
    Camera $camera,
    string $relativePath
  ): ?string {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') {
      return null;
    }

    if (DeferredImages::isPath($relativePath)) {
      return $this->app->deferredImages()->available($camera, $relativePath) ? $relativePath : null;
    }

    // Ein in der DB gespeicherter positiver Dateiumfang beweist nur, dass die
    // Variante beim Indexieren existierte. Die Cache-Retention kann sie danach
    // kontrolliert ins Langzeitarchiv verschoben haben. Deshalb wird vor jeder
    // öffentlichen URL zuerst der heutige Ablageort geprüft und bei einem alten
    // Cache-Pfad anschließend die bekannte Archivstruktur versucht. Ohne diese
    // Prüfung würden Browser nicht mehr vorhandene Cache-URLs abrufen und dabei
    // vermeidbare 404-Antworten erzeugen.
    $absolutePath = $this->app->resolveCameraStoragePath($camera, $relativePath);
    if ($absolutePath !== null && is_file($absolutePath)) {
      return $relativePath;
    }
    if ($this->isCanonicalArchiveVariantPath($camera, $relativePath)
      && !$this->isCanonicalCacheVariantPath($camera, $relativePath)) {
      return null;
    }

    $archiveRelative = $this->mapLegacyCacheVariantToArchiveRelativePath($camera, $relativePath);
    if ($archiveRelative === null) {
      return null;
    }
    $archiveAbsolutePath = $this->app->resolveCameraStoragePath($camera, $archiveRelative);
    return $archiveAbsolutePath !== null && is_file($archiveAbsolutePath) ? $archiveRelative : null;
  }

  public function mapLegacyCacheVariantToArchiveRelativePath(Camera $camera, string $relativePath): ?string
  {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $slugPattern = preg_quote($camera->slug(), '#');
    if (!preg_match(
      '#^cache/' . $slugPattern . '/(\d{4})/(\d{2})/(\d{2})/(\d{8})_(\d{2})(\d{2})(\d{2})(_utc[1-9]\d{0,10})?(?:_([a-z0-9]+))?\.(jpe?g|png|webp)$#i',
      $relativePath,
      $matches
    )) {
      return null;
    }

    $year = $matches[1];
    $month = $matches[2];
    $day = $matches[3];
    $dateStamp = $matches[4];
    $hour = $matches[5];
    $minute = $matches[6];
    $variant = strtolower((string) ($matches[9] ?? ''));
    $extension = strtolower((string) $matches[10]);
    if ($dateStamp !== $year . $month . $day || !in_array($variant, ['sm', 'lm', 'la', 'hu'], true)) {
      return null;
    }

    return sprintf(
      '%s/%s/%s/%s/%s/%s_%s.%s',
      trim($camera->storageArchiveDir(), '/'),
      $camera->slug(),
      $year,
      $month,
      $day,
      $hour . $minute . ($matches[8] ?? ''),
      $variant,
      $extension
    );
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return list<array<string,mixed>>
   */
  public function enrichWithWeather(Camera $camera, array $entries): array
  {
    if ($entries === []) {
      return $entries;
    }

    $slotTimestamps = [];
    foreach ($entries as $entry) {
      if (!empty($entry['weather_text'])) {
        continue;
      }
      $timestamp = isset($entry['timestamp']) && is_numeric($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
      if ($timestamp > 0) {
        $slotTimestamps[$timestamp] = true;
      }
    }
    if ($slotTimestamps === []) {
      return $entries;
    }

    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $pdo = $store->pdo();
      $weatherBySlot = [];
      $weatherQualityFilter = '';
      if ($this->weatherQualityFlagsTableExists($pdo)) {
        $weatherQualityFilter = ' AND NOT EXISTS (SELECT 1 FROM weather_quality_flags q WHERE q.capture_id = w.capture_id)';
        $activeWeatherQualityFrom = $this->activeWeatherQualityFrom($pdo);
        if ($activeWeatherQualityFrom !== null) {
          $weatherQualityFilter .= ' AND c.slot_ts < ' . $activeWeatherQualityFrom;
        }
      }
      foreach (array_chunk(array_keys($slotTimestamps), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
          "SELECT c.slot_ts, w.value
             FROM captures c
             JOIN weather_entries w ON w.capture_id = c.id
            WHERE c.slot_ts IN ({$placeholders})
              AND c.is_duplicate = 0{$weatherQualityFilter}
            ORDER BY c.slot_ts ASC, w.position ASC, w.id ASC"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $slotTs = isset($row['slot_ts']) && is_numeric($row['slot_ts']) ? (int) $row['slot_ts'] : 0;
          $value = trim((string) ($row['value'] ?? ''));
          if ($slotTs > 0 && $value !== '') {
            $weatherBySlot[$slotTs][] = $value;
          }
        }
      }
    } catch (Throwable) {
      return $entries;
    }

    foreach ($entries as &$entry) {
      if (!empty($entry['weather_text'])) {
        continue;
      }
      $timestamp = isset($entry['timestamp']) && is_numeric($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
      if ($timestamp > 0 && !empty($weatherBySlot[$timestamp])) {
        $entry['weather_text'] = implode("\n", $weatherBySlot[$timestamp]);
        $entry['weather_path'] = $entry['weather_path'] ?? null;
        $entry['weather_relative'] = $entry['weather_relative'] ?? null;
      }
    }
    unset($entry);
    return $entries;
  }

  /**
   * @param list<array<string,mixed>> $entries
   * @return list<array<string,mixed>>
   */
  public function suppressFlaggedWeather(Camera $camera, array $entries): array
  {
    if ($entries === []) {
      return $entries;
    }

    $slotTimestamps = [];
    foreach ($entries as $entry) {
      $timestamp = isset($entry['timestamp']) && is_numeric($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
      if ($timestamp > 0) {
        $slotTimestamps[$timestamp] = true;
      }
    }
    if ($slotTimestamps === []) {
      return $entries;
    }

    try {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $pdo = $store->pdo();
      if (!$this->weatherQualityFlagsTableExists($pdo)) {
        return $entries;
      }
      $flagged = [];
      $activeWeatherQualityFrom = $this->activeWeatherQualityFrom($pdo);
      foreach (array_chunk(array_keys($slotTimestamps), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
          "SELECT DISTINCT c.slot_ts
             FROM captures c
             JOIN weather_quality_flags q ON q.capture_id = c.id
            WHERE c.slot_ts IN ({$placeholders})"
        );
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $slotTs) {
          $flagged[(int) $slotTs] = true;
        }
      }
    } catch (Throwable) {
      return $entries;
    }

    if ($flagged === [] && $activeWeatherQualityFrom === null) {
      return $entries;
    }
    foreach ($entries as &$entry) {
      $timestamp = isset($entry['timestamp']) && is_numeric($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
      $suppress = isset($flagged[$timestamp])
        || ($activeWeatherQualityFrom !== null && $timestamp >= $activeWeatherQualityFrom);
      if ($timestamp > 0 && $suppress) {
        $entry['weather_path'] = null;
        $entry['weather_relative'] = null;
        $entry['weather_text'] = null;
      }
    }
    unset($entry);
    return $entries;
  }

  private function unboundedTimelineLimit(): int
  {
    $meta = $this->app->camerasMeta();
    $timeline = is_array($meta['timeline'] ?? null) ? $meta['timeline'] : [];
    $configured = (int) ($timeline['max_entries'] ?? (getenv('WEBCAM_TIMELINE_MAX_ENTRIES') ?: 300));
    return $configured > 0 ? min($configured, 5000) : 300;
  }

  private function boundedTimelineLimit(): int
  {
    $configured = getenv('WEBCAM_TIMELINE_RANGE_MAX_ENTRIES');
    $limit = is_string($configured) && is_numeric($configured) ? (int) $configured : 20000;
    return max(1, min($limit, 50000));
  }

  private function isCanonicalArchiveVariantPath(Camera $camera, string $relativePath): bool
  {
    $slugPattern = preg_quote($camera->slug(), '#');
    $archiveDir = preg_quote(trim($camera->storageArchiveDir(), '/'), '#');
    return (bool) preg_match(
      '#^' . $archiveDir . '/' . $slugPattern . '/\d{4}/\d{2}/\d{2}/\d{4}(?:_utc[1-9]\d{0,10})?_(?:la|lm|sm|hu|full)\.(?:jpe?g|png|webp)$#i',
      $relativePath
    );
  }

  private function isCanonicalCacheVariantPath(Camera $camera, string $relativePath): bool
  {
    $slugPattern = preg_quote($camera->slug(), '#');
    return (bool) preg_match(
      '#^cache/' . $slugPattern . '/\d{4}/\d{2}/\d{2}/\d{8}_\d{6}(?:_utc[1-9]\d{0,10})?(?:_(?:\d{2,4}|la|lm|sm|hu|full|orig|original))?\.(?:jpe?g|png|webp)$#i',
      $relativePath
    );
  }

  private function stripCameraPhp(string $url): string
  {
    return preg_replace('#/camera\.php/#', '/', $url) ?? $url;
  }

  private function weatherQualityFlagsTableExists(PDO $pdo): bool
  {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'weather_quality_flags' LIMIT 1");
    return $stmt !== false && $stmt->fetchColumn() !== false;
  }

  private function activeWeatherQualityFrom(PDO $pdo): ?int
  {
    $stmt = $pdo->prepare(
      "SELECT MIN(c.slot_ts)
         FROM captures c
         JOIN weather_quality_flags q ON q.capture_id = c.id
        WHERE c.slot_ts >= :from_ts
          AND q.flag IN ('frozen_value', 'out_of_range')"
    );
    $stmt->execute([':from_ts' => time() - 86400]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
      return null;
    }
    $timestamp = (int) $value;
    return $timestamp > 0 ? $timestamp : null;
  }
}
