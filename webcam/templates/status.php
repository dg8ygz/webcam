<?php
declare(strict_types=1);

// Rendert die in status/index.php berechneten Freshness-Zeilen. Alter und
// Severity sind bereits fachlich bestimmt; dieses Template formatiert Zeiten,
// zählt Zustände und erzeugt die sichtbare Tabelle.

/** @var list<array{camera:\Webcam\Domain\Camera,state:array<string,mixed>,age:?int,severity:string,last_capture:?int,last_success:?int,interval:int}> $rows */
/** @var string $heroText */
/** @var string $relativePrefix */

$formatAge = static function (?int $seconds): string {
  if ($seconds === null) {
    return '—';
  }
  $minutes = (int)floor($seconds / 60);
  if ($minutes < 60) {
    return $minutes . ' min';
  }
  $hours = (int)floor($minutes / 60);
  $remainingMinutes = $minutes % 60;
  return $hours . ' h ' . $remainingMinutes . ' min';
};

$normalizeAsset = static function (string $path) use ($relativePrefix): string {
  if ($path === '') {
    return $relativePrefix;
  }
  if (preg_match('#^(?:[a-z]+:)?//#i', $path)) {
    return $path;
  }
  return $relativePrefix . ltrim($path, '/');
};

$statusCounts = [
  'ok' => 0,
  'warn' => 0,
  'error' => 0,
  'unknown' => 0,
];
foreach ($rows as $row) {
  $severity = (string)($row['severity'] ?? 'unknown');
  if (!array_key_exists($severity, $statusCounts)) {
    $severity = 'unknown';
  }
  $statusCounts[$severity]++;
}

// Das externe Browsermodul erhält ausschließlich serialisierbare Daten. Damit
// bleibt dieses Template für die serverseitige Darstellung verantwortlich,
// während Modal und Live-Refresh ohne eingebetteten JavaScript-Code arbeiten.
$statusPageRows = array_map(static function ($row) use ($relativePrefix, $normalizeAsset, $app): array {
  /** @var array<string,mixed> $state */
  $state = $row['state'];
  $state['events'] = array_slice($state['events'] ?? [], 0, 50);
  $camera = $row['camera'];
  $publicBase = trim($app->cameraPresenter()->publicBaseUrl($camera), '/');
  $lastExifRelative = $state['last_exif_relative'] ?? null;
  $lastExifUrl = null;
  if (is_string($lastExifRelative) && $lastExifRelative !== '') {
    $exifPath = ($publicBase === '' ? '' : $publicBase . '/') . ltrim($lastExifRelative, '/');
    $lastExifUrl = $normalizeAsset($exifPath);
  }

  return [
    'slug' => $camera->slug(),
    'title' => $camera->title(),
    'state' => $state,
    'detailUrl' => $relativePrefix . 'camera/' . rawurlencode($camera->slug()),
    'logUrl' => $relativePrefix . 'status/log.php?cam=' . urlencode($camera->slug()),
    'exifUrl' => $lastExifUrl,
  ];
}, $rows);

$statusPagePayload = [
  'rows' => $statusPageRows,
  'detailApiBase' => $relativePrefix . 'api/public.php/cameras/',
  'listApiUrl' => $relativePrefix . 'api/public.php/cameras?per_page=200',
];
?>
<?php require __DIR__ . '/overview_header.php'; ?>

<section class="status-overview">
  <h1>Systemstatus</h1>
  <p>Überblick über Auslösezeiten, Fehlerzähler und automatische Neustarts der Kameras.</p>

  <div class="status-summary" aria-label="Statusübersicht">
    <div class="status-summary__item status-summary__item--ok">
      <span>OK</span>
      <strong><?= (int)$statusCounts['ok'] ?></strong>
    </div>
    <div class="status-summary__item status-summary__item--warn">
      <span>Warnung</span>
      <strong><?= (int)$statusCounts['warn'] ?></strong>
    </div>
    <div class="status-summary__item status-summary__item--error">
      <span>Fehler</span>
      <strong><?= (int)$statusCounts['error'] ?></strong>
    </div>
    <div class="status-summary__item">
      <span>Unbekannt</span>
      <strong><?= (int)$statusCounts['unknown'] ?></strong>
    </div>
  </div>

  <div class="status-table-wrap">
    <table class="status-table">
      <thead>
        <tr>
          <th>Kamera</th>
          <th>Letzte Auslösung</th>
          <th>Letzter Erfolg</th>
          <th>Alter</th>
          <th>Fehler in Folge</th>
          <th>Resets</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <?php $camera = $row['camera']; ?>
          <?php $state = $row['state']; ?>
          <tr
            class="severity-<?= htmlspecialchars($row['severity']) ?>"
            data-slug="<?= htmlspecialchars($camera->slug()) ?>"
            data-interval="<?= (int)$row['interval'] ?>"
            data-weather-quality-error="<?= (($state['weather_quality']['status'] ?? 'ok') === 'error') ? '1' : '0' ?>"
            data-duplicate-active="<?= !empty($state['duplicate_active']) ? '1' : '0' ?>"
          >
            <td class="cell-title"><?= htmlspecialchars($camera->title()) ?></td>
            <td data-field="last_capture"><?= $row['last_capture'] ? date('d.m.Y H:i', $row['last_capture']) : '—' ?></td>
            <td data-field="last_success"><?= $row['last_success'] ? date('d.m.Y H:i', $row['last_success']) : '—' ?></td>
            <td data-field="age"><?= $formatAge($row['age']) ?></td>
            <td data-field="failures"><?= (int)($state['consecutive_failures'] ?? 0) ?></td>
            <td data-field="resets"><?= (int)($state['resets_total'] ?? 0) ?></td>
            <td class="status-label" data-field="severity">
              <?php if ($row['severity'] === 'ok'): ?>
                <span class="badge badge-ok">OK</span>
              <?php elseif ($row['severity'] === 'warn'): ?>
                <span class="badge badge-warn">Warnung</span>
              <?php elseif ($row['severity'] === 'error'): ?>
                <span class="badge badge-error">Fehler</span>
              <?php else: ?>
                <span class="badge">Unbekannt</span>
              <?php endif; ?>
            </td>
            <td><button class="button button-small" type="button" data-modal-trigger="<?= htmlspecialchars($camera->slug()) ?>">Details</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<template id="status-modal-template">
  <div class="status-modal" role="dialog" aria-modal="true">
    <div class="status-modal__content">
      <header class="status-modal__header">
        <h2 class="status-modal__title"></h2>
        <button type="button" class="status-modal__close" aria-label="Schließen">×</button>
      </header>
      <section class="status-modal__body">
        <div class="status-modal__summary"></div>
        <h3>Ereignisprotokoll</h3>
        <div class="status-modal__events"></div>
      </section>
      <footer class="status-modal__footer">
        <a class="button secondary" data-modal-log href="#">Log herunterladen</a>
        <a class="button" data-modal-link>Zur Kamera</a>
      </footer>
    </div>
  </div>
</template>

<script type="application/json" id="status-page-data"><?= json_encode(
  $statusPagePayload,
  JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
