(() => {
  'use strict';

  /**
   * Timelapse-Controller und schmaler Playback-Koordinator.
   *
   * Leseführung:
   *
   * 1. Timelapse: Frames eines Zeitfensters laden, normalisieren und vorladen.
   * 2. Wiedergabe: Bildwechsel, Scrubber, Geschwindigkeit, Tastatur und
   *    Vollbildzustand gemeinsam verwalten.
   * 3. Tagesvideo: an den getrennten `camera-dayvideo.js`-Controller
   *    delegieren, ohne dessen API-/Inline-Zustand hier zu duplizieren.
   *
   * Das Modul speichert nichts dauerhaft. Timelapse-Frames und gebaute
   * Timelapse-Frames leben nur im aktuellen Browserdokument. Serverseitige
   * Limits und Zeitbereiche bleiben in der Public-API verbindlich.
   */
  // Calendar boundaries in the camera timezone, independent of browser timezone.
  // Find the first second of the civil date; DST days need not have 86400s.
  const calendarDayRange = (year, month, day, timezone) => {
    const formatter = new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
    });
    const keyAt = (seconds) => {
      const parts = Object.fromEntries(formatter.formatToParts(new Date(seconds * 1000)).map(p => [p.type, p.value]));
      return Number(parts.year) * 10000 + Number(parts.month) * 100 + Number(parts.day);
    };
    const boundary = (offset) => {
      const civil = new Date(Date.UTC(year, month - 1, day + offset));
      const key = civil.getUTCFullYear() * 10000 + (civil.getUTCMonth() + 1) * 100 + civil.getUTCDate();
      const estimate = civil.getTime() / 1000;
      let low = estimate - 36 * 3600;
      let high = estimate + 36 * 3600;
      while (low < high) {
        const middle = Math.floor((low + high) / 2);
        if (keyAt(middle) < key) low = middle + 1;
        else high = middle;
      }
      return low;
    };
    return [boundary(0), boundary(1) - 1];
  };

  const create = ({
    getLiveRoot,
    parseIntSafe,
    formatDateInTimezone,
    buildAppUrl,
    toRelativeUrl,
    toAbsoluteUrl,
    resolveAppUrl,
    normalizeAssetUrl,
    isLiveboxOpen,
    getEventTargetElement,
  } = {}) => {
    const requiredFunctions = [
      getLiveRoot,
      parseIntSafe,
      formatDateInTimezone,
      buildAppUrl,
      toRelativeUrl,
      toAbsoluteUrl,
      resolveAppUrl,
      normalizeAssetUrl,
      isLiveboxOpen,
      getEventTargetElement,
    ];
    if (requiredFunctions.some((candidate) => typeof candidate !== 'function')) {
      throw new Error('Playback-Modul: notwendige Seitenadapter fehlen.');
    }

    // Timelapse bindet seine Bedienelemente jeweils an den neu gerenderten
    // Playback-Block und räumt die vorige Instanz explizit auf.
    let timelapseCleanup = null;

    const dayvideoFactory = window.WebcamCameraDayvideo;
    if (!dayvideoFactory || typeof dayvideoFactory.create !== 'function') {
      throw new Error('Tagesvideo-Modul konnte nicht geladen werden.');
    }
    const dayvideoController = dayvideoFactory.create({
      getLiveRoot,
      formatDateInTimezone,
      buildAppUrl,
      toRelativeUrl,
      toAbsoluteUrl,
      getEventTargetElement,
    });

    // -------------------------------------------------------------------------
    // Timelapse/Playback.
    // Ein DOM-Austausch beendet zuerst Timer und dokumentweite Listener der
    // vorherigen Instanz. Ein entferntes Element sendet kein verlässliches
    // eigenes „remove“-Ereignis; deshalb besitzt der Controller diesen
    // expliziten Aufräumpfad.
    // -------------------------------------------------------------------------
    const initTimelapse = () => {
      if (timelapseCleanup) {
        const cleanup = timelapseCleanup;
        timelapseCleanup = null;
        cleanup();
      }
      const liveRoot = getLiveRoot();
      if (!liveRoot) return;
      const tlRoot = liveRoot.querySelector('[data-timelapse]');
      if (!tlRoot) return;
      const playBtn = tlRoot.querySelector('[data-tl-play]');
      const prevBtn = tlRoot.querySelector('[data-tl-prev]');
      const nextBtn = tlRoot.querySelector('[data-tl-next]');
      const dayPrevBtn = tlRoot.querySelector('[data-tl-day-prev]');
      const dayNextBtn = tlRoot.querySelector('[data-tl-day-next]');
      const speedSelect = tlRoot.querySelector('[data-tl-speed]');
      const frameCountBtn = tlRoot.querySelector('[data-tl-framecount]');
      const fullscreenBtn = tlRoot.querySelector('[data-tl-fullscreen]');
      const statusEl = tlRoot.querySelector('[data-tl-status]');
      const img = tlRoot.querySelector('[data-tl-img]');
      const meta = tlRoot.querySelector('[data-tl-meta]');
      const scrubber = tlRoot.querySelector('[data-tl-scrubber]');
      const scrubLabel = tlRoot.querySelector('[data-tl-scrubber-label]');
      const progressBar = tlRoot.querySelector('[data-tl-progress]');
      const progressLabel = tlRoot.querySelector('[data-tl-progress-label]');
      const scrubTip = tlRoot.querySelector('[data-tl-scrubber-tip]');
      if (!playBtn || !prevBtn || !nextBtn || !img || !meta || !speedSelect) {
        return;
      }
  
      tlRoot.hidden = false;
      let frames = [];
      let idx = 0;
      let playing = false;
      let timer = null;
      let currentFrom = null;
      let currentTo = null;
      let loading = false;
      let lastReq = 0;
      const frameCountOptions = [120, 60];
      let frameCountIdx = 0;
      const preloaded = new Set();
      let preloadLoaded = 0;
      let preloadTotal = 0;
      const setStatus = (text, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = text || '';
        statusEl.classList.toggle('is-error', !!isError);
      };
  
      const setProgress = (loaded, total) => {
        if (!progressBar || !progressLabel) return;
        const pct = total > 0 ? Math.round((loaded / total) * 100) : 0;
        progressBar.style.setProperty('--tl-progress', `${pct}%`);
        progressLabel.textContent = total > 0 ? `${loaded}/${total} (${pct}%)` : '0%';
      };
  
      const updatePlayLabel = () => {
        playBtn.textContent = playing ? '⏸' : '▶︎';
      };
  
      const updateControls = () => {
        const hasFrames = frames.length > 0;
        const multiFrames = frames.length > 1;
        const disableNav = loading || !hasFrames || !multiFrames;
        const disablePlay = loading || !hasFrames;
        playBtn.disabled = disablePlay;
        prevBtn.disabled = disableNav;
        nextBtn.disabled = disableNav;
        speedSelect.disabled = loading || !hasFrames;
        if (dayPrevBtn) dayPrevBtn.disabled = loading;
        if (dayNextBtn) dayNextBtn.disabled = loading;
        if (scrubber) scrubber.disabled = loading || !hasFrames;
        if (frameCountBtn) frameCountBtn.disabled = loading;
        if (fullscreenBtn) fullscreenBtn.disabled = false;
      };
  
      const updateScrubber = () => {
        if (!scrubber) return;
        if (!frames.length) {
          scrubber.value = '0';
          scrubber.max = '0';
          if (scrubLabel) scrubLabel.textContent = '—';
          return;
        }
        scrubber.max = String(Math.max(0, frames.length - 1));
        scrubber.value = String(idx);
        if (scrubLabel) {
          const f = frames[idx];
          const label = f?.time_label || f?.datetime_label || `${idx + 1}/${frames.length}`;
          const text = `${idx + 1}/${frames.length}${label ? ' · ' + label : ''}`;
          scrubLabel.textContent = text;
          scrubLabel.title = label || text;
          scrubber?.setAttribute('title', label || text);
          if (scrubTip) {
            scrubTip.hidden = true;
          }
        }
      };
  
      const setFrame = (i) => {
        if (!frames.length) return;
        idx = Math.max(0, Math.min(i, frames.length - 1));
        const f = frames[idx];
        img.src = f.url;
        meta.textContent = f.datetime_label || '';
        updateControls();
        updateScrubber();
        preloadAround(idx);
      };
  
      const preloadAround = (index) => {
        if (!frames.length) return;
        const candidates = [index + 1, index + 2, index - 1].filter((n) => n >= 0 && n < frames.length);
        candidates.forEach((n) => {
          const u = frames[n]?.url;
          if (u && !preloaded.has(u)) {
            preloadImage(u);
          }
        });
      };
  
      const preloadImage = (url) => {
        if (preloaded.has(url)) return;
        preloaded.add(url);
        const im = new Image();
        const clear = () => {
          preloadLoaded += 1;
          setProgress(preloadLoaded, preloadTotal);
        };
        im.onload = clear;
        im.onerror = clear;
        im.src = url;
      };
  
      const stop = () => {
        playing = false;
        if (timer) {
          clearTimeout(timer);
          timer = null;
        }
        updatePlayLabel();
      };
  
      const step = () => {
        if (!playing || !frames.length) return;
        const speed = parseFloat(speedSelect.value || '1');
        const delay = Math.max(80, 1000 / speed);
        idx = (idx + 1) % frames.length;
        setFrame(idx);
        timer = setTimeout(step, delay);
      };
  
      const playPause = () => {
        if (!frames.length || loading) return;
        playing = !playing;
        if (playing) {
          updatePlayLabel();
          step();
        } else {
          stop();
        }
      };
  
      const clampRange = (fromTs, toTs) => {
        const nowTs = Math.floor(Date.now() / 1000);
        let clampedTo = Math.min(toTs, nowTs);
        let clampedFrom = Math.min(fromTs, clampedTo - 60);
        if (clampedFrom < 0) clampedFrom = 0;
        if (clampedFrom >= clampedTo) {
          clampedFrom = Math.max(0, clampedTo - 3600);
        }
        return [clampedFrom, clampedTo];
      };
  
      const currentFrameCount = () => frameCountOptions[frameCountIdx] || frameCountOptions[0];
      const fetchRange = async (fromTs, toTs) => {
        const slug = liveRoot.dataset.cameraSlug || '';
        const endpoint = resolveAppUrl(liveRoot.dataset.timelineEndpoint || (slug ? `api/public.php/cameras/${slug}/timeline` : ''));
        if (!endpoint) {
          throw new Error('Timelapse endpoint missing');
        }
        const tlEndpoint = endpoint.replace('timeline', 'timelapse');
        const [clampedFrom, clampedTo] = clampRange(fromTs, toTs);
        const reqId = ++lastReq;
        loading = true;
        tlRoot.classList.add('is-loading');
        tlRoot.classList.remove('is-error', 'is-empty');
        updateControls();
        setStatus('Timelapse wird geladen …');
        try {
          const url = new URL(tlEndpoint, window.location.origin);
          url.searchParams.set('from', String(clampedFrom));
          url.searchParams.set('to', String(clampedTo));
          const frameCount = currentFrameCount();
          url.searchParams.set('frame_count', String(frameCount));
          url.searchParams.set('max_width', '1200');
          const res = await fetch(toRelativeUrl(url), { credentials: 'same-origin' });
          if (!res.ok) {
            const err = new Error('Timelapse fetch failed');
            err.status = res.status;
            throw err;
          }
          const data = await res.json();
          if (reqId !== lastReq) return; // outdated
          frames = Array.isArray(data.frames)
            ? data.frames
              .map((f) => (f ? { ...f, url: normalizeAssetUrl(f.url || '') } : null))
              .filter((f) => f && f.url)
            : [];
          idx = 0;
          currentFrom = data.from ?? clampedFrom;
          currentTo = data.to ?? clampedTo;
          preloadLoaded = 0;
          preloadTotal = frames.length;
          setProgress(0, preloadTotal);
          preloaded.clear();
          if (frames.length) {
            setFrame(0);
            setStatus('');
            tlRoot.classList.remove('is-empty', 'is-error');
            preloadAround(0);
            frames.forEach((f) => {
              if (f?.url) preloadImage(f.url);
            });
          } else {
            stop();
            meta.textContent = '';
            setStatus('Keine Aufnahmen in diesem Zeitfenster.', true);
            tlRoot.classList.add('is-empty');
          }
        } catch (e) {
          if (reqId !== lastReq) return;
          stop();
          if (e && e.status === 429) {
            setStatus('Rate-Limit erreicht – bitte kurz warten und Retry nutzen.', true);
          } else if (e && e.name === 'AbortError') {
            setStatus('Abgebrochen – bitte erneut versuchen.', true);
          } else {
            setStatus('Timelapse-Ladevorgang fehlgeschlagen.', true);
          }
          tlRoot.classList.add('is-error');
        } finally {
          if (reqId === lastReq) {
            loading = false;
            tlRoot.classList.remove('is-loading');
            updateControls();
            if (frameCountBtn) frameCountBtn.textContent = `${currentFrameCount()} Frames`;
            setProgress(preloadLoaded, preloadTotal);
          }
        }
      };
  
      const resolveSelectedTs = () => {
        const explicit = parseIntSafe(liveRoot.dataset.selectedTs);
        if (explicit) return explicit;
        const currentSnap = parseIntSafe(
          (liveRoot.querySelector('[data-current-snapshot]') || {}).dataset
            ?.currentSnapshot
        );
        if (currentSnap) return currentSnap;
        const ref = parseIntSafe(liveRoot.dataset.historyRefTs);
        if (ref) return ref;
        return Math.floor(Date.now() / 1000);
      };
  
      let dayOffset = 0;
      const resolveSelectedDayRange = () => {
        const timezone = liveRoot.dataset.timezone || 'UTC';
        const y = parseIntSafe(liveRoot.dataset.selectedYear);
        const m = parseIntSafe(liveRoot.dataset.selectedMonth);
        const d = parseIntSafe(liveRoot.dataset.selectedDay);
        if (!y || !m || !d) return null;
        return calendarDayRange(y, m, d + dayOffset, timezone);
      };

      const loadDefault = () => {
        const dayRange = resolveSelectedDayRange();
        if (dayRange) {
          fetchRange(dayRange[0], dayRange[1]).catch(() => {});
          return;
        }
        const center = resolveSelectedTs();
        const half = 12 * 3600;
        const fromTs = center - half;
        const toTs = center + half;
        fetchRange(fromTs, toTs).catch(() => {});
      };
  
      playBtn.addEventListener('click', () => playPause());
      prevBtn.addEventListener('click', () => {
        stop();
        if (frames.length) setFrame(idx - 1);
      });
      nextBtn.addEventListener('click', () => {
        stop();
        if (frames.length) setFrame(idx + 1);
      });
      speedSelect.addEventListener('change', () => {
        if (playing) {
          stop();
          playing = true;
          step();
        }
      });
      if (dayPrevBtn) {
        dayPrevBtn.addEventListener('click', () => {
          stop();
          if (currentFrom !== null && currentTo !== null) {
            dayOffset -= 1;
            const range = resolveSelectedDayRange();
            if (range) fetchRange(...range).catch(() => {});
            else fetchRange(currentFrom - 86400, currentTo - 86400).catch(() => {});
          }
        });
      }
      if (dayNextBtn) {
        dayNextBtn.addEventListener('click', () => {
          stop();
          if (currentFrom !== null && currentTo !== null) {
            dayOffset += 1;
            const range = resolveSelectedDayRange();
            if (range) fetchRange(...range).catch(() => {});
            else fetchRange(currentFrom + 86400, currentTo + 86400).catch(() => {});
          }
        });
      }
  
      loadDefault();
      updateControls();
      updatePlayLabel();
  
      const adjustSpeed = (delta) => {
        const options = Array.from(speedSelect.options).map((o) => parseFloat(o.value));
        const current = parseFloat(speedSelect.value || '1');
        const uniq = options.filter((v, i, arr) => arr.indexOf(v) === i).sort((a, b) => a - b);
        const pos = uniq.findIndex((v) => v === current);
        const nextPos = Math.max(0, Math.min(uniq.length - 1, pos + delta));
        speedSelect.value = String(uniq[nextPos] ?? current);
        speedSelect.dispatchEvent(new Event('change'));
      };
  
      const handleKeydown = (ev) => {
        if (tlRoot.hidden || isLiveboxOpen()) return;
        const target = ev.target;
        if (target instanceof HTMLElement && ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName)) return;
        if (ev.key === ' ' || ev.code === 'Space') {
          ev.preventDefault();
          playPause();
        } else if (ev.key === 'ArrowLeft') {
          ev.preventDefault();
          stop();
          if (frames.length) setFrame(idx - 1);
        } else if (ev.key === 'ArrowRight') {
          ev.preventDefault();
          stop();
          if (frames.length) setFrame(idx + 1);
        } else if (
          ev.key === '+' ||
          ev.key === '=' ||
          ev.key === 'Add' ||
          ev.key === 'Plus' ||
          ev.code === 'Equal' ||
          ev.code === 'BracketRight' || // Edge/DE layout meldet '+' als BracketRight
          ev.code === 'NumpadAdd' ||
          ev.keyCode === 107 ||
          ev.keyCode === 187
        ) {
          ev.preventDefault();
          adjustSpeed(1);
        } else if (
          ev.key === '-' ||
          ev.key === 'Subtract' ||
          ev.key === 'Minus' ||
          ev.code === 'Minus' ||
          ev.code === 'Slash' || // Edge/DE layout meldet '-' als Slash
          ev.code === 'NumpadSubtract' ||
          ev.keyCode === 109 ||
          ev.keyCode === 189
        ) {
          ev.preventDefault();
          adjustSpeed(-1);
        }
      };
  
      window.addEventListener('keydown', handleKeydown, true);
      const handleSpeedHotkeys = (ev) => {
        if (tlRoot.hidden || isLiveboxOpen()) return;
        const isPlus =
          ev.key === '+' ||
          ev.key === '=' ||
          ev.key === 'Add' ||
          ev.key === 'Plus' ||
          ev.code === 'Equal' ||
          ev.code === 'BracketRight' ||
          ev.code === 'NumpadAdd' ||
          ev.keyCode === 107 ||
          ev.keyCode === 187;
        const isMinus =
          ev.key === '-' ||
          ev.key === 'Subtract' ||
          ev.key === 'Minus' ||
          ev.code === 'Minus' ||
          ev.code === 'Slash' ||
          ev.code === 'NumpadSubtract' ||
          ev.keyCode === 109 ||
          ev.keyCode === 189;
        if (!isPlus && !isMinus) return;
        ev.preventDefault();
        adjustSpeed(isPlus ? 1 : -1);
      };
      window.addEventListener('keydown', handleSpeedHotkeys, true);
  
      if (scrubber) {
        scrubber.addEventListener('input', (ev) => {
          if (!frames.length) return;
          stop();
          const v = parseIntSafe(ev.target.value);
          if (!Number.isNaN(v)) {
            setFrame(v);
          }
        });
        scrubber.addEventListener('change', (ev) => {
          if (!frames.length) return;
          const v = parseIntSafe(ev.target.value);
          if (!Number.isNaN(v)) {
            setFrame(v);
          }
        });
      }
  
      if (frameCountBtn) {
        const updateFrameCountLabel = () => {
          const val = currentFrameCount();
          frameCountBtn.textContent = `${val} Frames`;
          frameCountBtn.disabled = loading;
        };
        frameCountBtn.addEventListener('click', () => {
          if (loading) return;
          frameCountIdx = (frameCountIdx + 1) % frameCountOptions.length;
          updateFrameCountLabel();
          if (currentFrom !== null && currentTo !== null) {
            stop();
            fetchRange(currentFrom, currentTo).catch(() => {});
          } else {
            loadDefault();
          }
        });
        updateFrameCountLabel();
      }
  
      const updateFullscreenUi = () => {
        const active = document.fullscreenElement === tlRoot;
        tlRoot.classList.toggle('is-fullscreen', active);
        if (fullscreenBtn) {
          fullscreenBtn.textContent = active ? '⤢' : '⛶';
          fullscreenBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
      };
  
      const toggleFullscreen = async () => {
        if (!fullscreenBtn) return;
        try {
          if (document.fullscreenElement === tlRoot) {
            await document.exitFullscreen();
          } else {
            await tlRoot.requestFullscreen({ navigationUI: 'hide' });
          }
          window.setTimeout(() => {
            tlRoot.scrollIntoView({ block: 'start', behavior: 'auto' });
          }, 50);
        } catch (e) {
          // ignore
        }
      };
  
      if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', () => {
          toggleFullscreen();
        });
        document.addEventListener('fullscreenchange', updateFullscreenUi);
      }

      timelapseCleanup = () => {
        // Ein laufender Fetch lässt sich in diesem Legacy-Endpunkt noch nicht
        // abbrechen. Die erhöhte Request-ID sorgt aber dafür, dass seine späte
        // Antwort den neuen Playback-Block nicht mehr verändert.
        lastReq += 1;
        stop();
        window.removeEventListener('keydown', handleKeydown, true);
        window.removeEventListener('keydown', handleSpeedHotkeys, true);
        if (fullscreenBtn) {
          document.removeEventListener('fullscreenchange', updateFullscreenUi);
        }
      };
    };
  
    const initDayvideoInline = () => dayvideoController.initInline();

    return Object.freeze({ initTimelapse, initDayvideoInline });
  };

  window.WebcamCameraPlayback = Object.freeze({ create, calendarDayRange });
})();
