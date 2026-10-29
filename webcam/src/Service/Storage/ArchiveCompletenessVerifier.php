<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DirectoryIterator;
use Webcam\App;

/**
 * Beweist vor einer Cache-Loeschung, dass ein kompletter Aufnahmetag im Archiv
 * angekommen ist.
 *
 * Geprueft werden bewusst nur exakt abgeleitete Zielpfade. Dadurch verursacht
 * die Kontrolle keinen rekursiven Scan des langsamen HDD-Archivs. Jede
 * Originalaufnahme im Cache muss als gueltige Archivvariante vorliegen;
 * vorhandene EXIF- und Wetter-Sidecars muessen ebenfalls angekommen sein.
 * Eine exakt leere EXIF-Quelle (z.B. RTSP ohne Kamera-EXIF) enthaelt keine
 * zu sichernden Metadaten; nur fuer diesen Fall ist kein Archiv-Sidecar noetig.
 * Sidecars sind Begleitdateien neben dem Foto, beispielsweise Wettertexte.
 * Diese Klasse loescht selbst nichts. Bei fehlender oder unlesbarer Sicherung
 * meldet sie complete=false, damit der Aufrufer den Cache-Tag behalten kann.
 */
final class ArchiveCompletenessVerifier
{
  private int $minSourceBytes;

  public function __construct(private readonly App $app)
  {
    $configured = (int) ($this->app->config('archive_min_jpeg_bytes') ?? 8192);
    $this->minSourceBytes = max(1024, $configured);
  }

