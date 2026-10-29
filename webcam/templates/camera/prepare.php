<?php
declare(strict_types=1);

// Darstellungsschicht der Kameraansicht. camera.php hat Bild, Navigation,
// Metadaten und View-Modus bereits ausgewählt; dieses Template baut daraus Menüs
// und HTML für Live, Archiv, Info, Best-Of und Playback. Es führt keine DB-
// Schreiboperationen aus. Closure-use-Listen müssen alle verwendeten Werte
// ausdrücklich enthalten, weil ein fehlender Wert die ganze Seite abbrechen kann.
//
// Leseführung:
// 1. Eingabedaten und dokumentrelative Asset-URLs vorbereiten,
// 2. passendes Hauptbild, Placeholder, Varianten und Overlays bestimmen,
// 3. Links, Streammodus und Menüzustand aus dem gewählten View ableiten,
// 4. wiederverwendete Timeline-/Menüblöcke als Render-Closures aufbauen,
// 5. History-/Dayvideo-Daten nur für die benötigte Ansicht bereitstellen,
// 6. ganz unten ausschließlich den ausgewählten HTML-View ausgeben.

/** @var array<string,mixed> $camera */
/** @var array<string,mixed> $status */
/** @var array{current:list<string>,archive:list<string>} $archiveYears */
/** @var list<array{url:string,filename:string,mtime:int}> $bestOf */
/** @var list<array{url:string,thumb_url:string,filename:string,likes:int,slot_ts:int}> $topLiked */
/** @var list<array{year:int,count:int}> $bestOfYears */
/** @var int|null $bestOfSelectedYear */
/** @var array<string,mixed> $timeline */
/** @var array<string,mixed>|null $snapshot */
/** @var array{
 *   year:array{label:string,prev_url:?string,next_url:?string},
 *   month:array{label:string,prev_url:?string,next_url:?string},
 *   day:array{label:string,prev_url:?string,next_url:?string},
 *   time:array{label:string,prev_url:?string,next_url:?string},
 *   selected_label:string,
 *   has_data:bool
 * } $timelineNav */
/** @var string|null $timelineLatestUrl */
/** @var list<array{label:string,timestamp:int,url:string,selected:bool}> $timelineTimeOptions */
/** @var string|null $exifData */
/** @var array{parts:list<string>,tone:string,luminance:?float}|null $overlay */
/** @var array{url:string,width:?int,height:?int,offset_top:int,offset_right:int,opacity:float}|null $overlayLogo */
/** @var array{relative_path:?string,is_best_of:bool} $bestOfState */
/** @var list<array<string,mixed>> $cameraLabels */
/** @var bool $frontendOverlaySuppressed */
/** @var bool $prefetchEnabled */
/** @var bool $timelineFetchEnabled */
/** @var array<string,int|float|string> $mapPayload */
/** @var bool $wcProfile */
/** @var string $viewMode */
/** @var array{live:string,info:?string,bestof:?string,global_bestof:?string,history:?string,playback:?string} $viewLinks */
/** @var string|null $logoTone */
$requestedStreamMode = isset($requestedStreamMode) && is_string($requestedStreamMode)
  ? $requestedStreamMode
  : '';

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$labelToggleInitiallyHidden = $frontendOverlaySuppressed || empty($cameraLabels);
$relativePrefix = webcam_document_relative_prefix($basePathValue);

// Asset- und Overlay-Grenze: interne relative Pfade bleiben unter dem aktuellen
// Request-Prefix; externe URLs werden nicht versehentlich als lokale Datei
// interpretiert. Existenzprüfungen für Overlays bleiben innerhalb der erlaubten
// Cache-/Legacy-Verzeichnisse.
$normalizeAsset = static function (string $path) use ($relativePrefix): string {
  if ($path === '') {
    return $relativePrefix;
  }
  if (str_starts_with($path, '/')) {
    return $path;
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  $trimmed = ltrim($path, '/');
  return $relativePrefix . $trimmed;
};

$overlayAssetExists = static function (string $url) use ($app): bool {
  $parts = parse_url($url);
  $path = isset($parts['path']) && is_string($parts['path']) ? ltrim($parts['path'], '/') : '';
  if ($path !== 'overlay.php') {
    return true;
  }
  $query = [];
  parse_str(isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '', $query);
  $slug = isset($query['slug']) && is_string($query['slug']) ? $query['slug'] : '';
  $file = isset($query['file']) && is_string($query['file']) ? $query['file'] : '';
  if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    return false;
  }
  if ($file === '' || preg_match('#[\\/]|\\.{2}#', $file)) {
    return false;
  }
  $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
  if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
    return false;
  }

  $primaryPath = $app->paths()->cachePath($slug . '/overlays/' . $file);
  $legacyPath = dirname(__DIR__) . '/assets/overlays/' . $slug . '/' . $file;
  return is_file($primaryPath) || is_file($legacyPath);
};

