<?php
declare(strict_types=1);

namespace Webcam\Service\View;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Übersetzt Domain-, Status- und Speicherwerte in Daten für Seiten und APIs.
 * Der Presenter verändert keine Konfiguration und gibt für den Browser nur
 * relative, zur aktuellen Installation passende URLs zurück.
 */
final class CameraPresenter
{
  public function __construct(private readonly App $app) {}

  /**
   * Baut das gemeinsame View-Model einer Kamera aus Konfiguration, Status und
   * aktuellen Dateipfaden. Es werden relative Browser-URLs ausgegeben, keine
   * absoluten Hostpfade.
   *
   * @return array<string,mixed>
   */
  public function overviewData(Camera $camera): array
  {
    $state = $this->app->statusService()->state($camera);
    $baseUrl = trim((string) $this->app->config('base_url', ''), '/');
    // Avoid leaking API path segments when base_url is auto-detected inside /api/*
    $baseUrl = preg_replace('#/api(?:/.*)?$#i', '', $baseUrl) ?: $baseUrl;
    $basePrefix = $baseUrl === '' ? '' : '/' . $baseUrl;
    $slug = $camera->slug();
    $resolutions = $camera->resolutions();
    if (!$resolutions) {
      $resolutions = $this->app->config('default_resolutions', []);
    }
    sort($resolutions);
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../..') ?: '');

    // Versuche, das letzte echte Capture aus Status/DB zu nehmen und daraus Varianten abzuleiten
    $latestCaptureRel = $state['last_capture_path'] ?? null;
    if (!$latestCaptureRel) {
      // ggf. aus Events (jüngster Erfolg mit Pfad)
      if (!empty($state['events']) && is_array($state['events'])) {
        foreach ($state['events'] as $ev) {
          if (($ev['type'] ?? '') === 'success' && !empty($ev['path'])) {
            $latestCaptureRel = $ev['path'];
            break;
          }
        }
      }
    }
    // kein weiterer FS-Fallback (keine current/ Nutzung)
    if (is_string($latestCaptureRel)) {
      $latestCaptureRel = str_replace('\\', '/', $latestCaptureRel);
      if ($projectRoot !== '' && str_starts_with($latestCaptureRel, $projectRoot)) {
        $latestCaptureRel = substr($latestCaptureRel, strlen($projectRoot));
      }
      $latestCaptureRel = ltrim($latestCaptureRel, '/');
    } else {
      $latestCaptureRel = null;
    }

    // Pilot-Current ist bereits fertig publiziert. Die Uebersicht soll keine
    // grossen Archivvarianten erzeugen und keine privaten Dateipfade anbieten.
    if (is_string($latestCaptureRel) && \Webcam\Service\Storage\DeferredImages::isPath($latestCaptureRel)) {
      $latestCaptureRel = null;
    }

    $buildVariantPath = static function (string $relativePath, string|int $variant): string {
      $variant = (string) $variant;
      $dot = strrpos($relativePath, '.');
      if ($dot === false) {
        return $relativePath . '_' . $variant;
      }
      $name = substr($relativePath, 0, $dot);
      $ext = substr($relativePath, $dot);
      // ersetze ggf. vorhandene Variante (_sm/_la/_full/_1600), aber lasse Zeitanteil (HHMMSS) intakt
      $name = preg_replace('#_(?:sm|lm|la|hu|full|orig|original|[1-9]\\d{2,3})$#i', '', $name) ?: $name;
      return $name . '_' . $variant . $ext;
    };

    $resolutionUrls = [];
    $currentImageUrl = null;
    $fullImageUrl = null;

    if ($latestCaptureRel) {
      $buildExistingVariantUrl = function (string|int $variant) use ($buildVariantPath, $latestCaptureRel, $camera, $basePrefix): ?string {
        $relative = $buildVariantPath($latestCaptureRel, $variant);
        $absolute = $this->app->resolveCameraStoragePath($camera, $relative);
        if ($absolute === null || !is_file($absolute)) {
          return null;
        }
        return $basePrefix . '/' . $relative;
      };

      foreach ($resolutions as $width) {
        $url = $buildExistingVariantUrl($width);
        if ($url === null) {
          continue;
        }
        $resolutionUrls[$width] = $url;
        if ($width === 400 && $currentImageUrl === null) {
          $currentImageUrl = $url;
        }
      }
      $fullImageUrl = $buildExistingVariantUrl('full') ?? $buildExistingVariantUrl('hu');
      if ($currentImageUrl === null && $resolutionUrls) {
        $smallerWidths = array_values(array_filter(array_keys($resolutionUrls), static fn(int $width): bool => $width <= 400));
        if ($smallerWidths !== []) {
          sort($smallerWidths, SORT_NUMERIC);
          $currentImageUrl = $resolutionUrls[(int) end($smallerWidths)] ?? null;
        }
      }
      if ($currentImageUrl === null && $resolutionUrls) {
        $first = reset($resolutionUrls);
        $currentImageUrl = is_string($first) ? $first : null;
      }
      if ($currentImageUrl === null) {
        $absolute = $this->app->resolveCameraStoragePath($camera, $latestCaptureRel);
        if ($absolute !== null && is_file($absolute)) {
          $currentImageUrl = $basePrefix . '/' . $latestCaptureRel;
        }
      }
      if ($fullImageUrl === null) {
        $fullImageUrl = $currentImageUrl;
      }
    }

    // Fallback auf slug/current/* wenn kein Pfad aus Capture bekannt
    if ($currentImageUrl === null) {
      $currentBase = '/' . trim($basePrefix . '/' . $slug . '/current', '/');
      $currentBase = preg_replace('#/{2,}#', '/', $currentBase) ?: '/' . $slug . '/current';
      foreach ($resolutions as $width) {
        if ($projectRoot !== '') {
          $absolute = (new \Webcam\Infrastructure\Filesystem\CurrentImageDirectory($projectRoot))->path($slug) . '/' . $width . '.jpg';
          if (!is_file($absolute)) {
            continue;
          }
        }
        $resolutionUrls[$width] = $currentBase . '/' . $width . '.jpg';
      }
      $currentImageUrl = $resolutionUrls[400] ?? null;
      if ($currentImageUrl === null && $resolutionUrls) {
        $smallerWidths = array_values(array_filter(array_keys($resolutionUrls), static fn(int $width): bool => $width <= 400));
        if ($smallerWidths !== []) {
          sort($smallerWidths, SORT_NUMERIC);
          $currentImageUrl = $resolutionUrls[(int) end($smallerWidths)] ?? null;
        }
      }
      $fullCurrentAbsolute = $projectRoot !== '' ? ((new \Webcam\Infrastructure\Filesystem\CurrentImageDirectory($projectRoot))->path($slug) . '/full.jpg') : '';
      if ($fullCurrentAbsolute !== '' && is_file($fullCurrentAbsolute)) {
        $fullImageUrl = $currentBase . '/full.jpg';
      } else {
        $fullImageUrl = $currentImageUrl;
      }
      if ($currentImageUrl === null && $fullImageUrl !== null) {
        $currentImageUrl = $fullImageUrl;
      }
    }

    $tz = $this->app->config('timezone', 'UTC');
    $lastCaptureAt = null;
    $lastCaptureTs = null;
    // Bevorzugt: jüngstes Erfolg-Event mit Datei-Pfad (Events sind absteigend sortiert)
    if (!empty($state['events']) && is_array($state['events'])) {
      foreach ($state['events'] as $event) {
        if (($event['type'] ?? '') === 'success' && !empty($event['path'])) {
          $lastCaptureTs = (int) ($event['timestamp'] ?? 0);
          break;
        }
      }
    }
    if (!$lastCaptureTs && !empty($state['last_capture_at']) && !empty($state['last_capture_path'])) {
      $lastCaptureTs = (int) $state['last_capture_at'];
    } elseif (!$lastCaptureTs && !empty($state['last_success_at'])) {
      // Fallback: letzter Erfolg, auch wenn kein Pfad vermerkt
      $lastCaptureTs = (int) $state['last_success_at'];
    } elseif (!$lastCaptureTs) {
      // Letzte bekannte Aufnahme aus DB (schneller als Timeline-Scan)
      try {
        $store = new CameraDataStore($this->app, $camera, null, true);
        $pdo = $store->pdo();
        $ts = $pdo->query('SELECT MAX(slot_ts) FROM captures WHERE is_duplicate = 0')->fetchColumn();
        if ($ts !== false && $ts !== null) {
          $lastCaptureTs = (int) $ts;
        }
      } catch (\Throwable) {
        // ignore
      }
    }
    if ($lastCaptureTs === null && $latestCaptureRel) {
      if (preg_match('#/(\d{8})_(\d{6})#', $latestCaptureRel, $m)) {
        $dt = DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('UTC'));
        if ($dt instanceof DateTimeImmutable) {
          $lastCaptureTs = $dt->getTimestamp();
        }
      } else {
        $fileAbs = $this->app->resolveCameraStoragePath($camera, $latestCaptureRel);
        if ($fileAbs && is_file($fileAbs)) {
          $lastCaptureTs = filemtime($fileAbs) ?: null;
        }
      }
    }
    if ($lastCaptureTs) {
      $lastCaptureAt = (new DateTimeImmutable('@' . $lastCaptureTs))->setTimezone(new DateTimeZone($tz));
    }
    $lastSuccessAt = $state['last_success_at'] ?? null;
    $ageSeconds = $lastSuccessAt ? max(0, time() - (int)$lastSuccessAt) : null;
    $interval = (int)($camera->capture()['interval_seconds'] ?? 600);

