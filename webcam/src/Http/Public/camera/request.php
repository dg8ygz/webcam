<?php
declare(strict_types=1);

/**
 * Request- und Schnellpfad der öffentlichen Kameraansicht.
 *
 * Leseführung:
 * 1. Kamera, Informationstext und vorhandene Archivjahre laden.
 * 2. Zeit-, Ansichts- und Streamparameter streng normalisieren.
 * 3. Link-Builder mit erhaltener History-/Playback-Semantik vorbereiten.
 * 4. Den eigenständigen Best-Of-Modus ohne teure Timeline früh rendern.
 *
 * Diese Datei ist kein HTTP-Einstieg. camera.php bindet sie in seinen Scope
 * ein; die unten erzeugten Variablen sind Eingaben für presentation.php.
 */

// ---------------------------------------------------------------------------
// Input parsing: slug, selection, derived time fields.
// ---------------------------------------------------------------------------
$slug = isset($_GET['cam']) ? (string) $_GET['cam'] : (isset($_GET['slug']) ? (string) $_GET['slug'] : '');
try {
  $camera = $app->cameraOrFail($slug);
} catch (\RuntimeException $e) {
  http_response_code(404);
  echo $app->renderer()->render('error', [
    'app' => $app,
    'pageTitle' => 'Kamera nicht gefunden',
    'message' => 'Die angeforderte Kamera wurde nicht gefunden.',
  ]);
  $cameraPageResponseComplete = true;
  return;
}

$presenter = $app->cameraPresenter();
if ($camera->isTest()) {
  header('X-Robots-Tag: noindex, nofollow');
}
$cameraData = $presenter->overviewData($camera);
$state = $app->statusService()->state($camera);

