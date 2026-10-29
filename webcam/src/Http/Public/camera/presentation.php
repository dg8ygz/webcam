<?php
declare(strict_types=1);

/**
 * Timeline-, Snapshot- und Präsentationsstufe der Kameraansicht.
 *
 * Leseführung:
 * 1. Optionalen HTML-Page-Cache nur für die reine Liveansicht prüfen.
 * 2. Timeline, gewählten Snapshot und Sonderansichten auflösen.
 * 3. Navigation, Wetter/EXIF/Overlay, Karte und Assets zusammenstellen.
 * 4. HTML rendern, atomar cachen und optionale Profilwerte ausgeben.
 *
 * Der gemeinsame Include-Scope ist beabsichtigt: Die Request-Stufe liefert
 * ausschließlich vorbereitete Werte; fachliche Datenzugriffe bleiben in den
 * bereits getrennten Services und View-Model-Buildern.
 */

// Live-/Archivsemantik und Cachefähigkeit sind getrennt: nav oder stream
// verändern die Darstellung, wählen aber kein Archivbild aus. Sie müssen den
// HTML-Cache umgehen, ohne die Seite intern zur Archivansicht zu machen.
$pageRenderingQueryKeys = array_values(array_diff(array_keys($_GET), ['cam', 'slug']));
$hasSelectionParams = $requestHasTimeSelection || isset($_GET['view']) || isset($_GET['bestof']);
$isLiveViewRequest = !$hasSelectionParams;
$isPageCacheEligible = $isLiveViewRequest && $pageRenderingQueryKeys === [];
$pageCacheFile = null;
$pageCacheRoutePrefix = null;
$lastSuccessTs = isset($state['last_success_at']) ? (int) $state['last_success_at'] : null;
if ($isPageCacheEligible && $lastSuccessTs && !$__wc_profile) {
  // Nur die reine Live-Ansicht bekommt das gerenderte HTML aus dem Page-Cache.
  // Sobald der Nutzer Jahr/Monat/Tag/Zeit oder einen speziellen View anfragt,
  // muss der Request frisch gerendert werden, damit Auswahl und Navigation
  // exakt zum gewünschten Snapshot passen.
  $pageCacheVersionSources = [
    $cameraPageEntryFile,
    $cameraPageRoot . '/src/Support/helpers.php',
    $cameraPageRoot . '/src/Service/View/CameraPresenter.php',
    $cameraPageRoot . '/templates/camera.php',
    $cameraPageRoot . '/templates/camera/prepare.php',
    $cameraPageRoot . '/templates/layout.php',
    $cameraPageRoot . '/VERSION',
    $cameraPageRoot . '/ITERATION',
    $cameraPageRoot . '/assets/css/app.css',
    $cameraPageRoot . '/assets/js/app.js',
    $cameraPageRoot . '/assets/js/theme.js',
  ];
  // Der Cache-Fingerprint verwendet dieselbe Assetquelle wie das Layout. Neue
  // Kameraskripte invalidieren dadurch automatisch den Live-HTML-Cache.
  foreach ($cameraPagePayloadBuilder->scripts(true, false) as $cameraPageScript) {
    $pageCacheVersionSources[] = $cameraPageRoot . '/assets/' . $cameraPageScript;
  }
  // Kameramenues sind global: auch das Test-Attribut einer ANDEREN Kamera
  // muss vorhandene Live-Seiten unmittelbar aus dem alten Cache herausnehmen.
  $pageCacheVersionData = ['base' => 'v33', 'config_version' => $app->camerasMeta()['version'] ?? 0];
  foreach ($pageCacheVersionSources as $pageCacheVersionPath) {
    $pageCacheVersionData[$pageCacheVersionPath] = is_file($pageCacheVersionPath)
      ? (int) filemtime($pageCacheVersionPath)
      : 0;
  }
  $pageCacheVersion = 'v29-' . substr(md5(json_encode($pageCacheVersionData, JSON_THROW_ON_ERROR)), 0, 8);
  $pageCacheRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  $pageCacheRequestPath = is_string($pageCacheRequestPath) && $pageCacheRequestPath !== ''
    ? $pageCacheRequestPath
    : '/camera/' . rawurlencode($slug);
  // Pretty-Route mit/ohne abschließenden Slash und camera.php benötigen
  // unterschiedlich tiefe dokumentrelative URLs. Sie dürfen deshalb niemals
  // dieselbe fertig gerenderte HTML-Datei aus dem Page-Cache verwenden.
  $pageCacheBaseUrl = trim((string) $app->config('base_url', ''), '/');
  $pageCacheRouteKey = substr(hash('sha256', $pageCacheRequestPath . "\n" . $pageCacheBaseUrl), 0, 12);
  $pageCacheRoutePrefix = 'live_r' . $pageCacheRouteKey . '_';
  $cacheDir = $app->paths()->statePath('page-cache/' . $slug);
  if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    $app->logger()->warning('page cache dir unavailable', ['dir' => $cacheDir, 'camera' => $slug]);
  } else {
    $pageCacheFile = rtrim($cacheDir, DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR
      . $pageCacheRoutePrefix
      . $pageCacheVersion
      . '_'
      . $lastSuccessTs
      . '.html';
    if (is_file($pageCacheFile) && is_readable($pageCacheFile)) {
      readfile($pageCacheFile);
      return;
    }
  }
}

