(() => {
  // Leseführung für diese große Kamera-Datei:
  //
  // 1. Seitenzustand und kleine Format-/URL-Helfer
  // 2. Adapter zu Status, Bildlabels, Playback und Bildvergleich
  // 3. Adapter zu ausgelagerter Umgebungskarte und Wetterkomponente
  // 4. Timeline laden, normalisieren und servergerenderte Navigation ergänzen
  // 5. Lightbox, Best-Of und Navigation über Tagesgrenzen
  // 6. Adapter für Livestream, Livebild-Lebenszyklus und Wiederverbindung
  // 7. Captcha, History-Picker, Likes und partielle Seitennavigation
  //
  // Der Code liegt in einer geschlossenen Funktion, damit seine Variablen nicht
  // unbeabsichtigt als globale Browservariablen sichtbar werden. `liveRoot`
  // darf nach einem partiellen Seitenwechsel ersetzt werden; die übrigen Werte
  // gehören nur zum aktuellen vollständigen Dokument.
  let liveRoot = document.getElementById('camera-live-root');
  const historyRoot = document.querySelector('[data-history-root]');
  const navRoot = document;
  if (!liveRoot && !historyRoot) {
    return;
  }
  window.cameraImageReady = false;
  const getLiveRoot = () => document.getElementById('camera-live-root');
  const prefetchEnabled = liveRoot ? liveRoot.dataset.prefetchEnabled === '1' : false;
  const historyEndpoint = (liveRoot && liveRoot.dataset.historyEndpoint) || (historyRoot && historyRoot.dataset.historyEndpoint) || null;
  const historyRefTs = (liveRoot && liveRoot.dataset.historyRefTs) || (historyRoot && historyRoot.dataset.historyRefTs) || null;

  if ('scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
  }

  let hasInitialFocus = false;
  const TIMELINE_FETCH_ENABLED = liveRoot ? liveRoot.dataset.timelineFetch === '1' : false;
  let cameraListCache = null;
  let cameraListPromise = null;
  const RECENT_CAMERA_STORAGE_KEY = 'webcam:recent-cameras:v1';
  const RECENT_CAMERA_LIMIT = 8;

  function hideMapForPlayback() {
    const mode = liveRoot?.dataset.viewMode || '';
    if (mode && mode !== 'live') {
      document.querySelectorAll('.camera-map').forEach((el) => el.remove());
    }
  }

  // -------------------------------------------------------------------------
  // Allgemeine Anzeige- und Zeithelfer.
  // Diese Funktionen bereiten Daten nur für die Oberfläche auf und schreiben
  // weder Serverzustand noch Browser-Storage.
  // -------------------------------------------------------------------------
  const parseIntSafe = (value) => {
    const n = parseInt(value, 10);
    return Number.isFinite(n) ? n : null;
  };

  // Der Bildvergleich nutzt dieselbe menschenlesbare Zeitdarstellung wie der
  // Statusdialog. Der Statuscontroller besitzt bewusst eine eigene interne
  // Fassung, damit er nicht von der großen Hauptdatei abhängig bleibt.
  const formatDateTime = (isoValue) => {
    if (!isoValue) return '—';
    const date = new Date(isoValue);
    if (!Number.isFinite(date.getTime())) return '—';
    try {
      const formatted = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Berlin',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      }).format(date);
      return `${formatted.replace(',', '')} Uhr`;
    } catch {
      return `${date.toLocaleString('de-DE')} Uhr`;
    }
  };

  // Playback erhält nur den normalisierten Elementknoten eines Ereignisses.
  // Seine konkrete Tastatur- und Mauslogik bleibt im Fachcontroller.
  const getEventTargetElement = (event) => {
    if (event.target instanceof Element) return event.target;
    return event.target?.parentElement instanceof Element ? event.target.parentElement : null;
  };

  // Die Kameraliste wird von mehreren Features genutzt (Status-Modal,
  // Umgebungskarte, Querverweise). Darum zentral laden und im gleichen
  // Seitenaufruf cachen.
  const fetchCameraList = async ({ force = false } = {}) => {
    if (!force && cameraListCache) {
      return cameraListCache;
    }
    if (cameraListPromise) {
      return cameraListPromise;
    }
    const url = buildAppUrl('api/public.php/cameras');
    url.searchParams.set('per_page', '200');
    cameraListPromise = fetch(toRelativeUrl(url), {
      headers: { Accept: 'application/json', 'X-WC-TEST': '1' },
      credentials: 'same-origin',
    })
      .then((resp) => {
        if (!resp.ok) {
          throw new Error(`HTTP ${resp.status}`);
        }
        return resp.json();
      })
      .then(async (data) => {
        const list = Array.isArray(data?.cameras) ? data.cameras : [];
        // Eine direkt geoeffnete Testkamera fehlt absichtlich in der Liste.
        // Nur fuer ihre eigene Umgebungskarte die bekannte Kamera ergaenzen;
        // das oeffentliche Navigationsmenue laedt weiterhin ausschliesslich
        // die gelisteten Kameras (refreshCameraNavList).
        const slug = getCameraSlugFromUrl();
        if (slug && !list.some(camera => camera.slug === slug)) {
          const detailUrl = buildAppUrl(`api/public.php/cameras/${encodeURIComponent(slug)}`);
          const response = await fetch(toRelativeUrl(detailUrl), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
          });
          if (response.ok) {
            const detail = await response.json();
            if (detail?.camera?.slug === slug) list.push(detail.camera);
          }
        }
        cameraListCache = list;
        return list;
      })
      .catch((error) => {
        cameraListCache = null;
        throw error;
      })
      .finally(() => {
        cameraListPromise = null;
      });
    return cameraListPromise;
  };

  const getCameraSlugFromUrl = () => {
    const fromData = liveRoot?.dataset?.cameraSlug || '';
    if (fromData) return fromData;
    try {
      const url = new URL(window.location.href);
      return url.searchParams.get('cam') || '';
    } catch {
      return '';
    }
  };

  const getAppBasePath = () => {
    let path = window.location.pathname || '';
    path = path.replace(/\/+$/, '');
    if (!path || path === '/') {
      return '';
    }
    if (path.endsWith('/camera.php')) {
      path = path.slice(0, -'/camera.php'.length);
    } else if (/\/camera\/[^/]+$/.test(path)) {
      path = path.replace(/\/camera\/[^/]+$/, '');
    } else if (path.endsWith('/camera')) {
      path = path.slice(0, -'/camera'.length);
    } else {
      const slug = getCameraSlugFromUrl();
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
    }
    return path === '/' ? '' : path;
  };

  const buildAppPath = (pathLike) => {
    const cleaned = String(pathLike || '').replace(/^\/+/, '');
    const base = getAppBasePath();
    if (!cleaned) {
      return base || '/';
    }
    if (!base) {
      return `/${cleaned}`;
    }
    return `${base}/${cleaned}`;
  };

  const resolveAppUrl = (value) => {
    if (!value) return '';
    const str = String(value);
    if (/^(https?:)?\/\//i.test(str)) return str;
    if (str.startsWith('/')) return str;
    if (str.startsWith('.')) {
      try {
        return new URL(str, window.location.href).toString();
      } catch {
        // fall through to base-path resolution
      }
    }
    const cleaned = str.replace(/^\.?\/*/, '');
    return buildAppPath(cleaned);
  };

  const buildAppUrl = (pathLike) => new URL(buildAppPath(pathLike), window.location.origin);

  const toRelativeUrl = (url) => url.pathname + url.search + url.hash;

  // -------------------------------------------------------------------------
  // Kurzlebiger Snapshot-Prefetch und Browser-Bildcache.
  //
  // Netzwerkrequests, Cachegrenzen und Blob-URLs liegen vollständig in
  // camera-snapshot-prefetch.js. Die Adapter bleiben hier früh definiert,
  // weil auch Bildvergleich und Umgebungskarte den zentralen URL-Helfer und
  // den Schlüssel des aktuell sichtbaren Snapshots verwenden.
  // -------------------------------------------------------------------------
  let snapshotPrefetchController = null;
  const getSnapshotPrefetchController = () => {
    if (snapshotPrefetchController) return snapshotPrefetchController;
    const factory = window.WebcamCameraSnapshotPrefetch;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Snapshot-Prefetch-Modul wurde nicht geladen.');
    }
    snapshotPrefetchController = factory.create({
      getLiveRoot,
      isPrefetchEnabled: () => prefetchEnabled,
    });
    return snapshotPrefetchController;
  };

  const resolveUrl = (...args) => getSnapshotPrefetchController().resolveUrl(...args);
  const getCurrentSnapshotKey = () => getSnapshotPrefetchController().getCurrentSnapshotKey();
  const consumePrefetchedDocument = (...args) => (
    getSnapshotPrefetchController().consumePrefetchedDocument(...args)
  );
  const hydrateLiveImageFromCache = () => (
    getSnapshotPrefetchController().hydrateLiveImageFromCache()
  );
  const prefetchAdjacentSnapshots = () => (
    getSnapshotPrefetchController().prefetchAdjacentSnapshots()
  );

  // -------------------------------------------------------------------------
  // Beschriftungen und bereits eingebrannte Alt-Overlays.
  //
  // Normalisierung, sichere DOM-Erzeugung, Browserzustand und Resize-Logik
  // liegen in camera-labels.js. Diese Adapter halten die Aufrufer in Timeline,
  // Lightbox und Metadaten-Dialog unabhängig von der Modulimplementierung.
  // -------------------------------------------------------------------------
  let labelController = null;
  const getLabelController = () => {
    if (labelController) return labelController;
    const factory = window.WebcamCameraLabels;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Beschriftungsmodul konnte nicht geladen werden.');
    }
    labelController = factory.create({
      getLiveRoot,
      parseIntSafe,
      resolveAppUrl,
    });
    return labelController;
  };

  const initLiveLabels = () => getLabelController().init();
  const updateLiveLabels = (labels) => getLabelController().update(labels);
  const applyEmbeddedOverlayState = (entry = null) => (
    getLabelController().applyEmbeddedOverlayState(entry)
  );

  // Navigationsmodus und Picker-Lebenszyklus liegen im Timeline-Modul. Diese
  // schmalen Adapter halten die übrigen Funktionsblöcke unabhängig davon, wann
  // das Modul nach dem vollständigen Laden der Datei erstmals benötigt wird.
  let timelineUiController = null;
  const getTimelineUiController = () => {
    if (timelineUiController) {
      return timelineUiController;
    }
    const factory = window.WebcamTimeline;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Timeline-Modul konnte nicht geladen werden.');
    }
    timelineUiController = factory.create({
      getLiveRoot,
      navRoot,
      prefersReducedMotion: () => prefersReducedMotion(),
      toRelativeUrl,
      getLiveDisplayMode: () => getLiveDisplayMode(),
    });
    return timelineUiController;
  };

  const normalizeNavMode = (value) => getTimelineUiController().normalizeNavMode(value);
  const resolveNavMode = (params = null) => getTimelineUiController().resolveNavMode(params);
  const withNavMode = (href, navMode) => getTimelineUiController().withNavMode(href, navMode);
  const updateLiveModeBadge = (navMode) => getTimelineUiController().updateLiveModeBadge(navMode);
  const initTimelinePickers = () => getTimelineUiController().initPickers();
  const closeTransientTimelineUi = () => getTimelineUiController().closeTransientUi();

  const buildCameraMenuLink = (slug) => {
    const safeSlug = encodeURIComponent(slug || '');
    return buildAppPath(`camera/${safeSlug}`);
  };

  const refreshCameraNavList = () => {
    if (!liveRoot) return;
    const menuGroup = liveRoot.querySelector('.timeline-controls__group--menus');
    if (!menuGroup) return;
    const menus = menuGroup.querySelectorAll('.tl-menu');
    const overviewMenu = menus[0] || null;
    if (!overviewMenu) return;
    const dropdown = overviewMenu.querySelector('.tl-menu__dropdown');
    const toggle = overviewMenu.querySelector('.tl-menu__toggle');
    if (!dropdown || !toggle) return;
    if (dropdown.dataset.apiLoaded === '1') return;

    const load = async () => {
      if (dropdown.dataset.apiLoaded === '1') return;
      dropdown.dataset.apiLoaded = '1';
      try {
        const url = buildAppUrl('api/public.php/cameras');
        url.searchParams.set('per_page', '200');
        const resp = await fetch(toRelativeUrl(url), { headers: { Accept: 'application/json', 'X-WC-TEST': '1' } });
        if (!resp.ok) return;
        const payload = await resp.json();
        const list = Array.isArray(payload.cameras) ? payload.cameras : [];
        if (!list.length) return;
        const startLink = dropdown.querySelector('a');
        dropdown.innerHTML = '';
        if (startLink) {
          dropdown.appendChild(startLink.cloneNode(true));
        }
        list.forEach((cam) => {
          if (!cam || !cam.slug) return;
          const a = document.createElement('a');
          a.href = buildCameraMenuLink(cam.slug);
          a.textContent = cam.title || cam.slug;
          a.dataset.cameraSlug = cam.slug;
          a.dataset.noAjax = '1';
          dropdown.appendChild(a);
        });
      } catch {
        // ignore
      }
    };
    toggle.addEventListener('click', () => {
      load();
    }, { once: true });
  };

  const toAbsoluteUrl = (pathLike) => {
    if (!pathLike) return null;
    const str = String(pathLike);
    if (/^(https?:)?\/\//i.test(str)) return str;
    if (str.startsWith('/')) return str;
    if (/^(?:data|blob):/i.test(str)) return str;
    if (str.startsWith('.')) {
      try {
        return new URL(str, window.location.href).toString();
      } catch {
        // fall through to app-base resolution
      }
    }
    return buildAppPath(str);
  };

  const normalizeAssetUrl = (value) => {
    if (!value) return '';
    const str = String(value);
    const absolute = toAbsoluteUrl(str);
    return absolute || str;
  };

  // Timeline-Antworten können je nach Datenalter unterschiedliche Namen für
  // Vorschaubilder und Größenvarianten enthalten. Diese Auswahl wird sowohl
  // von der Umgebungskarte als auch von der ausgelagerten Wetterkomponente
  // verwendet und bleibt deshalb als gemeinsamer URL-Helfer hier.
  const resolveEntryPreviewUrl = (entry) => {
    if (!entry || typeof entry !== 'object') {
      return '';
    }
    const variants = entry.variants && typeof entry.variants === 'object' ? entry.variants : {};
    const candidateKeys = ['320', '400', 'sm', 'lm', 'hu', 'la', 'full', 'best'];
    const candidates = [
      entry.preview_url,
      entry.url,
      ...candidateKeys.map((key) => variants?.[key]?.url),
    ];
    for (let idx = 0; idx < candidates.length; idx += 1) {
      const normalized = normalizeAssetUrl(candidates[idx]);
      if (normalized) {
        return normalized;
      }
    }
    return '';
  };

  const formatDateInTimezone = (ts, tzName) => {
    const timeMs = Number.isFinite(ts) ? ts * 1000 : Date.now();
    const date = new Date(timeMs);
    if (Number.isNaN(date.getTime())) {
      return new Date().toISOString().slice(0, 10);
    }
    const tz = tzName && typeof tzName === 'string' ? tzName : null;
    if (tz && typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
      try {
        const fmt = new Intl.DateTimeFormat('en-CA', {
          timeZone: tz,
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
        });
        return fmt.format(date);
      } catch {
        // fall through to local format
      }
    }
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  // -------------------------------------------------------------------------
  // Adapter zu Timelapse und Tagesvideo.
  //
  // Framezustand, Wiedergabetimer und Tastatursteuerung liegen in
  // camera-playback.js. Asynchrone Dayvideo-Builds und die Inline-Umschaltung
  // liegen getrennt in camera-dayvideo.js. Der Hauptcontroller stellt beiden
  // lediglich die gemeinsamen URL-, Zeit- und DOM-Helfer bereit.
  // -------------------------------------------------------------------------
  let playbackController = null;
  const getPlaybackController = () => {
    if (playbackController) {
      return playbackController;
    }
    const factory = window.WebcamCameraPlayback;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Playback-Modul konnte nicht geladen werden.');
    }
    playbackController = factory.create({
      getLiveRoot,
      parseIntSafe,
      formatDateInTimezone,
      buildAppUrl,
      toRelativeUrl,
      toAbsoluteUrl,
      resolveAppUrl,
      normalizeAssetUrl,
      isLiveboxOpen: () => isLiveboxOpen(),
      getEventTargetElement,
    });
    return playbackController;
  };

  const initTimelapse = () => getPlaybackController().initTimelapse();
  const initDayvideoInline = () => getPlaybackController().initDayvideoInline();

  // -------------------------------------------------------------------------
  // Snapshot-URLs, Adapter zum ausgelagerten Bildvergleich und zuletzt
  // betrachtete Kameras. URL-Reparatur und Verlauf bleiben hier gemeinsam,
  // weil sie Deployment-Prefix und partielle Seitennavigation kennen müssen.
  // Storage und Split-/Zoom-Oberfläche liegen in camera-image-compare.js.
  // -------------------------------------------------------------------------
  const repairLegacySnapshotImageUrl = (value) => {
    if (!value) {
      return '';
    }
    const raw = String(value);
    const appBase = getAppBasePath();
    if (!appBase) {
      return raw;
    }
    const legacyPrefix = `${appBase}/../`;
    if (raw.startsWith(legacyPrefix)) {
      return `${appBase}/${raw.slice(legacyPrefix.length)}`;
    }
    const absolute = resolveUrl(raw, window.location.href);
    if (!absolute) {
      return raw;
    }
    try {
      const parsed = new URL(absolute);
      if (parsed.origin !== window.location.origin || !parsed.pathname.startsWith(legacyPrefix)) {
        return raw;
      }
      return `${parsed.origin}${appBase}/${parsed.pathname.slice(legacyPrefix.length)}${parsed.search}${parsed.hash}`;
    } catch {
      return raw;
    }
  };

  const resolveSnapshotImageUrl = (value, base = window.location.href) => {
    if (!value) {
      return '';
    }
    const repaired = repairLegacySnapshotImageUrl(value);
    return resolveUrl(repaired, base) || normalizeAssetUrl(repaired);
  };

  // Der ausgelagerte Controller besitzt Storage, Snapshot-Grenze und die
  // interaktive Split-Ansicht. Die Instanz wird erst beim ersten Gebrauch
  // erzeugt, weil resolveUrl weiter unten gemeinsam mit Timeline/Prefetch
  // definiert wird und nach partieller Navigation der aktuelle Live-Root gilt.
  let imageCompareController = null;
  const getImageCompareController = () => {
    if (imageCompareController) {
      return imageCompareController;
    }
    const factory = window.WebcamImageCompare;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Bildvergleichskomponente wurde nicht geladen.');
    }
    imageCompareController = factory.create({
      getLiveRoot: () => getLiveRoot() || liveRoot,
      parseIntSafe,
      formatDateTime,
      resolveSnapshotImageUrl,
    });
    return imageCompareController;
  };

  // Dieser Adapter wird auch von „Zuletzt angesehen“ und Umgebungskarte
  // benötigt. Dialogaufbau und übrige Vergleichsaktionen liegen vollständig
  // im ausgelagerten Metadaten-Modal.
  const buildCurrentImageCompareSnapshot = () => getImageCompareController().buildCurrentSnapshot();

  const normalizeRecentCameraViews = (raw) => {
    if (!Array.isArray(raw)) {
      return [];
    }
    const seen = new Set();
    const views = [];
    raw.forEach((entry) => {
      if (!entry || typeof entry !== 'object') {
        return;
      }
      const slug = typeof entry.slug === 'string' ? entry.slug.trim() : '';
      const viewedAt = parseIntSafe(entry.viewedAt);
      if (!slug || !Number.isFinite(viewedAt) || viewedAt <= 0) {
        return;
      }
      const href = typeof entry.href === 'string' && entry.href.trim()
        ? (resolveUrl(entry.href, window.location.href) || entry.href.trim())
        : '';
      const capturedAt = typeof entry.capturedAt === 'string' ? entry.capturedAt.trim() : '';
      const timestamp = parseIntSafe(entry.timestamp);
      const fingerprint = href || `${slug}:${Number.isFinite(timestamp) ? timestamp : viewedAt}`;
      if (seen.has(fingerprint)) {
        return;
      }
      seen.add(fingerprint);
      views.push({
        slug,
        viewedAt,
        href,
        capturedAt,
        timestamp: Number.isFinite(timestamp) ? timestamp : null,
      });
    });
    views.sort((a, b) => b.viewedAt - a.viewedAt);
    return views.slice(0, RECENT_CAMERA_LIMIT);
  };

  const readRecentCameraViews = () => {
    if (typeof window === 'undefined' || !window.localStorage) {
      return [];
    }
    try {
      return normalizeRecentCameraViews(JSON.parse(window.localStorage.getItem(RECENT_CAMERA_STORAGE_KEY) || '[]'));
    } catch {
      return [];
    }
  };

  const writeRecentCameraViews = (views) => {
    if (typeof window === 'undefined' || !window.localStorage) {
      return false;
    }
    try {
      window.localStorage.setItem(RECENT_CAMERA_STORAGE_KEY, JSON.stringify(normalizeRecentCameraViews(views)));
      return true;
    } catch {
      return false;
    }
  };

  // "Zuletzt angesehen" speichert die konkrete Seite und Aufnahmezeit, aber
  // absichtlich keine Bild-URL. Archivvarianten dürfen später aus dem schnellen
  // Cache verschwinden; eine dauerhaft gespeicherte URL würde dann auf der
  // Übersicht einen vermeidbaren 404 erzeugen. Dort stammt das Vorschaubild
  // deshalb stets aus der aktuellen, serverseitig geprüften Kamerakachel.
  const rememberCurrentCameraView = () => {
    const root = getLiveRoot() || liveRoot;
    if (!root) {
      return;
    }
    const slug = typeof root.dataset.cameraSlug === 'string' ? root.dataset.cameraSlug.trim() : '';
    if (!slug) {
      return;
    }
    const snapshot = buildCurrentImageCompareSnapshot();
    const viewedAt = Math.floor(Date.now() / 1000);
    const href = window.location.pathname + window.location.search + window.location.hash;
    const snapshotTs = snapshot?.timestamp || parseIntSafe(root.dataset.selectedTs) || parseIntSafe(root.dataset.historyRefTs) || null;
    const fingerprint = href || `${slug}:${snapshotTs || viewedAt}`;
    const views = readRecentCameraViews().filter((entry) => {
      const entryFingerprint = entry.href || `${entry.slug}:${entry.timestamp || entry.viewedAt || 0}`;
      return entryFingerprint !== fingerprint;
    });
    views.unshift({
      slug,
      viewedAt,
      href,
      capturedAt: snapshot?.capturedAt || '',
      timestamp: snapshotTs,
    });
    writeRecentCameraViews(views);
  };

  // Adapter zur ausgelagerten Umgebungskarten-Komponente.
  //
  // camera-surroundings.js besitzt Validierung, zeitliche Bildzuordnung,
  // Popups, Blickfelder und den Leaflet-Lebenszyklus. Der ausgelagerte
  // Timeline-Datencontroller stellt dafür denselben kurzlebigen Tageszustand
  // bereit, den auch Hauptnavigation und Wetterfenster verwenden.
  // -------------------------------------------------------------------------
  let surroundingsController = null;

  const loadSurroundingsTimelineDay = async (cameraSlug, context, { preferCurrentView = false } = {}) => {
    try {
      return await getTimelineDataController().loadDay(cameraSlug, context, { preferCurrentView });
    } catch (error) {
      console.warn(`surroundings timeline fetch failed for ${cameraSlug}`, error);
      return null;
    }
  };

  const resolveSurroundingsContext = () => {
    const root = getLiveRoot() || liveRoot;
    if (!root) {
      return null;
    }
    const cameraSlug = root.dataset.cameraSlug || '';
    const currentTs = parseIntSafe(getCurrentSnapshotKey())
      || parseIntSafe(root.dataset.selectedTs)
      || parseIntSafe(root.dataset.historyRefTs)
      || parseIntSafe(historyRefTs);
    const dayParts = parseDayKey(resolveSelectedDayKey(currentTs));
    if (!cameraSlug || !currentTs || !dayParts) {
      return null;
    }
    return {
      cameraSlug,
      currentTs,
      year: dayParts.year,
      month: dayParts.month,
      day: dayParts.day,
    };
  };

  const getSurroundingsController = () => {
    if (surroundingsController) {
      return surroundingsController;
    }
    const factory = window.WebcamCameraSurroundings;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Umgebungskarten-Komponente wurde nicht geladen.');
    }
    surroundingsController = factory.create({
      getContext: resolveSurroundingsContext,
      loadCameras: fetchCameraList,
      loadTimelineDay: loadSurroundingsTimelineDay,
      resolveCurrentSnapshot: () => buildCurrentImageCompareSnapshot(),
      resolvePreviewUrl: resolveEntryPreviewUrl,
      resolveAssetUrl: normalizeAssetUrl,
      buildEntryHref: buildEntryUrl,
      buildCameraHref: buildCameraMenuLink,
    });
    return surroundingsController;
  };

  // Modal-Schließpfade müssen den Leaflet-Zustand aufräumen können, ohne die
  // Implementierungsdetails der ausgelagerten Komponente zu kennen.
  const destroySurroundingsMap = () => {
    surroundingsController?.destroy();
  };


  // -------------------------------------------------------------------------
  // Adapter zur ausgelagerten Wetterkomponente.
  //
  // camera-weather-model.js übersetzt die Legacy-Texte in Messreihen;
  // camera-weather.js besitzt Tagescache und Darstellung. Diese Datei stellt
  // nur den ausgewählten Archivtag und die ohnehin vorhandene Timeline-
  // Ladefunktion bereit. Dadurch entstehen weder eine zweite Datenquelle noch
  // ein zusätzlicher serverseitiger Cache.
  // -------------------------------------------------------------------------
  let weatherModalController = null;

  const getWeatherModalController = () => {
    if (weatherModalController) {
      return weatherModalController;
    }

    const factory = window.WebcamCameraWeather;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Wetterkomponente wurde nicht geladen.');
    }

    weatherModalController = factory.create({
      resolvePreviewUrl: resolveEntryPreviewUrl,
      resolveContext: () => {
        const root = getLiveRoot() || liveRoot;
        if (!root) {
          return null;
        }

        const cameraSlug = root.dataset.cameraSlug || '';
        const currentTs = parseIntSafe(getCurrentSnapshotKey())
          || parseIntSafe(root.dataset.selectedTs)
          || parseIntSafe(root.dataset.historyRefTs)
          || Math.floor(Date.now() / 1000);
        const dayKey = resolveSelectedDayKey(currentTs);
        const parts = parseDayKey(dayKey);
        if (!cameraSlug || !parts) {
          return null;
        }

        const todayKey = formatDateInTimezone(
          Math.floor(Date.now() / 1000),
          getLiveboxTimezone()
        );
        return {
          cameraSlug,
          dayKey,
          year: parts.year,
          month: parts.month,
          day: parts.day,
          isCurrentDay: dayKey === todayKey,
        };
      },
      loadTimelineDay: async (context) => {
        return getTimelineDataController().loadDay(context.cameraSlug, context, {
          preferCurrentView: true,
          rememberAsCurrent: true,
        });
      },
    });

    return weatherModalController;
  };
  // -------------------------------------------------------------------------
  // Gemeinsamer Metadaten-Dialog.
  //
  // Fokus, Seitensperre, asynchrone Race-Prüfung und die Menüaktionen für
  // EXIF, Wetter, Umgebungskarte und Bildvergleich liegen im ausgelagerten
  // camera-meta-modal.js. Die Hauptdatei stellt nur die bereits vorhandenen
  // Fachcontroller und die Aufräumfunktionen der Kameraansicht bereit.
  // -------------------------------------------------------------------------
  let metaModalController = null;

  const getMetaModalController = () => {
    if (metaModalController) {
      return metaModalController;
    }
    const factory = window.WebcamCameraMetaModal;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Metadaten-Modal konnte nicht geladen werden.');
    }
    metaModalController = factory.create({
      getLiveRoot: () => getLiveRoot() || liveRoot,
      closeTransientTimelineUi,
      initTimelinePickers,
      initLiveLabels,
      getWeatherController: getWeatherModalController,
      getSurroundingsController,
      destroySurroundings: destroySurroundingsMap,
      getImageCompareController,
    });
    return metaModalController;
  };

  const initMetaModal = () => getMetaModalController().init();

  // -------------------------------------------------------------------------
  // Adapter zum ausgelagerten Status-/Telemetriedialog.
  // Das Modul lädt und formatiert die Public-API-Daten selbst. Durch den
  // dynamischen Root-Adapter findet es nach partieller Navigation immer Modal,
  // Fallbackinhalt und Menülink des aktuell sichtbaren Kamerablocks.
  // -------------------------------------------------------------------------
  let statusController = null;
  const getStatusController = () => {
    if (statusController) return statusController;
    const factory = window.WebcamCameraStatus;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Status-Modul konnte nicht geladen werden.');
    }
    statusController = factory.create({ getLiveRoot });
    return statusController;
  };

  const initStatusModal = () => getStatusController().init();

  // -------------------------------------------------------------------------
  // Timeline-Daten und Navigationsleiste.
  //
  // API-Abruf, kurzlebiger Tagescache, Normalisierung und Nachbarsuche liegen
  // in camera-timeline-data.js. Diese Datei rendert nur noch das dort gebaute
  // Präsentationsmodell in die bereits serverseitig vorhandenen Bedienelemente.
  // -------------------------------------------------------------------------
  let timelineDataController = null;
  const getTimelineDataController = () => {
    if (timelineDataController) {
      return timelineDataController;
    }
    const factory = window.WebcamTimelineData;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Timeline-Datenmodul wurde nicht geladen.');
    }
    timelineDataController = factory.create({
      getLiveRoot,
      isFetchEnabled: () => TIMELINE_FETCH_ENABLED,
      parseIntSafe,
      getAppBasePath,
      resolveAppUrl,
      formatDateInTimezone,
      getCurrentSnapshotKey,
      normalizeNavMode,
      onPresentation: (presentation) => applyTimelinePresentation(presentation),
    });
    return timelineDataController;
  };

  const buildCameraPath = (...args) => getTimelineDataController().buildCameraPath(...args);
  const buildEntryUrl = (...args) => getTimelineDataController().buildEntryUrl(...args);
  const renderTimelineFromApi = () => getTimelineDataController().renderFromApi();
  const shouldHydrateTimelineOnLoad = () => getTimelineDataController().shouldHydrateOnLoad();

  // DOM-Aufbau, Hotspots und Bild-Ladezustand liegen in
  // camera-timeline-view.js. Die Hauptdatei reicht nur die bereits vorhandenen
  // Seitenadapter hinein und behält keine zweite Darstellungsschicht.
  let timelineViewController = null;
  const getTimelineViewController = () => {
    if (timelineViewController) return timelineViewController;
    const factory = window.WebcamCameraTimelineView;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Timeline-Darstellungsmodul wurde nicht geladen.');
    }
    timelineViewController = factory.create({
      getLiveRoot,
      buildEntryUrl: (...args) => buildEntryUrl(...args),
      initTimelinePickers: () => initTimelinePickers(),
      resolveNavMode: (...args) => resolveNavMode(...args),
      updateLiveModeBadge: (...args) => updateLiveModeBadge(...args),
      applyEntryOverlay: (...args) => applyEntryOverlay(...args),
      updateLiveLabels: (...args) => updateLiveLabels(...args),
      prefetchAdjacentSnapshots: () => prefetchAdjacentSnapshots(),
    });
    return timelineViewController;
  };

  const applyTimelinePresentation = (presentation) => (
    getTimelineViewController().applyPresentation(presentation)
  );
  const startImageLoading = () => getTimelineViewController().startImageLoading();
  const finishImageLoading = (detail = {}) => (
    getTimelineViewController().finishImageLoading(detail)
  );
  // -------------------------------------------------------------------------
  // Adapter zur ausgelagerten Lightbox-/Best-of-Komponente.
  // Die übrige Kameraoberfläche kennt nur diese bewusst kleine Schnittstelle;
  // Tagesdaten und URL-Regeln bleiben die bereits vorhandenen Seitendienste.
  // -------------------------------------------------------------------------
  let lightboxController = null;
  const getLightboxController = () => {
    if (lightboxController) {
      return lightboxController;
    }
    const factory = window.WebcamCameraLightbox;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Lightbox-Modul konnte nicht geladen werden.');
    }
    lightboxController = factory.create({
      getLiveRoot,
      parseIntSafe,
      normalizeAssetUrl,
      applyEmbeddedOverlayState: (...args) => applyEmbeddedOverlayState(...args),
      formatDateInTimezone,
      resolveAppUrl,
      buildAppUrl,
      toRelativeUrl,
      buildCameraPath: (...args) => buildCameraPath(...args),
    });
    return lightboxController;
  };

  const applyEntryOverlay = (entry) => getLightboxController().applyEntryOverlay(entry);
  const getLiveboxTimezone = () => getLightboxController().getTimezone();
  const parseDayKey = (value) => getLightboxController().parseDayKey(value);
  const resolveSelectedDayKey = (fallbackTs = null) => getLightboxController().resolveSelectedDayKey(fallbackTs);
  const ensureBestOfState = (slug, filename = null) => getLightboxController().ensureBestOfState(slug, filename);
  const getBestofNavTarget = (direction) => getLightboxController().getBestofNavTarget(direction);
  const openLivebox = () => getLightboxController().open();
  const closeLivebox = () => getLightboxController().close();
  const initLiveboxControls = () => getLightboxController().initControls();
  const isLiveboxOpen = () => getLightboxController().isOpen();
  // -------------------------------------------------------------------------
  // Adapter zum ausgelagerten Stream-Koordinator.
  // camera-stream.js verbindet MJPEG, Snapshot und Fallback. Session-Auswahl
  // und HLS-Decoderzustand liegen davor in zwei eigenen kleinen Modulen;
  // camera.js reicht nur Timeline-Badge und Snapshot-Ladevorgang hinein.
  // -------------------------------------------------------------------------
  let streamController = null;
  const getStreamController = () => {
    if (streamController) {
      return streamController;
    }
    const factory = window.WebcamCameraStream;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Stream-Modul konnte nicht geladen werden.');
    }
    streamController = factory.create({
      getLiveRoot,
      resolveNavMode: (...args) => resolveNavMode(...args),
      updateLiveModeBadge: (...args) => updateLiveModeBadge(...args),
      startImageLoading: (...args) => startImageLoading(...args),
      finishImageLoading: (...args) => finishImageLoading(...args),
      initLiveImageLoad: () => initLiveImageLoad(),
    });
    return streamController;
  };

  const getLiveDisplayMode = () => getStreamController().getLiveDisplayMode();
  const initLiveDisplay = () => getStreamController().initLiveDisplay();
  const restoreLiveDisplayPreference = (displayMode) => (
    getStreamController().restoreLiveDisplayPreference(displayMode)
  );
  const stopLiveStreamForSnapshotNavigation = () => {
    window.WebcamUsbLive?.stop();
    return getStreamController().stopForSnapshotNavigation();
  };

  // -------------------------------------------------------------------------
  // Adapter zum ausgelagerten Hauptbild-Lebenszyklus.
  //
  // Placeholder, Vollbildtausch, Fokus, Lightbox-Klick und Info-Rückweg liegen
  // in camera-live-image.js. Die Hauptdatei reicht nur die bereits getrennten
  // Stream-, Timeline-, Prefetch- und Lightbox-Schnittstellen hinein.
  // -------------------------------------------------------------------------
  let liveImageController = null;
  const getLiveImageController = () => {
    if (liveImageController) return liveImageController;
    const factory = window.WebcamCameraLiveImage;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Livebild-Modul konnte nicht geladen werden.');
    }
    liveImageController = factory.create({
      getLiveRoot,
      restoreLiveDisplayPreference: (...args) => restoreLiveDisplayPreference(...args),
      startImageLoading: (...args) => startImageLoading(...args),
      finishImageLoading: (...args) => finishImageLoading(...args),
      prefetchAdjacentSnapshots: () => prefetchAdjacentSnapshots(),
      openLivebox: () => openLivebox(),
      buildCameraPath: (...args) => buildCameraPath(...args),
    });
    return liveImageController;
  };

  const initLiveImageLoad = () => getLiveImageController().initLoad();
  const initInfoExit = () => getLiveImageController().initInfoExit();
  const scheduleReload = () => getLiveImageController().scheduleReload();
  const focusLiveImage = (...args) => getLiveImageController().focus(...args);

  // -------------------------------------------------------------------------
  // Adapter zur Archivhistorie.
  //
  // Dialogzustand, HTTP-Abfragen und Listener liegen im Fachmodul. camera.js
  // stellt nur URL-Helfer und den jeweils aktuellen Live-Root bereit.
  // -------------------------------------------------------------------------
  let historyController = null;
  const getHistoryController = () => {
    if (historyController) {
      return historyController;
    }
    const factory = window.WebcamCameraHistory;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('History-Modul konnte nicht geladen werden.');
    }
    historyController = factory.create({
      getLiveRoot,
      getInitialHistoryEndpoint: () => historyEndpoint,
      resolveAppUrl,
      normalizeAssetUrl,
      withNavMode,
    });
    return historyController;
  };

  const initDayHistory = () => getHistoryController().initDayHistory();
  const initHistoryPanel = () => getHistoryController().initHistoryPanel();

  // -------------------------------------------------------------------------
  // Adapter zur ausgelagerten Like-/Best-of-Aktion.
  // Captcha, CSRF-Request, Herz-Zustand und Fehlerdialog liegen gemeinsam im
  // Fachmodul. Die bestehende Lightbox liefert nur den bestätigten Best-of-
  // Zustand; alle verbindlichen Prüfungen bleiben weiterhin im Backend.
  // -------------------------------------------------------------------------
  let bestOfLikeController = null;
  const getBestOfLikeController = () => {
    if (bestOfLikeController) return bestOfLikeController;
    const factory = window.WebcamCameraBestOfLike;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Best-of-Like-Modul konnte nicht geladen werden.');
    }
    bestOfLikeController = factory.create({
      getLiveRoot,
      buildAppPath,
      updateLiveModeBadge,
      ensureBestOfState: (...args) => ensureBestOfState(...args),
    });
    return bestOfLikeController;
  };

  const initBestOfToggle = () => getBestOfLikeController().init();

  // -------------------------------------------------------------------------
  // Adapter zur partiellen Navigation innerhalb derselben Kamera.
  //
  // Das Fachmodul besitzt Request-Abbruch, Root-Austausch, History und die
  // Maus-/Tastaturereignisse. Diese Datei beschreibt nur, welche bestehenden
  // Controller nach einem erfolgreichen DOM-Austausch wieder anlaufen müssen.
  // -------------------------------------------------------------------------
  const reinitializeAfterPartialNavigation = ({ targetUrl }) => {
    // Der Root-Knoten bleibt aktuell bestehen und erhält neue Attribute sowie
    // neues HTML. Er wird trotzdem neu aufgelöst, damit ein späterer Wechsel
    // auf `replaceWith()` keine versteckte Alt-Referenz erzeugt.
    liveRoot = getLiveRoot();
    if (!liveRoot) return;

    // Zuerst Bild und unmittelbar sichtbare Bedienung starten, anschließend
    // Dialoge, Labels und Playback-Funktionen an die neuen Elemente binden.
    initLiveDisplay();
    initLiveImageLoad();
    initBestOfToggle();
    initDayHistory();
    initLiveboxControls();
    initMetaModal();
    initStatusModal();
    initDayvideoInline();
    initTimelapse();
    initInfoExit();
    initLiveLabels();
    hideMapForPlayback();
    initTimelinePickers();

    // Die servergerenderte Navigation ist sofort benutzbar. Eine optionale
    // API-Hydrierung läuft bewusst erst in einer Leerlaufphase des Browsers.
    const kickTimeline = () => renderTimelineFromApi();
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(kickTimeline, { timeout: 2000 });
    } else {
      window.setTimeout(kickTimeline, 200);
    }
    scheduleReload();
    focusLiveImage({ smooth: false, force: true });
    window.requestAnimationFrame(() => focusLiveImage({ smooth: false, force: true }));
    prefetchAdjacentSnapshots();
    updateLiveModeBadge(resolveNavMode(new URLSearchParams(targetUrl.search)));
  };

  let partialNavigationController = null;
  const getPartialNavigationController = () => {
    if (partialNavigationController) return partialNavigationController;
    const factory = window.WebcamCameraPartialNavigation;
    if (!factory || typeof factory.create !== 'function') {
      throw new Error('Modul für partielle Navigation konnte nicht geladen werden.');
    }
    partialNavigationController = factory.create({
      getLiveRoot,
      isTimelineFetchEnabled: () => TIMELINE_FETCH_ENABLED,
      cancelTimelineRequest: () => getTimelineDataController().cancel(),
      consumePrefetchedDocument: (...args) => consumePrefetchedDocument(...args),
      startImageLoading: () => startImageLoading(),
      beforeSnapshotNavigation: () => stopLiveStreamForSnapshotNavigation(),
      hydrateLiveImageFromCache: () => hydrateLiveImageFromCache(),
      getBestOfNavTarget: (...args) => getBestofNavTarget(...args),
      onContentReplaced: reinitializeAfterPartialNavigation,
    });
    return partialNavigationController;
  };

  const initPartialNavigation = () => getPartialNavigationController().init();

  // -------------------------------------------------------------------------
  // Abschließende Initialisierung der Seitenmodule.
  // -------------------------------------------------------------------------
  const initialise = () => {
    // History-only view (no liveRoot)
    if (!liveRoot) {
      initTimelinePickers();
      initHistoryPanel();
      return;
    }

    initPartialNavigation();
    rememberCurrentCameraView();
    initTimelinePickers();
    initDayHistory();
    initInfoExit();
    initLiveLabels();
    initHistoryPanel();
    initMetaModal();
    initStatusModal();
    refreshCameraNavList();
    initDayvideoInline();
    initBestOfToggle();
    initLiveboxControls();
    initLiveDisplay();
    initLiveImageLoad();
    updateLiveModeBadge(resolveNavMode());
    initTimelapse();
    if (shouldHydrateTimelineOnLoad()) {
      renderTimelineFromApi();
    }
    hideMapForPlayback();
    if (!history.state || !history.state.cameraAjax) {
      history.replaceState({ cameraAjax: true }, '', window.location.href);
    }
    if (!hasInitialFocus) {
      hasInitialFocus = true;
      const triggerFocus = () => {
        if (window.scrollY > 10) {
          return;
        }
        focusLiveImage({ smooth: false, force: true });
      };
      window.requestAnimationFrame(() => {
        triggerFocus();
        window.requestAnimationFrame(triggerFocus);
      });
    }
    scheduleReload();
    const isArchive = window.location.search.match(/year=\d{4}/);
    if (!isArchive && prefetchEnabled) {
      prefetchAdjacentSnapshots();
    }
  };

  initialise();
  window.addEventListener('camera:image-ready', rememberCurrentCameraView);

  window.addEventListener('load', () => {
    if (hasInitialFocus && window.scrollY > 10) {
      return;
    }
    focusLiveImage({ smooth: false, force: true });
    setTimeout(() => {
      if (window.scrollY <= 10) {
        focusLiveImage({ smooth: false, force: true });
      }
    }, 150);
  });
})();
