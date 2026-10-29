(() => {
  'use strict';

  /**
   * Tagesvideo-Controller der Kameraansicht.
   *
   * Leseführung:
   *
   * 1. `buildDayVideoRequest()` übersetzt den ausgewählten Kameratag und die
   *    Qualitäts- und Zeitraumfelder in einen Public-API-Aufruf.
   * 2. `loadDayVideoInto()` behandelt Cachetreffer, einen asynchron laufenden
   *    Server-Build und alle bewusst sichtbaren Fehlerzustände.
   * 3. `initInline()` bindet die drei Aktionen Öffnen, Schließen und Neu laden
   *    einmalig per Event-Delegation. Dadurch überlebt die Bindung partielle
   *    DOM-Wechsel der Kameraansicht.
   *
   * Das Modul kennt weder Timelapse-Frames noch deren Tastatursteuerung. Es
   * verwaltet ausschließlich den kurzlebigen Dayvideo-Player im Browser.
   */
  const create = ({
    getLiveRoot,
    formatDateInTimezone,
    buildAppUrl,
    toRelativeUrl,
    toAbsoluteUrl,
    getEventTargetElement,
  } = {}) => {
    const requiredFunctions = [
      getLiveRoot,
      formatDateInTimezone,
      buildAppUrl,
      toRelativeUrl,
      toAbsoluteUrl,
      getEventTargetElement,
    ];
    if (requiredFunctions.some((candidate) => typeof candidate !== 'function')) {
      throw new Error('Tagesvideo-Modul: notwendige Seitenadapter fehlen.');
    }

    let inlineEventsBound = false;
    let activePicker = null;
    const closePicker = (focus = false) => {
      if (!activePicker) return;
      const { picker, trigger, panel } = activePicker;
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      picker.append(panel);
      activePicker = null;
      if (focus && trigger.isConnected) trigger.focus({ preventScroll: true });
    };
    const openPicker = (picker) => {
      if (activePicker?.picker === picker) { closePicker(true); return; }
      closePicker();
      const trigger = picker.querySelector('[data-dayvideo-picker-trigger]');
      const panel = picker.querySelector('[data-dayvideo-picker-panel]');
      const input = picker.querySelector('input');
      activePicker = { picker, trigger, panel, input };
      // Portal avoids clipping by the camera image and keeps menus above video.
      document.body.append(panel); panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      const rect = trigger.getBoundingClientRect();
      const width = Math.min(Math.max(rect.width, 190), window.innerWidth - 16);
      const below = window.innerHeight - rect.bottom - 12;
      const above = rect.top - 12;
      const upwards = below < 200 && above > below;
      panel.style.width = `${width}px`;
      panel.style.maxHeight = `${Math.max(44, Math.min(300, upwards ? above : below))}px`;
      panel.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
      panel.style.top = upwards ? 'auto' : `${rect.bottom + 4}px`;
      panel.style.bottom = upwards ? `${window.innerHeight - rect.top + 4}px` : 'auto';
      const selected = panel.querySelector('[aria-selected="true"]');
      if (selected) {
        panel.scrollTop = selected.offsetTop - panel.clientHeight / 2 + selected.offsetHeight / 2;
        selected.focus({ preventScroll: true });
      }
    };
    const dismissSourceMenu = (target) => {
      const menu = target.closest('.tl-menu');
      if (!menu) return;
      if (menu.contains(document.activeElement)) document.activeElement.blur();
      menu.classList.add('is-dayvideo-dismissed');
    };
    let requestVersion = 0;
    let activeController = null;
    const cancelRequest = () => { ++requestVersion; activeController?.abort(); activeController = null; };

    const withCacheBust = (url, token) => {
      if (!url || !token) return url;
      try {
        const parsed = new URL(url, window.location.href);
        parsed.searchParams.set('v', token);
        return parsed.toString();
      } catch {
        const glue = url.includes('?') ? '&' : '?';
        return `${url}${glue}v=${encodeURIComponent(token)}`;
      }
    };

    // Der API-Request verwendet immer den aktuell im DOM ausgewählten Tag.
    // Fehlt ein expliziter Archivzeitpunkt, ist der aktuelle Tag der sichere
    // Fallback. FPS und Breite werden serverseitig zusätzlich begrenzt.
    const buildDayVideoRequest = () => {
      const liveRoot = getLiveRoot();
      if (!liveRoot) {
        throw new Error('Tagesvideo: Kameraansicht fehlt.');
      }
      const slug = liveRoot.dataset.cameraSlug || '';
      const timestampValue = liveRoot.getAttribute('data-history-ref-ts')
        || liveRoot.getAttribute('data-current-snapshot')
        || '';
      const timestamp = timestampValue && !Number.isNaN(Number(timestampValue))
        ? Number.parseInt(timestampValue, 10)
        : Math.floor(Date.now() / 1000);
      const timezone = liveRoot.dataset.timezone || 'Europe/Berlin';
      const dateIso = formatDateInTimezone(timestamp, timezone);
      const url = buildAppUrl(`api/public.php/cameras/${slug}/dayvideo`);
      const fpsSelect = liveRoot.querySelector('[data-dayvideo-fps]');
      const widthSelect = liveRoot.querySelector('[data-dayvideo-width]');
      const fps = fpsSelect ? Number.parseInt(fpsSelect.value, 10) : 5;
      const width = widthSelect ? Number.parseInt(widthSelect.value, 10) : 1200;
      url.searchParams.set('date', dateIso);
      url.searchParams.set('max_width', String(width));
      url.searchParams.set('fps', String(fps));
      const period = liveRoot.querySelector('[data-dayvideo-period]')?.value || 'day';
      const clock = liveRoot.querySelector('[data-dayvideo-clock]')?.value || '12:00';
      url.searchParams.set('period', period);
      if (['month', 'year'].includes(period)) url.searchParams.set('clock', clock);
      return { url, dateIso, fps, width, period, clock };
    };

    const updateSelection = () => {
      const root = getLiveRoot();
      if (!root) return;
      const { dateIso, period, clock } = buildDayVideoRequest();
      const long = ['month', 'year'].includes(period);
      const label = root.querySelector('[data-dayvideo-clock-label]');
      if (label) label.hidden = !long;
      const descriptions = {
        day: 'Ganzer Tag', sunrise: 'Sonnenaufgang: 1 Stunde davor bis 1½ Stunden danach',
        sunset: 'Abend: 1½ Stunden vor bis 1 Stunde nach Sonnenuntergang',
        night: 'Nacht: Sonnenuntergang bis zum nächsten Sonnenaufgang',
        month: `Monatsfilm: ${dateIso.slice(0, 7)} · täglich ${clock} Uhr ±30 Minuten`,
        year: `Jahresfilm: ${dateIso.slice(0, 4)} · täglich ${clock} Uhr ±30 Minuten`,
      };
      const text = root.querySelector('[data-dayvideo-selection]');
      if (text) text.textContent = `${long ? '' : `${dateIso} · `}${descriptions[period]} · ${root.dataset.timezone || 'Europe/Berlin'}`;
    };

    // 202 bedeutet: Der begrenzte Serverprozess arbeitet noch. Nur der erste
    // manuelle Reload setzt refresh=1; Folgeabfragen dürfen keinen neuen Build
    // starten. Nach spätestens 200 Antworten bleibt ein verständlicher Hinweis.
    const loadDayVideoInto = async (targets, { allowCache = true, attempt = 0, request = null } = {}) => {
      const { statusEl, videoEl, downloadEl, wrapEl } = targets;
      if (!request) {
        cancelRequest(); updateSelection();
        activeController = new AbortController();
        request = { version: requestVersion, controller: activeController, root: getLiveRoot(), url: buildDayVideoRequest().url };
        if (videoEl) { videoEl.pause(); videoEl.removeAttribute('src'); videoEl.load(); videoEl.hidden = true; }
        if (downloadEl) { downloadEl.hidden = true; downloadEl.removeAttribute('href'); }
        const refresh = getLiveRoot()?.querySelector('[data-dayvideo-refresh]');
        if (refresh) refresh.hidden = true;
      }
      const ensureCurrent = () => {
        if (request.version !== requestVersion || request.root !== getLiveRoot() || request.controller.signal.aborted) throw new DOMException('Auswahl geändert', 'AbortError');
      };
      ensureCurrent();
      if (statusEl) statusEl.textContent = 'Video wird geladen / erstellt…';
      const url = new URL(request.url);
      if (!allowCache && attempt === 0) url.searchParams.set('refresh', '1');
      url.searchParams.set('async', '1');
      url.searchParams.set('cb', Date.now().toString());
      const cacheBustToken = Date.now().toString();
      const response = await fetch(toRelativeUrl(url), { signal: request.controller.signal, headers: { 'X-WC-TEST': '1' } });
      ensureCurrent();

      if (response.status === 202) {
        let retryAfter = 3;
        try {
          const data = await response.json();
          if (data && data.retry_after) {
            retryAfter = Number.parseInt(data.retry_after, 10) || retryAfter;
          }
        } catch {
          // Eine unlesbare optionale Wartezeit ändert nicht den Grundzustand.
        }
        ensureCurrent();
        if (attempt >= 200) {
          if (statusEl) statusEl.textContent = 'Video wird noch erstellt. Bitte später erneut laden.';
          throw new Error('dayvideo-timeout');
        }
        await new Promise((resolve) => window.setTimeout(resolve, Math.max(1, Math.min(10, retryAfter)) * 1000));
        return loadDayVideoInto(targets, { allowCache, attempt: attempt + 1, request });
      }

      if (!response.ok) {
        let detail = '';
        let retryAfter = null;
        let frameCount = null;
        let minFrameCount = null;
        try {
          const data = await response.json();
          detail = typeof data?.detail === 'string' ? data.detail : '';
          retryAfter = data?.retry_after ? Number.parseInt(data.retry_after, 10) || null : null;
          frameCount = data?.frame_count ? Number.parseInt(data.frame_count, 10) || null : null;
          minFrameCount = data?.min_frame_count ? Number.parseInt(data.min_frame_count, 10) || null : null;
        } catch {
          // Auch ohne JSON-Detail bleibt der generische HTTP-Fehler sichtbar.
        }

        ensureCurrent();
        if (videoEl) {
          videoEl.pause();
          videoEl.removeAttribute('src');
          videoEl.load();
          videoEl.hidden = true;
        }
        if (wrapEl) wrapEl.hidden = true;
        if (downloadEl) {
          downloadEl.hidden = true;
          downloadEl.removeAttribute('href');
        }
        if (statusEl) {
          if (response.status === 429) {
            const seconds = retryAfter || Number.parseInt(response.headers.get('Retry-After'), 10);
            const wait = Number.isFinite(seconds) && seconds > 0
              ? ` Bitte in ${Math.ceil(seconds / 60)} Minute(n) erneut „Video laden“ wählen.`
              : ' Bitte später erneut „Video laden“ wählen.';
            statusEl.textContent = detail === 'Rate limit unavailable'
              ? 'Video-Erstellung derzeit nicht verfügbar. Bitte später erneut versuchen.'
              : `Das Kontingent für neue Videos ist vorübergehend ausgeschöpft.${wait} Vorhandene Videos bleiben abrufbar.`;
          } else if (detail === 'No entries for date' || detail === 'No local files for date') {
            statusEl.textContent = 'Für diesen Zeitraum und diese Uhrzeit sind keine verwendbaren Aufnahmen vorhanden.';
          } else if (detail === 'insufficient_frames') {
            statusEl.textContent = frameCount !== null && minFrameCount !== null
              ? `Noch zu wenige Bilder für dieses Video (${frameCount}/${minFrameCount}).`
              : 'Noch zu wenige Bilder für dieses Video.';
          } else if (detail === 'ffmpeg_failed_recently') {
            statusEl.textContent = retryAfter
              ? `Video-Erstellung fehlgeschlagen. Bitte in ${retryAfter}s erneut laden.`
              : 'Video-Erstellung fehlgeschlagen. Bitte erneut laden.';
          } else if (detail === 'dayvideo_not_prebuilt') {
            statusEl.textContent = 'Video ist noch nicht vorgebaut.';
          } else if (detail === 'dayvideo_build_disabled') {
            statusEl.textContent = 'Automatische Video-Erstellung ist deaktiviert. Video per Job vorbauen.';
          } else {
            statusEl.textContent = 'Video konnte nicht erzeugt werden.';
          }
        }
        throw new Error(`HTTP ${response.status}`);
      }

      const data = await response.json();
      ensureCurrent();
      if (videoEl && data.video_url) {
        videoEl.src = withCacheBust(toAbsoluteUrl(data.video_url), cacheBustToken);
        videoEl.load();
        if (wrapEl) wrapEl.hidden = false;
        videoEl.hidden = false;
      }
      if (downloadEl && data.download_url) {
        downloadEl.href = withCacheBust(toAbsoluteUrl(data.download_url), cacheBustToken);
        downloadEl.hidden = false;
      }
      const refresh = getLiveRoot()?.querySelector('[data-dayvideo-refresh]');
      if (refresh) refresh.hidden = !data.video_url;
      if (statusEl) {
        statusEl.textContent = data.cached ? 'Video aus Cache geladen.' : 'Video erstellt.';
      }
      return data;
    };

    // Alle zum Umschalten benötigten Elemente werden bei jeder Aktion neu aus
    // dem aktuellen DOM gelesen. Damit zeigen Klicks nach einer partiellen
    // Navigation nie auf veraltete Elemente.
    const getContext = () => {
      const root = getLiveRoot();
      if (!root) return null;
      const inline = root.querySelector('[data-dayvideo-inline]');
      const liveContainer = root.querySelector('.live-image');
      const liveDisplay = liveContainer?.dataset.liveDisplay || 'snapshot';
      const liveElements = Array.from(root.querySelectorAll('[data-live-image], [data-live-stream], [data-timelapse]'));
      let activeImage = liveDisplay === 'stream' ? root.querySelector('[data-live-stream]') : null;
      if (!activeImage) {
        activeImage = root.querySelector('[data-live-image]') || root.querySelector('[data-timelapse]');
      }
      if (!inline || !activeImage) return null;
      return {
        inline,
        liveElements,
        hotspotWrap: root.querySelector('[data-timeline-hotspots]'),
        overlayElements: root.querySelectorAll('.live-overlay, .bestof-hotspot, .live-logo, .live-labels'),
        liveContainer,
        status: inline.querySelector('[data-dayvideo-status]'),
        video: inline.querySelector('[data-dayvideo-video]'),
        download: inline.querySelector('[data-dayvideo-download]'),
        player: inline.querySelector('[data-dayvideo-player]'),
      };
    };

    const openInline = async (context, { allowCache = true } = {}) => {
      context.liveElements.forEach((element) => element.classList.add('is-hidden'));
      if (context.hotspotWrap) context.hotspotWrap.hidden = true;
      context.overlayElements.forEach((element) => element.classList.add('is-hidden'));
      context.liveContainer?.classList.add('is-dayvideo');
      context.inline.hidden = false;
      try {
        await loadDayVideoInto({
          statusEl: context.status,
          videoEl: context.video,
          downloadEl: context.download,
          wrapEl: context.player,
        }, { allowCache });
      } catch {
        // Der konkrete, für Nutzer hilfreiche Fehler steht bereits im Status.
      }
    };

    const closeInline = (context) => {
      closePicker(); cancelRequest();
      context.inline.hidden = true;
      context.liveElements.forEach((element) => element.classList.remove('is-hidden'));
      if (context.hotspotWrap) context.hotspotWrap.hidden = false;
      context.overlayElements.forEach((element) => element.classList.remove('is-hidden'));
      context.liveContainer?.classList.remove('is-dayvideo');
      if (context.video) {
        context.video.pause();
        context.video.removeAttribute('src');
        context.video.load();
      }
      if (context.download) {
        context.download.hidden = true;
        context.download.removeAttribute('href');
      }
      if (context.status) context.status.textContent = 'Bereit zum Laden…';
      if (context.player) context.player.hidden = true;
    };

    const initInline = () => {
      if (!getLiveRoot()) return;
      closePicker();
      if (!inlineEventsBound) {
        inlineEventsBound = true;
        document.addEventListener('change', (event) => {
          const target = getEventTargetElement(event);
          if (!target?.matches('[data-dayvideo-period], [data-dayvideo-clock], [data-dayvideo-fps], [data-dayvideo-width]')) return;
          const context = getContext();
          if (!context || !context.inline.contains(target)) return;
          updateSelection();
          cancelRequest();
          if (context.video) { context.video.pause(); context.video.removeAttribute('src'); context.video.load(); context.video.hidden = true; }
          if (context.player) context.player.hidden = true;
          if (context.download) { context.download.hidden = true; context.download.removeAttribute('href'); }
          context.inline.querySelector('[data-dayvideo-refresh]').hidden = true;
          if (context.status) context.status.textContent = 'Auswahl geändert. Mit „Video laden“ starten.';
        });
        window.addEventListener('pagehide', () => { closePicker(); cancelRequest(); });
        window.addEventListener('resize', () => closePicker());
        document.addEventListener('pointerdown', (event) => {
          if (activePicker && !activePicker.picker.contains(event.target) && !activePicker.panel.contains(event.target)) closePicker();
        });
        document.addEventListener('keydown', (event) => {
          if (!activePicker) return;
          if (event.key === 'Escape') { event.preventDefault(); closePicker(true); return; }
          if (event.key === 'Tab') { closePicker(true); return; }
          if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          const options = [...activePicker.panel.querySelectorAll('[data-dayvideo-picker-option]')];
          const index = options.indexOf(document.activeElement);
          const next = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : Math.max(0, Math.min(options.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
          options[next].focus({ preventScroll: true });
          options[next].scrollIntoView({ block: 'nearest' });
        });
        const restoreSourceMenu = event => {
          const toggle = getEventTargetElement(event)?.closest('.tl-menu__toggle');
          toggle?.closest('.tl-menu')?.classList.remove('is-dayvideo-dismissed');
        };
        document.addEventListener('pointerover', restoreSourceMenu);
        document.addEventListener('focusin', restoreSourceMenu);
        document.addEventListener('click', async (event) => {
          const target = getEventTargetElement(event);
          if (!target) return;
          const pickerTrigger = target.closest('[data-dayvideo-picker-trigger]');
          if (pickerTrigger) { event.preventDefault(); openPicker(pickerTrigger.closest('[data-dayvideo-picker]')); return; }
          const option = target.closest('[data-dayvideo-picker-option]');
          if (option && activePicker?.panel.contains(option)) {
            event.preventDefault();
            const { input, panel, trigger } = activePicker;
            input.value = option.dataset.dayvideoPickerOption;
            trigger.textContent = `${option.textContent} ▾`;
            panel.querySelectorAll('[data-dayvideo-picker-option]').forEach(item => {
              const selected = item === option;
              item.classList.toggle('is-selected', selected);
              item.setAttribute('aria-selected', String(selected));
            });
            closePicker(true);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return;
          }
          if (target.closest('.tl-menu__toggle')) restoreSourceMenu(event);
          if (target.closest('[data-dayvideo-open]')) {
            const context = getContext();
            if (!context) return;
            event.preventDefault();
            dismissSourceMenu(target);
            await openInline(context, { allowCache: true });
            return;
          }
          if (target.closest('[data-dayvideo-close]')) {
            const context = getContext();
            if (!context) return;
            event.preventDefault();
            closeInline(context);
            return;
          }
          if (target.closest('[data-dayvideo-reload], [data-dayvideo-refresh]')) {
            const context = getContext();
            if (!context) return;
            event.preventDefault();
            try {
              await loadDayVideoInto({
                statusEl: context.status,
                videoEl: context.video,
                downloadEl: context.download,
                wrapEl: context.player,
              }, { allowCache: !target.closest('[data-dayvideo-refresh]') });
            } catch {
              // Der konkrete Fehler wurde bereits in den Status geschrieben.
            }
          }
        });
      }

      if (window.location.hash === '#dayvideo') {
        const context = getContext();
        if (context) openInline(context, { allowCache: true });
      }
    };

    return Object.freeze({ initInline, loadDayVideoInto });
  };

  window.WebcamCameraDayvideo = Object.freeze({ create });
})();