// Bereichsberechnung, DB-Nächstsuche und begrenzte Legacy-Fallbacks liegen im
// Timeline-Service. camera.php behält nur die View-Model- und Renderlogik.
$lastTs = $cameraData['last_capture'] instanceof DateTimeImmutable
  ? $cameraData['last_capture']->getTimestamp()
  : null;
$timelineResolution = $app->cameraTimelineResolver()->resolve(
  $camera,
  $selectionCriteria,
  $yearParam !== null ? (int) $yearParam : null,
  $monthParam !== null ? (int) $monthParam : null,
  $dayParam !== null ? (int) $dayParam : null,
  $isLiveViewRequest,
  $lastTs,
  $archiveYears
);
$timeline = $timelineResolution['timeline'];
$selectedSnapshot = $timelineResolution['selected_snapshot'];
$selectedDayData = $timelineResolution['selected_day_data'];
$requestedYear = $timelineResolution['requested_year'];
$tzRange = new DateTimeZone((string) $app->config('timezone', 'UTC'));
$__wc_log('timeline resolved');
// Der Modus ändert Darstellung und Navigation, nicht die serverseitige Herkunft
// der Haupttimeline. Best-Of wurde im schnellen Sonderpfad bereits gerendert.
$viewMode = 'live';
if ($viewParam === 'info' && $hasInfoHtml) {
  $viewMode = 'info';
} elseif ($viewParam === 'bestof' && !empty($bestOf)) {
  $viewMode = 'bestof';
} elseif ($viewParam === 'global-bestof') {
  $viewMode = 'global-bestof';
} elseif ($viewParam === 'history') {
  $viewMode = 'history';
} elseif ($viewParam === 'playback' || $viewParam === 'timelapse') {
  $viewMode = 'playback';
}