  /**
   * @return array{complete:bool,captures:int,reason:string}
   */
  public function verifyDay(string $cacheDayDir, string $archiveDayDir): array
  {
    if (!is_dir($cacheDayDir)) {
      return $this->failed('cache day directory is missing');
    }
    if (!is_dir($archiveDayDir)) {
      return $this->failed('archive day directory is missing');
    }

    $captures = [];
    $files = [];
    foreach (new DirectoryIterator($cacheDayDir) as $entry) {
      if ($entry->isDot()) { continue; }
      if ($entry->isLink() || !$entry->isFile()) {
        return $this->failed('unverified cache entry: ' . $entry->getFilename());
      }
      $files[$entry->getFilename()] = $entry->getPathname();
      if (preg_match('/^(\d{8})_(\d{6})(_utc[1-9]\d{0,10})?\.jpg$/D', $entry->getFilename(), $match) !== 1) {
        continue;
      }
      $captures[] = [
        'base' => $match[1] . '_' . $match[2] . ($match[3] ?? ''),
        'time' => substr($match[2], 0, 4) . ($match[3] ?? ''),
        'path' => $entry->getPathname(),
      ];
    }

    foreach ($captures as $capture) {
      $destBase = rtrim($archiveDayDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $capture['time'];
      $sidecars = [
        $cacheDayDir . '/' . $capture['base'] . '_ex.txt' => $destBase . '_ex.txt',
        $cacheDayDir . '/' . $capture['base'] . '_WX.txt' => $destBase . '_wx.txt',
      ];
      $result = $this->verifyCapture($capture['path'], $destBase, $sidecars);
      if (!$result['complete']) {
        return $this->failed($result['reason'], count($captures));
      }
    }

    // Nur Bildvarianten eines nachweislich gesicherten Originals lassen sich
    // wieder erzeugen. Fuer andere Dateien, etwa Wetterdaten ohne passendes
    // Original, ist eine eigene bytegleiche Archivkopie erforderlich.
    // Ein anderes gesichertes Foto desselben Tages reicht dafuer nicht aus;
    // ebenso wenig beweist ein vorhandenes Verzeichnis eine vollstaendige Sicherung.
    $originals = array_fill_keys(array_column($captures, 'base'), true);
    foreach ($files as $name => $source) {
      if (isset($originals[substr($name, 0, -4)]) && str_ends_with($name, '.jpg')) { continue; }
      if (preg_match('/^(\d{8}_\d{6}(?:_utc[1-9]\d{0,10})?)_(?:sm|la|lm|hu|full|[0-9]+)\.jpg$/D', $name, $m)
        && isset($originals[$m[1]])) { continue; }
      if (str_ends_with($name, '_ex.txt') && filesize($source) === 0) { continue; }
      $targetName = $name;
      if (preg_match('/^\d{8}_(\d{4})\d{2}(_utc[1-9]\d{0,10})?_(.+)$/D', $name, $m)) {
        $targetName = $m[1] . ($m[2] ?? '') . '_' . ($m[3] === 'WX.txt' ? 'wx.txt' : $m[3]);
      }
      $target = $archiveDayDir . '/' . $targetName;
      $hash = @hash_file('sha256', $source);
      if (is_link($target) || !is_file($target) || $hash === false
        || @filesize($target) !== filesize($source) || @hash_file('sha256', $target) !== $hash) {
        return $this->failed('cache file lacks an identical archive copy: ' . $name, count($captures));
      }
    }

    return [
      'complete' => true,
      'captures' => count($captures),
      'reason' => 'all cache captures and archive variants verified',
    ];
  }

  /**
   * Prueft ein einzelnes, exakt abgeleitetes Archiv-Zielset. Diese Methode wird
   * sowohl vor einem Rollover-Skip als auch nach der atomaren Publikation
   * aufgerufen, damit vorhandene, aber defekte Dateien nicht als fertig gelten.
   *
   * @param array<string,string> $sidecars Zuordnung: Quelldatei => Archivdatei
   * @return array{complete:bool,reason:string}
   */
  public function verifyCapture(string $sourcePath, string $destBase, array $sidecars = []): array
  {
    $sourceInfo = $this->jpegInfo($sourcePath, $this->minSourceBytes);
    if ($sourceInfo === null) {
      return $this->captureFailed('cache source is not a valid JPEG: ' . basename($sourcePath));
    }

    $sourceHash = @hash_file('sha256', $sourcePath);
    if ($sourceHash === false) {
      return $this->captureFailed('cache source cannot be hashed');
    }

    foreach (VariantProfiles::CANONICAL_WIDTHS as $suffix => $configuredWidth) {
      $target = $destBase . '_' . $suffix . '.jpg';
      $targetInfo = $this->jpegInfo($target, 1);
      $expectedWidth = min($configuredWidth, $sourceInfo['width']);
      $expectedHeight = (int) max(1, round($sourceInfo['height'] * ($expectedWidth / $sourceInfo['width'])));
      if ($targetInfo === null) {
        return $this->captureFailed('archive variant is missing or invalid: ' . basename($target));
      }
      if ($targetInfo['width'] !== $expectedWidth || $targetInfo['height'] !== $expectedHeight) {
        return $this->captureFailed('archive variant has unexpected dimensions: ' . basename($target));
      }
    }

    foreach (VariantProfiles::ORIGINAL_CLONES as $suffix) {
      $target = $destBase . '_' . $suffix . '.jpg';
      $targetInfo = $this->jpegInfo($target, $this->minSourceBytes, false);
      if ($targetInfo === null) {
        return $this->captureFailed('archive original clone is missing or invalid: ' . basename($target));
      }
      if (
        $targetInfo['width'] !== $sourceInfo['width']
        || $targetInfo['height'] !== $sourceInfo['height']
        || $targetInfo['bytes'] !== $sourceInfo['bytes']
        || @hash_file('sha256', $target) !== $sourceHash
      ) {
        return $this->captureFailed('archive original clone differs from cache source: ' . basename($target));
      }
    }

    foreach ($sidecars as $source => $target) {
      if (!is_file($source)) {
        continue;
      }
      // Historische RTSP-Aufnahmen koennen ein leeres EXIF-Sidecar besitzen.
      // Nur die sicher festgestellte Groesse 0 ist ausgenommen: false bei
      // einem Stat-Fehler ist NICHT leer. Wetter und Whitespace bleiben strikt.
      $sourceSize = filesize($source);
      if ($sourceSize === false) {
        return $this->captureFailed('source sidecar size is unavailable: ' . basename($source));
      }
      if ($sourceSize === 0 && str_ends_with($source, '_ex.txt')) {
        continue;
      }
      $targetSize = is_file($target) ? filesize($target) : false;
      if ($targetSize === false || $targetSize <= 0) {
        return $this->captureFailed('archive sidecar is missing or empty: ' . basename($target));
      }
    }

    return ['complete' => true, 'reason' => 'archive target set verified'];
  }

  /**
   * @return array{width:int,height:int,bytes:int}|null
   */
  private function jpegInfo(string $path, int $minimumBytes, bool $decode = true): ?array
  {
    if (!is_file($path)) {
      return null;
    }
    $bytes = filesize($path);
    if ($bytes === false || $bytes < $minimumBytes || $bytes > SnapshotResourceLimits::MAX_BYTES) {
      return null;
    }
    $info = @getimagesize($path);
    if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
      return null;
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
      return null;
    }
    try {
      SnapshotResourceLimits::checkDimensions($info);
    } catch (\RuntimeException) {
      return null;
    }
    if ($decode) {
      // Die Bildbibliothek GD kann beschaedigte JPEGs teilweise reparieren
      // und trotzdem ein Bild zurueckgeben. Jede Warnung beim Einlesen macht
      // die Archivpruefung daher ungueltig: Die Cache-Quelle muss erhalten bleiben.
      $warning = false;
      $ignoreWarnings = ini_set('gd.jpeg_ignore_warning', '0');
      set_error_handler(static function () use (&$warning): bool { $warning = true; return true; });
      try {
        $image = imagecreatefromjpeg($path);
        if ($image !== false) { imagedestroy($image); }
      } finally {
        restore_error_handler();
        if ($ignoreWarnings !== false) { ini_set('gd.jpeg_ignore_warning', $ignoreWarnings); }
      }
      if ($warning || $image === false) { return null; }
    }
    return ['width' => $width, 'height' => $height, 'bytes' => (int) $bytes];
  }

  /**
   * @return array{complete:bool,captures:int,reason:string}
   */
  private function failed(string $reason, int $captures = 0): array
  {
    return ['complete' => false, 'captures' => $captures, 'reason' => $reason];
  }

  /** @return array{complete:bool,reason:string} */
  private function captureFailed(string $reason): array
  {
    return ['complete' => false, 'reason' => $reason];
  }
}
