<?php
declare(strict_types=1);
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$options = static function () use ($cameras, $esc): void {
  foreach ($cameras as $camera) {
    echo '<option value="' . $esc($camera['slug']) . '">' . $esc($camera['title']) . '</option>';
  }
};
// Keep the same global navigation as overview, weather and status.
$meta = $app->camerasMeta();
$heroText = $meta['overview_hero'] ?? $app->config('overview_hero', '');
require __DIR__ . '/overview_header.php';
?>
<div id="explore" class="explore-view" data-prefix="<?= $esc($relativePrefix) ?>" data-timezone="<?= $esc($timezone) ?>">
  <header class="explore-head">
    <h2>Mehrkamera</h2>
    <p>Perspektiven vergleichen und aktuelle Bilder als Diashow ansehen.</p>
  </header>
  <section data-panel="cameras" aria-label="Mehrkamera und Diashow">
    <div class="explore-controls">
      <label>Ansicht<select id="camera-mode"><option value="2">2 Kameras</option><option value="4">4 Kameras</option><option value="show">Diashow</option></select></label>
      <label>Datum<input id="multi-date" type="date" min="2000-01-01" max="2100-12-31" value="<?= date('Y-m-d') ?>"></label>
      <div class="multi-clock-picker" id="multi-clock-picker">
        <span id="multi-clock-label">Uhrzeit (<?= $esc($timezone) ?>)</span>
        <input id="multi-clock" type="hidden" value="<?= date('H:i') ?>">
        <button type="button" id="multi-clock-trigger" aria-label="Uhrzeit" aria-haspopup="listbox" aria-expanded="false" aria-controls="multi-clock-options"><?= date('H:i') ?> ▾</button>
        <div id="multi-clock-options" class="multi-clock-options" role="listbox" aria-labelledby="multi-clock-label" hidden>
          <?php for ($minute = 0; $minute < 1440; $minute += 10): $value = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60); ?>
            <button type="button" role="option" aria-selected="false" data-multi-clock="<?= $value ?>"><?= $value ?></button>
          <?php endfor; ?>
        </div>
      </div>
      <button type="button" id="multi-load">Zeitpunkt anzeigen</button>
      <button type="button" id="multi-live">Aktuelle Bilder</button>
    </div>
    <div class="explore-controls" id="camera-selects">
      <?php for ($i = 0; $i < 4; $i++): ?>
        <label <?= $i > 1 ? 'hidden' : '' ?>>Kamera <?= $i + 1 ?><select data-camera-select><?php $options(); ?></select></label>
      <?php endfor; ?>
    </div>
    <div id="multi-time-controls" class="explore-controls">
      <button type="button" id="multi-prev" aria-label="10 Minuten zurück">−10 min</button>
      <label class="explore-grow">Gemeinsame Tageszeit<input id="multi-range" type="range" min="0" max="1439" step="1" value="720"></label>
      <button type="button" id="multi-next" aria-label="10 Minuten weiter">+10 min</button>
    </div>
    <div id="show-controls" class="explore-controls" hidden>
      <label>Bildauswahl<select id="show-source"><option value="current">Aktuelle Bilder · gewählte Kameras</option><option value="random">Zufall · alle Kameras und Zeitpunkte</option></select></label>
      <label>Bildwechsel<select id="show-delay"><option value="5">5 Sekunden</option><option value="10" selected>10 Sekunden</option><option value="20">20 Sekunden</option></select></label>
      <button type="button" id="show-next" hidden>Nächstes Zufallsbild</button>
      <button type="button" id="show-play">Diashow starten</button>
      <button type="button" id="show-fullscreen">Vollbild</button>
      <span id="show-description">Gewählte Kameras · aktuelle Einzelbilder · Aktualisierung alle 60 Sekunden</span>
    </div>
    <p id="multi-status" role="status" aria-live="polite"></p>
    <div id="multi-grid" class="explore-grid"></div>
    <p class="explore-note">Vergleich: jeweils die nächste Aufnahme innerhalb ±10 Minuten. Aufnahmezeit und Abweichung stehen am Bild; Lücken werden nicht mit aktuellen Bildern ersetzt.</p>
  </section>
  <noscript>Für diese interaktiven Ansichten bitte JavaScript aktivieren. Die <a href="<?= $esc($relativePrefix) ?>">Kameraübersicht</a> bleibt erreichbar.</noscript>
</div>
