<?php
declare(strict_types=1);

// Rendert Kamera-Karten, Hero und Karte aus den von index.php vorbereiteten
// Presenter-Daten. Die lokalen URL-Helfer normalisieren nur Browserpfade; sie
// lesen keine Bilder und führen keine Capture- oder DB-Operationen aus.

/** @var list<array<string,mixed>> $cameras */
/** @var \Webcam\App $app */
/** @var list<array<string,mixed>> $mapMarkers */

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);
$basePrefix = $basePathValue === '' ? '' : '/' . $basePathValue;

$routerCameraPrefix = $relativePrefix . 'camera/';

$buildCameraLink = static function (string $slug, array $params = []) use ($routerCameraPrefix): string {
  $safeSlug = rawurlencode($slug);
  $query = $params ? http_build_query($params) : '';
  $base = $routerCameraPrefix . $safeSlug;
  return $query !== '' ? ($base . '?' . $query) : $base;
};

$normalizeAsset = static function (?string $path) use ($relativePrefix, $basePrefix): string {
  if ($path === null || $path === '') {
    return '';
  }
  // Absolute Pfade (beginnend mit /) unverändert lassen
  if ($path[0] === '/') {
    if ($basePrefix !== '' && str_starts_with($path, $basePrefix . '/')) {
      return $relativePrefix . ltrim(substr($path, strlen($basePrefix)), '/');
    }
    return $path;
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  return $relativePrefix . ltrim($path, '/');
};

$meta = $app?->camerasMeta() ?? [];
$heroText = $meta['overview_hero'] ?? ($app?->config('overview_hero') ?? 'Schnelle Kachelübersicht mit Filter & Sortierung – ähnlich foto-webcam.eu, aber leichtgewichtig.');
$mapConfig = $meta['overview_map'] ?? ['lat' => 51.96, 'lng' => 8.53, 'zoom' => 11, 'height_px' => 220];
?>

<?php require __DIR__ . '/overview_header.php'; ?>

<div class="overview-layout">
  <section class="overview-main" aria-label="Kameraliste">
    <div class="overview-grid" data-camera-grid>
<?php $cardIndex = 0; ?>
<?php foreach ($cameras as $camera): ?>
      <?php
        $detailUrl = $buildCameraLink((string) $camera['slug']);
        $streamMode = (string) ($camera['mode'] ?? '');
        $ageSeconds = $camera['age_seconds'];
        $interval = $camera['interval_seconds'] ?? 600;
        $freshness = 'unknown';
        if ($ageSeconds === null) {
          $ageLabel = 'Keine Daten';
        } else {
          $minutes = (int)floor($ageSeconds / 60);
          $ageLabel = $minutes < 1 ? 'Gerade eben' : $minutes . ' min';
          if ($ageSeconds <= $interval * 2) {
            $freshness = 'ok';
          } elseif ($ageSeconds <= $interval * 3) {
            $freshness = 'warn';
          } else {
            $freshness = 'error';
          }
        }
        $tags = $camera['tags'] ?? [];
        if (is_string($tags)) {
          $tags = array_map('trim', explode(',', $tags));
        }
        $tags = array_values(array_filter(
          $tags,
          static fn($t) => $t !== '' && !str_starts_with((string) $t, '__')
        ));
        // Responsive Hero für schnelleres LCP
        $resUrls = $camera['resolutions_urls'] ?? [];
        $availableWidths = array_map('intval', array_keys($resUrls));
        sort($availableWidths, SORT_NUMERIC);
        $displayWidths = array_values(array_filter($availableWidths, static fn(int $w): bool => $w <= 816));
        if ($displayWidths === []) {
          $displayWidths = $availableWidths;
        }
        $preferredWidths = array_values(array_filter($displayWidths, static fn(int $w): bool => $w <= 320));
        $fallbackWidth = $preferredWidths !== []
          ? max($preferredWidths)
          : ($displayWidths[0] ?? ($availableWidths[0] ?? 320));
        $heroUrl = $resUrls[$fallbackWidth] ?? $camera['current_image_url'];
        $heroUrl = $normalizeAsset($heroUrl);
        $srcset = [];
        foreach ($displayWidths as $w) {
          if (!isset($resUrls[$w])) {
            continue;
          }
          $srcset[] = $normalizeAsset($resUrls[$w]) . " {$w}w";
        }
        $srcsetAttr = $srcset ? implode(', ', $srcset) : '';
        $tagAttr = implode(',', $tags);
        $isHeroCard = ($cardIndex === 0);
      ?>
      <article class="camera-card camera-card--modern"
               data-camera-card
               data-title="<?= htmlspecialchars(strtolower($camera['title'])) ?>"
               data-slug="<?= htmlspecialchars($camera['slug']) ?>"
               data-mode="<?= htmlspecialchars($streamMode) ?>"
               data-age="<?= $ageSeconds !== null ? (int)$ageSeconds : 999999 ?>"
               data-last-ts="<?= $camera['last_capture'] instanceof DateTimeImmutable ? $camera['last_capture']->getTimestamp() : 0 ?>"
               data-interval="<?= (int) ($interval ?: 600) ?>"
               data-tags="<?= htmlspecialchars(strtolower($tagAttr)) ?>">
        <div class="card-media">
          <a class="camera-image" href="<?= htmlspecialchars($detailUrl) ?>" title="<?= htmlspecialchars($camera['title']) ?>">
            <?php if ($heroUrl !== ''): ?>
              <img class="camera-image__img" src="<?= htmlspecialchars($heroUrl) ?>"
                   alt="Aktuelles Bild von <?= htmlspecialchars($camera['title']) ?>"
                   loading="<?= $isHeroCard ? 'eager' : 'lazy' ?>"
                   <?= $isHeroCard ? 'fetchpriority="high"' : '' ?>
                   <?= $srcsetAttr ? 'srcset="' . htmlspecialchars($srcsetAttr) . '"' : '' ?>
                   sizes="(max-width: 640px) 100vw, (max-width: 1200px) 50vw, 33vw"
                   decoding="async">
            <?php else: ?>
              <span class="camera-image__placeholder">Kein aktuelles Bild</span>
            <?php endif; ?>
            <span class="camera-image__badge">Live</span>
          </a>
          <div class="media-badges">
            <span class="pill pill--mode" data-mode-pill><?= htmlspecialchars(strtoupper($streamMode)) ?></span>
            <span class="pill<?= $freshness !== 'unknown' ? ' pill--' . $freshness : '' ?>" data-age-pill>
              <?= htmlspecialchars($ageLabel) ?>
            </span>
          </div>
        </div>
        <div class="card-body">
          <div class="card-title-row">
            <div>
              <h2><?= htmlspecialchars($camera['title']) ?></h2>
              <p class="meta-line"><?= htmlspecialchars($camera['short_title'] ?? $camera['title']) ?></p>
            </div>
            <div class="card-actions-compact">
              <button class="btn ghost small fav-btn" type="button" data-action="fav-toggle" aria-label="Favorit umschalten" title="Favorit">☆</button>
              <a class="btn primary small" href="<?= htmlspecialchars($detailUrl) ?>">Öffnen</a>
              <a class="btn ghost small" href="<?= htmlspecialchars($buildCameraLink((string) $camera['slug'], ['view' => 'bestof'])) ?>">Best Shots</a>
            </div>
          </div>
          <?php if ($tags): ?>
            <div class="tag-row">
              <?php foreach ($tags as $tag): ?>
                <span class="tag-badge"><?= htmlspecialchars($tag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </article>
      <?php $cardIndex++; ?>
<?php endforeach; ?>
    </div>

    <div class="overview-toolbar">
      <div class="toolbar-actions">
        <label class="toolbar-inline">
          <span>Layout</span>
          <select data-layout-toggle>
            <option value="grid">Grid</option>
            <option value="list">Liste</option>
          </select>
        </label>
        <label class="toolbar-inline">
          <input type="checkbox" data-toggle-favs>
          <span>Nur Favoriten</span>
        </label>
      </div>
    </div>

    <section class="overview-recent" data-overview-recent hidden>
      <div class="overview-recent__header">
        <div>
          <strong>Zuletzt angesehen</strong>
          <span class="overview-recent__hint">Lokal in diesem Browser</span>
        </div>
        <button type="button" class="btn ghost small" data-recent-clear>Leeren</button>
      </div>
      <div class="overview-recent__list" data-recent-list></div>
    </section>

    <div class="overview-map-card" style="--map-height: <?= (int) ($mapConfig['height_px'] ?? 220) ?>px;">
      <h3>Karte</h3>
      <div id="webcam-map" class="map-shell" aria-label="Karte"></div>
    </div>
  </section>
</div>

<div class="lightbox" data-lightbox hidden>
  <div class="lightbox__inner">
    <div class="lightbox__meta">
      <strong data-lightbox-datetime></strong>
      <small data-lightbox-exif></small>
    </div>
    <div class="lightbox__toolbar">
      <button class="lightbox__btn" data-lightbox-zoom>Zoom</button>
      <button class="lightbox__btn" data-lightbox-close>Schließen</button>
    </div>
    <button class="lightbox__nav lightbox__nav--prev" data-lightbox-prev aria-label="Zurück (älter)">‹</button>
    <button class="lightbox__nav lightbox__nav--next" data-lightbox-next aria-label="Vor (neuer)">›</button>
    <div class="lightbox__stage">
      <img class="lightbox__img" data-lightbox-img alt="Webcam Bild">
    </div>
    <div class="lightbox__caption">
      <div>
        <strong data-lightbox-title></strong><br>
        <span data-lightbox-datetime></span>
      </div>
      <div class="lightbox__status" data-lightbox-status></div>
    </div>
  </div>
</div>

<?php
// Dynamische Serverdaten werden als nicht ausführbares JSON übergeben.
// overview.js liest diesen Block einmal beim Start. Die JSON_HEX-Flags
// verhindern, dass Kameratexte den Script-Block vorzeitig schließen können.
$overviewClientConfig = [
  'apiUrl' => $relativePrefix . 'api/public.php/cameras?per_page=200',
  'timezone' => (string) ($app?->config('timezone') ?? 'UTC'),
  'baseUrl' => $relativePrefix,
  'markers' => $mapMarkers ?? [],
  'map' => $mapConfig,
];
?>
<script type="application/json" data-overview-config>
<?= json_encode(
  $overviewClientConfig,
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
) ?>
</script>