$formatTime = static function (?int $timestamp): string {
  if (!$timestamp) {
    return '—';
  }
  return date('d.m.Y H:i \U\h\r', $timestamp);
};

$buildLogo = static function (?array $config) use ($normalizeAsset, $overlayAssetExists): ?array {
  if (!is_array($config)) {
    return null;
  }
  $logoUrl = isset($config['url']) && is_string($config['url'])
    ? trim($config['url'])
    : '';
  if ($logoUrl === '') {
    return null;
  }
  if (!$overlayAssetExists($logoUrl)) {
    return null;
  }
  if (!preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $logoUrl) && !str_starts_with($logoUrl, '/')) {
    $trimmed = ltrim($logoUrl, '/');
    $isAssets = str_starts_with($trimmed, 'assets/');
    $isOverlay = str_starts_with($trimmed, 'overlay.php');
    $isCache = str_starts_with($trimmed, 'cache/');
    if (!$isAssets && !$isOverlay && !$isCache) {
      $logoUrl = 'assets/' . $trimmed;
    } else {
      $logoUrl = $trimmed;
    }
  }
  $opacity = isset($config['opacity']) ? (float) $config['opacity'] : 1.0;
  $logo = [
    'url' => $normalizeAsset($logoUrl),
    'width' => isset($config['width']) ? (int) $config['width'] : null,
    'height' => isset($config['height']) ? (int) $config['height'] : null,
    'offset_top' => isset($config['offset_top']) ? (int) $config['offset_top'] : 0,
    'offset_right' => isset($config['offset_right']) ? (int) $config['offset_right'] : 0,
    'opacity' => max(0.0, min(1.0, $opacity)),
  ];
  $styleParts = [
    sprintf('top:%dpx', $logo['offset_top']),
    sprintf('right:%dpx', $logo['offset_right']),
    sprintf('opacity:%.3f', $logo['opacity']),
    'transform:none',
  ];
  if (!empty($logo['width'])) {
    $styleParts[] = sprintf('width:%dpx', (int) $logo['width']);
  }
  if (!empty($logo['height'])) {
    $styleParts[] = sprintf('height:%dpx', (int) $logo['height']);
  }
  $logo['style'] = implode('; ', $styleParts) . ';';
  return $logo;
};

$logoLightConfig = $buildLogo($camera['overlay_logo'] ?? null);
$logoDarkConfig = $buildLogo($camera['overlay_logo_dark'] ?? null);

