(() => {
  'use strict';

  /**
   * Timeline-Datencontroller der Kameraansicht.
   *
   * Dieses Modul besitzt drei eng zusammengehörende Aufgaben:
   *
   * 1. Es lädt einen Archivtag über die bestehende Public-API und hält das
   *    Ergebnis nur für die Lebensdauer der geöffneten Browserseite bereit.
   * 2. Es übersetzt die API-Zeilen in ein einheitliches Browsermodell.
   * 3. Es berechnet daraus die Ziele für Bild-, Stunden-, Tages-, Monats- und
   *    Jahresnavigation.
   *
   * Es gibt bewusst keinen LocalStorage-, JSON- oder Servercache. Der kleine
   * Map-Cache verhindert lediglich parallele Mehrfachabfragen derselben Seite.
   * Die eigentliche HTML-Darstellung bleibt in camera.js; dort sitzen auch die
   * vorhandenen Picker, Hotspots, Overlays und Bild-Prefetches.
   */

  const WARM_CACHE_MAX_AGE_MS = 15000;
  const REQUEST_TIMEOUT_MS = 20000;

  const create = ({
    getLiveRoot,
    isFetchEnabled,
    parseIntSafe,
    getAppBasePath,
    resolveAppUrl,
    formatDateInTimezone,
    getCurrentSnapshotKey,
    normalizeNavMode,
    onPresentation,
  } = {}) => {
    const requiredFunctions = [
      getLiveRoot,
      isFetchEnabled,
      parseIntSafe,
      getAppBasePath,
      resolveAppUrl,
      formatDateInTimezone,
      getCurrentSnapshotKey,
      normalizeNavMode,
      onPresentation,
    ];
    if (requiredFunctions.some((candidate) => typeof candidate !== 'function')) {
      throw new Error('Timeline-Daten: notwendige Seitenadapter fehlen.');
    }

    // Ein Eintrag enthält entweder fertige Daten oder genau einen laufenden
    // Promise. Dadurch teilen Wetter, Umgebungskarte und Haupttimeline einen
    // bereits begonnenen Request, statt denselben Archivtag mehrfach zu laden.
    const dayCache = new Map();
    let lastTimelineData = null;
    let lastTimelineMeta = null;
    let activeRequestId = 0;
    let activeFetchController = null;
    let activeFetchTimer = null;

    const cacheKeyFor = (cameraSlug, year, month, day) => {
      if (!cameraSlug) {
        return '';
      }
      // Die Live-API kann den Tag aus ts ableiten. Deshalb ist auch ein
      // vorübergehender „auto“-Schlüssel gültig, solange der Server noch keine
      // expliziten Kalenderattribute gerendert hat.
      return [cameraSlug, year || 'auto', month || 'auto', day || 'auto'].join(':');
    };

    const matchesLastTimeline = (cameraSlug, year, month, day) => {
      return !!(
        lastTimelineData
        && lastTimelineMeta
        && lastTimelineMeta.camera === cameraSlug
        && lastTimelineMeta.year === year
        && lastTimelineMeta.month === month
        && lastTimelineMeta.day === day
      );
    };

    // URL-Aufbau und sämtliche Kalender-/Nachbarberechnungen sind DOM- und
    // netzwerkfrei im Timeline-Modell gebündelt. Dieser Controller behält nur
    // Request-, Cache- und Race-Zustand.
    const modelFactory = window.WebcamTimelineModel;
    if (!modelFactory || typeof modelFactory.create !== 'function') {
      throw new Error('Timeline-Modell wurde nicht vor camera-timeline-data.js geladen.');
    }
    const timelineModel = modelFactory.create({
      parseIntSafe,
      getAppBasePath,
      normalizeNavMode,
    });
    const buildCameraPath = timelineModel.buildCameraPath;
    const buildEntryUrl = timelineModel.buildEntryUrl;
    const present = (data, context) => onPresentation(timelineModel.buildPresentation(data, context));

    // ---------------------------------------------------------------------
    // Gemeinsamer Tagescache für Timeline, Wetter und Umgebungskarte.
    // ---------------------------------------------------------------------

    const getCachedDay = (cameraSlug, context, { preferCurrentView = false } = {}) => {
      if (!cameraSlug || !context) {
        return null;
      }
      if (preferCurrentView
        && matchesLastTimeline(cameraSlug, context.year, context.month, context.day)) {
        return lastTimelineData;
      }
      return dayCache.get(cacheKeyFor(cameraSlug, context.year, context.month, context.day))?.data || null;
    };

    const buildDayUrl = (cameraSlug, context) => {
      if (!cameraSlug || !context) {
        return null;
      }
      const root = getLiveRoot();
      const configured = root?.dataset.cameraSlug === cameraSlug
        ? root.dataset.timelineEndpoint
        : '';
      const endpoint = resolveAppUrl(
        configured || `api/public.php/cameras/${encodeURIComponent(cameraSlug)}/timeline`
      );
      if (!endpoint) {
        return null;
      }
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set('limit', '2000');
      url.searchParams.set('year', String(context.year));
      url.searchParams.set('month', String(context.month));
      url.searchParams.set('day', String(context.day));
      if (context.currentTs) {
        url.searchParams.set('ts', String(context.currentTs));
      }
      return url;
    };

    const loadDay = async (cameraSlug, context, {
      preferCurrentView = false,
      rememberAsCurrent = false,
    } = {}) => {
      const cachedData = getCachedDay(cameraSlug, context, { preferCurrentView });
      if (cachedData) {
        if (rememberAsCurrent) {
          lastTimelineData = cachedData;
          lastTimelineMeta = {
            camera: cameraSlug,
            year: context.year,
            month: context.month,
            day: context.day,
          };
        }
        return cachedData;
      }
      if (!context?.year || !context?.month || !context?.day) {
        return null;
      }
      const cacheKey = cacheKeyFor(cameraSlug, context?.year, context?.month, context?.day);
      const url = buildDayUrl(cameraSlug, context);
      if (!cacheKey || !url) {
        return null;
      }
      const cachedState = dayCache.get(cacheKey);
      if (cachedState?.promise) {
        return cachedState.promise;
      }

      let fetchPromise;
      fetchPromise = fetch(url.toString(), {
        headers: { Accept: 'application/json', 'X-WC-TEST': '1' },
        credentials: 'same-origin',
      })
        .then((response) => {
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          return response.json();
        })
        .then((data) => {
          dayCache.set(cacheKey, { data, fetchedAt: Date.now(), promise: null });
          if (rememberAsCurrent) {
            lastTimelineData = data;
            lastTimelineMeta = {
              camera: cameraSlug,
              year: context.year,
              month: context.month,
              day: context.day,
            };
          }
          return data;
        })
        .catch((error) => {
          if (dayCache.get(cacheKey)?.promise === fetchPromise) {
            dayCache.delete(cacheKey);
          }
          throw error;
        });
      dayCache.set(cacheKey, { data: null, fetchedAt: Date.now(), promise: fetchPromise });
      return fetchPromise;
    };

    // ---------------------------------------------------------------------
    // Haupttimeline: Request-Kontext, Abbruch und Race-Schutz.
    // ---------------------------------------------------------------------

    const clearActiveRequest = (timer = null, controller = null) => {
      if (activeFetchTimer && (timer === null || activeFetchTimer === timer)) {
        window.clearTimeout(activeFetchTimer);
        activeFetchTimer = null;
      }
      if (activeFetchController && (controller === null || activeFetchController === controller)) {
        activeFetchController = null;
      }
    };

    const cancel = () => {
      activeRequestId += 1;
      if (activeFetchController) {
        activeFetchController.abort();
      }
      clearActiveRequest();
    };

    const resolveRequestContext = () => {
      const root = getLiveRoot();
      if (!isFetchEnabled() || !root) {
        return null;
      }
      const cameraSlug = root.dataset.cameraSlug || '';
      const endpoint = resolveAppUrl(
        root.dataset.timelineEndpoint
        || (cameraSlug ? `api/public.php/cameras/${encodeURIComponent(cameraSlug)}/timeline` : '')
      );
      if (!endpoint || !cameraSlug) {
        return null;
      }

      const urlParams = new URLSearchParams(window.location.search);
      const urlTimestamp = parseIntSafe(urlParams.get('ts')) || null;
      const currentTs = parseIntSafe(getCurrentSnapshotKey())
        || parseIntSafe(root.dataset.lastTs)
        || Math.floor(Date.now() / 1000);
      let requestYear = parseIntSafe(urlParams.get('year'))
        || parseIntSafe(root.dataset.selectedYear)
        || null;
      let requestMonth = parseIntSafe(urlParams.get('month'))
        || parseIntSafe(root.dataset.selectedMonth)
        || null;
      let requestDay = parseIntSafe(urlParams.get('day'))
        || parseIntSafe(root.dataset.selectedDay)
        || null;

      // Manche Links enthalten nur ts. Für den API-Tagesfilter wird dieser
      // Zeitstempel in der ausdrücklich konfigurierten Kamera-Zeitzone zerlegt.
      if (urlTimestamp && (!requestYear || !requestMonth || !requestDay)) {
        const dayKey = formatDateInTimezone(
          urlTimestamp,
          root.dataset.timezone || 'Europe/Berlin'
        );
        const match = typeof dayKey === 'string'
          ? dayKey.match(/^(\d{4})-(\d{2})-(\d{2})$/)
          : null;
        if (match) {
          requestYear = parseInt(match[1], 10);
          requestMonth = parseInt(match[2], 10);
          requestDay = parseInt(match[3], 10);
        }
      }

      return {
        endpoint,
        cameraSlug,
        currentTs,
        urlParams,
        requestYear,
        requestMonth,
        requestDay,
        cacheKey: cacheKeyFor(cameraSlug, requestYear, requestMonth, requestDay),
      };
    };

    const rememberCurrentPayload = (context, data) => {
      dayCache.set(context.cacheKey, { data, fetchedAt: Date.now(), promise: null });
      lastTimelineData = data;
      lastTimelineMeta = {
        camera: context.cameraSlug,
        year: context.requestYear,
        month: context.requestMonth,
        day: context.requestDay,
      };
    };

    const applyWarmData = (context, requestId) => {
      if (matchesLastTimeline(
        context.cameraSlug,
        context.requestYear,
        context.requestMonth,
        context.requestDay
      )) {
        present(lastTimelineData, context);
      }

      const cached = dayCache.get(context.cacheKey);
      if (cached?.data && Date.now() - (cached.fetchedAt || 0) < WARM_CACHE_MAX_AGE_MS) {
        present(cached.data, context);
      }
      if (cached?.promise) {
        cached.promise
          .then((data) => {
            if (requestId === activeRequestId) present(data, context);
          })
          .catch(() => {});
        return true;
      }
      return false;
    };

    const renderFromApi = async () => {
      const context = resolveRequestContext();
      if (!context || !context.cacheKey) {
        return;
      }
      const requestId = ++activeRequestId;
      if (applyWarmData(context, requestId)) {
        return;
      }

      // Auch nach einem warmen Treffer wird einmal frisch abgefragt. Dadurch
      // verschwinden neue Captures, Kalenderjahre und Labels spätestens nach
      // diesem Lauf aus dem nur kurzlebigen Browserzustand.
      const url = new URL(context.endpoint, window.location.origin);
      url.searchParams.set('limit', '2000');
      if (context.currentTs) url.searchParams.set('ts', String(context.currentTs));
      if (context.requestYear) url.searchParams.set('year', String(context.requestYear));
      if (context.requestMonth) url.searchParams.set('month', String(context.requestMonth));
      if (context.requestDay) url.searchParams.set('day', String(context.requestDay));

      if (activeFetchController) {
        activeFetchController.abort();
      }
      clearActiveRequest();
      const controller = new AbortController();
      const timer = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
      activeFetchController = controller;
      activeFetchTimer = timer;

      const previousData = dayCache.get(context.cacheKey)?.data || null;
      const fetchPromise = fetch(url.toString(), {
        credentials: 'same-origin',
        signal: controller.signal,
      }).then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
      });
      dayCache.set(context.cacheKey, {
        data: previousData,
        fetchedAt: Date.now(),
        promise: fetchPromise,
      });

      try {
        const data = await fetchPromise;
        clearActiveRequest(timer, controller);
        rememberCurrentPayload(context, data);
        if (requestId === activeRequestId) {
          present(data, context);
        }
      } catch (error) {
        clearActiveRequest(timer, controller);
        if (dayCache.get(context.cacheKey)?.promise === fetchPromise) {
          if (previousData) {
            dayCache.set(context.cacheKey, {
              data: previousData,
              fetchedAt: 0,
              promise: null,
            });
          } else {
            dayCache.delete(context.cacheKey);
          }
        }
        if (requestId !== activeRequestId) {
          return;
        }
        // Ein abgebrochener oder fehlgeschlagener Refresh darf die bereits
        // servergerenderte Navigation nicht entfernen. Wenn vorhanden, wird
        // lediglich der letzte nachweislich vollständige Payload erneut gezeigt.
        const cachedData = dayCache.get(context.cacheKey)?.data || null;
        if (cachedData) {
          present(cachedData, context);
        } else if (matchesLastTimeline(
          context.cameraSlug,
          context.requestYear,
          context.requestMonth,
          context.requestDay
        )) {
          present(lastTimelineData, context);
        }
      }
    };

    const shouldHydrateOnLoad = () => {
      const root = getLiveRoot();
      if (!isFetchEnabled() || !root) {
        return false;
      }
      const container = root.querySelector('[data-timeline-container]');
      if (!container) {
        return true;
      }
      const hasServerControls = !!container.querySelector('.timeline-controls__group');
      const hasServerPickers = !!container.querySelector(
        '[data-time-picker-trigger],[data-year-picker-trigger],'
        + '[data-month-picker-trigger],[data-day-picker-trigger],[data-hour-picker-trigger]'
      );
      const selectedTs = parseIntSafe(root.dataset.selectedTs);
      const viewMode = root.dataset.viewMode || 'live';
      return !(hasServerControls
        && hasServerPickers
        && selectedTs
        && ['live', 'history', 'playback'].includes(viewMode));
    };

    return Object.freeze({
      buildCameraPath,
      buildEntryUrl,
      getCachedDay,
      loadDay,
      renderFromApi,
      shouldHydrateOnLoad,
      cancel,
    });
  };

  window.WebcamTimelineData = Object.freeze({ create });
})();
