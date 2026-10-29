<?php
declare(strict_types=1);

// Schlanker Einstieg der Kamera-Darstellung:
// 1. gemeinsame Präsentationsdaten normalisieren,
// 2. wiederverwendete Menü-/Timeline-Renderer definieren,
// 3. nur bei Bedarf Verlaufsdaten laden,
// 4. anschließend ausschließlich den gewählten HTML-View ausgeben.
//
// Die eingebundenen Dateien teilen bewusst den lokalen Template-Scope. Neue
// Fachlogik gehört weiterhin in Presenter/Builder; diese Schicht formatiert
// nur die bereits vom Controller gelieferten Werte.
require __DIR__ . '/camera/prepare.php';
require __DIR__ . '/camera/navigation.php';
require __DIR__ . '/camera/history.php';
?>
<?php // Ab hier beginnt die Ausgabe. Die vorbereitenden PHP-Blöcke oben haben nur Daten normalisiert. ?>
<article class="camera-detail<?= $viewMode === 'global-bestof' ? ' camera-detail--global-bestof' : '' ?>">
<?php if (!in_array($viewMode, ['bestof', 'global-bestof'], true)): ?>
  <div id="camera-live-root"
       class="camera-live-root<?= $viewMode === 'info' ? ' is-info-view' : '' ?>"
       data-overlay-tone="<?= htmlspecialchars($overlayToneCurrent) ?>"
       data-timeline-endpoint="<?= htmlspecialchars($normalizeAsset($timelineEndpoint)) ?>"
       data-history-endpoint="<?= htmlspecialchars($normalizeAsset($historyEndpoint)) ?>"
       data-history-fallback="<?= htmlspecialchars($normalizeAsset($historyFallbackEndpoint)) ?>"
       data-camera-slug="<?= htmlspecialchars((string) ($camera['slug'] ?? '')) ?>"
       data-api-camera-detail="<?= htmlspecialchars($relativePrefix . 'api/public.php/cameras/' . rawurlencode((string) ($camera['slug'] ?? '')), ENT_QUOTES) ?>"
       data-archive-years='<?= htmlspecialchars(json_encode($archiveYearsList ?? [], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'
       data-archive-min-year="<?= htmlspecialchars((string) (min($archiveYearsList ?: [date('Y')]))); ?>"
       data-archive-max-year="<?= htmlspecialchars((string) (max($archiveYearsList ?: [date('Y')]))); ?>"
        data-live-link="<?= htmlspecialchars($viewLinks['live'] ?? '', ENT_QUOTES) ?>"
        data-view-mode="<?= htmlspecialchars((string) $viewMode) ?>"
        data-last-ts="<?= $camera['last_capture'] instanceof DateTimeImmutable ? $camera['last_capture']->getTimestamp() : '' ?>"
        data-prev-url="<?= htmlspecialchars($timelineNav['time']['prev_url'] ?? '', ENT_QUOTES) ?>"
        data-next-url="<?= htmlspecialchars($timelineNav['time']['next_url'] ?? '', ENT_QUOTES) ?>"
        data-selected-year="<?= htmlspecialchars((string) $selectedYearAttr) ?>"
       data-selected-month="<?= htmlspecialchars((string) $selectedMonthAttr) ?>"
       data-selected-day="<?= htmlspecialchars((string) $selectedDayAttr) ?>"
       data-selected-time="<?= htmlspecialchars((string) $selectedTimeAttr) ?>"
       data-selected-ts="<?= isset($snapshot['timestamp']) ? (int) $snapshot['timestamp'] : ($currentSnapshotTimestamp !== null ? (int) $currentSnapshotTimestamp : '') ?>"
       data-history-ref-ts="<?= (int) $historyRefTs ?>"
       data-timezone="<?= htmlspecialchars((string) $app->config('timezone', 'UTC')) ?>"
       data-labels-suppressed="<?= $frontendOverlaySuppressed ? '1' : '0' ?>"
       data-wc-profile="<?= !empty($wcProfile) ? '1' : '0' ?>"
       data-prefetch-enabled="<?= !empty($prefetchEnabled) ? '1' : '0' ?>"
       data-timeline-fetch="<?= !empty($timelineFetchEnabled) ? '1' : '0' ?>"
       data-exif-text="<?= htmlspecialchars($modalExifText ?? ($exifData ?? ''), ENT_QUOTES) ?>"
       data-weather-text="<?= htmlspecialchars($modalWeatherText ?? ($weatherData ?? ''), ENT_QUOTES) ?>"
       data-stream-enabled="<?= $streamEnabled ? '1' : '0' ?>"
       data-stream-endpoint="<?= htmlspecialchars($streamEndpoint, ENT_QUOTES) ?>"
       data-stream-hls-endpoint="<?= htmlspecialchars($streamHlsEndpoint, ENT_QUOTES) ?>"
       data-stream-max-runtime-seconds="<?= (int) $streamMaxRuntimeSeconds ?>"
       data-stream-preference="<?= htmlspecialchars($requestedStreamMode, ENT_QUOTES) ?>"
       data-stream-mode="<?= htmlspecialchars($streamMode, ENT_QUOTES) ?>">
    <?php if ($viewMode === 'live'): ?>
      <?php
        $slugList = [];
        if (isset($cameras) && is_array($cameras)) {
          $slugList = array_map(static fn($c) => ['slug' => $c['slug'] ?? '', 'title' => $c['title'] ?? ($c['slug'] ?? '')], $cameras);
        }
        if (!$slugList && isset($app)) {
          foreach ($app->cameras()->listed() as $c) {
            $slugList[] = ['slug' => $c->slug(), 'title' => $c->title()];
          }
        }
        $infoLink = $viewLinks['info'] ?? null;
        $historyLink = $viewLinks['history'] ?? null;
        $bestofLink = $viewLinks['bestof'] ?? null;
      ?>
    <?php endif; ?>
  <?php
    $timelineInteractive = !in_array($viewMode, ['bestof', 'global-bestof', 'playback'], true);
  ?>
  <?php if ($viewMode !== 'history'): ?>
    <?= $renderTimelineControls($timelineInteractive); ?>
  <section class="camera-live" style="<?= htmlspecialchars($infoBackgroundStyle, ENT_QUOTES) ?>">
    <?php
      // Archivbilder koennen ueber image.php?image=... kommen. Den Zeitstempel
      // als weiteren Parameter anhaengen, sonst wird er Teil des Bildschluessels.
      $placeholderSrcUrl = htmlspecialchars($placeholderUrlNormalized
        . (str_contains($placeholderUrlNormalized, '?') ? '&' : '?')
        . 'v=' . (int) $activeCacheKey, ENT_QUOTES);
      $activeImageVersionedUrl = $activeImageSrcNormalized
        . (str_contains($activeImageSrcNormalized, '?') ? '&' : '?')
        . 'v=' . (int) $activeCacheKey;
    ?>
    <link rel="preload" as="image" href="<?= $placeholderSrcUrl ?>" fetchpriority="high">
    <?php
      $snapshotAttr = $currentSnapshotTimestamp !== null ? (string) $currentSnapshotTimestamp : '';
      $snapshotAttrEscaped = htmlspecialchars($snapshotAttr, ENT_QUOTES);
      $liveImageStyleAttr = '';
    ?>
    <div class="<?= htmlspecialchars($liveImageClassAttr) ?>" data-current-snapshot="<?= $snapshotAttrEscaped ?>" data-live-display="<?= htmlspecialchars($liveDisplayDefault, ENT_QUOTES) ?>" data-native-display="<?= $nativeDisplayEnabled ? '1' : '0' ?>"<?= $liveImageStyleAttr !== '' ? ' style="' . htmlspecialchars($liveImageStyleAttr, ENT_QUOTES) . '"' : '' ?>>
    <?php if ($viewMode === 'info'): ?>
      <div class="live-image__info" style="<?= htmlspecialchars($infoBackgroundStyle, ENT_QUOTES) ?>">
        <?php
          $infoHeadline = (string) ($camera['short_title'] ?? $camera['title']);
          $infoHeadlineSecondary = $infoHeadline !== $camera['title'] ? $infoHeadline : null;
        ?>
        <div class="live-image__info-headline">
          <h3><?= htmlspecialchars($camera['title']) ?></h3>
          <?php if ($infoHeadlineSecondary !== null && $infoHeadlineSecondary !== ''): ?>
            <span><?= htmlspecialchars($infoHeadlineSecondary) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($hasInfoHtml): ?>
          <div class="live-image__info-html">
            <?= $infoHtml ?>
          </div>
        <?php else: ?>
          <p>Für diese Kamera wurden noch keine zusätzlichen Informationen hinterlegt.</p>
        <?php endif; ?>
        <a class="live-image__back" href="<?= htmlspecialchars($viewLinks['live']) ?>">Zurück zum Livebild</a>
      </div>
    <?php elseif ($usbLiveActive): ?>
      <?php require __DIR__ . '/camera/usb-live.php'; ?>
    <?php elseif ($isPlaybackView && $snapshot): ?>
      <div class="timelapse timelapse--inline" data-timelapse hidden>
        <div class="timelapse__controls">
          <button type="button" data-tl-play aria-label="Play/Pause">▶︎/⏸</button>
          <button type="button" data-tl-prev aria-label="Vorheriger Frame">‹</button>
          <button type="button" data-tl-next aria-label="Nächster Frame">›</button>
          <button type="button" data-tl-framecount aria-label="Frame-Anzahl wechseln">120 Frames</button>
          <button type="button" data-tl-fullscreen aria-label="Vollbild umschalten">⛶</button>
          <select data-tl-speed aria-label="Abspielgeschwindigkeit">
            <option value="1" selected>1×</option>
            <option value="2">2×</option>
            <option value="4">4×</option>
            <option value="6">6×</option>
          </select>
          <button type="button" data-tl-day-prev aria-label="Vortag"><<</button>
          <button type="button" data-tl-day-next aria-label="Folgetag">>></button>
        </div>
        <div class="timelapse__status" data-tl-status role="status" aria-live="polite"></div>
        <div class="timelapse__frame">
          <img data-tl-img alt="Timelapse Frame" loading="lazy">
          <div class="timelapse__meta" data-tl-meta></div>
        </div>
        <div class="timelapse__scrubber">
          <div class="timelapse__progress" data-tl-progress-wrap>
            <div class="timelapse__progress-bar" data-tl-progress></div>
            <span class="timelapse__progress-label" data-tl-progress-label>0%</span>
          </div>
          <div class="timelapse__heatmap" data-tl-heatmap></div>
          <input type="range" min="0" step="1" value="0" data-tl-scrubber aria-label="Frame wählen">
          <div class="timelapse__scrubber-tooltip" data-tl-scrubber-tip hidden>—</div>
          <div class="timelapse__scrubber-label" data-tl-scrubber-label>—</div>
        </div>
      </div>
      <?= $renderDayvideoInline(); ?>