$timelineEndpoint = 'api/public.php/cameras/' . rawurlencode((string) ($camera['slug'] ?? '')) . '/timeline';
$historyEndpoint = 'api/public.php/cameras/' . rawurlencode((string) ($camera['slug'] ?? '')) . '/history';
$historyFallbackEndpoint = 'api/history.php?slug=' . rawurlencode((string) ($camera['slug'] ?? ''));
$historyRefTs = null;
if (isset($snapshot['timestamp']) && is_numeric($snapshot['timestamp'])) {
  $historyRefTs = (int) $snapshot['timestamp'];
}
if ($historyRefTs === null && ($yearParam !== null || $monthParam !== null || $dayParam !== null || $timeParam !== null)) {
  $tz = new DateTimeZone($app->config('timezone', 'UTC'));
  $refYear = $yearParam !== null ? (int) $yearParam : null;
  if ($refYear !== null) {
    $refMonth = $monthParam !== null ? (int) $monthParam : 1;
    $refDay = $dayParam !== null ? (int) $dayParam : 1;
    $timeDigits = $timeParam !== null ? preg_replace('/[^0-9]/', '', (string) $timeParam) : '';
    if (!is_string($timeDigits)) {
      $timeDigits = '';
    }
    $timeDigits = $timeDigits !== '' ? str_pad($timeDigits, 6, '0', STR_PAD_RIGHT) : '000000';
    $refDt = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i:s',
      sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        $refYear,
        $refMonth,
        $refDay,
        (int) substr($timeDigits, 0, 2),
        (int) substr($timeDigits, 2, 2),
        (int) substr($timeDigits, 4, 2)
      ),
      $tz
    );
    if ($refDt instanceof DateTimeImmutable) {
      $historyRefTs = $refDt->getTimestamp();
    }
  }
}
if ($historyRefTs === null) {
  $historyRefTs = $camera['last_capture'] instanceof DateTimeImmutable
    ? $camera['last_capture']->getTimestamp()
    : ($status['last_success_at'] ?? time());
}
$isPlaybackView = $viewMode === 'playback';
$selectedYearAttr = $snapshot['year'] ?? $yearParam ?? '';
$selectedMonthAttr = $snapshot['month'] ?? $monthParam ?? '';
$selectedDayAttr = $snapshot['day'] ?? $dayParam ?? '';
$selectedTimeAttr = $snapshot['time'] ?? $timeParam ?? '';
$isPlaybackView = $viewMode === 'playback';

$storageCurrentDir = $camera['storage_current_dir'] ?? 'current';
if (!is_string($storageCurrentDir) || $storageCurrentDir === '') {
  $storageCurrentDir = 'current';
}
$storageCurrentDir = trim($storageCurrentDir, '/ ');
if ($storageCurrentDir === '') {
  $storageCurrentDir = 'current';
}

$cameraTags = $camera['tags'] ?? [];
if (is_string($cameraTags)) {
  $cameraTags = array_map('trim', explode(',', $cameraTags));
}
$cameraTags = is_array($cameraTags)
  ? array_values(array_filter(array_map(static fn($tag) => trim((string) $tag), $cameraTags), static fn(string $tag): bool => $tag !== ''))
  : [];
$nativeDisplayEnabled = in_array('__native_display', $cameraTags, true);

// Bildauswahl: aus den bekannten Varianten entstehen ein kleiner schneller
// Placeholder, das normale Anzeigebild und ein srcset. Bei Archiv-/Snapshot-
// Ansichten gewinnt deren Aufnahme gegenüber dem aktuellen Livebild.
$activeImageUrl = $camera['current_image_url'];
$resUrls = $camera['resolutions_urls'] ?? [];
$availableWidths = array_map('intval', array_keys($resUrls));
sort($availableWidths, SORT_NUMERIC);
$defaultWidth = null;
$targetDefault = 400;
if (isset($resUrls[$targetDefault])) {
  $defaultWidth = $targetDefault;
} elseif ($availableWidths) {
  foreach ($availableWidths as $w) {
    if ($w >= 300) {
      $defaultWidth = $w;
      break;
    }
  }
  if ($defaultWidth === null) {
    $defaultWidth = max($availableWidths);
  }
}
$placeholderWidth = null;
if ($availableWidths) {
  foreach ($availableWidths as $w) {
    if ($w >= 300) {
      $placeholderWidth = $w;
      break;
    }
  }
  if ($placeholderWidth === null) {
    $placeholderWidth = min($availableWidths);
  }
}
$placeholderUrl = $placeholderWidth !== null && isset($resUrls[$placeholderWidth])
  ? $resUrls[$placeholderWidth]
  : $activeImageUrl;
if ($defaultWidth !== null && isset($resUrls[$defaultWidth])) {
  $activeImageUrl = $resUrls[$defaultWidth];
}
$activeCacheKey = time();
$activeImageTimestamp = $activeCacheKey;
$activeImageSource = null;
$activeImageSourceType = null;
$resolveSourceType = static function (?string $value): ?string {
  if ($value === null || $value === '') {
    return null;
  }
  $parts = explode('/', $value);
  $type = strtolower($parts[0] ?? '');
  if ($type === '') {
    return null;
  }
  return $type;
};
$lastCapture = $camera['last_capture'] ?? null;
// Varianten-Mapping für benannte Keys
$variantWidthMap = ['sm' => 114, 'la' => 816, 'lm' => 1200, 'hu' => 4000, 'full' => 4000, 'original' => 4000];
$resolveVariantWidth = static function (string|int $key, array $variant) use ($variantWidthMap): ?int {
  if (isset($variant['width']) && is_numeric($variant['width'])) {
    return (int) $variant['width'];
  }
  if (is_numeric($key)) {
    return (int) $key;
  }
  return $variantWidthMap[(string) $key] ?? null;
};
$nativeDisplayMaxWidth = 0;

