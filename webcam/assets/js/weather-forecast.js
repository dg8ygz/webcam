(() => {
  'use strict';
  const root = document.querySelector('#weather-forecast');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const base = new URL(root.dataset.prefix || './', location.href);
  const zone = root.dataset.timezone;
  const format = ts => new Date(ts * 1000).toLocaleString('de-DE', { timeZone: zone, dateStyle: 'short', timeStyle: 'short' });
  const apiUrl = (route, params = {}) => {
    const url = new URL(`api/public.php/${route}`, base);
    url.search = new URLSearchParams(params);
    return url;
  };
  const cameraRoute = (slug, kind) => `cameras/${encodeURIComponent(slug)}/${kind}`;
  const controllers = new Map();
  async function get(key, route, params = {}, timeout = 45000) {
    controllers.get(key)?.abort();
    const controller = new AbortController();
    controllers.set(key, controller);
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
      const response = await fetch(apiUrl(route, params), { signal: controller.signal, headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok && response.status !== 202) throw new Error(data.detail || `HTTP ${response.status}`);
      if (controller.signal.aborted) throw new Error('Abruf abgebrochen.');
      return data;
    } finally {
      clearTimeout(timer);
      if (controllers.get(key) === controller) controllers.delete(key);
    }
  }
  function error(id, err) { if (err.name !== 'AbortError') $(id).textContent = err.message || 'Abruf fehlgeschlagen.'; }
  let forecastVersion = 0;
  // Small SVG plots share one time cursor; gaps never become zero values.
  function renderForecast(hours) {
    const container = $('forecast-charts');
    container.replaceChildren();
    hours = hours.filter(hour => Number.isFinite(hour.timestamp)).sort((a, b) => a.timestamp - b.timestamp);
    if (!hours.length) { container.textContent = 'Keine Vorhersagewerte verfügbar.'; return; }
    const definitions = [
      { title: 'Temperatur', unit: '°C', series: [['temperature', 'Temperatur', '#de683a']] },
      { title: 'Niederschlag pro Stunde', unit: 'mm', bars: true, zero: true, minimum: 1, series: [['rain', 'Niederschlag', '#319acc']] },
      { title: 'Wind und Böen', unit: 'km/h', zero: true, minimum: 10, series: [['wind', 'Wind', '#36a58e'], ['gust', 'Böen', '#b585dc']] },
      { title: 'Bewölkung', unit: '%', zero: true, maximum: 100, series: [['cloud', 'Bewölkung', '#8298b6']] },
    ];
    const number = value => Number(value.toFixed(1)).toLocaleString('de-DE');
    const svgNode = (name, attrs = {}, text = null) => {
      const node = document.createElementNS('http://www.w3.org/2000/svg', name);
      Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
      if (text !== null) node.textContent = text;
      return node;
    };
    const left = 48, right = 464, top = 18, bottom = 184;
    const first = hours[0].timestamp - 1800, last = hours.at(-1).timestamp + 1800;
    const x = stamp => left + (stamp - first) / (last - first) * (right - left);
    let selectedIndex = 0;
    const cards = definitions.map(def => {
      const card = document.createElement('figure'); card.className = 'forecast-chart';
      card.dataset.metric = def.series[0][0];
      const title = document.createElement('figcaption'); title.textContent = `${def.title} (${def.unit})`;
      const selected = document.createElement('p'); selected.className = 'forecast-chart-value';
      const svg = svgNode('svg', { viewBox: '0 0 480 238', role: 'img', tabindex: 0, 'aria-label': `${def.title} in ${def.unit}, stündliche Vorhersage; Auswahl mit Pfeiltasten` });
      const values = hours.flatMap(hour => def.series.map(([key]) => hour[key])).filter(Number.isFinite);
      let low = def.zero ? 0 : Math.floor(Math.min(...values));
      let high = def.maximum ?? Math.ceil(Math.max(def.minimum ?? -Infinity, ...values));
      if (!values.length) { low = 0; high = def.maximum ?? def.minimum ?? 1; }
      if (high <= low) high = low + 1;
      const y = value => bottom - (value - low) / (high - low) * (bottom - top);
      for (let tick = 0; tick <= 4; tick++) {
        const value = low + (high - low) * tick / 4, at = y(value);
        svg.append(svgNode('line', { x1: left, x2: right, y1: at, y2: at, class: 'forecast-gridline' }));
        svg.append(svgNode('text', { x: left - 8, y: at + 5, 'text-anchor': 'end', class: 'forecast-axis' }, number(value)));
      }
      const ticks = [...new Set([0, Math.round((hours.length - 1) / 3), Math.round(2 * (hours.length - 1) / 3), hours.length - 1])];
      ticks.forEach(index => {
        const stamp = hours[index].timestamp, date = new Date(stamp * 1000);
        const anchor = index === 0 ? 'start' : index === hours.length - 1 ? 'end' : 'middle';
        svg.append(svgNode('text', { x: x(stamp), y: 207, 'text-anchor': anchor, class: 'forecast-axis' }, date.toLocaleTimeString('de-DE', { timeZone: zone, hour: '2-digit', minute: '2-digit' })));
        svg.append(svgNode('text', { x: x(stamp), y: 228, 'text-anchor': anchor, class: 'forecast-axis' }, date.toLocaleDateString('de-DE', { timeZone: zone, day: '2-digit', month: '2-digit' })));
      });
      const markers = [];
      def.series.forEach(([key, label, color], seriesIndex) => {
        let path = '', connected = false, previous = null;
        hours.forEach(hour => {
          const value = hour[key];
          if (!Number.isFinite(value)) { connected = false; return; }
          const px = x(hour.timestamp), py = y(value);
          if (def.bars) {
            const width = Math.min(28, 3600 / (last - first) * (right - left) * .75);
            const bar = svgNode('rect', { x: px - width / 2, y: py, width, height: Math.max(0, bottom - py), fill: color, class: 'forecast-bar' });
            svg.append(bar);
          } else {
            path += `${connected && hour.timestamp - previous <= 5400 ? 'L' : 'M'}${px},${py} `;
            connected = true; previous = hour.timestamp;
          }
          const point = svgNode('circle', { cx: px, cy: py, r: 2.3, fill: color });
          point.append(svgNode('title', {}, `${format(hour.timestamp)} · ${label}: ${number(value)} ${def.unit}`));
          svg.append(point);
        });
        if (path) svg.append(svgNode('path', { d: path.trim(), fill: 'none', stroke: color, 'stroke-width': 2.5, 'stroke-dasharray': seriesIndex ? '7 4' : 'none', class: 'forecast-series' }));
        const marker = svgNode('circle', { r: 5, fill: color, stroke: 'currentColor', 'stroke-width': 1, visibility: 'hidden' });
        markers.push({ key, marker });
      });
      if (!values.length) svg.append(svgNode('text', { x: (left + right) / 2, y: 100, 'text-anchor': 'middle', class: 'forecast-axis' }, 'Keine Daten'));
      const cursor = svgNode('line', { y1: top, y2: bottom, class: 'forecast-cursor' });
      svg.append(cursor, ...markers.map(item => item.marker));
      const legend = document.createElement('div'); legend.className = 'forecast-legend';
      def.series.forEach(([, label, color], index) => {
        const item = document.createElement('span'), swatch = document.createElement('i');
        swatch.style.borderColor = color; if (index) swatch.style.borderTopStyle = 'dashed';
        item.append(swatch, label); legend.append(item);
      });
      card.append(title, selected, svg, legend); container.append(card);
      const pointAt = event => {
        const rect = svg.getBoundingClientRect();
        const stamp = first + ((event.clientX - rect.left) / rect.width * 480 - left) / (right - left) * (last - first);
        let nearest = 0;
        hours.forEach((hour, index) => { if (Math.abs(hour.timestamp - stamp) < Math.abs(hours[nearest].timestamp - stamp)) nearest = index; });
        selectHour(nearest);
      };
      svg.addEventListener('pointermove', event => { if (event.pointerType === 'mouse') pointAt(event); });
      svg.addEventListener('pointerdown', pointAt);
      svg.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        selectHour(event.key === 'Home' ? 0 : event.key === 'End' ? hours.length - 1
          : Math.max(0, Math.min(hours.length - 1, selectedIndex + (event.key === 'ArrowRight' ? 1 : -1))));
      });
      return { def, selected, cursor, markers, y };
    });
    function selectHour(index) {
      const hour = hours[index];
      selectedIndex = index;
      $('forecast-time').textContent = format(hour.timestamp);
      cards.forEach(({ def, selected, cursor, markers, y }) => {
        selected.textContent = def.series.map(([key, label]) => `${label}: ${Number.isFinite(hour[key]) ? `${number(hour[key])} ${def.unit}` : 'keine Daten'}`).join(' · ');
        cursor.setAttribute('x1', x(hour.timestamp)); cursor.setAttribute('x2', x(hour.timestamp));
        markers.forEach(({ key, marker }) => {
          const valid = Number.isFinite(hour[key]); marker.setAttribute('visibility', valid ? 'visible' : 'hidden');
          if (valid) { marker.setAttribute('cx', x(hour.timestamp)); marker.setAttribute('cy', y(hour[key])); }
        });
      });
    }
    selectHour(0);
  }
  async function forecast(slug, version) {
    $('forecast-status').textContent = 'DWD-Vorhersage wird geladen …'; $('forecast-charts').replaceChildren(); $('forecast-time').textContent = '';
    try {
      const result = await get('forecast', cameraRoute(slug, 'forecast'));
      if (version !== forecastVersion) return;
      $('forecast-status').textContent = `${result.station.name} · ${result.station.distance_km} km vom Kamerastandort · Ausgabe ${format(result.data.issued_at)} · Abruf ${format(result.fetched_at)}${result.stale ? ' · Zwischengespeicherte Daten, Aktualisierung derzeit nicht möglich' : ''}`;
      renderForecast(result.data.hours.slice(0, 48));
    } catch (err) { if (version === forecastVersion) error('forecast-status', err); }
  }
  function loadWeather() {
    forecast($('weather-camera').value, ++forecastVersion);
  }
  $('weather-load').addEventListener('click', loadWeather);
  $('weather-camera').addEventListener('change', loadWeather);
  function suspend() {
    const pending = controllers.size > 0;
    ++forecastVersion;
    controllers.forEach(controller => controller.abort()); controllers.clear();
    if (pending) $('forecast-status').textContent = 'Abruf abgebrochen. Bitte Wetter erneut laden.';
  }
  root.addEventListener('toggle', () => { if (!root.open) suspend(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) suspend(); });
  window.addEventListener('pagehide', suspend);
  if (location.hash === '#weather-forecast') root.open = true;
})();