$rawInfoHtml = (string) ($cameraData['info_html'] ?? '');
if (trim($rawInfoHtml) === '') {
  $infoFile = null;
  try {
    $infoFile = $app->paths()->infoPath($camera->slug() . '.html');
  } catch (\RuntimeException $e) {
    $infoFile = null;
  }
  $rawInfoHtml = $infoFile && is_file($infoFile) ? (string) file_get_contents($infoFile) : '';
}
$infoHtml = sanitize_admin_html($rawInfoHtml);
$hasInfoHtml = $infoHtml !== '';
$archiveYearsRaw = $app->archiveBrowser()->listYears($camera);
$archiveYears = is_array($archiveYearsRaw) ? $archiveYearsRaw : [];
$archiveYearsList = [];
if (is_array($archiveYearsRaw)) {
  $archiveYearsList = array_map('strval', array_values(array_unique(array_merge(
    $archiveYearsRaw['current'] ?? [],
    $archiveYearsRaw['archive'] ?? []
  ))));
  rsort($archiveYearsList, SORT_STRING);
}
$buildTimelineLink = static function (array $entry, bool $forceHistory = false) use ($slug): string {
  $params = [
    'cam' => $slug,
    'year' => sprintf('%04d', (int) ($entry['year'] ?? 0)),
    'month' => sprintf('%02d', (int) ($entry['month'] ?? 0)),
    'day' => sprintf('%02d', (int) ($entry['day'] ?? 0)),
    'time' => (string) ($entry['time'] ?? ''),
  ];
  if ($forceHistory) {
    $params['view'] = 'history';
  }
  return '?' . http_build_query($params);
};
$buildTimelineMinuteLink = static function (array $entry, bool $forceHistory = false) use ($slug): string {
  $rawTime = preg_replace('/[^0-9]/', '', (string) ($entry['time'] ?? '')) ?: '';
  $minute = $rawTime !== '' ? substr(str_pad($rawTime, 4, '0', STR_PAD_LEFT), 0, 4) : '';
  $params = [
    'cam' => $slug,
    'year' => sprintf('%04d', (int) ($entry['year'] ?? 0)),
    'month' => sprintf('%02d', (int) ($entry['month'] ?? 0)),
    'day' => sprintf('%02d', (int) ($entry['day'] ?? 0)),
  ];
  if ($minute !== '') {
    $params['time'] = $minute;
  }
  if ($forceHistory) {
    $params['view'] = 'history';
  }
  return '?' . http_build_query($params);
};
$buildMinuteLinkFromTimestamp = static function (int $timestamp, string $slug, string $tzName): string {
  $tz = new DateTimeZone($tzName);
  $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
  return '?' . http_build_query([
    'cam' => $slug,
    'year' => $dt->format('Y'),
    'month' => $dt->format('m'),
    'day' => $dt->format('d'),
    'time' => $dt->format('Hi'),
  ]);
};
$buildYearOnlyLink = static function (int $yearValue, bool $forceHistory = false) use ($slug): string {
  $params = [
    'cam' => $slug,
    'year' => sprintf('%04d', $yearValue),
  ];
  if ($forceHistory) {
    $params['view'] = 'history';
  }
  return '?' . http_build_query($params);
};
$buildYearWithSelection = static function (int $yearValue, $monthParam, $dayParam, $timeParam, string $slug, bool $forceHistory = false): string {
  $params = [
    'cam' => $slug,
    'year' => sprintf('%04d', $yearValue),
  ];
  if ($monthParam !== null) {
    $params['month'] = sprintf('%02d', (int) $monthParam);
  }
  if ($dayParam !== null) {
    $params['day'] = sprintf('%02d', (int) $dayParam);
  }
  if ($timeParam !== null) {
    $params['time'] = (string) $timeParam;
  }
  if ($forceHistory) {
    $params['view'] = 'history';
  }
  return '?' . http_build_query($params);
};
$timestampParam = isset($_GET['ts']) && is_numeric($_GET['ts']) ? (int) $_GET['ts'] : null;
$yearParam = isset($_GET['year']) && preg_match('/^\d{4}$/', (string) $_GET['year']) ? $_GET['year'] : null;
$monthParam = isset($_GET['month']) && preg_match('/^\d{1,2}$/', (string) $_GET['month']) ? $_GET['month'] : null;
$dayParam = isset($_GET['day']) && preg_match('/^\d{1,2}$/', (string) $_GET['day']) ? $_GET['day'] : null;
$timeParam = isset($_GET['time']) && preg_match('/^\d{2}:?\d{2}(?:\d{2})?$/', (string) $_GET['time']) ? $_GET['time'] : null;
$requestHasTimeSelection = $timestampParam !== null || $yearParam !== null || $monthParam !== null || $dayParam !== null || $timeParam !== null;
// Wenn nur ts geliefert wird, Zeitkomponenten ableiten (Minutenauflösung genügt).
if ($timestampParam !== null && ($yearParam === null || $monthParam === null || $dayParam === null || $timeParam === null)) {
  $tz = new DateTimeZone($app->config('timezone', 'UTC'));
  $dt = (new DateTimeImmutable('@' . $timestampParam))->setTimezone($tz);
  if ($yearParam === null) {
    $yearParam = $dt->format('Y');
  }
  if ($monthParam === null) {
    $monthParam = $dt->format('n');
  }
  if ($dayParam === null) {
    $dayParam = $dt->format('j');
  }
  if ($timeParam === null) {
    $timeParam = $dt->format('Hi');
  }
}

// Default-Range auf den Monat der letzten Aufnahme begrenzen, wenn nichts selektiert wurde.
if (!$requestHasTimeSelection) {
  $defaultTs = null;
  if ($cameraData['last_capture'] instanceof DateTimeImmutable) {
    $defaultTs = $cameraData['last_capture']->getTimestamp();
  } elseif (!empty($status['last_success_at'])) {
    $defaultTs = (int) $status['last_success_at'];
  }
  if ($defaultTs) {
    $dt = (new DateTimeImmutable('@' . $defaultTs))->setTimezone(new DateTimeZone($app->config('timezone', 'UTC')));
    $yearParam = $dt->format('Y');
    $monthParam = $dt->format('n');
  }
}
$viewParam = isset($_GET['view']) ? strtolower((string) $_GET['view']) : '';
$streamParam = $_GET['stream'] ?? null;
$formatParam = $_GET['format'] ?? null;
$requestedStreamMode = (is_string($streamParam) && strtolower(trim($streamParam)) === 'hls')
  || (is_string($formatParam) && strtolower(trim($formatParam)) === 'hls')
  ? 'hls'
  : '';
