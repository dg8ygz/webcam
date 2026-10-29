<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Liest Best-Of-Dateien und kombiniert sie mit Like-Daten der Kamera-SQLite.
 * Kleine abgeleitete Listen-Caches reduzieren wiederholte Sortierarbeit; ihre
 * Fingerprints hängen vom echten Verzeichnis-/DB-Stand ab und sie sind keine
 * Quelle für Timeline- oder Capture-Metadaten.
 */
final class BestOfGallery
{
  private readonly BestOfFileCatalog $files;
  private readonly BestOfLikeRepository $likes;

  public function __construct(private readonly App $app)
  {
    $cache = new BestOfGalleryCache($app);
    $this->files = new BestOfFileCatalog($app, $cache);
    $this->likes = new BestOfLikeRepository($app, $cache);
  }

  /**
   * Liefert neueste Best-Of-Dateien als UI-Einträge, optional begrenzt.
   *
   * @return list<array{url:string,filename:string,mtime:int}>
   */
  public function list(Camera $camera, ?int $limit = null): array
  {
    $info = $this->app->bestOfLocation($camera);
    $dir = $info['directory'];
    $relativePrefix = $info['relative'];
    if (!is_dir($dir)) {
      return [];
    }
    $basenames = $this->files->orderedBasenames($camera, $dir, $limit);
    if (!$basenames) {
      return [];
    }
    return $this->buildBestOfEntries($camera, $dir, $relativePrefix, $basenames);
  }

  /**
   * Die paginierte Best-Of-API darf nicht erst die komplette Galerie mappen und
   * danach `array_slice()` darauf anwenden. Bei großen Ordnern macht genau das
   * den "Mehr laden"-Pfad unnötig teuer. Deshalb wird erst auf der bereits
   * deduplizierten Basename-Liste gepaged und nur der tatsächlich benötigte
   * Ausschnitt zu API-Items aufgebaut.
   * Optional filtert die Methode vor dem Ausschnitt nach dem Jahr im Dateinamen.
   *
   * @return array{
   *   items:list<array{url:string,thumb_url:string,filename:string,mtime:?int,slot_ts:?int,link:?string}>,
   *   page:int,
   *   per_page:int,
   *   total:int,
   *   total_pages:int
   * }
   */
  public function page(Camera $camera, int $page = 1, int $perPage = 50, ?int $yearFilter = null): array
  {
    $page = max(1, $page);
    $perPage = max(1, min(1000, $perPage));

    $info = $this->app->bestOfLocation($camera);
    $dir = $info['directory'];
    $relativePrefix = $info['relative'];
    if (!is_dir($dir)) {
      return [
        'items' => [],
        'page' => $page,
        'per_page' => $perPage,
        'total' => 0,
        'total_pages' => 1,
      ];
    }

    $allBasenames = $this->files->orderedBasenames($camera, $dir, null);
    if ($yearFilter !== null) {
      $allBasenames = array_values(array_filter($allBasenames, static function (string $basename) use ($yearFilter): bool {
        return preg_match('/^' . preg_quote((string) $yearFilter, '/') . '\d{4}_\d{6}/', $basename) === 1;
      }));
    }
    $total = count($allBasenames);
    $totalPages = (int) max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($allBasenames, $offset, $perPage);

    return [
      'items' => $this->buildBestOfEntries($camera, $dir, $relativePrefix, $slice),
      'page' => $page,
      'per_page' => $perPage,
      'total' => $total,
      'total_pages' => $totalPages,
    ];
  }