if ($snapshot) {
  $activeCacheKey = (int) ($snapshot['timestamp'] ?? time());
  $activeImageTimestamp = $activeCacheKey;
  $sourceValue = $snapshot['source'] ?? null;
  $activeImageSource = is_string($sourceValue) ? $sourceValue : null;
  $activeImageSourceType = $resolveSourceType($activeImageSource);

// Wähle kleinstmögliche Variante als Placeholder aus Snapshot-Varianten
$variants = $snapshot['variants'] ?? [];
$bestUrl = null;
$bestWidth = null;
foreach ($variants as $key => $variant) {
  $url = $variant['url'] ?? null;
    if (!is_string($url) || $url === '') {
      continue;
    }
    $width = $resolveVariantWidth($key, is_array($variant) ? $variant : []);
    if ($width === null) {
      continue;
    }
    if ($bestWidth === null || $width < $bestWidth) {
      $bestWidth = $width;
      $bestUrl = $url;
    }
  }
if ($bestUrl !== null) {
  $placeholderUrl = $bestUrl;
} elseif (!empty($snapshot['preview_url'])) {
  $placeholderUrl = $snapshot['preview_url'];
}
// Fallback auf current nur, falls keine Snapshot-Variante gefunden
if ($placeholderUrl === null) {
  $placeholderUrl = $activeImageUrl;
}
}
if (!$snapshot && $lastCapture instanceof DateTimeImmutable) {
  $activeImageTimestamp = $lastCapture->getTimestamp();
}
if ($activeImageSourceType === null) {
  $activeImageSourceType = $resolveSourceType($storageCurrentDir);
}

// Full image: größtmögliche Variante bis 1600px bevorzugt, Srcset: alle Varianten bis 1600px (fallback max)
$fullImageUrl = $activeImageUrl;
$fullSrcset = [];
$displaySrcsetCap = 1600;

$collectVariant = static function (string $url, ?int $w) use (&$fullSrcset, $displaySrcsetCap): void {
  if ($w === null || $w <= 0 || $w > $displaySrcsetCap) {
    return;
  }
  $fullSrcset[$w] = "{$url} {$w}w";
};

if ($snapshot && !empty($snapshot['variants']) && is_array($snapshot['variants'])) {
  $maxW = 0;
  $bestUnderCap = 0;
  $cap = 1600;
  foreach ($snapshot['variants'] as $key => $variant) {
    if (!isset($variant['url'])) {
      continue;
    }
    $url = (string) $variant['url'];
    $w = $resolveVariantWidth($key, is_array($variant) ? $variant : []);
    $collectVariant($url, $w);
    if ($w !== null && $w > $nativeDisplayMaxWidth) {
      $nativeDisplayMaxWidth = $w;
    }
    if ($w !== null && $w > $maxW) {
      $maxW = $w;
    }
    if ($w !== null && $w <= $cap && $w > $bestUnderCap) {
      $bestUnderCap = $w;
      $fullImageUrl = $url;
    }
  }
  if ($bestUnderCap === 0 && $maxW > 0) {
    // kein <=cap gefunden, nimm größtes verfügbares
    foreach ($snapshot['variants'] as $key => $variant) {
      if (!isset($variant['url'])) {
        continue;
      }
      $w = $resolveVariantWidth($key, is_array($variant) ? $variant : []);
      if ($w === $maxW) {
        $fullImageUrl = (string) $variant['url'];
        break;
      }
    }
  } elseif ($bestUnderCap === 0 && !empty($snapshot['preview_url'])) {
    $fullImageUrl = $snapshot['preview_url'];
  }
} else {
  $maxW = 0;
  $bestUnderCap = 0;
  $cap = 1600;
  foreach ($availableWidths as $w) {
    if (!isset($resUrls[$w])) {
      continue;
    }
    $url = $resUrls[$w];
    $collectVariant($url, $w);
    if ($w > $maxW) {
      $maxW = $w;
    }
    if ($w <= $cap && $w > $bestUnderCap) {
      $bestUnderCap = $w;
      $fullImageUrl = $url;
    }
  }
  if ($bestUnderCap === 0 && $maxW > 0) {
    foreach ($availableWidths as $w) {
      if ($w === $maxW && isset($resUrls[$w])) {
        $fullImageUrl = $resUrls[$w];
        break;
      }
    }
  }
}