<?php elseif ($snapshot): ?>
      <?php if ($streamEnabled): ?>
        <?php if ($streamEndpoint !== ''): ?>
          <img
            data-live-stream
            data-stream-src="<?= htmlspecialchars($streamEndpoint, ENT_QUOTES) ?>"
            alt="Livestream <?= htmlspecialchars($camera['title']) ?>">
        <?php endif; ?>
        <?php if ($streamHlsEndpoint !== ''): ?>
          <video
            data-live-hls
            data-hls-src="<?= htmlspecialchars($streamHlsEndpoint, ENT_QUOTES) ?>"
            muted
            autoplay
            controls
            playsinline
            preload="none"
            aria-label="HLS-Livestream <?= htmlspecialchars($camera['title']) ?>"></video>
        <?php endif; ?>
        <div class="live-stream-status" data-stream-status role="status" aria-live="polite" hidden></div>
      <?php endif; ?>
      <img
        data-live-image
        data-capture-timestamp="<?= (int)$activeImageTimestamp ?>"
        src="<?= $placeholderSrcUrl ?>"
        srcset="<?= htmlspecialchars($placeholderUrlNormalized) ?> 1x"
        sizes="100vw"
        data-full-src="<?= htmlspecialchars($activeImageVersionedUrl, ENT_QUOTES) ?>"
        <?= $fullSrcsetAttrNormalized ? 'data-full-srcset="' . htmlspecialchars($fullSrcsetAttrNormalized) . '"' : '' ?>
        fetchpriority="high"
        alt="Livebild <?= htmlspecialchars($camera['title']) ?>">
      <?php
        $navModeParam = isset($_GET['nav']) ? strtolower((string) $_GET['nav']) : '';
        $navModeAllowed = ['day', 'month', 'year', 'hour', 'time', 'bestof'];
        $navModeResolved = in_array($navModeParam, $navModeAllowed, true) ? $navModeParam : 'time';
        if (isset($_GET['bestof'])) {
          $navModeResolved = 'bestof';
        }
        $navModeGroup = in_array($navModeResolved, ['year', 'month', 'day', 'hour'], true) ? $navModeResolved : 'time';
        $navGroup = $timelineNav[$navModeGroup] ?? ($timelineNav['time'] ?? []);
        $timePrevUrl = $appendNavParam($navGroup['prev_url'] ?? null, $navModeGroup);
        $timeNextUrl = $appendNavParam($navGroup['next_url'] ?? null, $navModeGroup);
        $timePrevKey = $navGroup['prev_timestamp'] ?? null;
        $timeNextKey = $navGroup['next_timestamp'] ?? null;
      ?>
        <?= $renderDayvideoInline(); ?>
      <div class="live-labels" data-live-labels aria-hidden="true" hidden></div>
      <script type="application/json" data-live-labels-json>
        <?= json_encode($cameraLabels ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
      </script>
      <div data-timeline-hotspots>
        <?php if (is_string($timePrevUrl) && $timePrevUrl !== ''): ?>
          <div
            class="timeline-hotspot timeline-hotspot--left"
            data-timeline-hotspot="left"
            data-url="<?= htmlspecialchars($timePrevUrl) ?>"
            <?php if ($timePrevKey !== null): ?>data-target-key="<?= (int) $timePrevKey ?>"<?php endif; ?>
            role="button"
            aria-label="Vorheriges Bild anzeigen"
            tabindex="0"
          >
            <span class="timeline-hotspot__icon" aria-hidden="true">&lsaquo;</span>
          </div>
        <?php endif; ?>
        <?php if (is_string($timeNextUrl) && $timeNextUrl !== ''): ?>
          <div
            class="timeline-hotspot timeline-hotspot--right"
            data-timeline-hotspot="right"
            data-url="<?= htmlspecialchars($timeNextUrl) ?>"
            <?php if ($timeNextKey !== null): ?>data-target-key="<?= (int) $timeNextKey ?>"<?php endif; ?>
            role="button"
            aria-label="Nächstes Bild anzeigen"
            tabindex="0"
          >
            <span class="timeline-hotspot__icon" aria-hidden="true">&rsaquo;</span>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($overlay) && !empty($overlay['parts'])): ?>
        <div class="live-overlay live-overlay--<?= htmlspecialchars($overlayToneCurrent) ?>" data-live-overlay<?= $frontendOverlaySuppressed ? ' hidden' : '' ?>>
          <?php foreach ($overlay['parts'] as $part): ?>
            <span class="live-overlay__item"><?= htmlspecialchars($part) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php
        $logoToneResolved = null;
        if (isset($logoTone) && is_string($logoTone) && in_array($logoTone, ['light', 'dark'], true)) {
          $logoToneResolved = $logoTone;
        } else {
          $logoToneResolved = $overlayToneCurrent;
        }
        $logoImage = null;
        if ($logoToneResolved === 'dark') {
          $logoImage = $logoDarkConfig ?? $logoLightConfig;
        } else {
          $logoImage = $logoLightConfig ?? $logoDarkConfig;
        }
      ?>
      <?php if ($logoImage): ?>
        <?php
          // Build inline style so that logos scale with viewport size while keeping any configured styles.
          $logoStyle = trim((string) ($logoImage['style'] ?? ''));
          if ($logoStyle !== '' && !str_ends_with($logoStyle, ';')) {
            $logoStyle .= ';';
          }
          $logoStyle .= 'width:auto;height:auto;';
          // Responsive clamp: small viewports get a smaller logo, large viewports can show the full size.
          $logoStyle .= 'max-width:clamp(72px,20vw,260px);max-height:clamp(56px,16vh,200px);';
        ?>
        <img
          class="live-logo"
          src="<?= htmlspecialchars($logoImage['url']) ?>"
          alt=""
          aria-hidden="true"
          style="<?= htmlspecialchars($logoStyle, ENT_QUOTES) ?>"
          <?= $frontendOverlaySuppressed ? 'hidden' : '' ?>
        >
      <?php endif; ?>
      <?php if ($bestOfRelativePath): ?>
        <?php $hotspotClasses = 'bestof-hotspot' . ($bestOfActive ? ' is-active' : ''); ?>
        <div class="<?= htmlspecialchars($hotspotClasses) ?>" data-bestof-hotspot>
          <?php $bestOfButtonClasses = 'bestof-toggle' . ($bestOfActive ? ' is-active' : ''); ?>
          <button
            type="button"
            class="<?= htmlspecialchars($bestOfButtonClasses) ?>"
            data-bestof-toggle
            data-camera="<?= htmlspecialchars($camera['slug']) ?>"
            data-path="<?= htmlspecialchars($bestOfRelativePath, ENT_QUOTES) ?>"
            data-active="<?= $bestOfActive ? '1' : '0' ?>"
            aria-pressed="<?= $bestOfActive ? 'true' : 'false' ?>"
            aria-label="<?= $bestOfActive ? 'Bereits geliked' : 'Bild liken' ?>"
          >
            <span class="bestof-toggle__icon" aria-hidden="true">❤</span>
            <span class="sr-only"><?= $bestOfActive ? 'Dieses Bild wurde geliked' : 'Bild liken' ?></span>
          </button>
        </div>
      <?php endif; ?>
      <?php
        $navBadgeLabels = [
          'bestof' => 'Best-Of',
          'year' => 'Jahr',
          'month' => 'Monat',
          'day' => 'Tag',
          'hour' => 'Stunde',
          'time' => 'Live',
        ];
        $navBadgeLabel = $navBadgeLabels[$navModeResolved] ?? 'Live';
        if ($navModeResolved === 'time' && $liveDisplayDefault === 'stream') {
          $navBadgeLabel = $requestedStreamMode === 'hls' ? 'Livestream HLS' : 'Livestream';
        }
      ?>
      <div class="live-mode-badge" data-live-mode-badge data-mode="<?= htmlspecialchars($navModeResolved) ?>">
        <?= htmlspecialchars($navBadgeLabel) ?>
      </div>
