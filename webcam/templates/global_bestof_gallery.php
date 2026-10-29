<?php
declare(strict_types=1);

/**
 * Gemeinsamer Inhalt des globalen Best Of. Der aufrufende View entscheidet,
 * welche Kopfzeile davor steht; diese Datei rendert ausschließlich die Galerie.
 *
 * @var \Webcam\App $app
 * @var array<string,mixed> $globalBestOfPage
 * @var list<\Webcam\Domain\Camera> $cameraOptions
 * @var int|null $globalSelectedYear
 * @var string|null $globalSelectedCamera
 * @var int $globalBestOfPerPage
 * @var string $relativePrefix
 */

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$basePrefix = $basePathValue === '' ? '' : '/' . $basePathValue;
$timezone = new DateTimeZone((string) $app->config('timezone', 'UTC'));
$items = is_array($globalBestOfPage['items'] ?? null) ? $globalBestOfPage['items'] : [];
$years = is_array($globalBestOfPage['years'] ?? null) ? $globalBestOfPage['years'] : [];
$total = (int) ($globalBestOfPage['total'] ?? 0);
$totalPages = (int) ($globalBestOfPage['total_pages'] ?? 1);
$page = (int) ($globalBestOfPage['page'] ?? 1);
$globalBestOfFormAction ??= $relativePrefix . 'bestof';
$globalBestOfCameraField ??= 'camera';
$globalBestOfYearField ??= 'year';
$globalBestOfHiddenFields ??= [];
$globalBestOfClearUrl ??= $relativePrefix . 'bestof';
$globalBestOfBackUrl ??= $relativePrefix;
$globalBestOfBackLabel ??= 'Übersicht';

$normalizePublicPath = static function (?string $path) use ($relativePrefix, $basePrefix): string {
  $path = is_string($path) ? trim($path) : '';
  if ($path === '') {
    return '#';
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  if ($path[0] === '/') {
    if ($basePrefix !== '' && str_starts_with($path, $basePrefix . '/')) {
      return $relativePrefix . ltrim(substr($path, strlen($basePrefix)), '/');
    }
    return $path;
  }
  return $relativePrefix . ltrim($path, './');
};

$formatTimestamp = static function (mixed $timestamp) use ($timezone): string {
  if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
    return 'Zeit unbekannt';
  }
  return (new DateTimeImmutable('@' . (int) $timestamp))
    ->setTimezone($timezone)
    ->format('d.m.Y H:i');
};
?>
<section class="camera-bestof global-bestof"
         data-global-bestof
         data-endpoint="<?= htmlspecialchars($relativePrefix . 'api/public.php/bestof', ENT_QUOTES) ?>"
         data-app-prefix="<?= htmlspecialchars($relativePrefix, ENT_QUOTES) ?>"
         data-page="<?= $page ?>"
         data-per-page="<?= (int) $globalBestOfPerPage ?>"
         data-total-pages="<?= $totalPages ?>"
         data-selected-year="<?= $globalSelectedYear !== null ? (int) $globalSelectedYear : '' ?>"
         data-selected-camera="<?= htmlspecialchars((string) ($globalSelectedCamera ?? ''), ENT_QUOTES) ?>">
  <header class="global-bestof__header">
    <a class="global-bestof__back" href="<?= htmlspecialchars($globalBestOfBackUrl, ENT_QUOTES) ?>">← <?= htmlspecialchars($globalBestOfBackLabel) ?></a>
  </header>

  <form class="global-bestof__filters" method="get" action="<?= htmlspecialchars($globalBestOfFormAction, ENT_QUOTES) ?>">
    <?php foreach ($globalBestOfHiddenFields as $name => $value): ?>
      <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>">
    <?php endforeach; ?>
    <label>
      <span>Kamera</span>
      <select name="<?= htmlspecialchars($globalBestOfCameraField, ENT_QUOTES) ?>">
        <option value="">Alle Kameras</option>
        <?php foreach ($cameraOptions as $cameraOption): ?>
          <option value="<?= htmlspecialchars($cameraOption->slug(), ENT_QUOTES) ?>"<?= $globalSelectedCamera === $cameraOption->slug() ? ' selected' : '' ?>>
            <?= htmlspecialchars($cameraOption->title(), ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Jahr</span>
      <select name="<?= htmlspecialchars($globalBestOfYearField, ENT_QUOTES) ?>">
        <option value="">Alle Jahre</option>
        <?php foreach ($years as $yearItem): ?>
          <?php $year = (int) ($yearItem['year'] ?? 0); if ($year <= 0) continue; ?>
          <option value="<?= $year ?>"<?= $globalSelectedYear === $year ? ' selected' : '' ?>>
            <?= $year ?> (<?= (int) ($yearItem['count'] ?? 0) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button" type="submit">Anzeigen</button>
    <?php if ($globalSelectedYear !== null || $globalSelectedCamera !== null): ?>
      <a class="button ghost" href="<?= htmlspecialchars($globalBestOfClearUrl, ENT_QUOTES) ?>">Filter löschen</a>
    <?php endif; ?>
    <span class="global-bestof__count" data-global-bestof-count><?= $total ?> Bilder</span>
  </form>

  <div class="bestof-grid global-bestof__grid" data-global-bestof-grid>
    <?php foreach ($items as $item): ?>
      <a class="bestof-card global-bestof-card"
         href="<?= htmlspecialchars($normalizePublicPath($item['link'] ?? null), ENT_QUOTES) ?>"
         data-camera-slug="<?= htmlspecialchars((string) ($item['camera_slug'] ?? ''), ENT_QUOTES) ?>"
         data-slot-ts="<?= isset($item['slot_ts']) ? (int) $item['slot_ts'] : '' ?>">
        <img src="<?= htmlspecialchars($normalizePublicPath($item['thumb_url'] ?? $item['url'] ?? null), ENT_QUOTES) ?>"
             data-full-src="<?= htmlspecialchars($normalizePublicPath($item['url'] ?? null), ENT_QUOTES) ?>"
             alt="Best-Of-Aufnahme von <?= htmlspecialchars((string) ($item['camera_title'] ?? ''), ENT_QUOTES) ?>"
             loading="lazy"
             decoding="async">
        <span class="global-bestof-card__caption">
          <strong><?= htmlspecialchars((string) ($item['camera_title'] ?? $item['camera_slug'] ?? ''), ENT_QUOTES) ?></strong>
          <small><?= htmlspecialchars($formatTimestamp($item['slot_ts'] ?? null), ENT_QUOTES) ?></small>
        </span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
    <p class="bestof-placeholder" data-global-bestof-empty>Für diesen Filter sind keine Best-Of-Bilder vorhanden.</p>
  <?php endif; ?>

  <div class="bestof-more global-bestof__more">
    <button class="button ghost" type="button" data-global-bestof-more<?= $page >= $totalPages ? ' hidden' : '' ?>>Mehr laden</button>
    <span class="muted" data-global-bestof-status aria-live="polite"></span>
  </div>
</section>
