<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Übersetzt zwischen vollständigen Timeline-Einträgen und kompaktem Cache-JSON.
 *
 * Warum eine eigene Klasse?
 * - LegacyArchiveCache kümmert sich um Dateien, Frische und Rebuilds.
 * - Diese Klasse kennt ausschließlich das gespeicherte Datenformat.
 * - Änderungen an URLs oder Sidecar-Pfaden vermischen sich dadurch nicht mit
 *   Locking, Verzeichnisanlage oder Admin-Status.
 *
 * Die kurzen JSON-Schlüssel (ts, y, m ...) sind bewusst beibehalten:
 * Bestehende Cache-Dateien bleiben lesbar und große Jahresdateien bleiben
 * kleiner. Nach außen werden stets die ausführlichen Timeline-Schlüssel
 * zurückgegeben.
 */
final class LegacyArchiveCacheCodec
{
  public function __construct(
    private readonly App $app,
    private readonly ArchiveStoragePaths $storagePaths
  ) {
  }

  /**
   * Verdichtet UI-fertige Einträge für die JSON-Datei.
   *
   * Varianten enthalten im Cache nur ihren relativen Pfad. URL, Dateiname und
   * Primärbild lassen sich beim Lesen zuverlässig daraus rekonstruieren.
   *
   * @param list<array<string,mixed>> $entries
   * @return list<array<string,mixed>>
   */
  public function compress(array $entries): array
  {
    $compressed = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      // Ungültige oder unvollständige Varianten werden nicht still zu
      // kaputten Links. Nur ein nicht-leerer relativer Pfad ist speicherbar.
      $variants = [];
      if (isset($entry['variants']) && is_array($entry['variants'])) {
        foreach ($entry['variants'] as $variant => $info) {
          if (!is_array($info)) {
            continue;
          }
          $relative = $info['relative_path'] ?? null;
          if (is_string($relative) && $relative !== '') {
            $variants[(string) $variant] = $relative;
          }
        }
      }

      $compressed[] = [
        'ts' => (int) ($entry['timestamp'] ?? 0),
        'y' => (int) ($entry['year'] ?? 0),
        'm' => (int) ($entry['month'] ?? 0),
        'd' => (int) ($entry['day'] ?? 0),
        't' => (string) ($entry['time'] ?? ''),
        'tl' => (string) ($entry['time_label'] ?? ''),
        'dl' => (string) ($entry['datetime_label'] ?? ''),
        'iso' => (string) ($entry['datetime_iso'] ?? ''),
        'primary' => $entry['primary_variant'] ?? null,
        'variants' => $variants,
        'exif' => $entry['exif_relative'] ?? null,
        'weather' => $entry['weather_relative'] ?? null,
      ];
    }
    return $compressed;
  }

  /**
   * Erkennt das ältere Cache-Format mit vollständig ausgebauten Varianten.
   *
   * Der Test eines repräsentativen Eintrags genügt: Eine Jahresdatei wurde
   * immer als Ganzes in genau einem Format geschrieben.
   *
   * @param list<array<string,mixed>> $entries
   */
  public function containsExpandedLegacyEntries(array $entries): bool
  {
    if ($entries === []) {
      return false;
    }
    $sample = $entries[array_key_first($entries)];
    if (!is_array($sample) || !isset($sample['variants']) || !is_array($sample['variants']) || $sample['variants'] === []) {
      return false;
    }
    $variant = $sample['variants'][array_key_first($sample['variants'])];
    return is_array($variant) && array_key_exists('relative_path', $variant);
  }

  /**
   * Baut aus dem kompakten JSON wieder normale Timeline-Einträge.
   *
   * Drei Dinge passieren in klarer Reihenfolge:
   * 1. kurze Cache-Schlüssel werden auf sprechende Feldnamen abgebildet,
   * 2. relative Varianten erhalten dokumentrelative beziehungsweise
   *    archivrelative URLs,
   * 3. EXIF-/Wetter-Sidecars werden auf echte Dateisystempfade aufgelöst.
   *
   * @param list<array<string,mixed>> $entries
   * @return list<array<string,mixed>>
   */
  public function hydrate(Camera $camera, array $entries): array
  {
    if ($entries === []) {
      return [];
    }

    $basePath = $this->storagePaths->baseRoot($camera);
    $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    $timezone = new \DateTimeZone((string) $this->app->config('timezone', 'UTC'));

    foreach ($entries as $index => &$entry) {
      if (!is_array($entry)) {
        unset($entries[$index]);
        continue;
      }

      // Phase 1: das interne Kurzformat in das öffentliche Timeline-Schema
      // überführen. Interne Schlüssel werden danach bewusst entfernt.
      $entry['timestamp'] = (int) ($entry['ts'] ?? 0);
      $entry['year'] = (int) ($entry['y'] ?? 0);
      $entry['month'] = (int) ($entry['m'] ?? 0);
      $entry['day'] = (int) ($entry['d'] ?? 0);
      $entry['time'] = (string) ($entry['t'] ?? '');
      $entry['time_label'] = (string) ($entry['tl'] ?? '');
      $entry['datetime_label'] = (string) ($entry['dl'] ?? '');
      $entry['datetime_iso'] = (string) ($entry['iso'] ?? '');
      $entry['primary_variant'] = $entry['primary'] ?? null;
      $entry['exif_relative'] = $entry['exif'] ?? null;
      $entry['weather_relative'] = $entry['weather'] ?? null;
      unset(
        $entry['ts'],
        $entry['y'],
        $entry['m'],
        $entry['d'],
        $entry['t'],
        $entry['tl'],
        $entry['dl'],
        $entry['iso'],
        $entry['primary'],
        $entry['exif'],
        $entry['weather']
      );

      // Alte Dateien können formatierte Labels noch nicht enthalten. Sie
      // werden aus dem Zeitstempel in der konfigurierten Kamera-Zeitzone
      // ergänzt, nicht aus der Server-Zeitzone.
      if ($entry['time_label'] === '' && $entry['time'] !== '') {
        $entry['time_label'] = substr($entry['time'], 0, 2) . ':' . substr($entry['time'], 2, 2);
      }
      if ($entry['datetime_label'] === '' || $entry['datetime_iso'] === '') {
        $date = (new \DateTimeImmutable('@' . $entry['timestamp']))->setTimezone($timezone);
        $entry['datetime_label'] = $date->format('d.m.Y H:i');
        $entry['datetime_iso'] = $date->format(DATE_ATOM);
      }

      // Phase 2: Cache-Dateien nutzen dokumentrelative URLs. Archivvarianten
      // hängen dagegen unter dem pro Kamera berechneten öffentlichen Basis-URL.
      $variants = [];
      if (isset($entry['variants']) && is_array($entry['variants'])) {
        foreach ($entry['variants'] as $variant => $relativePath) {
          if (!is_string($relativePath) || $relativePath === '') {
            continue;
          }
          $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
          $publicBase = str_starts_with($relative, 'cache/') ? '' : $publicBaseArchive;
          $variants[(string) $variant] = [
            'relative_path' => $relative,
            'url' => $this->publicUrl($publicBase, $relative),
            'filename' => basename($relative),
          ];
        }
      }
      $entry['variants'] = $variants;
      $this->selectPrimaryVariant($entry, $variants);

      // Phase 3: Sidecars werden nur dann als Pfad angeboten, wenn im Cache ein
      // relativer Name steht. Ob die Datei noch existiert, prüft der spätere
      // Leser wie bei normalen Timeline-Einträgen.
      $entry['exif_path'] = is_string($entry['exif_relative']) && $entry['exif_relative'] !== ''
        ? $this->storagePaths->join($basePath, $entry['exif_relative'])
        : null;
      $entry['weather_path'] = is_string($entry['weather_relative']) && $entry['weather_relative'] !== ''
        ? $this->storagePaths->join($basePath, $entry['weather_relative'])
        : null;
    }
    unset($entry);

    // Ungültige Nicht-Array-Einträge wurden oben entfernt; array_values stellt
    // anschließend wieder die von Aufrufern erwartete fortlaufende Liste her.
    return array_values($entries);
  }

  /**
   * Legt Vorschau/URL anhand der bevorzugten Variante fest.
   *
   * @param array<string,mixed> $entry
   * @param array<string,array{relative_path:string,url:string,filename:string}> $variants
   */
  private function selectPrimaryVariant(array &$entry, array $variants): void
  {
    $entry['relative_path'] = null;
    $entry['preview_url'] = null;
    $entry['url'] = null;

    $primary = $entry['primary_variant'];
    if ($primary !== null && isset($variants[$primary])) {
      $selected = $variants[$primary];
    } elseif ($variants !== []) {
      $primary = array_key_first($variants);
      $selected = $variants[$primary];
      $entry['primary_variant'] = $primary;
    } else {
      return;
    }

    $entry['relative_path'] = $selected['relative_path'];
    $entry['preview_url'] = $selected['url'];
    $entry['url'] = $selected['url'];
  }

  private function publicUrl(string $base, string $relativePath): string
  {
    if ($relativePath === '') {
      return '';
    }
    return $base === '' ? $relativePath : $base . '/' . $relativePath;
  }
}
