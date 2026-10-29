<?php
declare(strict_types=1);

/*
 * Lokale, nur lesende Statusseite. Sie greift ausschliesslich auf feste
 * Dateien unter /run zu. Sensoren, GPIO und Kamera werden niemals aus einem
 * Webrequest heraus angesprochen.
 */

const DEFAULT_STATUS_ROOT = '/run/camera-rpi-status';

$statusRoot = getenv('CAMERA_RPI_STATUS_ROOT') ?: DEFAULT_STATUS_ROOT;
$statusRoot = rtrim($statusRoot, '/');

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
// Keep the UI update self-contained for existing two-file Pi installers. Only
// this exact static script is authorized by CSP; no unsafe-inline or requests
// from hover events, and no sensor/GPIO interaction from the browser.
$chartScript = <<<'JS'
(() => {
  'use strict';
  const date = new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin', dateStyle: 'short', timeStyle: 'medium'
  });
  const number = new Intl.NumberFormat('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1});
  function initCharts(root = document) {
  root.querySelectorAll('.chart-interactive').forEach((box, chartIndex) => {
    const data = JSON.parse(box.dataset.chart);
    const rows = data.rows;
    const svg = box.querySelector('svg');
    if (!rows.length || !svg) return;
    const first = rows[0].epoch, span = Math.max(1, rows[rows.length - 1].epoch - first);
    const cursor = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    cursor.classList.add('chart-cursor');
    cursor.setAttribute('y1', '48'); cursor.setAttribute('y2', String(data.bottom));
    cursor.setAttribute('visibility', 'hidden'); cursor.setAttribute('aria-hidden', 'true');
    svg.append(cursor);
    const tip = document.createElement('div');
    tip.className = 'chart-tooltip'; tip.id = `chart-values-${chartIndex}`;
    tip.hidden = true; tip.setAttribute('role', 'status');
    const time = document.createElement('strong');
    const details = document.createElement('div');
    const note = document.createElement('small');
    tip.append(time, details, note); box.append(tip);
    svg.setAttribute('tabindex', '0'); svg.setAttribute('aria-describedby', tip.id);
    let selected = rows.length - 1;

    // Always report an actual stored snapshot, never interpolated PWM/heater
    // or a fabricated time. Unknown fields and stopped-controller rows stay
    // unknown; simulation remains explicit even in historical tooltips.
    function show(index, inGap = false) {
      selected = Math.max(0, Math.min(rows.length - 1, index));
      const row = rows[selected];
      const x = data.left + (row.epoch - first) / span * (data.right - data.left);
      cursor.setAttribute('x1', String(x)); cursor.setAttribute('x2', String(x));
      cursor.setAttribute('visibility', 'visible');
      time.textContent = date.format(new Date(row.epoch * 1000));
      details.replaceChildren();
      data.series.forEach(([key, label, unit]) => {
        const value = row.state === 'stopped' ? null : row[key];
        const line = document.createElement('span');
        line.className = `value-${key}`;
        const text = row.hidden_outputs?.includes(key) ? 'Ausgeblendet'
          : value === null || value === undefined ? 'Unbekannt'
          : key === 'heater' ? (value === 1 ? 'An' : 'Aus') : `${number.format(value)} ${unit}`;
        line.textContent = `${label}: ${text}`;
        details.append(line);
      });
      note.textContent = (inGap ? 'Messlücke · nächster gespeicherter Messpunkt' : 'Gespeicherter Messpunkt')
        + (row.mode === 'simulate' ? ' · Simulation' : '');
      tip.hidden = false;
    }
    function hide() { tip.hidden = true; cursor.setAttribute('visibility', 'hidden'); }

    // Invert the SVG transform, including mobile letterboxing. Only X selects
    // time; every vertical position in the SVG is a usable pointer target.
    function pointer(event) {
      const matrix = svg.getScreenCTM();
      if (!matrix) return;
      const point = svg.createSVGPoint(); point.x = event.clientX; point.y = event.clientY;
      const x = point.matrixTransform(matrix.inverse()).x;
      const target = first + Math.max(0, Math.min(1, (x - data.left) / (data.right - data.left))) * span;
      let lo = 0, hi = rows.length - 1;
      while (lo < hi) {
        const mid = Math.floor((lo + hi) / 2);
        if (rows[mid].epoch < target) lo = mid + 1; else hi = mid;
      }
      const previous = Math.max(0, lo - 1);
      const index = target - rows[previous].epoch <= rows[lo].epoch - target ? previous : lo;
      const gap = rows[lo].epoch - rows[previous].epoch > 45
        && target > rows[previous].epoch + 1 && target < rows[lo].epoch - 1;
      show(index, gap);
    }
    svg.addEventListener('pointermove', pointer);
    svg.addEventListener('pointerdown', pointer);
    svg.addEventListener('pointerleave', hide);
    svg.addEventListener('focus', () => show(selected));
    svg.addEventListener('blur', hide);
    svg.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return;
      event.preventDefault();
      if (event.key === 'Escape') { hide(); return; }
      show(event.key === 'Home' ? 0 : event.key === 'End' ? rows.length - 1
        : selected + (event.key === 'ArrowLeft' ? -1 : 1));
    });
  });
  }
  initCharts();

  // One read-only background request per minute, never a document navigation.
  // Keep the existing image node when its version is unchanged. A new JPEG is
  // decoded off-screen before replacing it, so slow downloads cannot flash an
  // empty frame. A missing image, however, removes the old preview immediately
  // (e.g. privacy deletion); a failed download retains its old timestamp too.
  const interval = 60000;
  let busy = false, timer, lastAttempt = Date.now();
  async function update() {
    if (busy || document.hidden) return;
    busy = true; lastAttempt = Date.now(); clearTimeout(timer);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    let imageFailed = false;
    try {
      const url = new URL(location.href);
      url.search = ''; url.hash = '';
      const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
      if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('status response');
      // A template keeps response images inert: DOMParser may already download
      // them, even when the displayed image version has not changed.
      const template = document.createElement('template');
      template.innerHTML = await response.text();
      const next = template.content;
      const selectors = ['.page-head', '.hero', 'main > .chart-card:not(.climate-history):not(.climate-overview)', '.climate-history', '.climate-overview'];
      if (selectors.some(selector => !next.querySelector(selector) || !document.querySelector(selector))) throw new Error('status structure');
      const hero = document.querySelector('.hero'), nextHero = next.querySelector('.hero');
      const oldImage = hero.querySelector('img'), nextImage = nextHero.querySelector('img');
      let readyImage = null;
      if (nextImage) {
        const src = new URL(nextImage.getAttribute('src'), location.href);
        if (src.origin !== location.origin || src.pathname !== location.pathname
            || src.searchParams.get('asset') !== 'latest-image') throw new Error('image URL');
        if (oldImage && oldImage.src === src.href && oldImage.complete && oldImage.naturalWidth > 0) {
          readyImage = oldImage;
        } else {
          try {
            readyImage = new Image();
            const image = readyImage;
            await new Promise((resolve, reject) => {
              const abort = () => { image.src = ''; reject(new Error('image timeout')); };
              controller.signal.addEventListener('abort', abort, {once: true});
              const cleanup = () => controller.signal.removeEventListener('abort', abort);
              image.onload = async () => {
                try { await image.decode(); cleanup(); resolve(); } catch (error) { cleanup(); reject(error); }
              };
              image.onerror = () => { cleanup(); reject(new Error('image download')); };
              if (controller.signal.aborted) { abort(); return; }
              image.src = src.href;
            });
            readyImage.alt = nextImage.alt;
          } catch { imageFailed = true; }
        }
      }
      const scroll = [scrollX, scrollY];
      // Imported scripts are never executed. Only the fixed status fragments
      // are adopted; the original CSP-hashed code remains in control.
      if (!imageFailed) {
        if (readyImage && readyImage === oldImage) {
          // Do not even detach/adopt the unchanged image: with no-store that
          // can trigger another fetch despite an identical src and DOM node.
          hero.querySelector('.overlay').replaceWith(nextHero.querySelector('.overlay'));
        } else if (readyImage) {
          hero.replaceChildren(readyImage, nextHero.querySelector('.overlay'));
        } else {
          hero.replaceChildren(...nextHero.childNodes);
        }
      }
      for (const selector of selectors.filter(selector => selector !== '.hero')) {
        document.querySelector(selector).replaceWith(next.querySelector(selector));
      }
      document.title = next.querySelector('title')?.textContent || document.title;
      initCharts();
      scrollTo(...scroll);
      document.querySelector('.page-head .refresh').textContent = imageFailed
        ? 'Neues Bild nicht ladbar · letzter Bildstand bleibt sichtbar'
        : 'Aktualisierung alle 60 Sekunden · ohne Neuladen';
    } catch {
      document.querySelector('.page-head .refresh').textContent = 'Aktualisierung fehlgeschlagen · letzter Stand bleibt sichtbar';
    } finally {
      clearTimeout(timeout); busy = false;
      timer = setTimeout(update, interval);
    }
  }
  timer = setTimeout(update, interval);
  // Hidden tabs do not poll; returning to an overdue tab refreshes once.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - lastAttempt >= interval) update();
  });
})();
JS;
$chartScriptHash = base64_encode(hash('sha256', $chartScript, true));
header("Content-Security-Policy: default-src 'self'; script-src 'sha256-$chartScriptHash'; style-src 'self'; img-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

// Feste RAM-Vorschau ausliefern, keinen vom Request waehlbaren Dateipfad oeffnen.
if (($_GET['asset'] ?? '') === 'latest-image') {
    $imagePath = $statusRoot . '/latest.jpg';
    if (!is_file($imagePath) || !is_readable($imagePath)) {
        http_response_code(404);
        exit;
    }
    $size = filesize($imagePath);
    if ($size === false || $size < 128 || $size > 100 * 1024 * 1024) {
        http_response_code(503);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . $size);
    readfile($imagePath);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Liest freigegebene Statusfelder; fehlende Daten bleiben fuer die Anzeige unbekannt. */
function read_status_file(string $path, array $allowed): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false || count($lines) > 32) {
        return [];
    }
    $result = [];
    foreach ($lines as $line) {
        if (strlen($line) > 512 || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (in_array($key, $allowed, true) && !array_key_exists($key, $result)) {
            $result[$key] = $value;
        }
    }
    return $result;
}

/** Plausible Epoch-Zeit fuer die Anzeige, sonst null statt eines erfundenen Datums. */
function safe_epoch(string $value): ?int
{
    if (!preg_match('/^[0-9]{10}$/D', $value)) {
        return null;
    }
    $epoch = (int)$value;
    return $epoch >= 1577836800 && $epoch <= time() + 3600 ? $epoch : null;
}

/** Nur das publizierte Dezimalformat und den jeweiligen Messbereich akzeptieren. */
function safe_decimal(string $value, float $minimum, float $maximum): ?float
{
    if (!preg_match('/^-?[0-9]{1,3}(?:\.[0-9])?$/D', $value)) {
        return null;
    }
    $number = (float)$value;
    return $number >= $minimum && $number <= $maximum ? $number : null;
}

/** Validierte Messreihen auf SVG-Koordinaten abbilden, ohne Sensor-/Dateischreibzugriff. */
function graph_points(array $rows, string $key, float $minimum, float $maximum): string
{
    $points = [];
    $count = count($rows);
    if ($count < 2 || $maximum <= $minimum) {
        return '';
    }
    $start = $rows[0]['epoch'];
    $span = max(1, $rows[array_key_last($rows)]['epoch'] - $start);
    foreach ($rows as $row) {
        $x = 24.0 + (($row['epoch'] - $start) / $span) * 852.0;
        $ratio = ((float)$row[$key] - $minimum) / ($maximum - $minimum);
        $y = 236.0 - max(0.0, min(1.0, $ratio)) * 188.0;
        $points[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
    }
    return implode(' ', $points);
}

date_default_timezone_set('Europe/Berlin');

$sensor = read_status_file($statusRoot . '/current.status', [
    'updated_epoch', 'temperature_c', 'humidity_percent',
]);
$capture = read_status_file($statusRoot . '/latest.status', [
    'captured_epoch', 'requested_epoch', 'server_id', 'camera_slug',
]);
// Reuse the public capture metadata, never read private server configs/keys.
// Until the first published capture there is no known webcam identifier.
$cameraSlug = trim((string)($capture['camera_slug'] ?? ''));
// Kennungen bleiben technisch kleingeschrieben; nur den Anzeigenamen am
// Satzanfang gross schreiben. Die erlaubten Kennungen sind ASCII-basiert.
$cameraDisplayName = ucfirst($cameraSlug);
$localTitle = 'Lokaler Blick' . ($cameraDisplayName !== '' ? ' auf ' . $cameraDisplayName : '');

$temperature = safe_decimal((string)($sensor['temperature_c'] ?? ''), -40, 80);
$humidity = safe_decimal((string)($sensor['humidity_percent'] ?? ''), 0, 100);
$sensorEpoch = safe_epoch((string)($sensor['updated_epoch'] ?? ''));
$captureEpoch = safe_epoch((string)($capture['captured_epoch'] ?? ''));
$climate = read_status_file($statusRoot . '/climate.status', [
    'updated_epoch', 'mode', 'state', 'message', 'heater', 'fan_percent',
    'fan_target_percent', 'dew_point_c', 'dew_gap_c', 'outputs', 'fault', 'measurement_source',
]);
$climateFault = trim((string)($climate['fault'] ?? ''));
$climateEpoch = safe_epoch((string)($climate['updated_epoch'] ?? ''));
$climateFresh = $climateEpoch !== null && time() - $climateEpoch >= 0 && time() - $climateEpoch <= 10;
// The second overview uses the regulator's current RAM snapshot, not inferred
// heater feedback or RPM. Missing/stale values are unknown, never synthetic zero.
$climateDew = $climateFresh ? safe_decimal((string)($climate['dew_point_c'] ?? ''), -150, 80) : null;
$climateGap = $climateFresh ? safe_decimal((string)($climate['dew_gap_c'] ?? ''), 0, 230) : null;
$climatePwm = $climateFresh ? safe_decimal((string)($climate['fan_percent'] ?? ''), 0, 100) : null;
$climateHeater = $climateFresh ? match ($climate['heater'] ?? '') {
    'on' => 'An', 'off' => 'Aus', default => 'Unbekannt',
} : 'Unbekannt';
$climateMode = $climateFresh ? match ($climate['mode'] ?? '') {
    'off' => 'Aus', 'simulate' => 'Simulation', 'auto' => 'Automatik', 'manual80' => 'Festbetrieb: Heizung an / Lüfter 80 % (ohne DHT22)', default => 'Unbekannt',
} : 'Unbekannt';

$hasImage = is_file($statusRoot . '/latest.jpg') && is_readable($statusRoot . '/latest.jpg');

// Historischer Verlauf und aktueller Sensorstatus bleiben getrennt: nach Restore
// sind alte Messpunkte kein Beleg fuer eine gerade erfolgreiche Sensorabfrage.
$history = [];
$historyPath = $statusRoot . '/history.tsv';
if (is_file($historyPath) && is_readable($historyPath)) {
    $lines = file($historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach (array_slice($lines, -2880) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 3) {
                continue;
            }
            $epoch = safe_epoch($fields[0]);
            $temp = safe_decimal($fields[1], -40, 80);
            $humid = safe_decimal($fields[2], 0, 100);
            if ($epoch !== null && $temp !== null && $humid !== null) {
                $history[$epoch] = ['epoch' => $epoch, 'temperature' => $temp, 'humidity' => $humid];
            }
        }
    }
}

// One sorted record per timestamp keeps SVG geometry and binary hover lookup
// consistent after history restore, duplicate records or clock corrections.
ksort($history, SORT_NUMERIC);
$history = array_values($history);
$temperatures = array_column($history, 'temperature');
$tempMin = $temperatures ? floor(min($temperatures) - 1) : 0.0;
$tempMax = $temperatures ? ceil(max($temperatures) + 1) : 40.0;
if ($tempMax <= $tempMin) {
    $tempMax = $tempMin + 2;
}
$temperaturePoints = graph_points($history, 'temperature', $tempMin, $tempMax);
$humidityPoints = graph_points($history, 'humidity', 0, 100);
$historyStart = $history ? date('H:i', (int)$history[0]['epoch']) : '–';
$historyEnd = $history ? date('H:i', (int)$history[array_key_last($history)]['epoch']) : '–';

// Separate bounded regulator history; never invent past actuator states from
// temperature history. Older installations without the optional helper degrade
// to an empty graph, while the existing sensor graph and current table remain.
$climateHistoryLib = is_file(__DIR__.'/../../scripts/lib/climate-history.php')
    ? __DIR__.'/../../scripts/lib/climate-history.php' : '/usr/local/libexec/climate-history.php';
$climateRows=[];$climateSeries=[];$climateMin=0;$climateMax=10;
if (is_readable($climateHistoryLib)) {
    require_once $climateHistoryLib;
    $climateRows=ClimateHistory::read($statusRoot.'/climate-history.tsv',time());
    // Only the plot/hover uses masked data; current values and raw logs stay exact.
    if (method_exists(ClimateHistoryChart::class,'displayRows')) {
        $climateRows=ClimateHistoryChart::displayRows($climateRows);
    }
    $degrees=[];
    foreach ($climateRows as $row) {
        foreach (['dew','gap'] as $key) { if ($row[$key]!==null) { $degrees[]=$row[$key]; } }
    }
    if ($degrees) { $climateMin=floor(min($degrees))-1;$climateMax=ceil(max($degrees))+1; }
    $climateSeries=[
        'dew'=>ClimateHistoryChart::series($climateRows,'dew',$climateMin,$climateMax),
        'gap'=>ClimateHistoryChart::series($climateRows,'gap',$climateMin,$climateMax),
        'pwm'=>ClimateHistoryChart::series($climateRows,'pwm',0,100,48,228,true),
        'heater'=>ClimateHistoryChart::series($climateRows,'heater',0,1,264,288,true),
    ];
}
$climateHistoryStart=$climateRows ? date('d.m. H:i:s',$climateRows[0]['epoch']) : '–';
$climateHistoryEnd=$climateRows ? date('d.m. H:i:s',$climateRows[array_key_last($climateRows)]['epoch']) : '–';
// Data attributes are HTML-escaped JSON from validated snapshots, not executable
// script. The existing bounded page read is reused; hovering never fetches data.
$sensorChartData = json_encode(['rows'=>$history, 'left'=>24, 'right'=>876, 'bottom'=>236,
    'series'=>[['temperature','Temperatur','°C'],['humidity','Luftfeuchte','%']]], JSON_THROW_ON_ERROR);
$climateChartData = json_encode(['rows'=>$climateRows, 'left'=>70, 'right'=>830, 'bottom'=>288,
    'series'=>[['dew','Taupunkt','°C'],['gap','Abstand','°C'],['pwm','PWM','%'],['heater','Heizung','']]], JSON_THROW_ON_ERROR);
// Content version invalidates cached CSS after a UI-only update. Fixed local
// file, no writes or changing timestamp URL on each 60-second page refresh.
$styleVersion=substr(hash_file('sha256',__DIR__.'/style.css') ?: 'missing',0,12);
// Same release metadata as the admin; no shell command, user path or SD write.
$versionFile='/usr/local/share/doc/camera-rpi/VERSION';
if (!is_file($versionFile)) { $versionFile=dirname(__DIR__,2).'/VERSION'; }
$softwareVersion=trim((string)@file_get_contents($versionFile));
if (!preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D',$softwareVersion)) { $softwareVersion='unbekannt'; }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <noscript><meta http-equiv="refresh" content="60"></noscript>
  <title><?= e($localTitle) ?> · Camera-RPi</title>
  <!-- Absolute alias works for both / and /camera-status/ without redirects. -->
  <link rel="stylesheet" href="/camera-status/style.css?v=<?= e($styleVersion) ?>">
</head>
<body>
<main>
  <header class="page-head">
    <div><p class="eyebrow">Camera-RPi <?= e($softwareVersion === 'unbekannt' ? $softwareVersion : 'V' . $softwareVersion) ?></p><h1><?= e($localTitle) ?></h1></div>
    <div class="refresh">Aktualisierung alle 60 Sekunden · ohne Neuladen</div>
    <?php if ($climateFault !== ''): ?>
      <div class="climate-fault" role="alert">
        <strong>Gespeicherte Klimastörung: <?= e($climateFault) ?></strong>
        <p><?= $climateFresh
          ? 'Der Klimaregler meldet eine Störung, die quittiert werden muss.'
          : 'Letzter bekannter Fehler; der Reglerstatus ist veraltet.' ?>
          <a href="/camera-admin/#climate">Störung im Admin prüfen und quittieren</a></p>
      </div>
    <?php endif; ?>
  </header>

  <section class="hero" aria-label="Letzte Kameraaufnahme">
    <?php if ($hasImage): ?>
      <img src="?asset=latest-image&amp;v=<?= e((string)($captureEpoch ?? time())) ?>" alt="Zuletzt aufgenommene Kamerafotografie">
    <?php else: ?>
      <div class="image-empty">Noch kein lokales Kamerabild vorhanden.</div>
    <?php endif; ?>
    <div class="overlay">
      <div>
        <span>Letzte Aufnahme</span>
        <strong><?= $captureEpoch !== null ? e(date('d.m.Y · H:i:s', $captureEpoch)) : 'noch unbekannt' ?></strong>
      </div>
      <div class="overlay-values">
        <strong><?= $temperature !== null ? e(number_format($temperature, 1, ',', '.')) . ' °C' : '– °C' ?></strong>
        <strong><?= $humidity !== null ? e(number_format($humidity, 1, ',', '.')) . ' % rF' : '– % rF' ?></strong>
      </div>
    </div>
  </section>

  <section class="chart-card">
    <div class="section-head">
      <div><p class="eyebrow">Verlauf im RAM</p><h2>Temperatur &amp; Luftfeuchte im Kamera-Innengehäuse</h2></div>
      <div class="legend"><span class="temperature">Temperatur</span><span class="humidity">Luftfeuchte</span></div>
    </div>
    <?php if (count($history) >= 2): ?>
      <div class="chart-interactive" data-chart="<?= e($sensorChartData) ?>">
      <svg class="chart" viewBox="0 0 900 280" role="img" aria-label="Verlauf von Temperatur und Luftfeuchte">
        <g class="grid">
          <line x1="24" y1="48" x2="876" y2="48"/><line x1="24" y1="95" x2="876" y2="95"/>
          <line x1="24" y1="142" x2="876" y2="142"/><line x1="24" y1="189" x2="876" y2="189"/>
          <line x1="24" y1="236" x2="876" y2="236"/>
        </g>
        <polyline class="humidity-line" points="<?= e($humidityPoints) ?>"/>
        <polyline class="temperature-line" points="<?= e($temperaturePoints) ?>"/>
        <text x="24" y="264"><?= e($historyStart) ?></text><text x="876" y="264" text-anchor="end"><?= e($historyEnd) ?></text>
        <text x="28" y="42"><?= e(number_format($tempMax, 0, ',', '.')) ?> °C</text>
        <text x="872" y="42" text-anchor="end">100 %</text>
      </svg>
      </div>
    <?php else: ?>
      <p class="empty">Die Verlaufsgrafik erscheint nach den ersten zwei gültigen DHT22-Messungen.</p>
    <?php endif; ?>
    <p class="updated">Letzte Sensormessung: <?= $sensorEpoch !== null ? e(date('d.m.Y, H:i:s', $sensorEpoch)) : 'noch keine' ?></p>
  </section>

  <section class="chart-card climate-history" aria-labelledby="climate-history-heading">
    <div class="section-head">
      <div><p class="eyebrow">Reglerverlauf im RAM</p><h2 id="climate-history-heading">Taupunkt &amp; Steuerung</h2></div>
      <div class="legend climate-legend"><span class="dew">Taupunkt</span><span class="gap">Abstand</span><span class="pwm">PWM</span><span class="heater">Heizung</span></div>
    </div>
    <p class="updated">Aufnahmepausen und deren PWM-Anlaufimpulse ausgeblendet; sonstige Spitzen und Rohdaten bleiben erhalten.</p>
    <?php if ($climateRows): ?>
      <div class="chart-interactive" data-chart="<?= e($climateChartData) ?>">
      <svg class="chart climate-chart" viewBox="0 0 900 330" role="img" aria-label="Verlauf von Taupunkt und Abstand in Grad Celsius, Lüfter-PWM in Prozent und Heizung An/Aus">
        <title>Taupunkt und Abstand links in °C, PWM rechts in Prozent. Heizung unten: An oder Aus. Simulation ist gestrichelt.</title>
        <g class="grid">
          <?php for ($tick=0;$tick<=4;$tick++): $y=48+$tick*45; ?>
            <line x1="70" y1="<?= $y ?>" x2="830" y2="<?= $y ?>"/>
            <text x="60" y="<?= $y+5 ?>" text-anchor="end"><?= e(number_format($climateMax-($climateMax-$climateMin)*$tick/4,1,',','.')) ?></text>
            <text x="840" y="<?= $y+5 ?>"><?= 100-$tick*25 ?></text>
          <?php endfor; ?>
          <line x1="70" y1="264" x2="830" y2="264"/><line x1="70" y1="288" x2="830" y2="288"/>
        </g>
        <text x="70" y="24">°C</text><text x="830" y="24" text-anchor="end">PWM %</text>
        <text x="60" y="269" text-anchor="end">An</text><text x="60" y="293" text-anchor="end">Aus</text>
        <?php foreach ($climateSeries as $series=>$segments): foreach ($segments as $segment): ?>
          <path class="climate-line <?= e($series) ?>-line mode-<?= e($segment['mode']) ?>" d="<?= e($segment['d']) ?>"/>
        <?php endforeach; endforeach; ?>
        <text x="70" y="320"><?= e($climateHistoryStart) ?></text><text x="830" y="320" text-anchor="end"><?= e($climateHistoryEnd) ?></text>
      </svg>
      </div>
    <?php else: ?>
      <p class="empty">Die Reglergrafik beginnt mit der ersten Aufzeichnung nach dem Update.</p>
    <?php endif; ?>
    <p class="updated">Bis zu 48 Stunden · Simulation gestrichelt · Lücken bleiben sichtbar.</p>
  </section>

  <section class="chart-card climate-overview" aria-labelledby="climate-heading">
    <div class="section-head">
      <div><p class="eyebrow">Aktuelle Regelwerte</p><h2 id="climate-heading">Taupunkt, Lüfter &amp; Heizung</h2></div>
      <span class="refresh">Betriebsart: <?= e($climateMode) ?></span>
    </div>
    <p><?= $climateFresh ? e((string)($climate['message'] ?? '')) : 'Kein frischer Reglerstatus — Werte unbekannt.' ?></p>
    <div class="table-scroll" role="region" aria-label="Aktuelle Klima-Regelwerte" tabindex="0">
      <table class="climate-table" aria-labelledby="climate-heading">
        <thead><tr><th scope="col">Taupunkt</th><th scope="col">Abstand</th><th scope="col">Lüfter-PWM</th><th scope="col">Heizung</th></tr></thead>
        <tbody><tr>
          <td><?= $climateDew !== null ? e(number_format($climateDew, 1, ',', '.')) . ' °C' : '–' ?></td>
          <td><?= $climateGap !== null ? e(number_format($climateGap, 1, ',', '.')) . ' °C' : '–' ?></td>
          <td><?= $climatePwm !== null ? e(number_format($climatePwm, 1, ',', '.')) . ' %' : '–' ?></td>
          <td><?= e($climateHeater) ?></td>
        </tr></tbody>
      </table>
    </div>
    <p class="updated">Reglerstand: <?= $climateEpoch !== null ? e(date('d.m.Y, H:i:s', $climateEpoch)) : 'noch keiner' ?></p>
    <?php if ($climateFresh && ($climate['measurement_source'] ?? '') === 'current'): ?>
      <p>Taupunkt und Abstand aus dem aktuellen gültigen Messpaar; unabhängig von der Freigabe der Regelung.</p>
    <?php elseif ($climateFresh && ($climate['measurement_source'] ?? '') === 'average'): ?>
      <p>Taupunkt und Abstand aus dem gemittelten Messpuffer des Reglers.</p>
    <?php endif; ?>
  </section>
</main>
<script><?= $chartScript ?></script>
</body>
</html>