// Das globale Best Of kann innerhalb einer Kamera geöffnet werden. In diesem
// Modus bleibt deren vollständige Kopfzeile erhalten; nur der Inhalt darunter
// wird durch dieselbe Galerie ersetzt, die auch die Startseite verwendet.
$globalBestOfPage = [];
$globalBestOfPerPage = 48;
$globalSelectedYear = null;
$globalSelectedCamera = null;
$globalCameraOptions = [];
if ($viewMode === 'global-bestof') {
  $globalSelectedYear = isset($_GET['global_year']) && is_numeric($_GET['global_year'])
    ? (int) $_GET['global_year']
    : null;
  $globalSelectedCamera = isset($_GET['global_camera'])
    ? trim((string) $_GET['global_camera'])
    : null;
  $globalSelectedCamera = $globalSelectedCamera !== '' ? $globalSelectedCamera : null;
  $globalCameraOptions = $app->cameras()->listed();
  if ($globalSelectedCamera !== null && $app->cameras()->findListed($globalSelectedCamera) === null) {
    http_response_code(404);
    echo $app->renderer()->render('error', [
      'app' => $app,
      'pageTitle' => 'Kamera nicht gefunden',
      'message' => 'Die gewählte Kamera existiert nicht.',
    ]);
    exit;
  }
  $globalBestOfPage = $app->bestOfGallery()->globalPage(
    $globalCameraOptions,
    1,
    $globalBestOfPerPage,
    $globalSelectedYear,
    $globalSelectedCamera
  );
}
$isArchiveSelection = $hasSelectionParams;
$__wc_log('snapshot selected');
// Legacy-Best-Of-Links enthalten teilweise nur einen Dateinamen. basename hält
// Verzeichnisteile fern; danach wird nach dem echten Capture-Pfad gesucht.
$bestOfParam = isset($_GET['bestof']) ? basename((string)$_GET['bestof']) : null;
if ($bestOfParam !== null) {
  $bestOfInfo = $app->bestOfLocation($camera);
  $bestPath = rtrim($bestOfInfo['directory'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bestOfParam;
  if (is_file($bestPath)) {
    $ts = null;
    if (preg_match('/^(\\d{4})(\\d{2})(\\d{2})_(\\d{2})(\\d{2})(\\d{2})/', $bestOfParam, $m)) {
      $str = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
      $dtGuess = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str, new DateTimeZone($app->config('timezone', 'UTC')));
      if ($dtGuess) {
        $ts = $dtGuess->getTimestamp();
      }
    }
    $exactSnapshot = null;
    if ($ts !== null) {
      $candidateSnapshots = [];
      if (is_array($selectedSnapshot)) {
        $candidateSnapshots[] = $selectedSnapshot;
      }
      foreach (($timeline['entries'] ?? []) as $entry) {
        if (is_array($entry)) {
          $candidateSnapshots[] = $entry;
        }
      }
      foreach (($selectedDayData['entries'] ?? []) as $entry) {
        if (is_array($entry)) {
          $candidateSnapshots[] = $entry;
        }
      }
      foreach ($candidateSnapshots as $candidateSnapshot) {
        if ((int) ($candidateSnapshot['timestamp'] ?? 0) !== $ts) {
          continue;
        }
        if (!empty($candidateSnapshot['variants']) && is_array($candidateSnapshot['variants'])) {
          $exactSnapshot = $candidateSnapshot;
          break;
        }
      }
    }
    if ($exactSnapshot !== null) {
      $selectedSnapshot = $exactSnapshot;
      $selectedSnapshot['source'] = 'bestof';
    } else {
      $dt = $ts !== null ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($app->config('timezone', 'UTC'))) : null;
      $relativeBest = ($bestOfInfo['relative'] !== null ? $bestOfInfo['relative'] . '/' : '') . $bestOfParam;
      $publicBest = ($bestOfInfo['relative'] !== null ? 'storage/' . $relativeBest : $relativeBest);
      $url = ltrim($publicBest, '/');
      $url = preg_replace('#/camera\\.php/#', '/', $url) ?? $url;

      // Versuch: echte Capture-Datei (cache oder archive) zu finden, damit Likes funktionieren.
      $resolvedRel = null;
      if ($dt) {
        $candidateBase = sprintf('%s/%s/%s/%s', $camera->slug(), $dt->format('Y'), $dt->format('m'), $dt->format('d'));
        $candidates = [
          'cache/' . $candidateBase . '/' . $bestOfParam,
          'archive/' . $candidateBase . '/' . $bestOfParam,
        ];
        foreach ($candidates as $cand) {
          $abs = str_starts_with($cand, 'cache/')
            ? $app->paths()->cachePath(substr($cand, strlen('cache/')))
            : $app->paths()->storagePath($cand);
          if (is_file($abs)) {
            $resolvedRel = $cand;
            $publicRel = str_starts_with($cand, 'archive/') ? 'storage/' . $cand : $cand;
            $url = ltrim($publicRel, '/');
            break;
          }
        }
      }

      $selectedSnapshot = [
        'timestamp' => $dt?->getTimestamp(),
        'year' => $dt ? (int) $dt->format('Y') : null,
        'month' => $dt ? (int) $dt->format('m') : null,
        'day' => $dt ? (int) $dt->format('d') : null,
        'time' => $dt ? $dt->format('His') : null,
        'time_label' => $dt ? $dt->format('H:i') : null,
        'datetime_label' => $dt ? $dt->format('d.m.Y H:i') : null,
        'datetime_iso' => $dt ? $dt->format(DateTimeInterface::ATOM) : null,
        'source' => 'bestof',
        'variants' => [
          'best' => [
            'relative_path' => $resolvedRel ?? $relativeBest,
            'url' => $url,
            'filename' => $bestOfParam,
          ],
        ],
        'exif_path' => null,
        'exif_relative' => null,
        'exif_text' => null,
        'weather_path' => null,
        'weather_relative' => null,
        'weather_text' => null,
        'likes_count' => null,
        'relative_path' => $resolvedRel ?? $relativeBest,
        'preview_url' => $url,
        'url' => $url,
        'primary_variant' => 'best',
      ];
    }
  }
}
// Die Bildsuche ist abgeschlossen. Ab hier entstehen Navigation, Downloads,
// Metadaten, Overlay, Karte und schließlich das Template-View-Model.
$snapshot = $selectedSnapshot; // alias for templates expecting $snapshot