// USB is an explicit live-view selection, never inherited by archive requests.
if ($streamParam === 'usb' && !$requestHasTimeSelection) {
  $requestedStreamMode = 'usb';
}

// Diese Basis wird sowohl vom schnellen Best-Of-Pfad als auch vom normalen
// Timeline-Render verwendet. Änderungen an gemeinsamen Templatewerten müssen
// dadurch nicht mehr an zwei weit auseinanderliegenden Stellen erfolgen.
$cameraPageBasePayload = $cameraPagePayloadBuilder->base(
  $app,
  $camera,
  $cameraData,
  $state,
  ['grouped' => $archiveYears, 'years' => $archiveYearsList],
  ['year' => $yearParam, 'month' => $monthParam, 'day' => $dayParam, 'time' => $timeParam],
  ['html' => $infoHtml, 'available' => $hasInfoHtml],
  ['log' => $__wc_log, 'enabled' => $__wc_profile],
  $requestedStreamMode
);

// Enforce history view in all timeline navigation links when applicable
$forceHistoryLinks = $viewParam === 'history';
if ($forceHistoryLinks) {
  $oldBuildTimelineLink = $buildTimelineLink;
  $oldBuildYearOnlyLink = $buildYearOnlyLink;
  $oldBuildYearWithSelection = $buildYearWithSelection;
  $buildTimelineLink = static function (array $entry) use ($oldBuildTimelineLink): string {
    return $oldBuildTimelineLink($entry, true);
  };
  $buildYearOnlyLink = static function (int $yearValue) use ($oldBuildYearOnlyLink): string {
    return $oldBuildYearOnlyLink($yearValue, true);
  };
  $buildYearWithSelection = static function (int $yearValue, $monthParam, $dayParam, $timeParam, string $slug) use ($oldBuildYearWithSelection): string {
    return $oldBuildYearWithSelection($yearValue, $monthParam, $dayParam, $timeParam, $slug, true);
  };
}
$forcePlaybackLinks = $viewParam === 'playback';
if ($forcePlaybackLinks) {
  $oldBuildTimelineLink = $buildTimelineLink;
  $oldBuildYearOnlyLink = $buildYearOnlyLink;
  $oldBuildYearWithSelection = $buildYearWithSelection;
  $appendPlayback = static function (string $url): string {
    if ($url === '') {
      return '?view=playback';
    }
    if (str_contains($url, 'view=')) {
      return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'view=playback';
  };
  $buildTimelineLink = static function (array $entry) use ($oldBuildTimelineLink, $appendPlayback): string {
    return $appendPlayback($oldBuildTimelineLink($entry, false));
  };
  $buildYearOnlyLink = static function (int $yearValue) use ($oldBuildYearOnlyLink, $appendPlayback): string {
    return $appendPlayback($oldBuildYearOnlyLink($yearValue, false));
  };
  $buildYearWithSelection = static function (int $yearValue, $monthParam, $dayParam, $timeParam, string $slug) use ($oldBuildYearWithSelection, $appendPlayback): string {
    return $appendPlayback($oldBuildYearWithSelection($yearValue, $monthParam, $dayParam, $timeParam, $slug, false));
  };
}
$selectionCriteria = [
  'timestamp' => $timestampParam,
  'year' => $yearParam,
  'month' => $monthParam,
  'day' => $dayParam,
  'time' => $timeParam,
];
$loadBestOf = $viewParam === 'bestof';
$bestOf = [];
$topLiked = [];
$bestOfPerPage = 50;
$bestOfPage = 1;
$bestOfSelectedYear = isset($_GET['bestof_year']) && is_numeric($_GET['bestof_year']) ? (int) $_GET['bestof_year'] : null;
$bestOfYears = [];
if ($loadBestOf) {
  $bestOfYears = $app->bestOfGallery()->years($camera);
  $bestOfPageData = $app->bestOfGallery()->page($camera, $bestOfPage, $bestOfPerPage, $bestOfSelectedYear);
  $bestOf = $bestOfPageData['items'] ?? [];
  $topLiked = $app->bestOfGallery()->topLiked($camera, 20);

  // Der Best-Of-View braucht weder Timeline noch Snapshot-/Overlay-Logik.
  // Ein früher Render spart deshalb den kompletten Archiv-/Navigations-Bootpfad
  // und macht den ersten Seitenaufruf deutlich leichter.
  $buildBestOfViewUrl = static function (array $params): string {
    $query = http_build_query($params);
    return $query === '' ? '?' : '?' . $query;
  };
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

  $liveQuery = $baseQuery;
  $infoQuery = $baseQuery;
  $bestOfQuery = $baseQuery;
  $historyQuery = $baseQuery;
  $playbackQuery = $baseQuery;
  $bestOfQuery['view'] = 'bestof';
  if ($bestOfSelectedYear !== null) {
    $bestOfQuery['bestof_year'] = (string) $bestOfSelectedYear;
  }
  $historyQuery['view'] = 'history';
  $playbackQuery['view'] = 'playback';

  $viewLinks = [
    'live' => $buildBestOfViewUrl($liveQuery),
    'info' => null,
    'bestof' => $buildBestOfViewUrl($bestOfQuery),
    'global_bestof' => $buildBestOfViewUrl(array_merge($baseQuery, ['view' => 'global-bestof'])),
    'history' => $buildBestOfViewUrl($historyQuery),
    'playback' => $buildBestOfViewUrl($playbackQuery),
  ];
  if ($hasInfoHtml) {
    $infoQuery['view'] = 'info';
    $viewLinks['info'] = $buildBestOfViewUrl($infoQuery);
  }
  $bestOfLink = $viewLinks['bestof'];

  $pageHtml = $app->renderer()->render('camera', $cameraPagePayloadBuilder->build($cameraPageBasePayload, [
    'bestOf' => $bestOf,
    'bestOfPage' => $bestOfPage,
    'bestOfPerPage' => $bestOfPerPage,
    'bestOfYears' => $bestOfYears,
    'bestOfSelectedYear' => $bestOfSelectedYear,
    'topLiked' => $topLiked,
    'sidebarDownloads' => [
      'exif' => null,
      'weather' => null,
      'weatherTimestamp' => null,
      'has_info' => $hasInfoHtml,
      'bestof_link' => $bestOfLink,
    ],
    'viewMode' => 'bestof',
    'viewLinks' => $viewLinks,
    'bestOfLink' => $bestOfLink,
  ]));
  $__wc_log('bestof early render');

  if ($__wc_profile) {
    try {
      $json = json_encode($__wc_marks, JSON_THROW_ON_ERROR);
      $snippet = '<script>(function(){try{const m=' . $json . ';m.forEach(function(entry){var label=entry[0];var t=entry[1];if(typeof t==="number"){console.log("[WC_PROFILE_CLIENT]", t.toFixed(3)+"s", label);}else{console.log("[WC_PROFILE_CLIENT]", label);}});}catch(e){console.warn("WC_PROFILE_CLIENT log failed",e);}})();</script>';
      $pageHtml = str_replace('</body>', $snippet . '</body>', $pageHtml);
    } catch (Throwable $e) {
      // ignore
    }
  }

  echo $pageHtml;
  $cameraPageResponseComplete = true;
  return;
}