// Wenn wir ein größeres Bild haben, setze Full auf max, Placeholder bleibt (klein)
$activeImageSrc = $fullImageUrl !== '' ? $fullImageUrl : $activeImageUrl;
$fullSrcsetAttr = $fullSrcset ? implode(', ', $fullSrcset) : '';
if ($nativeDisplayEnabled && $activeImageSrc !== '') {
  // Datenschutzkameras mit kleiner Quellauflösung sollen nicht erst klein geladen und
  // danach sichtbar aufgeblasen werden. Für diesen Modus laden wir direkt die native Quelle.
  $placeholderUrl = $activeImageSrc;
  $fullSrcsetAttr = '';
}

$normalizeSrcset = static function (string $srcset) use ($normalizeAsset): string {
  $items = array_filter(array_map('trim', explode(',', $srcset)));
  $normalized = [];
  foreach ($items as $item) {
    if ($item === '') {
      continue;
    }
    $parts = preg_split('/\s+/', $item);
    $url = $parts[0] ?? '';
    $descriptor = $parts[1] ?? '';
    if ($url === '') {
      continue;
    }
    $url = $normalizeAsset($url);
    $normalized[] = trim($url . ($descriptor !== '' ? ' ' . $descriptor : ''));
  }
  return implode(', ', $normalized);
};

$placeholderUrlNormalized = $normalizeAsset((string) $placeholderUrl);
$activeImageSrcNormalized = $normalizeAsset((string) $activeImageSrc);
$fullSrcsetAttrNormalized = $fullSrcsetAttr ? $normalizeSrcset($fullSrcsetAttr) : '';

$bestOfRelativePath = null;
$bestOfActive = false;
if (!empty($bestOfState['relative_path']) && is_string($bestOfState['relative_path'])) {
  $bestOfRelativePath = $bestOfState['relative_path'];
  $bestOfActive = !empty($bestOfState['is_best_of']);
}

// Inline-Lazyloader für EXIF/Best-Of
$scripts[] = 'js/lazy-meta.js';

$infoLink = $viewLinks['info'] ?? null;
$historyLink = $viewLinks['history'] ?? null;
$bestofLink = $viewLinks['bestof'] ?? null;
$slugForLinks = '';
if (isset($slug) && is_string($slug) && $slug !== '') {
  $slugForLinks = $slug;
} elseif (isset($camera['slug']) && is_string($camera['slug'])) {
  $slugForLinks = $camera['slug'];
}
$bestofNavLink = $bestofLink ?: ($slugForLinks !== '' ? ('?cam=' . urlencode($slugForLinks) . '&view=bestof') : '#');
$bestofNavLink = is_string($bestofNavLink) && $bestofNavLink !== '' ? $bestofNavLink : '#';

$basePrefix = trim((string) $app->config('base_url', ''), '/');
$stripBase = static function (string $val) use ($basePrefix): string {
  if ($basePrefix !== '' && str_starts_with($val, $basePrefix . '/')) {
    return substr($val, strlen($basePrefix) + 1);
  }
  return $val;
};

foreach ($bestOf as $index => $item) {
  $url = $item['url'] ?? '';
  if ($url && !preg_match('#^(?:[a-z]+:)?//#i', $url)) {
    $bestOf[$index]['url'] = $normalizeAsset($stripBase($url));
  }
  $thumb = $item['thumb_url'] ?? '';
  if ($thumb && !preg_match('#^(?:[a-z]+:)?//#i', $thumb) && !str_starts_with($thumb, '/')) {
    $bestOf[$index]['thumb_url'] = $normalizeAsset($stripBase($thumb));
  } elseif (!isset($bestOf[$index]['thumb_url']) && isset($bestOf[$index]['url'])) {
    $bestOf[$index]['thumb_url'] = $bestOf[$index]['url'];
  }
}