$timelineLatestUrl = null;
// Immer die jüngste Aufnahme (letzte 24h) als "Aktuell" – nur laden, wenn nicht im Archiv-Navigationsmodus
if (!$isArchiveSelection && $cameraData['last_capture'] instanceof DateTimeImmutable) {
  $timelineLatestUrl = $buildMinuteLinkFromTimestamp(
    $cameraData['last_capture']->getTimestamp(),
    $slug,
    (string) $app->config('timezone', 'UTC')
  );
} elseif (!$isArchiveSelection) {
  $nowTs = time();
  $recent = $app->archiveBrowser()->timeline($camera, (int)gmdate('Y', $nowTs), $nowTs - 86400, $nowTs);
  if (!empty($recent['latest']) && is_array($recent['latest'])) {
    $timelineLatestUrl = $buildTimelineMinuteLink($recent['latest']);
  }
}
if ($timelineLatestUrl === null && !empty($timeline['latest']) && is_array($timeline['latest'])) {
  $timelineLatestUrl = $buildTimelineMinuteLink($timeline['latest']);
} elseif ($cameraData['last_capture'] instanceof DateTimeImmutable) {
  $timelineLatestUrl = $buildMinuteLinkFromTimestamp(
    $cameraData['last_capture']->getTimestamp(),
    $slug,
    (string) $app->config('timezone', 'UTC')
  );
}
$globalLatestUrl = '?cam=' . urlencode($slug);
if (isset($cameraData['last_capture']) && $cameraData['last_capture'] instanceof DateTimeImmutable) {
  $globalLatestUrl = $buildMinuteLinkFromTimestamp(
    $cameraData['last_capture']->getTimestamp(),
    $slug,
    (string) $app->config('timezone', 'UTC')
  );
}
// In Archiv-Ansicht immer den globalen Latest-Link nutzen (nicht den aktuellen Jahres-Timeline-Latest)
if ($isArchiveSelection) {
  $timelineLatestUrl = $globalLatestUrl;
}