<?php else: ?>
      <div class="live-image__info live-image__info--empty">
        <h3><?= htmlspecialchars($camera['title']) ?></h3>
        <p>Aktuell liegen keine Aufnahmen vor. Bitte wählen Sie einen anderen Zeitpunkt oder versuchen Sie es später erneut.</p>
      </div>
<?php endif; ?>
    </div>

  </section>
<?php endif; ?>
  </div>

  <div class="meta-modal" data-meta-modal hidden>
    <div class="meta-modal__backdrop" data-meta-close></div>
    <div class="meta-modal__dialog" role="dialog" aria-modal="true">
      <header class="meta-modal__header">
        <h3 class="meta-modal__title" data-meta-title>EXIF</h3>
        <button type="button" class="meta-modal__close" data-meta-close aria-label="Schließen">×</button>
      </header>
      <div class="meta-modal__body">
        <div class="meta-modal__content" data-meta-content></div>
      </div>
    </div>
  </div>

  <div class="meta-modal meta-modal--status" data-status-modal hidden>
    <div class="meta-modal__backdrop" data-status-close></div>
    <div class="meta-modal__dialog meta-modal__dialog--wide" role="dialog" aria-modal="true">
      <header class="meta-modal__header">
        <h3 class="meta-modal__title">Status &amp; Telemetrie</h3>
        <button type="button" class="meta-modal__close" data-status-close aria-label="Schließen">×</button>
      </header>
      <div class="meta-modal__body">
        <div class="meta-modal__content" data-status-content></div>
      </div>
    </div>
  </div>

  <?php if ($viewMode === 'live'): ?>
    <div data-status-content hidden>
      <h3>Status &amp; Telemetrie</h3>
      <dl class="telemetry-list">
        <div>
          <dt>Letzte Auslösung</dt>
          <dd><?= $camera['last_capture'] instanceof DateTimeImmutable ? $camera['last_capture']->format('d.m.Y H:i \U\h\r') : '—' ?></dd>
        </div>
        <div>
          <dt>Letzter Erfolg</dt>
          <dd><?= $formatTime($status['last_success_at'] ?? null) ?></dd>
        </div>
        <div>
          <dt>Letzter Fehler</dt>
          <dd><?= $formatTime($status['last_failure_at'] ?? null) ?></dd>
        </div>
        <div>
          <dt>Fehler in Folge</dt>
          <dd><?= (int)($status['consecutive_failures'] ?? 0) ?></dd>
        </div>
        <div>
          <dt>Intervall</dt>
          <dd><?= (int)round(($camera['interval_seconds'] ?? 600) / 60) ?> min</dd>
        </div>
        <div>
          <dt>Resets gesamt</dt>
          <dd><?= (int)($status['resets_total'] ?? 0) ?></dd>
        </div>
        <div>
          <dt>Letzte Wetterdaten</dt>
          <dd><?= $formatTime($status['last_weather_at'] ?? null) ?></dd>
        </div>
        <div>
          <dt>Letzter Reset</dt>
          <dd><?= $formatTime($status['last_reset_at'] ?? null) ?></dd>
        </div>
      </dl>
    </div>

    <?php if (!empty($enableLeaflet)): ?>
      <?php
        $mapHeight = null;
        if (isset($camera['location']['map_height_px']) && is_numeric($camera['location']['map_height_px'])) {
          $mapHeight = (int) $camera['location']['map_height_px'];
        }
      ?>
      <section class="camera-map">
        <h2>Blickrichtung &amp; Standort</h2>
        <?php
          $mapStyle = '';
          if ($mapHeight) {
            $mapStyle = sprintf(
              ' style="--camera-map-height: %dpx; height: %dpx;"',
              (int) $mapHeight,
              (int) $mapHeight
            );
          }
        ?>
        <?php
          // Das Kartenmodul liest ausschließlich dieses kleine JSON-Paket. Die
          // Attributkodierung verhindert, dass Kameratitel HTML einschleusen.
          $mapConfigJson = json_encode(
            $mapPayload ?? [],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
          );
        ?>
        <div
          id="camera-map"
          class="map"
          data-map-config="<?= htmlspecialchars($mapConfigJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          <?= $mapStyle ?>
        ></div>
      </section>
    <?php endif; ?>

