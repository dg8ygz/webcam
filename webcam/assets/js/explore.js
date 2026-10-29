(() => {
  'use strict';
  const root = document.querySelector('#explore');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const base = new URL(root.dataset.prefix || './', location.href);
  if (location.hash === '#weather') {
    location.replace(new URL('weather#weather-forecast', base).href);
    return;
  }
  const zone = root.dataset.timezone;
  const format = ts => new Date(ts * 1000).toLocaleString('de-DE', { timeZone: zone, dateStyle: 'short', timeStyle: 'short' });
  const asset = path => new URL(path, base).href;
  const apiUrl = (route, params = {}) => {
    const url = new URL(`api/public.php/${route}`, base);
    url.search = new URLSearchParams(params);
    return url;
  };
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
  const clockTrigger = $('multi-clock-trigger'), clockPanel = $('multi-clock-options');
  const clockOptions = [...clockPanel.querySelectorAll('[data-multi-clock]')];
  function syncClock() {
    clockTrigger.textContent = `${$('multi-clock').value} ▾`;
    clockOptions.forEach(option => option.setAttribute('aria-selected', String(option.dataset.multiClock === $('multi-clock').value)));
    const [hour, minute] = $('multi-clock').value.split(':').map(Number);
    $('multi-range').value = String(hour * 60 + minute);
  }
  function closeClock(focus = false) {
    clockPanel.hidden = true; clockTrigger.setAttribute('aria-expanded', 'false');
    if (focus) clockTrigger.focus({ preventScroll: true });
  }
  clockTrigger.addEventListener('click', () => {
    if (!clockPanel.hidden) { closeClock(true); return; }
    syncClock(); clockPanel.hidden = false; clockTrigger.setAttribute('aria-expanded', 'true');
    const rect = clockTrigger.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 190), innerWidth - 16);
    const below = innerHeight - rect.bottom - 12, above = rect.top - 12;
    const upwards = below < 200 && above > below;
    clockPanel.style.width = `${width}px`;
    clockPanel.style.maxHeight = `${Math.max(44, Math.min(300, upwards ? above : below))}px`;
    clockPanel.style.left = `${Math.max(8, Math.min(rect.left, innerWidth - width - 8))}px`;
    clockPanel.style.top = upwards ? 'auto' : `${rect.bottom + 4}px`;
    clockPanel.style.bottom = upwards ? `${innerHeight - rect.top + 4}px` : 'auto';
    const selected = clockOptions[Math.min(143, Math.round(Number($('multi-range').value) / 10))];
    selected.focus({ preventScroll: true });
    clockPanel.scrollTop = selected.offsetTop - clockPanel.clientHeight / 2;
  });
  clockPanel.addEventListener('click', event => {
    const option = event.target.closest('[data-multi-clock]');
    if (!option) return;
    $('multi-clock').value = option.dataset.multiClock;
    syncClock(); closeClock(true);
  });
  clockPanel.addEventListener('keydown', event => {
    if (event.key === 'Escape' || event.key === 'Tab') {
      if (event.key === 'Escape') event.preventDefault();
      closeClock(true); return;
    }
    if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const index = clockOptions.indexOf(document.activeElement);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? 143
      : Math.max(0, Math.min(143, index + (event.key === 'ArrowDown' ? 1 : -1)));
    clockOptions[next].focus();
  });
  document.addEventListener('pointerdown', event => { if (!$('multi-clock-picker').contains(event.target)) closeClock(); });
  window.addEventListener('resize', () => closeClock());
  window.addEventListener('scroll', () => closeClock());
  syncClock();
  const selectors = [...root.querySelectorAll('[data-camera-select]')];
  selectors.forEach((select, index) => { select.selectedIndex = Math.min(index, select.options.length - 1); });
  // Prefer Stromberg in slot three, without duplicating an existing default.
  const thirdCamera = selectors[2];
  if (thirdCamera && [...thirdCamera.options].some(option => option.value === 'stromberg')) {
    const existing = selectors.find((select, index) => index !== 2 && select.value === 'stromberg');
    if (existing) existing.value = thirdCamera.value;
    thirdCamera.value = 'stromberg';
  }
  let multiVersion = 0, showRun = 0, showTimer, refreshTimer, showIndex = 0, slots = [], live = true, showPlaying = false;
  const randomShow = () => $('camera-mode').value === 'show' && $('show-source').value === 'random';
  const stopShow = () => {
    showPlaying = false; ++showRun;
    clearTimeout(showTimer); clearInterval(refreshTimer); showTimer = refreshTimer = null;
    controllers.get('multi')?.abort(); ++multiVersion;
    $('show-play').textContent = 'Diashow starten';
  };
  function renderSlots() {
    const show = $('camera-mode').value === 'show';
    $('multi-grid').classList.toggle('is-show', show);
    const selected = show ? slots.slice(showIndex % Math.max(slots.length, 1), showIndex % Math.max(slots.length, 1) + 1) : slots;
    $('multi-grid').replaceChildren(...selected.map(slot => {
      const figure = document.createElement('figure');
      const caption = document.createElement('figcaption');
      const link = document.createElement('a');
      link.href = asset(`camera/${encodeURIComponent(slot.slug)}`);
      link.textContent = slot.title;
      caption.append(link, document.createElement('br'));
      if (slot.frame) {
        const image = document.createElement('img');
        image.alt = `${slot.title} · ${format(slot.frame.timestamp)}`;
        image.src = asset(slot.frame.url);
        image.addEventListener('error', () => { image.hidden = true; caption.append(' · Bilddatei nicht verfügbar'); }, { once: true });
        figure.append(image);
        const minutes = Math.round(slot.offset_seconds / 60);
        caption.append(`${format(slot.frame.timestamp)} · ${randomShow() ? 'Zufällige Archivaufnahme' : live ? `Alter ${Math.max(0, -minutes)} min` : `Abweichung ${minutes > 0 ? '+' : ''}${minutes} min`}`);
      } else {
        const missing = document.createElement('div'); missing.className = 'missing';
        missing.textContent = 'Keine Aufnahme im Zeitfenster'; figure.append(missing);
      }
      figure.append(caption);
      return figure;
    }));
  }
  async function loadMulti(current = false) {
    if (randomShow()) { return loadRandom(); }
    const version = ++multiVersion;
    live = current || $('camera-mode').value === 'show';
    const count = $('camera-mode').value === '2' ? 2 : 4;
    const cameras = [...new Set(selectors.slice(0, count).map(el => el.value))].filter(Boolean);
    if (!cameras.length) { $('multi-status').textContent = 'Keine öffentlichen Kameras verfügbar.'; return; }
    $('multi-status').textContent = 'Bilder werden geladen …';
    const [h, m] = $('multi-clock').value.split(':').map(Number);
    $('multi-range').value = String(h * 60 + m);
    try {
      const data = await get('multi', 'presentation/multiview', { cameras: cameras.join(','), live: live ? '1' : '0', date: $('multi-date').value, clock: $('multi-clock').value });
      if (version !== multiVersion) return;
      slots = data.cameras; renderSlots();
      $('multi-status').textContent = `${live ? 'Aktuelle Aufnahmen' : `Vergleich für ${format(data.at)}`} · ${slots.filter(s => s.frame).length}/${slots.length} Bilder`;
    } catch (err) { if (version === multiVersion) error('multi-status', err); }
  }
  async function loadRandom() {
    const version = ++multiVersion;
    $('multi-status').textContent = 'Zufällige Archivaufnahme wird geladen …';
    try {
      const data = await get('multi', 'presentation/random');
      if (version !== multiVersion || !randomShow()) return;
      slots = [data.camera]; showIndex = 0; live = false; renderSlots();
      $('multi-status').textContent = 'Zufallsdiashow · alle öffentlichen Kameras · gesamtes Archiv';
    } catch (err) { if (version === multiVersion) error('multi-status', err); }
  }
  function cameraMode() {
    closeClock();
    stopShow(); showIndex = 0;
    const mode = $('camera-mode').value, show = mode === 'show';
    const random = randomShow();
    $('camera-selects').hidden = random;
    selectors.forEach(select => { select.disabled = random; });
    $('show-next').hidden = !random;
    $('multi-live').disabled = random;
    $('show-description').textContent = random
      ? 'Alle öffentlichen Kameras · zufällige Aufnahme aus dem gesamten Archiv'
      : 'Gewählte Kameras · aktuelle Einzelbilder · Aktualisierung alle 60 Sekunden';
    selectors.forEach((select, i) => { select.parentElement.hidden = mode === '2' && i > 1; });
    $('show-controls').hidden = !show; $('multi-time-controls').hidden = show;
    ['multi-date', 'multi-clock', 'multi-clock-trigger', 'multi-load'].forEach(id => { $(id).disabled = show; });
    loadMulti(show || live);
  }
  $('camera-mode').addEventListener('change', cameraMode);
  $('show-source').addEventListener('change', () => {
    slots = []; renderSlots(); cameraMode();
  });
  $('show-next').addEventListener('click', () => { stopShow(); loadRandom(); });
  selectors.forEach(el => el.addEventListener('change', () => loadMulti(live)));
  $('multi-load').addEventListener('click', () => loadMulti(false));
  $('multi-live').addEventListener('click', () => loadMulti(true));
  function timeFromRange() {
    const minute = Number($('multi-range').value);
    $('multi-clock').value = `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
    syncClock();
  }
  $('multi-range').addEventListener('input', timeFromRange);
  $('multi-range').addEventListener('change', () => loadMulti(false));
  for (const [id, delta] of [['multi-prev', -10], ['multi-next', 10]]) $(id).addEventListener('click', () => {
    $('multi-range').value = String(Math.max(0, Math.min(1439, Number($('multi-range').value) + delta)));
    timeFromRange(); loadMulti(false);
  });
  $('show-play').addEventListener('click', () => {
    if (showPlaying) { stopShow(); return; }
    showPlaying = true;
    const run = ++showRun;
    $('show-play').textContent = 'Diashow pausieren';
    const schedule = () => {
      showTimer = setTimeout(async () => {
        if (randomShow()) await loadRandom(); else { showIndex++; renderSlots(); }
        // A pause or mode change while fetching must not revive the timer.
        if (showPlaying && showRun === run) schedule();
      }, Number($('show-delay').value) * 1000);
    };
    schedule();
    if (!randomShow()) refreshTimer = setInterval(() => loadMulti(true), 60000);
  });
  $('show-delay').addEventListener('change', stopShow);
  $('show-fullscreen').addEventListener('click', async () => {
    try { if (document.fullscreenElement) await document.exitFullscreen(); else await $('multi-grid').requestFullscreen(); }
    catch { $('multi-status').textContent = 'Vollbild wird von diesem Browser nicht unterstützt.'; }
  });

  function suspend() {
    closeClock();
    stopShow();
    controllers.forEach(controller => controller.abort()); controllers.clear();
    ++multiVersion;
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) suspend(); });
  window.addEventListener('pagehide', suspend);
  const requestedCamera = new URLSearchParams(location.search).get('camera');
  if (selectors[0] && [...selectors[0].options].some(option => option.value === requestedCamera)) selectors[0].value = requestedCamera;
  loadMulti(true);
})();