// Der Builder lädt nur die für Nachbarpfeile nötigen Kalenderdaten und gibt
// das bereits templatefertige Navigationsmodell zurück. Die Link-Closures
// bleiben hier, weil sie zuvor gewählte History-/Playback-Parameter enthalten.
$navigationViewModel = $timelineNavigationBuilder->build(
  $camera,
  $selectedSnapshot,
  $timeline,
  $selectedDayData,
  $archiveYearsList,
  [
    'year' => $yearParam,
    'month' => $monthParam,
    'day' => $dayParam,
    'time' => $timeParam !== null ? (string) $timeParam : null,
    'slug' => $slug,
  ],
  $buildTimelineLink,
  $buildYearWithSelection,
  $__wc_log
);
$timeline = $navigationViewModel['timeline'];
$timelineNav = $navigationViewModel['navigation'];
$timePickerOptions = $navigationViewModel['time_picker_options'];
$hourPickerOptions = $navigationViewModel['hour_picker_options'];
$yearPickerOptions = $navigationViewModel['year_picker_options'];

// Menü- und Picker-Links aus derselben geprüften Auswahl bauen. Dadurch bleiben
// sinnvolle Jahr/Monat/Tag/Zeit-Werte beim View-Wechsel erhalten.
$baseQuery = ['cam' => $slug];
if ($timestampParam !== null) {
  $baseQuery['ts'] = (string) $timestampParam;
}
if ($yearParam !== null) {
  $baseQuery['year'] = $yearParam;
}
if ($monthParam !== null) {
  $baseQuery['month'] = sprintf('%02d', (int) $monthParam);
}
if ($dayParam !== null) {
  $baseQuery['day'] = sprintf('%02d', (int) $dayParam);
}
if ($timeParam !== null) {
  $baseQuery['time'] = $timeParam;
}
if ($viewParam === 'history') {
  $baseQuery['view'] = 'history';
}
if ($viewParam === 'playback') {
  $baseQuery['view'] = 'playback';
}
$buildQueryUrl = static function (array $params): string {
  $query = http_build_query($params);
  return $query === '' ? '?' : '?' . $query;
};
$buildMonthUrl = static function (int $monthValue) use ($buildQueryUrl, $baseQuery): string {
  $params = $baseQuery;
  // Beim Monatswechsel darf der alte Tag/Zeitstempel nicht mitgeschleppt werden,
  // sonst entstehen ungültige Ziele (z.B. 30.12 bei aktuellem Datum 14.12).
  unset($params['day'], $params['time'], $params['ts']);
  $params['month'] = sprintf('%02d', $monthValue);
  return $buildQueryUrl($params);
};
$buildDayUrl = static function (int $dayValue) use ($buildQueryUrl, $baseQuery): string {
  $params = $baseQuery;
  $params['day'] = sprintf('%02d', $dayValue);
  return $buildQueryUrl($params);
};
$infoQuery = $baseQuery;
$bestOfQuery = $baseQuery;
$historyQuery = $baseQuery;
$liveQuery = $baseQuery;
unset($liveQuery['view']); // Live-Link soll immer ohne view-Parameter sein
$viewLinks = [
  'live' => $buildQueryUrl($liveQuery),
  'info' => null,
  'bestof' => null,
  'global_bestof' => null,
  'history' => null,
  'playback' => null,
];
if ($hasInfoHtml) {
  $infoQuery['view'] = 'info';
  $viewLinks['info'] = $buildQueryUrl($infoQuery);
}
if (!empty($bestOf)) {
  $bestOfQuery['view'] = 'bestof';
  $viewLinks['bestof'] = $buildQueryUrl($bestOfQuery);
}
$globalBestOfQuery = $baseQuery;
$globalBestOfQuery['view'] = 'global-bestof';
$viewLinks['global_bestof'] = $buildQueryUrl($globalBestOfQuery);
$historyQuery['view'] = 'history';
$viewLinks['history'] = $buildQueryUrl($historyQuery);
$playbackQuery = $baseQuery;
$playbackQuery['view'] = 'playback';
$viewLinks['playback'] = $buildQueryUrl($playbackQuery);
$bestOfLink = $viewLinks['bestof'] ?? null;
if (!$bestOfLink && !empty($bestOf)) {
  $bestOfLink = '?cam=' . urlencode($slug) . '&view=bestof';
}
// Das Template erhält fertige Picker-Labels und URLs. JavaScript öffnet die
// Menüs, baut die Haupttimeline aber nicht clientseitig neu auf.
$monthPickerOptions = [];