<?php endif; ?>

  <?php if ($viewMode === 'history'): ?>
    <?= $renderTimelineControls(); ?>
    <section
      class="history-panel"
      id="history-panel"
      data-history-root
      data-history-endpoint="<?= htmlspecialchars($normalizeAsset($historyEndpoint)) ?>"
      data-history-fallback="<?= htmlspecialchars($normalizeAsset($historyFallbackEndpoint)) ?>"
      data-history-ref-ts="<?= (int) $historyRefTs ?>"
      data-selected-year="<?= htmlspecialchars((string) ($selectedSnapshot['year'] ?? $selectedYearAttr ?? $yearParam ?? '')) ?>"
      data-selected-month="<?= htmlspecialchars((string) ($selectedSnapshot['month'] ?? $selectedMonthAttr ?? $monthParam ?? '')) ?>"
      data-selected-day="<?= htmlspecialchars((string) ($selectedSnapshot['day'] ?? $selectedDayAttr ?? $dayParam ?? '')) ?>"
      data-selected-time="<?= htmlspecialchars((string) ($selectedSnapshot['time'] ?? $selectedTimeAttr ?? $timeParam ?? '')) ?>"
    >
      <h2>Tagesverlauf</h2>
      <div class="history-panel__tabs" role="tablist" aria-label="Archivvergleich">
        <button type="button" id="history-tab-days" class="history-tab is-active" data-history-tab="days" role="tab" aria-controls="history-panel-content" aria-selected="true">Letzte 30 Tage</button>
        <button type="button" id="history-tab-months" class="history-tab" data-history-tab="months" role="tab" aria-controls="history-panel-content" aria-selected="false">Monatsvergleich (13)</button>
        <button type="button" id="history-tab-years" class="history-tab" data-history-tab="years" role="tab" aria-controls="history-panel-content" aria-selected="false">Jahresvergleich</button>
      </div>
      <div id="history-panel-content" class="history-panel__content" data-history-content role="tabpanel" aria-labelledby="history-tab-days">
        <div class="bestof-placeholder" data-history-placeholder>Verlauf wird geladen…</div>
        <div class="bestof-grid history-grid" data-history-list></div>
      </div>
    </section>
  <?php endif; ?>