$topLiked = array_map(static function (array $item) use ($normalizeAsset, $stripBase): array {
  if (!empty($item['url']) && is_string($item['url'])) {
    $item['url'] = $normalizeAsset($stripBase($item['url']));
  }
  if (!empty($item['thumb_url']) && is_string($item['thumb_url'])) {
    $item['thumb_url'] = $normalizeAsset($stripBase($item['thumb_url']));
  }
  return $item;
}, $topLiked ?? []);
$liveImageClasses = ['live-image'];
if ($viewMode === 'info' || $viewMode === 'bestof' || !$snapshot) {
  $liveImageClasses[] = 'is-static';
}
// In non-live views we don't load a live image; suppress the "Bild wird geladen" placeholder.
if ($viewMode !== 'live') {
  $liveImageClasses[] = 'is-loaded';
}
$infoBackgroundStyle = '';
if (!empty($activeImageUrl)) {
  $infoBackgroundStyle = '--live-info-bg: url("' . htmlspecialchars($normalizeAsset($activeImageUrl), ENT_QUOTES) . '");';
}
$liveImageClassAttr = implode(' ', $liveImageClasses);
$currentSnapshotTimestamp = $snapshot['timestamp'] ?? null;
$cameraSlug = (string) ($camera['slug'] ?? '');
$stream = is_array($camera['stream'] ?? null) ? $camera['stream'] : [];
$streamMode = strtolower((string) ($stream['mode'] ?? ''));
$captureTransport = strtolower((string) ($camera['capture_transport'] ?? ''));
$streamEndpoint = '';
$streamHlsEndpoint = '';
$streamMaxRuntimeSeconds = isset($stream['max_runtime_seconds']) ? (int) $stream['max_runtime_seconds'] : 0;
if ($streamMaxRuntimeSeconds <= 0) {
  $streamMaxRuntimeSeconds = (int) getenv('WEBCAM_STREAM_MAX_SECONDS');
}
if ($streamMaxRuntimeSeconds <= 0) {
  $streamMaxRuntimeSeconds = 1800;
}
$streamMaxRuntimeSeconds = max(30, min(86400, $streamMaxRuntimeSeconds));
if ($cameraSlug !== '' && $streamMode === 'rtsp') {
  $streamEndpoint = $normalizeAsset('stream/' . rawurlencode($cameraSlug));
  $streamHlsEndpoint = $normalizeAsset('stream/' . rawurlencode($cameraSlug) . '?format=hls');
} elseif ($streamMode === 'mjpeg') {
  $candidate = (string) ($stream['playlist_url'] ?? $stream['snapshot_url'] ?? '');
  if ($candidate !== '') {
    $streamEndpoint = $normalizeAsset($candidate);
  }
} elseif ($streamMode === 'hls') {
  $candidate = (string) ($stream['playlist_url'] ?? $stream['snapshot_url'] ?? '');
  if ($candidate !== '') {
    $streamHlsEndpoint = $normalizeAsset($candidate);
  }
}
if ($streamHlsEndpoint === '' && $cameraSlug !== '' && $captureTransport === 'rtsp') {
  $streamHlsEndpoint = $normalizeAsset('stream/' . rawurlencode($cameraSlug) . '?format=hls');
}
if ($requestedStreamMode === 'hls') {
  // Bewusste Auswahl über ?stream=hls: nicht zuerst einen MJPEG-Slot belegen.
  $streamEndpoint = '';
}
$streamEnabled = ($streamEndpoint !== '' || $streamHlsEndpoint !== '') && $viewMode === 'live';
$liveDisplayDefault = $streamEnabled ? 'stream' : 'snapshot';
if ($streamHlsEndpoint !== '' && $viewMode === 'live') {
  $scripts[] = 'vendor/hls/hls.light.min.js';
}