    return [
      'slug' => $camera->slug(),
      'title' => $camera->title(),
      'short_title' => $camera->shortTitle(),
      'description' => $camera->description(),
      'info_html' => $camera->infoHtml(),
      'tags' => $camera->tags(),
      'location' => $camera->location(),
      'current_image_url' => $currentImageUrl,
      'full_image_url' => $fullImageUrl,
      'resolutions' => $resolutions,
      'resolutions_urls' => $resolutionUrls,
      'last_capture' => $lastCaptureAt,
      'age_seconds' => $ageSeconds,
      'interval_seconds' => $interval,
      'mode' => $camera->mode()->value,
      // Only the transport is public; capture endpoints and keys stay private.
      'capture_transport' => $camera->captureTransport()->value,
      'state' => $state,
      'stream' => $camera->stream(),
      'weather_commands' => $camera->weatherCommands(),
      'overlay_logo' => $camera->overlayLogo(),
      'overlay_logo_dark' => $camera->overlayLogoDark(),
      'labels' => $camera->labels(),
      'storage_current_dir' => $camera->storageCurrentDir(),
      'storage_archive_dir' => $camera->storageArchiveDir(),
      'storage_best_of_dir' => $camera->storageBestOfDir(),
    ];
  }

  /**
   * Erzeugt overviewData für alle Kameras in Repository-Reihenfolge.
   *
   * @return array<int,array<string,mixed>>
   */
  public function overviewList(): array
  {
    $list = [];
    foreach ($this->app->cameras()->listed() as $camera) {
      $list[] = $this->overviewData($camera);
    }
    return $list;
  }

  /** Liefert den dokumentrelativen öffentlichen Archivpfad dieser Kamera. */
  public function publicBaseUrl(Camera $camera): string
  {
    $storage = $camera->storage();
    if (isset($storage['public_base']) && is_string($storage['public_base']) && $storage['public_base'] !== '') {
      $custom = trim($storage['public_base']);
      if ($custom === '') {
        return '';
      }
      if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $custom)) {
        return rtrim($custom, '/');
      }
      if ($custom[0] === '/') {
        return ltrim($custom, '/');
      }
      $normalized = trim($custom, '/');
      if ($normalized === '') {
        return '';
      }
      $segments = explode('/', $normalized);
      if ($segments && strcasecmp(end($segments), $camera->slug()) === 0) {
        array_pop($segments);
      }
      return implode('/', array_filter($segments, static fn(string $part): bool => $part !== ''));
    }

    $publicRoot = trim($this->resolvePublicRootPath($camera), '/');
    // Für relative Auslieferung bewusst keine führende Slash hinzufügen; so funktionieren Proxyprefixe und Subdir-Deploys.
    return $publicRoot;
  }

  private function resolvePublicRootPath(Camera $camera): string
  {
    $storage = $camera->storage();
    $basePath = $storage['base_path'] ?? $this->app->paths()->storagePath($camera->slug());
    $basePath = str_replace('\\', '/', $basePath);
    $basePath = rtrim($basePath, '/');
    $slug = $camera->slug();
    $lastSlash = strrpos($basePath, '/');
    if ($lastSlash !== false) {
      $lastSegment = substr($basePath, $lastSlash + 1);
      if (strcasecmp($lastSegment, $slug) === 0) {
        $basePath = substr($basePath, 0, $lastSlash);
      }
    } elseif (strcasecmp($basePath, $slug) === 0) {
      $basePath = '';
    }
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../..') ?: '');
    if ($basePath !== '' && str_starts_with($basePath, '/')) {
      if ($projectRoot !== '' && str_starts_with($basePath, $projectRoot)) {
        $basePath = substr($basePath, strlen($projectRoot));
      }
      $basePath = ltrim($basePath, '/');
    }
    if ($basePath === '') {
      return 'storage';
    }
    return trim($basePath, '/');
  }
}