<?php endif; ?>

<?php if ($viewMode === 'global-bestof'): ?>
  <?= $renderTimelineControls(false); ?>
  <?php
    $cameraOptions = $globalCameraOptions;
    $globalBestOfFormAction = $relativePrefix . 'camera/' . rawurlencode((string) ($camera['slug'] ?? ''));
    $globalBestOfCameraField = 'global_camera';
    $globalBestOfYearField = 'global_year';
    $globalBestOfHiddenFields = ['view' => 'global-bestof'];
    $globalBestOfClearUrl = (string) ($viewLinks['global_bestof'] ?? $globalBestOfFormAction . '?view=global-bestof');
    $globalBestOfBackUrl = (string) ($viewLinks['live'] ?? $globalBestOfFormAction);
    $globalBestOfBackLabel = 'Zur Kamera';
    require __DIR__ . '/global_bestof_gallery.php';
  ?>
<?php endif; ?>

<?php if ($viewMode === 'bestof'): ?>
  <div class="camera-detail">
    <?= $renderTimelineControls(false); ?>
  </div>
  <?php
    $bestOfFilterBase = $relativePrefix . 'camera/' . rawurlencode((string) ($camera['slug'] ?? ''));
    $bestOfFilterParams = ['view' => 'bestof'];
  ?>
  <section class="camera-bestof camera-bestof--inline"
           data-api-camera-detail="<?= htmlspecialchars($relativePrefix . 'api/public.php/cameras/' . rawurlencode((string) ($camera['slug'] ?? '')), ENT_QUOTES) ?>">
    <header class="camera-bestof__header">
      <div>
        <h2>Best Shots</h2>
        <p class="camera-bestof__subtitle">
          Ausgewählte Aufnahmen der Kamera <?= htmlspecialchars($camera['title'] ?? $camera['slug'] ?? '', ENT_QUOTES) ?>.
        </p>
      </div>
      <a class="button ghost" href="<?= htmlspecialchars($viewLinks['live'] ?? $relativePrefix . 'camera/' . rawurlencode((string) ($camera['slug'] ?? '')), ENT_QUOTES) ?>">Livebild öffnen</a>
    </header>

    <section class="bestof-section" id="topliked" aria-labelledby="topliked-heading">
      <div class="bestof-section__header">
        <h3 id="topliked-heading">Top 20 Likes</h3>
      </div>
      <div class="bestof-grid" data-topliked-grid>
        <div class="bestof-placeholder">Top-Likes werden geladen…</div>
      </div>
    </section>
    <script type="application/json" id="topliked-data">
      <?= json_encode($topLiked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>

    <section class="bestof-section" aria-labelledby="bestof-heading">
      <div class="bestof-section__header">
        <h3 id="bestof-heading">Best Of</h3>
        <?php if (!empty($bestOfYears)): ?>
          <div class="bestof-year-filter">
            <?php
              $allParams = $bestOfFilterParams;
              $allUrl = $bestOfFilterBase . '?' . http_build_query($allParams);
            ?>
            <a class="button <?= $bestOfSelectedYear === null ? '' : 'ghost' ?>"
               href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"
               data-bestof-year-option="<?= htmlspecialchars('all', ENT_QUOTES) ?>">
              Alle
            </a>
            <?php foreach ($bestOfYears as $yearItem): ?>
              <?php
                $year = (int) ($yearItem['year'] ?? 0);
                $count = (int) ($yearItem['count'] ?? 0);
                if ($year <= 0) {
                  continue;
                }
                $params = $bestOfFilterParams;
                $params['bestof_year'] = (string) $year;
                $yearUrl = $bestOfFilterBase . '?' . http_build_query($params);
              ?>
              <a class="button <?= $bestOfSelectedYear === $year ? '' : 'ghost' ?>"
                 href="<?= htmlspecialchars($yearUrl, ENT_QUOTES) ?>"
                 data-bestof-year-option="<?= htmlspecialchars((string) $year, ENT_QUOTES) ?>">
                <?= htmlspecialchars((string) $year, ENT_QUOTES) ?> <small>(<?= $count ?>)</small>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="bestof-grid"
           data-bestof-grid
           data-bestof-endpoint="<?= htmlspecialchars($relativePrefix . 'api/public.php/cameras/' . rawurlencode((string) ($camera['slug'] ?? '')) . '/bestof', ENT_QUOTES) ?>"
           data-bestof-page="<?= (int) ($bestOfPage ?? 1) ?>"
           data-bestof-per-page="<?= (int) ($bestOfPerPage ?? 50) ?>"
           data-bestof-year="<?= $bestOfSelectedYear !== null ? htmlspecialchars((string) $bestOfSelectedYear, ENT_QUOTES) : '' ?>">
        <div class="bestof-placeholder">Best-Of wird geladen…</div>
      </div>
      <div class="bestof-more">
        <button type="button" class="button ghost" data-bestof-more>Mehr laden</button>
      </div>
    </section>
    <script type="application/json" id="bestof-data">
      <?= json_encode($bestOf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
  </section>
<?php endif; ?>

  <footer class="camera-footer">
    <a class="button" href="<?= htmlspecialchars($relativePrefix) ?>">Zurück zur Übersicht</a>
  </footer>
</article>

<div class="lightbox" data-livebox hidden>
  <div class="lightbox__inner">
    <div class="lightbox__meta">
      <strong data-livebox-datetime></strong>
      <small data-livebox-exif></small>
    </div>
    <div class="lightbox__toolbar">
      <button
        class="lightbox__btn"
        type="button"
        data-livebox-help
        aria-controls="lightbox-keyboard-help"
        aria-expanded="false"
      >Hilfe</button>
      <button class="lightbox__btn" data-livebox-zoom>Zoom</button>
      <button class="lightbox__btn" data-livebox-close>Schließen</button>
    </div>
    <aside
      class="lightbox__keyboard-help"
      id="lightbox-keyboard-help"
      data-livebox-help-panel
      aria-label="Tastatursteuerung der Bildansicht"
      hidden
    >
      <strong>Tastatursteuerung</strong>
      <dl>
        <div><dt><kbd>←</kbd> <kbd>↑</kbd> <kbd>→</kbd> <kbd>↓</kbd></dt><dd>Bildausschnitt verschieben</dd></div>
        <div><dt><kbd>Bild ↑</kbd> / <kbd>Bild ↓</kbd></dt><dd>Vorheriges / nächstes Bild</dd></div>
        <div><dt><kbd>Shift</kbd> + <kbd>Bild ↑</kbd> / <kbd>Bild ↓</kbd></dt><dd>Vorheriger / nächster Tag</dd></div>
        <div><dt><kbd>Pos1</kbd> / <kbd>Ende</kbd></dt><dd>Erstes / letztes Bild des Tages</dd></div>
        <div><dt><kbd>+</kbd> / <kbd>−</kbd></dt><dd>Vergrößern / verkleinern</dd></div>
        <div><dt><kbd>0</kbd></dt><dd>Bild einpassen</dd></div>
        <div><dt><kbd>D</kbd></dt><dd>Sichtbaren Bildausschnitt herunterladen</dd></div>
        <div><dt><kbd>H</kbd></dt><dd>Diese Hilfe öffnen / schließen</dd></div>
        <div><dt><kbd>Esc</kbd></dt><dd>Hilfe oder Bildansicht schließen</dd></div>
      </dl>
    </aside>
    <button class="lightbox__nav lightbox__nav--prev" data-livebox-prev aria-label="Vorheriges Bild">‹</button>
    <button class="lightbox__nav lightbox__nav--next" data-livebox-next aria-label="Nächstes Bild">›</button>
    <div class="lightbox__stage">
      <img class="lightbox__img" data-livebox-img alt="Livebild">
    </div>
    <div class="lightbox__caption">
      <div>
        <strong data-livebox-title><?= htmlspecialchars($camera['title'] ?? '') ?></strong><br>
        <span data-livebox-datetime></span>
      </div>
      <div class="lightbox__status" data-livebox-status></div>
    </div>
  </div>
</div>
