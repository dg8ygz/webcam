(() => {
  'use strict';

  /**
   * Erzeugt die Bild-Lightbox für ein Kameradokument.
   *
   * Timeline und Best-of bleiben fachlich getrennte Serverquellen. Dieses
   * Modul führt ihre bereits normalisierten Treffer nur für die sichtbare
   * Browsernavigation zusammen; sein Cache lebt ausschließlich bis zum
   * nächsten vollständigen Seitenaufruf.
   */
  const create = ({
    getLiveRoot,
    parseIntSafe,
    normalizeAssetUrl,
    applyEmbeddedOverlayState,
    formatDateInTimezone,
    resolveAppUrl,
    buildAppUrl,
    toRelativeUrl,
    buildCameraPath,
  } = {}) => {
    const dependencies = [
      getLiveRoot,
      parseIntSafe,
      normalizeAssetUrl,
      applyEmbeddedOverlayState,
      formatDateInTimezone,
      resolveAppUrl,
      buildAppUrl,
      toRelativeUrl,
      buildCameraPath,
    ];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new Error('Lightbox: notwendige Seitenadapter fehlen.');
    }

    // #camera-live-root selbst bleibt bei partieller Navigation bestehen; nur
    // Attribute und Inhalt werden ausgetauscht. Die stabile Referenz verhindert
    // deshalb doppelte globale Lightbox-Listener nach jedem Archivsprung.
    const liveRoot = getLiveRoot();

    // -------------------------------------------------------------------------
    // Lightbox und Best-Of.
    // Die Lightbox kann über Bilder desselben Tages, über Tagesgrenzen und durch
    // paginierte Best-Of-Treffer navigieren. Sie hält dafür einen eigenen,
    // ausschließlich im Browser lebenden Index.
    // -------------------------------------------------------------------------
    const livebox = document.querySelector('[data-livebox]');
    const lbImg = document.querySelector('[data-livebox-img]');
    const lbClose = document.querySelector('[data-livebox-close]');
    const lbDtNodes = Array.from(document.querySelectorAll('[data-livebox-datetime]'));
    const lbStatus = document.querySelector('[data-livebox-status]');
    const lbPrev = document.querySelector('[data-livebox-prev]');
    const lbNext = document.querySelector('[data-livebox-next]');
    const lbZoom = document.querySelector('[data-livebox-zoom]');
    const lbHelp = document.querySelector('[data-livebox-help]');
    const lbHelpPanel = document.querySelector('[data-livebox-help-panel]');
    const lbExifNodes = Array.from(document.querySelectorAll('[data-livebox-exif]'));
    let controlsController = null;
    let timelineItemsLivebox = [];
    let timelineIndexLivebox = 0;
    let exifFallbackText = '';
    let liveboxLoadedDays = new Set();
    let liveboxDayEdges = new Map();
    let liveboxDayLoads = new Map();
    let liveboxLoadingAdjacent = false;
    let liveboxMode = 'timeline';
    let liveboxBestOfState = null;
    let liveboxImageRequestId = 0;
    const bestOfCache = new Map();
    // -----------------------------------------------------------------------
    // Bild-, EXIF- und Overlay-Normalisierung
    // -----------------------------------------------------------------------
    // Timeline-Datensätze aus verschiedenen Ausbauphasen benennen ihre größte
    // Variante unterschiedlich. Die feste Priorität verhindert, dass eine
    // kleine Vorschau in der vergrößerbaren Lightbox landet.
    const setNodesText = (nodes, text) => {
      nodes.forEach((node) => {
        if (node) node.textContent = text || '';
      });
    };
  
    // Fachliche Normalisierung und sichere Overlay-Ausgabe liegen in einem
    // DOM-nahen, aber netzwerkfreien Teilmodul. Der Koordinator verwendet nur
    // dessen klar benannte Ergebnisse.
    const entryFactory = window.WebcamCameraLightboxEntry;
    if (!entryFactory || typeof entryFactory.create !== 'function') {
      throw new Error('Lightbox-Eintragsmodell wurde nicht vor camera-lightbox.js geladen.');
    }
    const entryModel = entryFactory.create({
      liveRoot,
      parseIntSafe,
      normalizeAssetUrl,
      applyEmbeddedOverlayState,
      formatDateInTimezone,
    });
    const {
      applyEntryOverlay,
      computeExifSummary,
      formatOverlayTimestamp,
      getTimezone: getLiveboxTimezone,
      buildDayKey,
      parseDayKey,
      resolveEntryDayKey,
      resolveSelectedDayKey,
      mapEntryToItem,
      buildTimelineItems,
    } = entryModel;
  
    const buildTimelineUrl = ({ year = null, month = null, day = null, ts = null } = {}) => {
      const slug = liveRoot?.dataset.cameraSlug || '';
      const endpointRaw = liveRoot?.dataset.timelineEndpoint || (slug ? `api/public.php/cameras/${slug}/timeline` : '');
      const endpoint = resolveAppUrl(endpointRaw);
      if (!endpoint) return null;
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('limit', '2000');
      if (year) url.searchParams.set('year', String(year));
      if (month) url.searchParams.set('month', String(month));
      if (day) url.searchParams.set('day', String(day));
      if (ts) url.searchParams.set('ts', String(ts));
      return url;
    };
  
    const storeLiveboxDayEdges = (dayKey, data) => {
      if (!dayKey) return;
      liveboxDayEdges.set(dayKey, {
        prev: data?.day_prev_entry || null,
        next: data?.day_next_entry || null,
      });
    };
  
    const buildLiveboxItemKey = (item) => {
      if (!item) return '';
      if (item.timestamp) return `ts:${item.timestamp}`;
      return `url:${item.url || ''}`;
    };
  
    const sortLiveboxItems = (a, b) => {
      const aTs = a?.timestamp || 0;
      const bTs = b?.timestamp || 0;
      if (aTs !== bTs) return aTs - bTs;
      return String(a?.url || '').localeCompare(String(b?.url || ''));
    };
  
    const mergeLiveboxItems = (items) => {
      if (!items || !items.length) return;
      const merged = new Map();
      timelineItemsLivebox.forEach((item) => {
        merged.set(buildLiveboxItemKey(item), item);
      });
      items.forEach((item) => {
        const key = buildLiveboxItemKey(item);
        if (key && !merged.has(key)) {
          merged.set(key, item);
        }
      });
      timelineItemsLivebox = Array.from(merged.values());
      timelineItemsLivebox.sort(sortLiveboxItems);
    };
  
    // -----------------------------------------------------------------------
    // Best-of-Seiten und deren kurzlebiger Browserzustand
    // -----------------------------------------------------------------------
    // Legacy-Best-of-Antworten besitzen nicht immer slot_ts. In diesem Fall
    // wird ausschließlich das streng definierte Zeitpräfix des Dateinamens
    // ausgewertet; beliebige Dateinamen werden nicht als Datum interpretiert.
    const parseBestOfTimestamp = (filename) => {
      if (!filename) return null;
      const m = filename.match(/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/);
      if (!m) return null;
      const year = parseInt(m[1], 10);
      const month = parseInt(m[2], 10);
      const day = parseInt(m[3], 10);
      const hour = parseInt(m[4], 10);
      const minute = parseInt(m[5], 10);
      const second = parseInt(m[6], 10);
      if (!year || !month || !day) return null;
      const dt = new Date(year, month - 1, day, hour, minute, second);
      if (!Number.isFinite(dt.getTime())) return null;
      return Math.floor(dt.getTime() / 1000);
    };
  
    const buildBestOfItems = (items = []) => {
      if (!Array.isArray(items)) return [];
      return items.map((entry) => {
        const filename = entry.filename || (entry.url ? entry.url.split('/').pop() : '') || '';
        const ts = parseIntSafe(entry.slot_ts) || parseBestOfTimestamp(filename);
        const url = normalizeAssetUrl(entry.url || entry.thumb_url || '');
        if (!url) return null;
        const datetime = ts ? formatOverlayTimestamp({ timestamp: ts }) : '';
        const dayKey = ts ? formatDateInTimezone(ts, getLiveboxTimezone()) : null;
        return {
          url,
          filename,
          link: typeof entry.link === 'string' ? resolveAppUrl(entry.link) : null,
          timestamp: ts || null,
          datetime,
          time: '',
          exif: '',
          dayKey,
        };
      }).filter(Boolean);
    };
  
    const fetchBestOfPage = async (slug, page, perPage = 200) => {
      if (!slug) return null;
      const url = buildAppUrl(`api/public.php/cameras/${slug}/bestof`);
      url.searchParams.set('per_page', String(perPage));
      url.searchParams.set('page', String(page));
      try {
        const resp = await fetch(toRelativeUrl(url), { credentials: 'same-origin' });
        if (!resp.ok) return null;
        return resp.json();
      } catch {
        return null;
      }
    };
  
    const ensureBestOfState = async (slug, targetFilename = null) => {
      if (!slug) return null;
      let state = bestOfCache.get(slug);
      if (!state) {
        state = { items: [], page: 0, totalPages: null, total: null, perPage: 200 };
      }
      const hasTarget = (name) => {
        if (!name) return false;
        return state.items.some((item) => item && item.filename === name);
      };
      const canLoadMore = () => state.totalPages === null || state.page < state.totalPages;
  
      while (canLoadMore()) {
        if (targetFilename && hasTarget(targetFilename)) {
          break;
        }
        if (!targetFilename && state.items.length) {
          break;
        }
        const nextPage = state.page + 1;
        const data = await fetchBestOfPage(slug, nextPage, state.perPage);
        if (!data || !Array.isArray(data.items)) {
          break;
        }
        const mapped = buildBestOfItems(data.items);
        if (mapped.length) {
          state.items.push(...mapped);
        }
        state.page = parseIntSafe(data.page) || nextPage;
        state.totalPages = parseIntSafe(data.total_pages) || state.totalPages;
        state.total = parseIntSafe(data.total) || state.total;
        if (state.totalPages !== null && state.page >= state.totalPages) {
          break;
        }
      }
  
      bestOfCache.set(slug, state);
      return state;
    };
  
    const loadMoreBestOf = async () => {
      if (!liveboxBestOfState) return false;
      if (liveboxBestOfState.totalPages !== null && liveboxBestOfState.page >= liveboxBestOfState.totalPages) {
        return false;
      }
      const slug = liveRoot?.dataset.cameraSlug || '';
      if (!slug) return false;
      const nextPage = liveboxBestOfState.page + 1;
      liveboxLoadingAdjacent = true;
      updateNavButtons();
      const data = await fetchBestOfPage(slug, nextPage, liveboxBestOfState.perPage);
      liveboxLoadingAdjacent = false;
      if (!data || !Array.isArray(data.items)) {
        updateNavButtons();
        return false;
      }
      const mapped = buildBestOfItems(data.items);
      if (mapped.length) {
        liveboxBestOfState.items.push(...mapped);
        timelineItemsLivebox = liveboxBestOfState.items;
      }
      liveboxBestOfState.page = parseIntSafe(data.page) || nextPage;
      liveboxBestOfState.totalPages = parseIntSafe(data.total_pages) || liveboxBestOfState.totalPages;
      liveboxBestOfState.total = parseIntSafe(data.total) || liveboxBestOfState.total;
      updateNavButtons();
      return mapped.length > 0;
    };
  
    const getBestofNavTarget = async (direction) => {
      const urlParams = new URLSearchParams(window.location.search);
      const bestofParam = urlParams.get('bestof');
      if (!bestofParam) return null;
      const slug = liveRoot?.dataset.cameraSlug || '';
      if (!slug) return null;
      const state = await ensureBestOfState(slug, bestofParam);
      if (!state || !state.items.length) return null;
      let idx = state.items.findIndex((it) => it.filename === bestofParam);
      if (idx === -1 && state.items.length === 1) {
        idx = 0;
      }
      if (idx === -1) return null;
      let targetIdx = direction === 'next' ? idx - 1 : idx + 1;
      if (targetIdx < 0) return null;
      if (targetIdx >= state.items.length) {
        const nextPage = state.page + 1;
        if (state.totalPages !== null && nextPage > state.totalPages) {
          return null;
        }
        const data = await fetchBestOfPage(slug, nextPage, state.perPage);
        if (!data || !Array.isArray(data.items)) {
          return null;
        }
        const mapped = buildBestOfItems(data.items);
        if (mapped.length) {
          state.items.push(...mapped);
        }
        state.page = parseIntSafe(data.page) || nextPage;
        state.totalPages = parseIntSafe(data.total_pages) || state.totalPages;
        state.total = parseIntSafe(data.total) || state.total;
        bestOfCache.set(slug, state);
      }
      const target = state.items[targetIdx];
      if (!target) return null;
      if (target.link) {
        return resolveAppUrl(target.link) || target.link;
      }
      const base = buildCameraPath(slug);
      return `${base}?bestof=${encodeURIComponent(target.filename || '')}`;
    };
  
    // -----------------------------------------------------------------------
    // Nachladen benachbarter Archivtage
    // -----------------------------------------------------------------------
    // Die API bleibt die einzige Metadatenquelle. Die Sets verhindern nur im
    // aktuellen Browserdokument doppelte Requests beim Vor-/Zurückspringen.
    const fetchTimelineForDay = async ({ year, month, day, ts = null }) => {
      const url = buildTimelineUrl({ year, month, day, ts });
      if (!url) return null;
      try {
        const response = await fetch(toRelativeUrl(url), { credentials: 'same-origin' });
        if (!response.ok) return null;
        return response.json();
      } catch (error) {
        return null;
      }
    };
  
    const ensureDayLoaded = async ({ year, month, day, ts = null } = {}) => {
      if (!year || !month || !day) return false;
      const dayKey = buildDayKey(year, month, day);
      if (!dayKey) return false;
      if (liveboxLoadedDays.has(dayKey)) return true;
      if (liveboxDayLoads.has(dayKey)) {
        return liveboxDayLoads.get(dayKey);
      }
      const loader = (async () => {
        const data = await fetchTimelineForDay({ year, month, day, ts });
        if (!data) return false;
        const items = buildTimelineItems(data);
        storeLiveboxDayEdges(dayKey, data);
        if (items.length) {
          mergeLiveboxItems(items);
        }
        liveboxLoadedDays.add(dayKey);
        return true;
      })();
      liveboxDayLoads.set(dayKey, loader);
      const result = await loader;
      liveboxDayLoads.delete(dayKey);
      return result;
    };
  
    const exifCache = new Map();
    // -----------------------------------------------------------------------
    // Darstellung des aktiven Bildes und Navigation im zusammengeführten Index
    // -----------------------------------------------------------------------
    const renderLiveboxStatus = () => {
      if (!lbStatus) return;
      if (liveboxLoadingAdjacent) {
        lbStatus.textContent = 'Lade…';
        return;
      }
      if (!timelineItemsLivebox.length) {
        lbStatus.textContent = '';
        return;
      }
      if (liveboxMode === 'bestof' && liveboxBestOfState?.total) {
        lbStatus.textContent = `${timelineIndexLivebox + 1} / ${liveboxBestOfState.total}`;
        return;
      }
      lbStatus.textContent = `${timelineIndexLivebox + 1} / ${timelineItemsLivebox.length}`;
    };
  
    const updateLightboxMeta = (item = null) => {
      const dt = item?.datetime || item?.time || '';
      const zoomLabel = controlsController?.getScaleLabel() || '2x';
      setNodesText(lbDtNodes, dt ? `${dt} · ${zoomLabel}` : zoomLabel);
      const setExifText = (text, short = '') => {
        const value = short && short.trim() !== '' ? short : text || exifFallbackText || '';
        setNodesText(lbExifNodes, value);
      };
      if (item?.exif && item.exif.trim() !== '') {
        setExifText(item.exif, item.exifShort);
      } else if (item?.exifPath) {
        const key = item.exifPath;
        if (exifCache.has(key)) {
            const cached = exifCache.get(key);
            setExifText(cached.raw, cached.short);
        } else {
          fetch(item.exifPath)
            .then((r) => (r.ok ? r.text() : ''))
            .then((txt) => {
              const clean = (txt || '').trim();
              const short = computeExifSummary(clean);
              exifCache.set(key, { raw: clean, short });
              if (timelineItemsLivebox[timelineIndexLivebox] === item) {
                setExifText(clean, short);
              }
            })
            .catch(() => {});
        }
      } else {
        setExifText('');
      }
      renderLiveboxStatus();
    };
  
    const getAdjacentDayKey = (dayKey, direction) => {
      if (!dayKey) return null;
      const edges = liveboxDayEdges.get(dayKey);
      if (!edges) return null;
      const entry = direction === 'prev' ? edges.prev : edges.next;
      return resolveEntryDayKey(entry);
    };
  
    /** Setzt alle rein sitzungsbezogenen Treffer und Tageskanten zurück. */
    const resetLiveboxState = () => {
      timelineItemsLivebox = [];
      timelineIndexLivebox = 0;
      liveboxLoadedDays = new Set();
      liveboxDayEdges = new Map();
      liveboxDayLoads = new Map();
      liveboxLoadingAdjacent = false;
      liveboxMode = 'timeline';
      liveboxBestOfState = null;
    };
  
    const buildFallbackLiveboxItem = ({ currentUrl, currentTs, filename = '', datetime = '', exif = '' } = {}) => {
      if (!currentUrl) {
        return null;
      }
      const fallbackKey = resolveSelectedDayKey(currentTs);
      const fallbackParts = parseDayKey(fallbackKey);
      return {
        url: currentUrl,
        filename,
        timestamp: currentTs || null,
        datetime: datetime || (currentTs ? formatOverlayTimestamp({ timestamp: currentTs }) : ''),
        time: '',
        exif,
        dayKey: fallbackKey,
        year: fallbackParts?.year || null,
        month: fallbackParts?.month || null,
        day: fallbackParts?.day || null,
      };
    };
  
    const resolveLiveboxIndex = ({ currentUrl = '', currentTs = null, filename = null, fallback = 0 } = {}) => {
      if (!timelineItemsLivebox.length) {
        return 0;
      }
      if (filename) {
        const idxByFilename = timelineItemsLivebox.findIndex((item) => item.filename === filename);
        if (idxByFilename >= 0) {
          return idxByFilename;
        }
      }
      if (currentTs) {
        const idxByTs = timelineItemsLivebox.findIndex((item) => item.timestamp && item.timestamp === currentTs);
        if (idxByTs >= 0) {
          return idxByTs;
        }
      }
      if (currentUrl) {
        const idxByUrl = timelineItemsLivebox.findIndex((item) => item.url && currentUrl.includes(item.url.split('?')[0]));
        if (idxByUrl >= 0) {
          return idxByUrl;
        }
      }
      return Math.max(0, Math.min(fallback, timelineItemsLivebox.length - 1));
    };
  
    const showLivebox = (index) => {
      if (!livebox) {
        return;
      }
      livebox.classList.add('is-open');
      livebox.removeAttribute('hidden');
      setLiveboxImage(index);
    };
  
    const canNavigateBestOfPrev = () => {
      if (!timelineItemsLivebox.length) return false;
      if (timelineIndexLivebox < timelineItemsLivebox.length - 1) return true;
      return Boolean(liveboxBestOfState && liveboxBestOfState.totalPages !== null && liveboxBestOfState.page < liveboxBestOfState.totalPages);
    };
  
    const canNavigateBestOfNext = () => {
      if (!timelineItemsLivebox.length) return false;
      return timelineIndexLivebox > 0;
    };
  
    const canNavigateTimelinePrev = () => {
      if (!timelineItemsLivebox.length) return false;
      if (timelineIndexLivebox > 0) return true;
      const dayKey = timelineItemsLivebox[0]?.dayKey;
      return Boolean(getAdjacentDayKey(dayKey, 'prev'));
    };
  
    const canNavigateTimelineNext = () => {
      if (!timelineItemsLivebox.length) return false;
      if (timelineIndexLivebox < timelineItemsLivebox.length - 1) return true;
      const dayKey = timelineItemsLivebox[timelineItemsLivebox.length - 1]?.dayKey;
      return Boolean(getAdjacentDayKey(dayKey, 'next'));
    };
  
    const canNavigatePrev = () => {
      return liveboxMode === 'bestof' ? canNavigateBestOfPrev() : canNavigateTimelinePrev();
    };
  
    const canNavigateNext = () => {
      return liveboxMode === 'bestof' ? canNavigateBestOfNext() : canNavigateTimelineNext();
    };
  
    const updateNavButtons = () => {
      if (lbPrev) lbPrev.disabled = liveboxLoadingAdjacent || !canNavigatePrev();
      if (lbNext) lbNext.disabled = liveboxLoadingAdjacent || !canNavigateNext();
      renderLiveboxStatus();
    };
  
    const setLiveboxImageLoading = (loading) => {
      if (!livebox) return;
      livebox.classList.toggle('is-loading', Boolean(loading));
    };
  
    /**
     * Wechselt atomar zum gewünschten Index. Eine laufende ältere Bildantwort
     * darf den Ladezustand eines inzwischen neu ausgewählten Bildes nicht mehr
     * beenden; dafür dient liveboxImageRequestId.
     */
    const setLiveboxImage = (index) => {
      if (!lbImg || !timelineItemsLivebox.length) return;
      timelineIndexLivebox = Math.max(0, Math.min(index, timelineItemsLivebox.length - 1));
      const item = timelineItemsLivebox[timelineIndexLivebox];
      const requestId = ++liveboxImageRequestId;
      const finishCurrentRequest = () => {
        if (requestId === liveboxImageRequestId) {
          setLiveboxImageLoading(false);
        }
      };
      setLiveboxImageLoading(true);
      lbImg.addEventListener('load', finishCurrentRequest, { once: true });
      lbImg.addEventListener('error', finishCurrentRequest, { once: true });
      lbImg.src = item.url;
      controlsController?.resetForImage();
      updateLightboxMeta(item);
      updateNavButtons();
      if (lbImg.complete && lbImg.naturalWidth > 0) {
        window.requestAnimationFrame(finishCurrentRequest);
      }
    };
  
    const shiftBestOfLightbox = async (delta) => {
      if (!timelineItemsLivebox.length) return;
      const step = -delta;
      let nextIndex = timelineIndexLivebox + step;
      if (nextIndex >= 0 && nextIndex < timelineItemsLivebox.length) {
        setLiveboxImage(nextIndex);
        return;
      }
      if (step > 0) {
        const loaded = await loadMoreBestOf();
        if (loaded) {
          nextIndex = Math.max(0, Math.min(nextIndex, timelineItemsLivebox.length - 1));
          setLiveboxImage(nextIndex);
          return;
        }
      }
      updateNavButtons();
    };
  
    const shiftTimelineLightbox = async (delta) => {
      if (!timelineItemsLivebox.length) return;
      const currentItem = timelineItemsLivebox[timelineIndexLivebox] || null;
      let nextIndex = timelineIndexLivebox + delta;
      if (nextIndex >= 0 && nextIndex < timelineItemsLivebox.length) {
        setLiveboxImage(nextIndex);
        return;
      }
  
      const direction = delta < 0 ? 'prev' : 'next';
      const dayKey = currentItem?.dayKey;
      const adjacentDayKey = getAdjacentDayKey(dayKey, direction);
      const adjacentParts = parseDayKey(adjacentDayKey);
      if (!adjacentParts || liveboxLoadingAdjacent) {
        updateNavButtons();
        return;
      }
  
      liveboxLoadingAdjacent = true;
      updateNavButtons();
      const loaded = await ensureDayLoaded(adjacentParts);
      liveboxLoadingAdjacent = false;
      updateNavButtons();
      if (!loaded) return;
  
      const currentIndex = timelineItemsLivebox.indexOf(currentItem);
      if (currentIndex >= 0) {
        timelineIndexLivebox = currentIndex;
      }
      nextIndex = timelineIndexLivebox + delta;
      if (nextIndex >= 0 && nextIndex < timelineItemsLivebox.length) {
        setLiveboxImage(nextIndex);
      }
    };
  
    const shiftLightbox = async (delta) => {
      if (!timelineItemsLivebox.length) return;
      if (liveboxMode === 'bestof') {
        await shiftBestOfLightbox(delta);
        return;
      }
      await shiftTimelineLightbox(delta);
    };

    /**
     * Liest die lokale Uhrzeit eines Treffers als Sekunden seit Mitternacht.
     * Der Vergleich erfolgt bewusst in der Kamerazeitzone, damit ein
     * Tagessprung auch an Sommerzeitgrenzen möglichst dieselbe Uhrzeit trifft.
     */
    const resolveItemClockSeconds = (item) => {
      const timeMatch = String(item?.time || '').match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
      if (timeMatch) {
        return (parseInt(timeMatch[1], 10) * 3600)
          + (parseInt(timeMatch[2], 10) * 60)
          + parseInt(timeMatch[3] || '0', 10);
      }
      if (!item?.timestamp) return null;
      try {
        const parts = new Intl.DateTimeFormat('de-DE', {
          timeZone: getLiveboxTimezone(),
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hourCycle: 'h23',
        }).formatToParts(new Date(item.timestamp * 1000));
        const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return (parseInt(values.hour || '0', 10) * 3600)
          + (parseInt(values.minute || '0', 10) * 60)
          + parseInt(values.second || '0', 10);
      } catch {
        return null;
      }
    };

    /** Springt innerhalb des aktuellen Tages zum ersten oder letzten Bild. */
    const jumpToDayBoundary = (boundary) => {
      if (liveboxMode !== 'timeline' || !timelineItemsLivebox.length) return;
      const currentItem = timelineItemsLivebox[timelineIndexLivebox] || null;
      const dayItems = timelineItemsLivebox.filter((item) => item.dayKey === currentItem?.dayKey);
      if (!dayItems.length) return;
      const target = boundary === 'first' ? dayItems[0] : dayItems[dayItems.length - 1];
      const targetIndex = timelineItemsLivebox.indexOf(target);
      if (targetIndex >= 0) setLiveboxImage(targetIndex);
    };

    /**
     * Lädt den vorherigen oder nächsten vorhandenen Tag und wählt dort den
     * Treffer, dessen lokale Uhrzeit dem Ausgangsbild am nächsten liegt.
     */
    const shiftTimelineDay = async (direction) => {
      if (liveboxMode !== 'timeline' || !timelineItemsLivebox.length || liveboxLoadingAdjacent) return;
      const currentItem = timelineItemsLivebox[timelineIndexLivebox] || null;
      const adjacentDayKey = getAdjacentDayKey(currentItem?.dayKey, direction);
      const adjacentParts = parseDayKey(adjacentDayKey);
      if (!adjacentParts) return;

      liveboxLoadingAdjacent = true;
      updateNavButtons();
      let loaded = false;
      try {
        loaded = await ensureDayLoaded(adjacentParts);
      } finally {
        // Auch ein unerwarteter API-/Mappingfehler darf die Navigation nicht
        // dauerhaft im Zustand „Lade…“ und mit gesperrten Tasten zurücklassen.
        liveboxLoadingAdjacent = false;
        updateNavButtons();
      }
      if (!loaded) return;

      const dayItems = timelineItemsLivebox.filter((item) => item.dayKey === adjacentDayKey);
      if (!dayItems.length) return;
      const referenceClock = resolveItemClockSeconds(currentItem);
      let target = direction === 'prev' ? dayItems[dayItems.length - 1] : dayItems[0];
      if (referenceClock !== null) {
        target = dayItems.reduce((closest, candidate) => {
          const closestClock = resolveItemClockSeconds(closest);
          const candidateClock = resolveItemClockSeconds(candidate);
          if (candidateClock === null) return closest;
          if (closestClock === null) return candidate;
          return Math.abs(candidateClock - referenceClock) < Math.abs(closestClock - referenceClock)
            ? candidate
            : closest;
        }, target);
      }
      const targetIndex = timelineItemsLivebox.indexOf(target);
      if (targetIndex >= 0) setLiveboxImage(targetIndex);
    };
  
    const openBestOfLivebox = async ({ bestofParam, cameraSlug, currentUrl, currentTs }) => {
      liveboxMode = 'bestof';
      liveboxBestOfState = await ensureBestOfState(cameraSlug, bestofParam);
      timelineItemsLivebox = liveboxBestOfState?.items || [];
  
      if (!timelineItemsLivebox.length) {
        const fallbackItem = buildFallbackLiveboxItem({ currentUrl, currentTs, filename: bestofParam });
        if (fallbackItem) {
          timelineItemsLivebox.push(fallbackItem);
        }
      }
  
      const index = resolveLiveboxIndex({ currentUrl, currentTs, filename: bestofParam, fallback: 0 });
      showLivebox(index);
    };
  
    const openTimelineLivebox = async ({ currentUrl, currentTs }) => {
      const selectedDayKey = resolveSelectedDayKey(currentTs);
      const selectedParts = parseDayKey(selectedDayKey);
      if (selectedParts) {
        await ensureDayLoaded({ ...selectedParts, ts: currentTs });
      }
  
      if (!timelineItemsLivebox.length) {
        const fallbackItem = buildFallbackLiveboxItem({
          currentUrl,
          currentTs,
          datetime: currentTs ? new Date(currentTs * 1000).toLocaleString('de-DE') : '',
          exif: exifFallbackText || '',
        });
        if (fallbackItem) {
          timelineItemsLivebox.push(fallbackItem);
        }
      }
  
      const fallbackIndex = Math.max(0, timelineItemsLivebox.length - 1);
      const index = resolveLiveboxIndex({ currentUrl, currentTs, fallback: fallbackIndex });
      showLivebox(index);
    };
  
    const openLivebox = async () => {
      if (!livebox || !lbImg) return;
      resetLiveboxState();
      liveboxMode = 'timeline';
      const img = liveRoot?.querySelector('[data-live-image]');
      if (!(img instanceof HTMLImageElement)) return;
  
      const urlParams = new URLSearchParams(window.location.search);
      const bestofParam = urlParams.get('bestof');
      const cameraSlug = liveRoot?.dataset.cameraSlug || '';
      const currentTs = parseIntSafe(img.dataset.captureTimestamp || '');
      const currentUrl = normalizeAssetUrl(img.getAttribute('data-full-src') || img.currentSrc || img.src || '');
      exifFallbackText = '';
  
      if (bestofParam) {
        await openBestOfLivebox({ bestofParam, cameraSlug, currentUrl, currentTs });
        return;
      }
  
      await openTimelineLivebox({ currentUrl, currentTs });
    };
  
    const closeLivebox = () => {
      if (!livebox) return;
      liveboxImageRequestId += 1;
      controlsController?.setHelpOpen(false);
      livebox.classList.remove('is-open');
      livebox.classList.remove('is-loading');
      livebox.setAttribute('hidden', 'hidden');
      if (lbImg) {
        lbImg.src = '';
        lbImg.dataset.dx = '0';
        lbImg.dataset.dy = '0';
      }
    };
  
    // -----------------------------------------------------------------------
    // Maus-, Tastatur- und Zoombedienung
    // -----------------------------------------------------------------------
    // Zoom, Verschieben und Tastaturbelegung sind ein eigenständiger
    // Bediencontroller. Er kennt keine Timeline-Daten und meldet nur die
    // gewünschte Navigationsrichtung an diesen Koordinator zurück.
    const controlsFactory = window.WebcamCameraLightboxControls;
    if (!controlsFactory || typeof controlsFactory.create !== 'function') {
      throw new Error('Lightbox-Bedienung wurde nicht vor camera-lightbox.js geladen.');
    }
    controlsController = controlsFactory.create({
      lightbox: livebox,
      image: lbImg,
      closeButton: lbClose,
      previousButton: lbPrev,
      nextButton: lbNext,
      zoomButton: lbZoom,
      helpButton: lbHelp,
      helpPanel: lbHelpPanel,
      onClose: closeLivebox,
      onShift: shiftLightbox,
      onShiftDay: shiftTimelineDay,
      onJumpDayBoundary: jumpToDayBoundary,
      onZoomChanged: () => {
        const item = timelineItemsLivebox[timelineIndexLivebox] || null;
        updateLightboxMeta(item);
      },
    });
    const initLiveboxControls = () => controlsController.init();
  

    return Object.freeze({
      applyEntryOverlay,
      getTimezone: getLiveboxTimezone,
      parseDayKey,
      resolveSelectedDayKey,
      fetchTimelineForDay,
      ensureBestOfState,
      getBestofNavTarget,
      open: openLivebox,
      close: closeLivebox,
      initControls: initLiveboxControls,
      isOpen: () => Boolean(livebox?.classList.contains('is-open')),
    });
  };

  window.WebcamCameraLightbox = Object.freeze({ create });
})();