$selectedYearValue = $yearParam !== null ? (int)$yearParam : ($selectedSnapshot['year'] ?? $requestedYear ?? null);
$selectedMonthValue = $monthParam !== null ? (int)$monthParam : ($selectedSnapshot['month'] ?? null);
$selectedDayValue = $dayParam !== null ? (int)$dayParam : ($selectedSnapshot['day'] ?? null);
if ($selectedYearValue !== null && isset($timeline['months'][$selectedYearValue]) && is_array($timeline['months'][$selectedYearValue])) {
  foreach ($timeline['months'][$selectedYearValue] as $monthValue) {
    $monthPickerOptions[] = [
      'label' => sprintf('%02d', (int) $monthValue),
      'url' => $buildMonthUrl((int) $monthValue),
      'selected' => (int) $monthValue === (int) ($selectedMonthValue ?? 0),
    ];
  }
}
$dayPickerOptions = [];
if ($selectedYearValue !== null && $selectedMonthValue !== null && isset($timeline['days'][$selectedYearValue][$selectedMonthValue])) {
  foreach ($timeline['days'][$selectedYearValue][$selectedMonthValue] as $dayValue) {
    $dayPickerOptions[] = [
      'label' => sprintf('%02d', (int) $dayValue),
      'url' => $buildDayUrl((int) $dayValue),
      'selected' => (int) $dayValue === (int) ($selectedDayValue ?? 0),
    ];
  }
}
// Alle Zusatzinformationen des ausgewählten Bildes werden gemeinsam und ohne
// HTML-Abhängigkeit aufbereitet. camera.php behält dadurch nur die Auswahl des
// Snapshots und reicht ein fertiges Präsentationsmodell an das Template weiter.
$snapshotPresentation = $snapshotPresentationBuilder->build(
  $camera,
  $selectedSnapshot,
  $state,
  $cameraData,
  $bestOf,
  $isLiveViewRequest,
  $viewMode
);
$exifData = $snapshotPresentation['exif_data'];
$weatherData = $snapshotPresentation['weather_data'];
$overlay = $snapshotPresentation['overlay'];
$logoTone = $snapshotPresentation['logo_tone'];
$frontendOverlaySuppressed = $snapshotPresentation['frontend_overlay_suppressed'];
$cameraLabels = $snapshotPresentation['camera_labels'];
$bestOfState = $snapshotPresentation['best_of_state'];
$weatherDayAvailable = $snapshotPresentation['weather_day_available'];
$modalWeatherText = $snapshotPresentation['modal_weather_text'];
$modalExifText = $snapshotPresentation['modal_exif_text'];

// Kartendaten auf ein kleines Payload reduzieren. Das eigenständige
// camera-map.js zeichnet daraus Marker und Sichtfeld; camera.php enthält damit
// keine JavaScript-Stringverkettung mehr.
$location = $camera->location();
$mapPayload = [
  'lat' => $location['latitude'] ?? 0,
  'lng' => $location['longitude'] ?? 0,
  'bearing' => $location['orientation_deg'] ?? 0,
  'fov' => $location['field_of_view_deg'] ?? 60,
  'reachKm' => $location['orientation_visualization_km'] ?? 5,
  'title' => $camera->title(),
  'centerOffset' => $location['map_center_offset'] ?? 0,
  'centerOffsetY' => $location['map_center_offset'] ?? 0,
  'centerOffsetX' => $location['map_center_offset_x'] ?? 0,
];