  /**
   * Fuehrt die Best-Of-Listen mehrerer Kameras zu einer globalen Chronologie
   * zusammen. Die vorhandenen per-Kamera-Listen bleiben dabei die Quelle; es
   * wird weder eine globale Datenbank noch ein weiterer Listen-Cache erzeugt.
   *
   * Undatierte Legacy-Dateien stehen bewusst am Ende. Ihre Datei-MTime ist
   * keine belastbare Aufnahmezeit und darf deshalb nicht als solche erscheinen.
   *
   * @param list<Camera> $cameras
   * @return array{
   *   items:list<array<string,mixed>>,
   *   page:int,
   *   per_page:int,
   *   total:int,
   *   total_pages:int,
   *   years:list<array{year:int,count:int}>
   * }
   */
  public function globalPage(
    array $cameras,
    int $page = 1,
    int $perPage = 48,
    ?int $yearFilter = null,
    ?string $cameraFilter = null
  ): array {
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $cameraFilter = is_string($cameraFilter) && $cameraFilter !== '' ? $cameraFilter : null;
    $lists = [];
    $yearCounts = [];
    $total = 0;

    foreach ($cameras as $camera) {
      // Auch bei einem Aufrufer mit all() duerfen Testbilder weder in Items
      // noch Jahreszaehler/Pagination der globalen Sammlung gelangen.
      if ($camera->isTest()) {
        continue;
      }
      if ($cameraFilter !== null && $camera->slug() !== $cameraFilter) {
        continue;
      }
      $info = $this->app->bestOfLocation($camera);
      $dir = $info['directory'];
      if (!is_dir($dir)) {
        continue;
      }
      $basenames = $this->files->orderedBasenames($camera, $dir, null);
      if (!$basenames) {
        continue;
      }

      foreach ($basenames as $basename) {
        if (preg_match('/^(\d{4})\d{2}\d{2}_\d{6}/', $basename, $match) !== 1) {
          continue;
        }
        $year = (int) $match[1];
        $yearCounts[$year] = ($yearCounts[$year] ?? 0) + 1;
      }
      if ($yearFilter !== null) {
        $basenames = array_values(array_filter(
          $basenames,
          static fn(string $basename): bool => preg_match(
            '/^' . preg_quote((string) $yearFilter, '/') . '\d{4}_\d{6}/',
            $basename
          ) === 1
        ));
      }
      if (!$basenames) {
        continue;
      }
      $total += count($basenames);
      $lists[] = [
        'camera' => $camera,
        'directory' => $dir,
        'relative' => $info['relative'],
        'basenames' => $basenames,
      ];
    }

    $totalPages = (int) max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $needed = $page * $perPage;
    $candidates = [];
    foreach ($lists as $list) {
      /** @var Camera $camera */
      $camera = $list['camera'];
      $entries = $this->buildBestOfEntries(
        $camera,
        (string) $list['directory'],
        is_string($list['relative']) ? $list['relative'] : null,
        array_slice($list['basenames'], 0, $needed)
      );
      foreach ($entries as $position => $entry) {
        $entry['camera_slug'] = $camera->slug();
        $entry['camera_title'] = $camera->title();
        $entry['_camera_position'] = $position;
        $candidates[] = $entry;
      }
    }

    usort($candidates, static function (array $a, array $b): int {
      $aTs = isset($a['slot_ts']) ? (int) $a['slot_ts'] : 0;
      $bTs = isset($b['slot_ts']) ? (int) $b['slot_ts'] : 0;
      $comparison = $bTs <=> $aTs;
      if ($comparison !== 0) {
        return $comparison;
      }
      $comparison = strcmp((string) ($a['camera_slug'] ?? ''), (string) ($b['camera_slug'] ?? ''));
      if ($comparison !== 0) {
        return $comparison;
      }
      $comparison = ((int) ($a['_camera_position'] ?? 0)) <=> ((int) ($b['_camera_position'] ?? 0));
      if ($comparison !== 0) {
        return $comparison;
      }
      return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });

    $offset = ($page - 1) * $perPage;
    $items = array_slice($candidates, $offset, $perPage);
    foreach ($items as &$item) {
      unset($item['_camera_position']);
    }
    unset($item);

    krsort($yearCounts, SORT_NUMERIC);
    $years = [];
    foreach ($yearCounts as $year => $count) {
      $years[] = ['year' => (int) $year, 'count' => (int) $count];
    }

    return [
      'items' => array_values($items),
      'page' => $page,
      'per_page' => $perPage,
      'total' => $total,
      'total_pages' => $totalPages,
      'years' => $years,
    ];
  }

  /**
   * Ermittelt die in Best-Of-Dateinamen vorkommenden Jahre und Bildanzahlen.
   *
   * @return list<array{year:int,count:int}>
   */
  public function years(Camera $camera): array
  {
    $info = $this->app->bestOfLocation($camera);
    $dir = $info['directory'];
    if (!is_dir($dir)) {
      return [];
    }

    $counts = [];
    foreach ($this->files->orderedBasenames($camera, $dir, null) as $basename) {
      if (!preg_match('/^(\d{4})\d{2}\d{2}_\d{6}/', $basename, $match)) {
        continue;
      }
      $year = (int) $match[1];
      $counts[$year] = ($counts[$year] ?? 0) + 1;
    }

    if (!$counts) {
      return [];
    }

    krsort($counts, SORT_NUMERIC);
    $years = [];
    foreach ($counts as $year => $count) {
      $years[] = [
        'year' => (int) $year,
        'count' => (int) $count,
      ];
    }
    return $years;
  }