// The opt-in testcam uses the normal camera layout and navigation. Its bounded
// on-demand controller remains separate from the existing RTSP lifecycle.
$usbLiveLink = null;
if ($cameraSlug === 'test') {
  try {
    if (\Webcam\Service\Stream\UsbStream::forPaths($app->paths())->registration($cameraSlug)) {
      $usbLiveLink = $relativePrefix . 'camera/test?stream=usb';
    }
  } catch (\Throwable $error) {
    $app->logger()->warning('USB livestream menu unavailable', ['camera' => $cameraSlug]);
  }
}
$usbLiveActive = $usbLiveLink !== null && $requestedStreamMode === 'usb' && $viewMode === 'live';
if ($usbLiveActive) {
  // Returning from video means the current photo, not an archive selection
  // frozen when the stream page opened. Keep this route free of date/stream
  // parameters so the regular live-page path can serve the latest capture.
  $viewLinks['live'] = $relativePrefix . 'camera/' . rawurlencode($cameraSlug);
  $timelineLatestUrl = $viewLinks['live'];
  $streamEnabled = false; // Do not start a second RTSP/HLS controller.
  $liveImageClassAttr .= ' is-usb-live is-loaded';
  $scripts[] = 'vendor/hls/hls.light.min.js';
  $scripts[] = 'js/usb-live.js';
  $styles[] = 'css/usb-live.css';
  $scripts = array_values(array_unique($scripts));
}

$navSlugList = [];
if (isset($cameras) && is_array($cameras)) {
  $navSlugList = array_map(static fn($c) => ['slug' => $c['slug'] ?? '', 'title' => $c['title'] ?? ($c['slug'] ?? '')], $cameras);
}
if (!$navSlugList && isset($app)) {
  foreach ($app->cameras()->listed() as $c) {
    $navSlugList[] = ['slug' => $c->slug(), 'title' => $c->title()];
  }
}

$requestPathRaw = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestPathRaw = is_string($requestPathRaw) ? $requestPathRaw : '';
$requestPath = rtrim($requestPathRaw, '/');
$routerNavPrefix = $relativePrefix . 'camera/';
if ($requestPath !== '' && preg_match('#/camera/[^/]+$#', $requestPath)) {
  $routerNavPrefix = str_ends_with($requestPathRaw, '/') ? '../' : './';
}
$buildCameraNavLink = static function (string $slug) use ($routerNavPrefix): string {
  $safeSlug = rawurlencode($slug);
  return $routerNavPrefix . $safeSlug;
};

$appendNavParam = static function (?string $url, string $navMode): ?string {
  if ($url === null || $url === '' || $navMode === 'time') {
    return $url;
  }
  $allowed = ['year', 'month', 'day', 'hour', 'bestof'];
  if (!in_array($navMode, $allowed, true)) {
    return $url;
  }
  $parts = parse_url($url);
  if ($parts === false) {
    return $url;
  }
  $query = [];
  if (!empty($parts['query'])) {
    parse_str($parts['query'], $query);
  }
  $query['nav'] = $navMode;
  $queryString = http_build_query($query);
  $scheme = $parts['scheme'] ?? '';
  $host = $parts['host'] ?? '';
  $port = isset($parts['port']) ? ':' . $parts['port'] : '';
  $path = $parts['path'] ?? '';
  $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
  $prefix = $scheme !== '' && $host !== '' ? ($scheme . '://' . $host . $port) : '';
  $result = $prefix . $path;
  if ($queryString !== '') {
    $result .= '?' . $queryString;
  }
  if ($fragment !== '') {
    $result .= $fragment;
  }
  if ($result === '' && $queryString !== '') {
    $result = '?' . $queryString;
  }
  return $result !== '' ? $result : $url;
};

$timelineInteractive = !in_array($viewMode, ['bestof', 'global-bestof', 'playback'], true);
// Playback disables archive pickers, but must retain an escape to the current
// photo. Do not carry the selected day, view or stream parameters into it.
if ($isPlaybackView) {
  $timelineLatestUrl = $relativePrefix . 'camera/' . rawurlencode($cameraSlug);
  $viewLinks['live'] = $timelineLatestUrl;
}
$bestOfInfoToolsDisabled = in_array($viewMode, ['bestof', 'global-bestof'], true);
$timelineCameraTitle = $viewMode === 'global-bestof'
  ? 'Globales Best-Of'
  : (string) (($camera['short_title'] ?? '') !== '' ? $camera['short_title'] : ($camera['title'] ?? $cameraSlug));
