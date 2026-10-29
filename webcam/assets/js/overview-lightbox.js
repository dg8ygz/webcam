(() => {
  'use strict';

  // Die Lightbox besitzt ihren eigenen Lade-, Navigations- und Zoomzustand.
  // Der Übersichtscontroller erhält nur open()/close(); DOM-Details und EXIF-
  // Nachladen bleiben vollständig in diesem Modul.
  const create = () => {
    const shell = document.querySelector('[data-lightbox]');
    const image = document.querySelector('[data-lightbox-img]');
    const title = document.querySelector('[data-lightbox-title]');
    const datetime = document.querySelector('[data-lightbox-datetime]');
    const status = document.querySelector('[data-lightbox-status]');
    const exif = document.querySelector('[data-lightbox-exif]');
    const previous = document.querySelector('[data-lightbox-prev]');
    const next = document.querySelector('[data-lightbox-next]');
    const closeButton = document.querySelector('[data-lightbox-close]');
    const zoomButton = document.querySelector('[data-lightbox-zoom]');
    const stage = document.querySelector('.lightbox__stage');
    if (!shell || !image) return Object.freeze({ open: async () => {}, close: () => {} });

    const timelines = new Map();
    const textCache = new Map();
    let currentSlug = '';
    let zoomed = true;
    const baseScale = 1.2;
    const zoomScale = 1.6;
    const drag = { active: false, x: 0, y: 0, originX: 0, originY: 0 };

    const fetchText = async (path) => {
      if (!path) return null;
      if (textCache.has(path)) return textCache.get(path);
      try {
        const response = await fetch(path);
        if (!response.ok) throw new Error('load failed');
        const value = await response.text();
        textCache.set(path, value);
        return value;
      } catch {
        textCache.set(path, null);
        return null;
      }
    };

    /** Vereinheitlicht API-Arrays und ältere EXIF-Objekte als lesbaren Text. */
    const normalizeExif = (value) => {
      if (!value) return null;
      if (typeof value === 'string') return value;
      const entries = Array.isArray(value)
        ? value.map((entry) => [entry?.tag, entry?.value])
        : (typeof value === 'object' ? Object.entries(value) : []);
      const lines = entries.map(([key, item]) => {
        const cleanKey = String(key ?? '').trim();
        const cleanValue = String(item ?? '').trim();
        return cleanKey && cleanValue ? `${cleanKey} | ${cleanValue}` : '';
      }).filter(Boolean);
      return lines.length ? lines.join('\n') : null;
    };

    const pickField = (text, key) => {
      if (!text) return null;
      const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      return text.match(new RegExp(`${escaped}\\s*\\|\\s*([^\\n|]+)`, 'i'))?.[1]?.trim() || null;
    };

    const summarizeExif = (text) => {
      if (!text) return '';
      const exposure = pickField(text, 'Exposure Time') || pickField(text, 'Exposure');
      let aperture = pickField(text, 'FNumber') || pickField(text, 'F-Number');
      if (aperture) aperture = `f/${aperture.replace(/^f?\/?/i, '').trim()}`;
      const isoValue = pickField(text, 'ISO');
      const primary = [exposure, aperture, isoValue ? `ISO ${isoValue}` : null].filter(Boolean);
      if (primary.length) return primary.join(' · ');
      return text.split(/\r?\n/).map((line) => {
        const parts = line.split('|').map((part) => part.trim());
        return parts.length >= 2 && parts[0] && parts[1] && !/^stream viewers$/i.test(parts[0])
          ? `${parts[0]}: ${parts[1]}` : '';
      }).filter(Boolean).slice(0, 3).join(' · ');
    };

    const renderExif = async (item) => {
      if (!exif || !item) return;
      let text = normalizeExif(item.exif);
      if (!text && item.exif_path) text = await fetchText(item.exif_path);
      exif.textContent = summarizeExif(text) || 'EXIF: –';
    };

    /** Reduziert Timeline-Einträge auf die Felder, die die Lightbox wirklich braucht. */
    const loadTimeline = async (slug) => {
      if (timelines.has(slug)) return timelines.get(slug);
      const pathBase = location.pathname.endsWith('index.php')
        ? location.pathname.slice(0, -'index.php'.length) : location.pathname;
      const response = await fetch(`${location.origin}${pathBase}api/public.php/cameras/${encodeURIComponent(slug)}/timeline?days=30`);
      if (!response.ok) throw new Error('Timeline-Fehler');
      const data = await response.json();
      const items = (Array.isArray(data.entries) ? data.entries : []).map((entry) => {
        const variants = entry.variants || {};
        const best = variants['1600'] || variants['1200'] || variants.hu || variants['720'] || variants.sm || null;
        return {
          timestamp: entry.timestamp || 0,
          date_label: entry.datetime_label || '',
          url: best?.url || entry.url || '',
          thumb_url: variants['180']?.url || entry.preview_url || entry.url || '',
          exif: normalizeExif(entry.exif_text || entry.exif),
          exif_path: entry.exif_relative || entry.exif_path || null,
        };
      }).sort((left, right) => right.timestamp - left.timestamp);
      const state = { items, idx: 0 };
      timelines.set(slug, state);
      return state;
    };

    const show = (state, index) => {
      const item = state.items[index];
      if (!item) return;
      state.idx = index;
      image.src = item.url || item.thumb_url || '';
      if (datetime) datetime.textContent = item.date_label ? `${item.date_label} Uhr` : '';
      if (status) status.textContent = `${index + 1}/${state.items.length}`;
      if (previous) previous.disabled = index <= 0;
      if (next) next.disabled = index >= state.items.length - 1;
      void renderExif(item);
    };

    const shift = (delta) => {
      const state = timelines.get(currentSlug);
      if (!state) return;
      const index = state.idx + delta;
      if (index >= 0 && index < state.items.length) show(state, index);
    };

    const close = () => {
      shell.classList.remove('is-open');
      shell.setAttribute('hidden', 'hidden');
      image.src = '';
      image.style.transform = 'translate(-50%, -50%) scale(1)';
      image.dataset.dx = '0';
      image.dataset.dy = '0';
      zoomed = true;
      drag.active = false;
    };

    const open = async (card) => {
      currentSlug = card.dataset.slug || '';
      const detailUrl = card.querySelector('.camera-image')?.getAttribute('href') || '';
      zoomed = true;
      image.dataset.dx = '0';
      image.dataset.dy = '0';
      image.style.transform = `translate(-50%, -50%) scale(${baseScale})`;
      if (title) title.textContent = card.querySelector('h2')?.textContent || currentSlug;
      if (status) status.textContent = 'Lade…';
      shell.classList.add('is-open');
      shell.removeAttribute('hidden');
      try {
        const state = await loadTimeline(currentSlug);
        show(state, state.idx);
      } catch (error) {
        if (!status) return;
        status.textContent = error.message || 'Ladevorgang fehlgeschlagen.';
        if (detailUrl) {
          const link = document.createElement('a');
          link.href = detailUrl;
          link.className = 'lightbox__fallback';
          link.textContent = 'Zur Kameraansicht';
          status.append(document.createElement('br'), link);
        }
      }
    };

    const clamp = () => stage ? (stage.clientHeight * (zoomScale - 1)) / 2 : 450;
    const applyTransform = () => {
      const dx = Number.parseFloat(image.dataset.dx || '0');
      const dy = Number.parseFloat(image.dataset.dy || '0');
      image.style.transform = `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(${zoomed ? zoomScale : baseScale})`;
    };
    const moveVertical = (delta) => {
      const value = Number.parseFloat(image.dataset.dy || '0') + delta;
      image.dataset.dy = String(Math.max(-clamp(), Math.min(clamp(), value)));
      applyTransform();
    };

    // Alle globalen Pointer-/Tastatur-Listener werden genau einmal beim Aufbau
    // dieses Controllers registriert.
    closeButton?.addEventListener('click', close);
    shell.addEventListener('click', (event) => { if (event.target === shell) close(); });
    previous?.addEventListener('click', () => shift(1));
    next?.addEventListener('click', () => shift(-1));
    zoomButton?.addEventListener('click', () => {
      zoomed = !zoomed;
      image.style.cursor = zoomed ? 'grab' : 'default';
      applyTransform();
    });
    if (stage) {
      image.addEventListener('mousedown', (event) => {
        if (!zoomed) return;
        drag.active = true;
        drag.originX = event.clientX;
        drag.originY = event.clientY;
        drag.x = Number.parseFloat(image.dataset.dx || '0');
        drag.y = Number.parseFloat(image.dataset.dy || '0');
        image.style.cursor = 'grabbing';
      });
      window.addEventListener('mouseup', () => {
        if (drag.active) image.style.cursor = 'grab';
        drag.active = false;
      });
      window.addEventListener('mousemove', (event) => {
        if (!drag.active || !zoomed) return;
        image.dataset.dx = String(drag.x + event.clientX - drag.originX);
        const y = drag.y + event.clientY - drag.originY;
        image.dataset.dy = String(Math.max(-clamp(), Math.min(clamp(), y)));
        applyTransform();
      });
      stage.addEventListener('wheel', (event) => {
        if (!zoomed) return;
        event.preventDefault();
        moveVertical(-event.deltaY * 0.2);
      }, { passive: false });
      window.addEventListener('keydown', (event) => {
        if (!shell.classList.contains('is-open')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') shift(1);
        if (event.key === 'ArrowRight') shift(-1);
        if (zoomed && event.key === 'ArrowUp') { event.preventDefault(); moveVertical(40); }
        if (zoomed && event.key === 'ArrowDown') { event.preventDefault(); moveVertical(-40); }
      });
    }
    return Object.freeze({ open, close });
  };

  window.WebcamOverviewLightbox = Object.freeze({ create });
})();
