(() => {
  'use strict';
  // A single viewer lifecycle, with bounded retry only while explicitly active.
  // No polling after Stop or page departure; tab changes keep the same deadline.
  const root = document.querySelector('[data-usb-player]');
  if (!root) return;
  const video = root.querySelector('video');
  const state = root.querySelector('[data-usb-state]');
  const start = root.querySelector('[data-usb-start]');
  const stop = root.querySelector('[data-usb-stop]');
  let active = false, player = null, timer = null, hardStop = null, preparationTimer = null, abort = null;
  let generation = 0, deadline = 0, started = false;
  const duration = Number(video.dataset.duration) === 300 ? 300000 : 1800000;
  const finished = duration === 300000 ? '5 Minuten beendet. Bei Bedarf erneut starten.' : '30-Minuten-Freigabe beendet.';
  const release = () => {
    if (player) player.destroy();
    player = null;
    video.pause(); video.removeAttribute('src'); video.load();
  };
  const end = (message = 'Aus. Die Kamerafreigabe läuft automatisch aus.') => {
    active = false; generation++; clearTimeout(timer); clearTimeout(hardStop); abort?.abort(); release();
    clearTimeout(preparationTimer); preparationTimer = null;
    start.disabled = false; stop.disabled = true; state.textContent = message;
  };
  const retry = (message = 'Warte auf Kamera – bei Fotoaufnahme wird kurz pausiert …', delay = 3000) => {
    release(); clearTimeout(timer);
    if (active) { state.textContent = message; timer = setTimeout(connect, delay); }
  };
  const connect = async () => {
    if (!active) return;
    if (Date.now() >= deadline) { end(started ? finished : 'Keine Aufnahme/kein Stream verfügbar. Bitte später erneut starten.'); return; }
    const ownGeneration = generation;
    const current = () => active && generation === ownGeneration;
    abort = new AbortController();
    const requestAbort = abort;
    const requestTimeout = setTimeout(() => requestAbort.abort(), 8000);
    try {
      // Probe is side-effect free: the photo guard uses SERVER time and the
      // accepted slot, not the viewer's clock or an arbitrary fixed wait.
      // Reconnects may continue an admitted viewer across the start guard.
      // The server also requires a fresh matching registry entry; this flag
      // alone grants nothing. A new Start always uses the normal waiting rule.
      const probe = await fetch(video.dataset.source + '&probe=1' + (started ? '&resume=1' : ''), {cache: 'no-store', signal: requestAbort.signal});
      if (!current()) return;
      if (probe.status === 403) { end('Stream gesperrt oder administrativ getrennt.'); return; }
      if (!probe.ok) {
        let message = 'Stream derzeit nicht verfügbar oder alle Plätze belegt. Neuer Versuch …';
        if (probe.headers.get('content-type')?.includes('application/json')) {
          const detail = await probe.json();
          if (!current()) return;
          if (typeof detail.message === 'string') message = detail.message;
        }
        retry(message, 5000); return;
      }
      const availability = await probe.json();
      if (!current()) return;
      if (!availability.ok || !availability.ready) { retry(availability.message || 'Warte auf die Bildaufnahme …', 5000); return; }
      // Explicit preparation admits the viewer and renews demand. A passive
      // probe never starts the camera. HLS starts only after a valid playlist.
      if (preparationTimer === null) {
        preparationTimer = setTimeout(() => end('Stream konnte innerhalb von 60 Sekunden nicht gestartet werden. Bitte erneut versuchen.'), 60000);
      }
      const response = await fetch(video.dataset.source + '&prepare=1', {method: 'POST', cache: 'no-store', signal: requestAbort.signal});
      if (!current()) return;
      if (response.status === 403) { end('Stream gesperrt oder administrativ getrennt.'); return; }
      if (!response.ok) { retry('Stream derzeit nicht verfügbar. Neuer Versuch …'); return; }
      const preparation = await response.json();
      if (!current()) return;
      if (!preparation.ok || !preparation.ready) { retry(preparation.message || 'Stream wird gestartet …'); return; }
      clearTimeout(preparationTimer); preparationTimer = null;
      if (window.Hls?.isSupported()) {
        player = new Hls({enableWorker: true, maxBufferLength: 8, backBufferLength: 0});
        player.on(Hls.Events.ERROR, (_event, data) => {
          if (!current()) return;
          if (data.response?.code === 403) { end('Stream administrativ getrennt.'); }
          else if (data.fatal) retry();
        });
        player.loadSource(video.dataset.source); player.attachMedia(video);
      } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = video.dataset.source;
      } else { end('Dieser Browser unterstützt HLS nicht.'); return; }
      video.play().catch(() => { if (current()) state.textContent = 'Bitte Wiedergabe im Video starten.'; });
      state.textContent = 'Stream verbunden.';
    } catch (error) { if (current()) retry(); }
    finally { clearTimeout(requestTimeout); }
  };
  // The five minutes start with actual playback, not while waiting for the
  // photo or encoder. Reconnects never reset that deadline; waiting is bounded.
  video.addEventListener('playing', () => {
    if (!active || started) return;
    started = true; deadline = Date.now() + duration; clearTimeout(hardStop);
    hardStop = setTimeout(() => end(finished), duration);
    state.textContent = duration === 300000 ? 'Livestream läuft für 5 Minuten.' : 'Stream verbunden.';
  });
  const begin = () => {
    if (active || document.hidden) return;
    active = true; generation++; started = false; deadline = Date.now() + 900000;
    start.disabled = true; stop.disabled = false;
    hardStop = setTimeout(() => end('Wartezeit abgelaufen. Bitte später erneut starten.'), 900000);
    state.textContent = 'Kamera wird angefragt …'; connect();
  };
  start.addEventListener('click', begin);
  stop.addEventListener('click', () => end());
  // A full navigation only fires pagehide once the next document is ready.
  // Release HLS and pending probes at the user's click, before that request
  // competes with the old stream. Opening another tab keeps this player alive.
  const cameraRoot = root.closest('#camera-live-root') || root;
  cameraRoot.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0
      || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target instanceof Element
      ? event.target.closest('a[data-usb-snapshot-link]') : null;
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    end('Livebild wird geladen …');
  });
  video.addEventListener('error', () => { if (active && !player) retry(); });
  window.addEventListener('pagehide', () => end());
  // Camera timeline navigation may replace the main area without pagehide.
  // Its existing navigation adapter explicitly releases this controller first.
  window.WebcamUsbLive = Object.freeze({stop: () => end()});
  // Switching tabs is not Stop. Browsers may suspend background timers, so
  // recheck the original wall-clock deadline on return, without extending it.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && active && Date.now() >= deadline) {
      end(started ? finished : 'Wartezeit abgelaufen. Bitte später erneut starten.');
    }
  });
  // Following Options > Livestream is the explicit user start action.
  if (video.dataset.autostart === '1') begin();
})();