$envName = strtolower((string) getenv('WEBCAM_ENV'));
$enableLeaflet = !in_array($viewMode, ['history', 'bestof', 'global-bestof'], true);
$prefetchEnabled = !$isArchiveSelection && $viewMode === 'live';
$timelineFetchEnabled = !in_array($viewMode, ['bestof', 'global-bestof'], true);
if ($envName === 'test') {
  $enableLeaflet = false;
  $prefetchEnabled = false;
  $timelineFetchEnabled = false;
}

$pageHtml = $app->renderer()->render('camera', $cameraPagePayloadBuilder->build($cameraPageBasePayload, [
  'bestOf' => $bestOf,
  'bestOfPage' => $bestOfPage,
  'bestOfPerPage' => $bestOfPerPage,
  'bestOfYears' => $bestOfYears,
  'bestOfSelectedYear' => $bestOfSelectedYear,
  'topLiked' => $topLiked,
  'globalBestOfPage' => $globalBestOfPage,
  'globalBestOfPerPage' => $globalBestOfPerPage,
  'globalSelectedYear' => $globalSelectedYear,
  'globalSelectedCamera' => $globalSelectedCamera,
  'globalCameraOptions' => $globalCameraOptions,
  'timeline' => $timeline,
  'snapshot' => $selectedSnapshot,
  'timelineNav' => $timelineNav,
  'monthPickerOptions' => $monthPickerOptions,
  'dayPickerOptions' => $dayPickerOptions,
  'hourPickerOptions' => $hourPickerOptions,
  'yearPickerOptions' => $yearPickerOptions,
  'timelineTimeOptions' => $timePickerOptions,
  'timelineLatestUrl' => $timelineLatestUrl,
  'exifData' => $exifData,
  'overlay' => $overlay,
  'logoTone' => $logoTone,
  'frontendOverlaySuppressed' => $frontendOverlaySuppressed,
  'cameraLabels' => $cameraLabels,
  'bestOfState' => $bestOfState,
  'sidebarDownloads' => [
    'exif' => $exifData,
    'weather' => $weatherData,
    'weather_available' => $weatherDayAvailable,
    'weatherTimestamp' => $status['last_weather_at'] ?? null,
    'has_info' => $hasInfoHtml,
    'bestof_link' => $viewLinks['bestof'] ?? null,
  ],
  'modalWeatherText' => $modalWeatherText,
  'modalExifText' => $modalExifText,
  'viewMode' => $viewMode,
  'viewLinks' => $viewLinks,
  'bestOfLink' => $bestOfLink,
  'enableLeaflet' => $enableLeaflet,
  'prefetchEnabled' => $prefetchEnabled,
  'timelineFetchEnabled' => $timelineFetchEnabled,
  'isLiveViewRequest' => $isLiveViewRequest,
  'isArchiveSelection' => $isArchiveSelection,
  'mapPayload' => $mapPayload,
]));
$__wc_log('rendered html');

if ($__wc_profile) {
  try {
    $json = json_encode($__wc_marks, JSON_THROW_ON_ERROR);
    $snippet = '<script>(function(){try{const m=' . $json . ';m.forEach(function(entry){var label=entry[0];var t=entry[1];if(typeof t==="number"){console.log("[WC_PROFILE_CLIENT]", t.toFixed(3)+"s", label);}else{console.log("[WC_PROFILE_CLIENT]", label);}});}catch(e){console.warn("WC_PROFILE_CLIENT log failed",e);}})();</script>';
    $pageHtml = str_replace('</body>', $snippet . '</body>', $pageHtml);
  } catch (Throwable $e) {
    // ignore
  }
}

