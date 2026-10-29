(() => {
  'use strict';

  /**
   * Archivhistorie der Kameraansicht.
   *
   * Es gibt zwei bewusst getrennte Oberflächen:
   *
   * 1. Der kleine Tageshistorie-Dialog liest bereits im HTML eingebettete
   *    Aufnahmen eines Hotspots und öffnet maximal 30 Vorschaubilder.
   * 2. Das große History-Panel lädt Tages-, Monats- oder Jahreskarten über die
   *    Public-API und hält die Antwort nur bis zum Schließen der Browserseite.
   *
   * Persistente Timeline-Caches werden hier nicht angelegt. SQLite bleibt die
   * serverseitige Sollquelle; der Map-Cache vermeidet lediglich wiederholte
   * HTTP-Abfragen beim Wechsel zwischen bereits geöffneten Reitern.
   */
  const create = ({
    getLiveRoot,
    getInitialHistoryEndpoint,
    resolveAppUrl,
    normalizeAssetUrl,
    withNavMode,
  } = {}) => {
    const requiredFunctions = [getLiveRoot, resolveAppUrl, normalizeAssetUrl, withNavMode];
    if (requiredFunctions.some((candidate) => typeof candidate !== 'function')) {
      throw new Error('History-Modul: notwendige Seitenadapter fehlen.');
    }

    let dayHistoryModal = null;
    const initializedPanels = new WeakSet();
    let activeApplyHash = null;
    let globalNavigationBound = false;

    const parseDayHistory = (payload) => {
      if (!payload) {
        return [];
      }
      try {
        const parsed = JSON.parse(payload);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        // Ungültige eingebettete Historie wird als leer behandelt. Dadurch
        // landet weder defektes JSON noch fremdes HTML ungeprüft im Dialog.
        return [];
      }
    };

    const createDayHistoryModal = () => {
      const wrapper = document.createElement('div');
      wrapper.className = 'day-history-modal';
      wrapper.innerHTML = `
        <div class="day-history-modal__backdrop" data-day-history-dismiss></div>
        <div class="day-history-modal__dialog" role="dialog" aria-modal="true" aria-label="Tageshistorie" tabindex="-1">
          <button type="button" class="day-history-modal__close" data-day-history-close aria-label="Schließen">&times;</button>
          <div class="day-history-modal__body">
            <h3>Tagesverlauf der letzten 30 Aufnahmen</h3>
            <p class="day-history-modal__hint">Klicke auf ein Vorschaubild, um es in voller Größe zu öffnen.</p>
            <div class="day-history-modal__grid" data-day-history-grid></div>
          </div>
        </div>
      `;
      document.body.appendChild(wrapper);

      const dialog = wrapper.querySelector('.day-history-modal__dialog');
      const backdrop = wrapper.querySelector('[data-day-history-dismiss]');
      const closeButton = wrapper.querySelector('[data-day-history-close]');
      const grid = wrapper.querySelector('[data-day-history-grid]');

      const setVisible = (state) => {
        wrapper.classList.toggle('is-visible', state);
        document.body.classList.toggle('modal-open', state);
        if (state && dialog) {
          dialog.focus();
        }
      };

      const renderItems = (items) => {
        grid.innerHTML = '';
        items.forEach((item) => {
          const figure = document.createElement('figure');
          figure.className = 'day-history-modal__item';

          const hiResUrl = normalizeAssetUrl(item.hi_res_url || item.url || '');
          const link = document.createElement('a');
          link.href = hiResUrl || normalizeAssetUrl(item.url || '');
          link.target = '_blank';
          link.rel = 'noopener';

          // Der Zeitstempel sorgt dafür, dass ein nachträglich repariertes
          // Archivbild nicht aus einem veralteten Browsercache stammt.
          const separator = hiResUrl.includes('?') ? '&' : '?';
          const img = document.createElement('img');
          img.src = `${hiResUrl}${separator}v=${item.timestamp}`;
          img.alt = `Aufnahme ${item.date_label || ''} ${item.time_label || ''}`.trim();
          link.appendChild(img);

          const caption = document.createElement('figcaption');
          caption.textContent = `${item.date_label || ''} · ${item.time_label || ''}`;
          figure.appendChild(link);
          figure.appendChild(caption);
          grid.appendChild(figure);
        });
      };

      const close = () => {
        setVisible(false);
        grid.innerHTML = '';
      };

      const open = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
          return;
        }
        renderItems(items.slice(0, 30));
        setVisible(true);
      };

      closeButton.addEventListener('click', close);
      backdrop.addEventListener('click', close);
      document.addEventListener('keydown', (event) => {
        if (wrapper.classList.contains('is-visible') && event.key === 'Escape') {
          event.preventDefault();
          close();
        }
      });

      return Object.freeze({ open, close });
    };

    const getDayHistoryModal = () => {
      if (!dayHistoryModal) {
        dayHistoryModal = createDayHistoryModal();
      }
      return dayHistoryModal;
    };

    const initDayHistory = () => {
      const liveRoot = getLiveRoot();
      if (!liveRoot) {
        return;
      }
      const triggers = liveRoot.querySelectorAll('[data-day-history-trigger]');
      triggers.forEach((trigger) => {
        // Dieser Marker ist wichtig, weil camera.js die Funktion nach einem
        // partiellen Navigationsschritt erneut aufruft.
        if (trigger.dataset.dayHistoryReady === '1') {
          return;
        }
        trigger.dataset.dayHistoryReady = '1';
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          const items = parseDayHistory(trigger.getAttribute('data-history'));
          if (items.length === 0) {
            // eslint-disable-next-line no-alert
            alert('Für diese Uhrzeit liegen in den letzten 30 Tagen keine Aufnahmen vor.');
            return;
          }
          getDayHistoryModal().open(items);
        });
      });
    };

    const addRangeParameters = (url, mode, historyRefTs) => {
      url.searchParams.set('mode', mode);
      if (mode === 'months') {
        url.searchParams.set('months', '13');
      } else if (mode === 'years') {
        url.searchParams.set('years', '30');
      } else {
        url.searchParams.set('days', '30');
      }
      if (historyRefTs && historyRefTs > 0) {
        url.searchParams.set('ts', String(historyRefTs));
      }
      return url;
    };

    const fetchJson = async (url, timeoutMs) => {
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort('history-timeout'), timeoutMs);
      try {
        const response = await fetch(url.toString(), {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        return await response.json();
      } finally {
        window.clearTimeout(timeout);
      }
    };

    const bindGlobalNavigation = () => {
      if (globalNavigationBound) {
        return;
      }
      globalNavigationBound = true;

      window.addEventListener('hashchange', () => {
        if (activeApplyHash) {
          activeApplyHash();
        }
      });

      // Menülinks auf derselben Seite wechseln sofort den History-Reiter. Bei
      // Links auf eine andere Kamera bleibt die normale Navigation erhalten.
      document.addEventListener('click', (event) => {
        const anchor = event.target instanceof Element ? event.target.closest('a') : null;
        if (!anchor || !['#days', '#months', '#years'].includes(anchor.hash)) {
          return;
        }
        const samePage = !anchor.getAttribute('href') || anchor.pathname === window.location.pathname;
        if (!samePage) {
          return;
        }
        event.preventDefault();
        if (window.location.hash !== anchor.hash) {
          window.location.hash = anchor.hash;
        } else if (activeApplyHash) {
          activeApplyHash();
        }
      });
    };

    const initHistoryPanel = () => {
      const root = document.querySelector('[data-history-root]');
      if (!root || initializedPanels.has(root)) {
        return;
      }

      const liveRoot = getLiveRoot();
      const initialEndpoint = typeof getInitialHistoryEndpoint === 'function'
        ? getInitialHistoryEndpoint()
        : '';
      const endpoint = resolveAppUrl(
        initialEndpoint || root.getAttribute('data-history-endpoint') || ''
      );
      const fallbackEndpoint = resolveAppUrl(
        root.getAttribute('data-history-fallback')
        || (liveRoot && liveRoot.getAttribute('data-history-fallback'))
        || ''
      );
      const historyRefTsAttr = root.getAttribute('data-history-ref-ts')
        || (liveRoot && liveRoot.getAttribute('data-history-ref-ts'))
        || '';
      const historyRefTs = Number.isNaN(Number(historyRefTsAttr))
        ? null
        : parseInt(historyRefTsAttr, 10);
      const tabs = root.querySelectorAll('[data-history-tab]');
      const placeholder = root.querySelector('[data-history-placeholder]');
      const list = root.querySelector('[data-history-list]');
      if (!endpoint || !placeholder || !list) {
        return;
      }

      initializedPanels.add(root);
      const cache = new Map();

      const setActiveTab = (mode) => {
        tabs.forEach((tab) => {
          const isActive = tab.dataset.historyTab === mode;
          tab.classList.toggle('is-active', isActive);
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        const activeTab = root.querySelector(`[data-history-tab="${mode}"]`);
        const content = root.querySelector('[data-history-content]');
        if (activeTab && content) {
          content.setAttribute('aria-labelledby', activeTab.id || '');
        }
      };

      const renderItems = (items, mode) => {
        list.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
          placeholder.textContent = 'Keine Aufnahmen gefunden.';
          placeholder.classList.remove('is-hidden');
          return;
        }

        placeholder.classList.add('is-hidden');
        const images = [];
        const navMode = mode === 'years' ? 'year' : (mode === 'months' ? 'month' : 'day');
        items.forEach((item) => {
          const link = document.createElement('a');
          link.className = 'bestof-card history-card';
          link.href = withNavMode(item.link || '#', navMode);
          link.setAttribute('aria-label', `${item.date_label || ''} ${item.time_label || ''}`.trim());

          const img = document.createElement('img');
          img.loading = 'lazy';
          img.decoding = 'async';
          img.src = normalizeAssetUrl(item.thumb_url || item.url || '');
          if (item.url) {
            img.dataset.fullSrc = normalizeAssetUrl(item.url);
          }
          images.push(img);
          link.appendChild(img);

          const caption = document.createElement('div');
          caption.className = 'bestof-card__caption';
          caption.textContent = `${item.date_label || ''} · ${item.time_label || ''}`.trim();
          link.appendChild(caption);
          list.appendChild(link);
        });

        // Erst wenn alle Vorschaubilder entweder geladen oder fehlgeschlagen
        // sind, ist der Ladevorgang für diesen Reiter vollständig beendet.
        Promise.all(images.map((img) => new Promise((resolve) => {
          if (img.complete) {
            resolve();
            return;
          }
          img.addEventListener('load', resolve, { once: true });
          img.addEventListener('error', resolve, { once: true });
        }))).then(() => placeholder.classList.add('is-hidden'));
      };

      const load = async (mode) => {
        setActiveTab(mode);
        placeholder.textContent = 'Verlauf wird geladen…';
        placeholder.classList.remove('is-hidden');
        list.innerHTML = '';

        if (cache.has(mode)) {
          renderItems(cache.get(mode), mode);
          return;
        }

        const timeoutMs = mode === 'days' ? 20000 : 30000;
        try {
          let data = await fetchJson(
            addRangeParameters(new URL(endpoint, window.location.origin), mode, historyRefTs),
            timeoutMs
          );

          // Eine syntaktisch gültige, aber leere JSON-Antwort kann bei einem
          // kurzzeitigen Backend-Abbruch entstehen. Nur der häufig genutzte
          // Tagesmodus erhält dafür genau einen kontrollierten Wiederholungs-
          // versuch; Netzwerkfehler und Timeouts werden nicht verschleiert.
          if (!data && mode === 'days') {
            data = await fetchJson(
              addRangeParameters(new URL(endpoint, window.location.origin), mode, historyRefTs),
              timeoutMs
            );
          }

          // Ältere Installationen können noch einen PHP-Fallback deklarieren.
          // Er wird nur benutzt, wenn die primäre Antwort kein Item-Array hat.
          if ((!data || !Array.isArray(data.items)) && fallbackEndpoint) {
            data = await fetchJson(
              addRangeParameters(new URL(fallbackEndpoint, window.location.href), mode, historyRefTs),
              timeoutMs
            );
          }

          const items = data && Array.isArray(data.items) ? data.items : [];
          cache.set(mode, items);
          renderItems(items, mode);
        } catch (error) {
          const isAbort = error && error.name === 'AbortError';
          placeholder.textContent = mode === 'months'
            ? (isAbort ? 'Monatsverlauf Timeout – bitte erneut versuchen.' : 'Monatsverlauf konnte nicht geladen werden.')
            : mode === 'years'
              ? (isAbort ? 'Jahresvergleich Timeout – bitte erneut versuchen.' : 'Jahresvergleich konnte nicht geladen werden.')
              : (isAbort ? 'Tagesverlauf Timeout – bitte erneut versuchen.' : 'Verlauf konnte nicht geladen werden.');
          console.warn('history load failed', error);
          list.innerHTML = '';
          placeholder.classList.remove('is-hidden');
        }
      };

      tabs.forEach((tab) => {
        tab.addEventListener('click', () => load(tab.dataset.historyTab || 'days'));
      });

      const applyHash = () => {
        const modeByHash = {
          '#months': 'months',
          '#years': 'years',
          '#days': 'days',
          '#history-panel': 'days',
        };
        const mode = modeByHash[document.location.hash];
        if (!mode) {
          return false;
        }
        load(mode);
        return true;
      };

      activeApplyHash = applyHash;
      bindGlobalNavigation();
      if (!applyHash() && window.location.search.includes('view=history')) {
        load('days');
      }
    };

    return Object.freeze({ initDayHistory, initHistoryPanel });
  };

  window.WebcamCameraHistory = Object.freeze({ create });
})();