  /**
   * @param list<string> $basenames
   * @return list<array{url:string,thumb_url:string,filename:string,mtime:?int,slot_ts:?int,link:?string}>
   */
  private function buildBestOfEntries(Camera $camera, string $dir, ?string $relativePrefix, array $basenames): array
  {
    $baseUrl = ltrim(rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/'), '/');
    $results = [];
    $hasDatePrefix = static fn(string $name): bool => (bool) preg_match('/^\\d{8}_\\d{6}/', $name);
    foreach ($basenames as $basename) {
      $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
      $relative = null;
      if ($relativePrefix !== null) {
        $relative = $relativePrefix . '/' . $basename;
      } else {
        $relative = $basename;
      }
      if ($relativePrefix === null && $baseUrl === '') {
        continue;
      }
      $prefix = $baseUrl === '' ? '' : $baseUrl . '/';
      $thumbRelative = null;
      $thumbPath = preg_replace('/\.jpg$/i', '_thumb.jpg', $file);
      if ($thumbPath && is_file($thumbPath)) {
        if ($relativePrefix !== null) {
          $thumbRelative = $relativePrefix . '/' . basename($thumbPath);
        } elseif ($baseUrl !== '') {
          $thumbRelative = basename($thumbPath);
        }
      } else {
        // Fallback: nutze Original, wenn kein Thumb existiert
        $thumbRelative = $relative;
      }
      $url = $prefix . ltrim($relative, '/');
      $thumbUrl = $thumbRelative ? ($prefix . ltrim($thumbRelative, '/')) : $url;
      // Falls base_url versehentlich camera.php enthält, entfernen.
      $url = preg_replace('#/camera\\.php/#', '/', $url) ?? $url;
      $thumbUrl = preg_replace('#/camera\\.php/#', '/', $thumbUrl) ?? $thumbUrl;

      $link = null;
      $tsGuess = $this->files->timestamp($relative);
      $slotTs = $hasDatePrefix(basename($file)) ? $tsGuess : null;
      $bestofParam = basename($file);
      $cameraPath = $this->cameraViewPath($camera);
      if ($slotTs !== null) {
        $dt = new \DateTimeImmutable('@' . $tsGuess);
        $dt = $dt->setTimezone(new \DateTimeZone($this->app->config('timezone', 'UTC')));
        $params = [
          'year' => $dt->format('Y'),
          'month' => $dt->format('m'),
          'day' => $dt->format('d'),
          'time' => $dt->format('His'),
          'bestof' => $bestofParam,
        ];
        $query = http_build_query($params);
        $link = str_contains($cameraPath, '?')
          ? ($cameraPath . '&' . $query)
          : ($cameraPath . '?' . $query);
      } else {
        // Ohne Datumspräfix: via bestof-Parameter zurück auf die Kamera-Ansicht.
        $query = http_build_query(['bestof' => $bestofParam]);
        $link = str_contains($cameraPath, '?')
          ? ($cameraPath . '&' . $query)
          : ($cameraPath . '?' . $query);
      }

      // Doppelte camera.php-Segmente korrigieren, falls base_url bereits darauf zeigt
      if (is_string($link)) {
        $link = preg_replace('#/camera\\.php/camera\\.php#', '/camera.php', $link) ?? $link;
      }

      $results[] = [
        'url' => $url,
        'thumb_url' => $thumbUrl,
        'filename' => $basename,
        'mtime' => $slotTs, // UI-Datum nur, wenn wirklich aus Dateiname abgeleitet
        'slot_ts' => $slotTs,
        'link' => $link,
      ];
    }
    return $results;
  }

  /**
   * Auflösung von Relativpfaden inkl. Best-Of-Sonderfall.
   */
  private function resolveAnyStoragePath(Camera $camera, string $relative): ?string
  {
    $rel = ltrim($relative, '/');
    if ($rel === '') {
      return null;
    }
    if (str_starts_with($rel, 'bestof/')) {
      try {
        return $this->app->paths()->storagePath($rel);
      } catch (\Throwable) {
        return null;
      }
    }
    return $this->app->resolveCameraStoragePath($camera, $rel);
  }

  /**
   * Liest die Top-Likes aus der Kamera-DB und behält nur Einträge, deren Bildpfad
   * noch auflösbar ist. Die resultierenden Links führen zurück zur Kameraansicht.
   * Die Bilder müssen nicht bereits im Best-Of-Ordner liegen.
   *
   * @return list<array{url:string,thumb_url:string,filename:string,likes:int,slot_ts:int}>
   */
  public function topLiked(Camera $camera, int $limit = 20): array
  {
    $rows = $this->likes->topLiked($camera, $limit);
    if (!$rows) {
      return [];
    }
    $bestOfLocation = $this->app->bestOfLocation($camera);
    $bestOfDir = $bestOfLocation['directory'];
    $bestOfRelative = $bestOfLocation['relative'];
    $publicBaseArchive = rtrim($this->app->cameraPresenter()->publicBaseUrl($camera), '/');
    $prefixArchive = $publicBaseArchive === '' ? '' : $publicBaseArchive . '/';
    $results = [];
    foreach ($rows as $row) {
      $rel = $row['relative_path'] ?? null;
      if (!is_string($rel) || $rel === '') {
        continue;
      }
      $rel = ltrim($rel, '/');
      $thumbRel = $row['thumb_rel'] ?? $rel;

      // Manche gelikte Capture-Varianten liegen nicht mehr im Cache, obwohl
      // die Best-Of-Kopie weiter existiert. Dann darf das Grid nicht mit 404ern
      // enden, sondern nutzt die stabilere Best-Of-Datei als Fallback.
      if (!$this->storagePathExists($camera, $rel)) {
        $bestOfFallback = $this->bestOfRelativeFromSource($bestOfDir, $bestOfRelative, basename($rel));
        if ($bestOfFallback !== null) {
          $rel = $bestOfFallback;
        }
      }

      if (!is_string($thumbRel) || $thumbRel === '' || !$this->storagePathExists($camera, $thumbRel)) {
        $bestOfThumbFallback = $this->bestOfThumbRelativeFromSource($bestOfDir, $bestOfRelative, basename($rel));
        if ($bestOfThumbFallback !== null) {
          $thumbRel = $bestOfThumbFallback;
        } else {
          $thumbRel = $rel;
        }
      }

      // Öffentlicher Pfad: cache/ bleibt relativ, archive/bestof gehen über publicBaseArchive.
      $publicRel = ltrim($rel, '/');
      $publicThumbRel = ltrim($thumbRel ?? '', '/');
      if (str_starts_with($publicRel, 'archive/') || str_starts_with($publicRel, 'bestof/')) {
        $publicRel = $prefixArchive . $publicRel;
      }
      if (str_starts_with($publicThumbRel, 'archive/') || str_starts_with($publicThumbRel, 'bestof/')) {
        $publicThumbRel = $prefixArchive . $publicThumbRel;
      }
      $url = $publicRel;
      $thumbUrl = $publicThumbRel !== '' ? $publicThumbRel : $publicRel;

      // Link zurück in die Kamera-Ansicht mit Datum/Uhrzeit
      $link = null;
      if (!empty($row['slot_ts'])) {
        $dt = new \DateTimeImmutable('@' . (int)$row['slot_ts']);
        $dt = $dt->setTimezone(new \DateTimeZone($this->app->config('timezone', 'UTC')));
        $params = [
          'year' => $dt->format('Y'),
          'month' => $dt->format('m'),
          'day' => $dt->format('d'),
          'time' => $dt->format('His'),
        ];
        if (str_starts_with($rel, 'bestof/')) {
          $params['bestof'] = basename($rel);
        }
        $params = http_build_query($params);
        $cameraPath = $this->cameraViewPath($camera);
        $link = str_contains($cameraPath, '?')
          ? ($cameraPath . '&' . $params)
          : ($cameraPath . '?' . $params);
      }
      $url = preg_replace('#/camera\\.php/#', '/', $url) ?? $url;
      $thumbUrl = preg_replace('#/camera\\.php/#', '/', $thumbUrl) ?? $thumbUrl;
      $results[] = [
        'url' => $url,
        'thumb_url' => $thumbUrl,
        'filename' => basename($rel),
        'likes' => (int) ($row['likes'] ?? 0),
        'slot_ts' => (int) ($row['slot_ts'] ?? 0),
        'link' => $link,
      ];
    }
    return $results;
  }

  private function cameraViewPath(Camera $camera): string
  {
    $siteBase = rtrim((string) $this->app->config('base_url', ''), '/');
    $siteBase = preg_replace('#/api(?:/.*)?$#i', '', $siteBase) ?: $siteBase;
    if (preg_match('#/camera\\.php$#i', $siteBase)) {
      return './camera.php?cam=' . rawurlencode($camera->slug());
    }
    return './' . rawurlencode($camera->slug());
  }

  private function storagePathExists(Camera $camera, string $relative): bool
  {
    $absolute = $this->resolveAnyStoragePath($camera, $relative);
    return $absolute !== null && is_file($absolute);
  }

  private function bestOfRelativeFromSource(string $bestOfDir, ?string $bestOfRelative, string $basename): ?string
  {
    $candidate = rtrim($bestOfDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($candidate)) {
      return null;
    }
    return $bestOfRelative !== null ? ($bestOfRelative . '/' . $basename) : $basename;
  }

  private function bestOfThumbRelativeFromSource(string $bestOfDir, ?string $bestOfRelative, string $basename): ?string
  {
    $thumbBase = preg_replace('/\.jpg$/i', '_thumb.jpg', $basename);
    if (!is_string($thumbBase) || $thumbBase === '') {
      return null;
    }
    $candidate = rtrim($bestOfDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $thumbBase;
    if (!is_file($candidate)) {
      return null;
    }
    return $bestOfRelative !== null ? ($bestOfRelative . '/' . $thumbBase) : $thumbBase;
  }
}