if ($pageCacheFile !== null && !$__wc_profile) {
  $dir = dirname($pageCacheFile);
  $lockPath = $dir . DIRECTORY_SEPARATOR . '.' . ($pageCacheRoutePrefix ?? 'live_') . 'write.lock';
  $lockHandle = @fopen($lockPath, 'c');
  if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX)) {
    $app->logger()->warning('page cache lock failed', ['path' => $lockPath, 'camera' => $slug]);
  } else {
    if (!webcam_atomic_write_file($pageCacheFile, $pageHtml)) {
      $app->logger()->warning('page cache write failed', ['path' => $pageCacheFile, 'camera' => $slug]);
    } else {
      foreach (glob($dir . '/live_*.html') ?: [] as $old) {
        if ($old === $pageCacheFile) {
          continue;
        }
        $basename = basename($old);
        $isLegacyCache = !str_starts_with($basename, 'live_r');
        $isSameRouteCache = is_string($pageCacheRoutePrefix)
          && str_starts_with($basename, $pageCacheRoutePrefix);
        if (($isLegacyCache || $isSameRouteCache) && is_file($old) && !unlink($old) && is_file($old)) {
          $app->logger()->warning('page cache cleanup failed', ['path' => $old, 'camera' => $slug]);
        }
      }
    }
    flock($lockHandle, LOCK_UN);
  }
  if (is_resource($lockHandle)) {
    fclose($lockHandle);
  }
}
echo $pageHtml;
$__wc_log('output flushed');

if ($__wc_profile) {
  // Zusammenfassung als einzelne Zeile (gut greifbar für curl/grep)
  $findMark = static function (string $label) use ($__wc_marks): ?float {
    foreach ($__wc_marks as $entry) {
      if ($entry[0] === $label) {
        return (float) $entry[1];
      }
    }
    return null;
  };

  $summary = [];
  $tBootstrap = $findMark('bootstrap');
  $tTimeline = $findMark('timeline loaded (day fs)') ?? $findMark('timeline loaded');
  $tSnapshot = $findMark('snapshot selected');
  $tEntries = $findMark('entriesForDay loaded') ?? $findMark('entriesForDay reused');
  $tNav = $findMark('nav prep done');
  $tRender = $findMark('rendered html');
  $tTotal = $findMark('output flushed') ?? (microtime(true) - $__wc_t0);

  if ($tTimeline !== null && $tBootstrap !== null) {
    $summary[] = sprintf('timeline=%.3fs', $tTimeline - $tBootstrap);
  }
  if ($tSnapshot !== null && $tTimeline !== null) {
    $summary[] = sprintf('select=%.3fs', $tSnapshot - $tTimeline);
  }
  if ($tEntries !== null && $tSnapshot !== null) {
    $summary[] = sprintf('entries=%.3fs', $tEntries - $tSnapshot);
  }
  if ($tNav !== null && $tEntries !== null) {
    $summary[] = sprintf('nav=%.3fs', $tNav - $tEntries);
  }
  if ($tRender !== null && $tNav !== null) {
    $summary[] = sprintf('render=%.3fs', $tRender - $tNav);
  }
  if ($tTotal !== null) {
    $summary[] = sprintf('total=%.3fs', $tTotal);
  }
  $summary[] = sprintf('peak_memory=%.1fMiB', memory_get_peak_usage(true) / 1048576);

  $summaryLine = '[WC_PROFILE_SUMMARY] ' . implode('; ', $summary);
  echo "<!-- {$summaryLine} -->\n";
  try {
    $logFile = $app->paths()->statePath('wc_profile.log');
    if (file_put_contents($logFile, $summaryLine . PHP_EOL, FILE_APPEND) === false) {
      error_log('camera.php: wc_profile summary write failed');
    }
  } catch (Throwable) {
    // ignore
  }
}
$hasInfoHtml = $infoHtml !== '';
if ($viewMode === 'info' && !$hasInfoHtml) {
  $viewMode = 'live';
}
