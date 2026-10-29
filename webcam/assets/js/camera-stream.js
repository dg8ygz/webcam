(() => {
  'use strict';

  /**
   * Erzeugt den Stream-Controller für die aktuell gerenderte Kameraansicht.
   *
   * Der Controller besitzt ausschließlich kurzlebigen Browserzustand:
   * Reconnect-Timer, Frame-Überwachung und eine optionale hls.js-Instanz.
   * Kamera-, Viewer- und Berechtigungsdaten bleiben bei den vorhandenen
   * Serverendpunkten; dieses Modul führt keine zusätzliche Datenquelle ein.
   */
  const create = ({
    getLiveRoot,
    resolveNavMode,
    updateLiveModeBadge,
    startImageLoading,
    finishImageLoading,
    initLiveImageLoad,
  } = {}) => {
    const dependencies = [
      getLiveRoot,
      resolveNavMode,
      updateLiveModeBadge,
      startImageLoading,
      finishImageLoading,
      initLiveImageLoad,
    ];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new Error('Stream-Controller: notwendige Seitenadapter fehlen.');
    }

    // -----------------------------------------------------------------------
    // Konfiguration, Laufzeitzustand und feste Schutzgrenzen
    // -----------------------------------------------------------------------
    const getStreamConfig = () => {
      const root = getLiveRoot();
      if (!root) {
        return { enabled: false, endpoint: '', hlsEndpoint: '', mode: '', preference: '', preferHls: false };
      }
      const enabled = root.dataset.streamEnabled === '1';
      const endpoint = root.dataset.streamEndpoint || '';
      const hlsEndpoint = root.dataset.streamHlsEndpoint || '';
      const mode = root.dataset.streamMode || '';
      const preference = root.dataset.streamPreference || '';
      const maxRuntimeSeconds = Number.parseInt(root.dataset.streamMaxRuntimeSeconds || '0', 10) || 0;
      return {
        enabled: enabled && (!!endpoint || !!hlsEndpoint),
        endpoint,
        hlsEndpoint,
        mode,
        preference,
        maxRuntimeSeconds,
        preferHls: preference === 'hls' || mode === 'hls' || (!endpoint && !!hlsEndpoint),
      };
    };

    let streamReconnectTimer = null;
    let lastStreamReconnectAt = 0;
    let streamFirstFrameTimer = null;
    let streamNoFrameFailures = 0;
    let streamHadFrame = false;
    let streamFallbackActive = false;
    let streamFailureProbeRunning = false;
    let hlsFallbackTried = false;
    let hlsController = null;
    const STREAM_FIRST_FRAME_TIMEOUT_MS = 12000;
    const STREAM_AVAILABILITY_TIMEOUT_MS = 5000;
    const STREAM_MAX_NO_FRAME_FAILURES = 3;
    const STREAM_DEFAULT_RECONNECT_MS = 25 * 60 * 1000;
    const STREAM_MIN_RECONNECT_MS = 60 * 1000;
    const STREAM_RUNTIME_GRACE_MS = 60 * 1000;
    const STREAM_RECONNECT_SETTLE_MS = 1000;
    const STREAM_CLIENT_LIMIT_RETRY_MS = 10000;
    const STREAM_DISCONNECTED_MESSAGE = 'Verbindung getrennt';
    const isStreamDebug = () => window.location.search.includes('streamdebug=1') || window.location.search.includes('debug_stream=1');

    // -----------------------------------------------------------------------
    // Endpunktaufbereitung und belegungsfreie Verfügbarkeitsprüfung
    // -----------------------------------------------------------------------
    // `probe=1` fragt nur den Serverstatus ab und darf selbst keinen
    // MJPEG-Viewer-Slot belegen. 403 bedeutet einen kontrollierten Admin-Stopp;
    // 503 bedeutet ein belegtes Clientlimit mit späterem Wiederholungsversuch.
    // Andere Antworten lassen den echten Streamversuch entscheiden.
    const getStreamBaseSrc = () => {
      const root = getLiveRoot();
      if (!root) return '';
      const streamImg = root.querySelector('[data-live-stream]');
      if (!streamImg) return '';
      return streamImg.dataset.streamBase || streamImg.dataset.streamSrc || streamImg.getAttribute('src') || '';
    };

    const getHlsVideo = () => {
      const root = getLiveRoot();
      return root?.querySelector('[data-live-hls]') || null;
    };

    const buildStreamSrc = (base) => {
      if (!base) return '';
      const sep = base.includes('?') ? '&' : '?';
      return `${base}${sep}cb=${Date.now()}`;
    };

    const buildStreamProbeUrl = (base) => {
      if (!base) return '';
      const url = new URL(base, window.location.href);
      url.searchParams.set('probe', '1');
      return url.toString();
    };

    const probeStreamAvailability = async (base) => {
      const probeUrl = buildStreamProbeUrl(base);
      if (!probeUrl) {
        return { available: true };
      }
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort('stream-probe-timeout'), STREAM_AVAILABILITY_TIMEOUT_MS);
      try {
        const response = await fetch(probeUrl, {
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
        });
        if (response.status !== 503 && response.status !== 403) {
          return { available: true, status: response.status };
        }
        const retryAfterHeader = Number.parseInt(response.headers.get('Retry-After') || '', 10);
        let data = null;
        try {
          data = await response.json();
        } catch {
          data = null;
        }
        return {
          available: false,
          status: response.status,
          retryAfterSeconds: Number.isFinite(retryAfterHeader) && retryAfterHeader > 0 ? retryAfterHeader : null,
          message: data?.message || (response.status === 403 ? STREAM_DISCONNECTED_MESSAGE : 'Livestream ist derzeit ausgelastet.'),
        };
      } catch (error) {
        return { available: true, status: 0, error };
      } finally {
        window.clearTimeout(timeout);
      }
    };

    // -----------------------------------------------------------------------
    // Ressourcen sicher freigeben und sichtbaren Status pflegen
    // -----------------------------------------------------------------------
    // Beim Rendererwechsel müssen sowohl das img-src als auch hls.js und das
    // Videoelement beendet werden. Andernfalls blieben serverseitige Viewer
    // oder Browser-Decoder trotz sichtbarem Snapshot weiter aktiv.
    const stopStreamNoFrameWatch = () => {
      if (streamFirstFrameTimer) {
        window.clearTimeout(streamFirstFrameTimer);
        streamFirstFrameTimer = null;
      }
    };

    const resetStreamNoFrameState = () => {
      streamHadFrame = false;
      streamNoFrameFailures = 0;
      stopStreamNoFrameWatch();
    };

    const stopHlsStream = () => hlsController?.stop();

    const stopMjpegStream = () => {
      const root = getLiveRoot();
      const streamImg = root?.querySelector('[data-live-stream]');
      if (streamImg) {
        streamImg.removeAttribute('src');
      }
    };

    const getStreamStatusElement = () => {
      const root = getLiveRoot();
      return root?.querySelector('[data-stream-status]') || null;
    };

    const setStreamStatus = (message) => {
      const status = getStreamStatusElement();
      if (!status) return;
      const text = String(message || '').trim();
      status.textContent = text;
      status.hidden = text === '';
    };

    const getStreamStatusText = () => {
      const status = getStreamStatusElement();
      return status ? String(status.textContent || '').trim() : '';
    };

    const clearStreamStatus = () => {
      setStreamStatus('');
    };

    const markStreamDisconnected = () => {
      setStreamStatus(STREAM_DISCONNECTED_MESSAGE);
    };

    const isControlledStreamStop = (availability) => {
      return !availability?.available && availability.status === 403;
    };

    const isStreamClientLimit = (availability) => {
      return !availability?.available && availability.status === 503;
    };

    const stopStreamAfterDeniedProbe = (availability) => {
      streamFallbackActive = true;
      setStreamStatus(availability?.message || STREAM_DISCONNECTED_MESSAGE);
      setLiveDisplayMode('snapshot', { persist: false, fallback: true });
    };

    const scheduleStreamClientLimitRetry = (availability) => {
      const retryMs = availability?.retryAfterSeconds
        ? Math.max(1000, availability.retryAfterSeconds * 1000)
        : STREAM_CLIENT_LIMIT_RETRY_MS;
      const retrySeconds = Math.max(1, Math.round(retryMs / 1000));
      streamFallbackActive = true;
      stopStreamReconnect();
      resetStreamNoFrameState();
      stopMjpegStream();
      stopHlsStream();
      setStreamStatus(`${availability?.message || 'Livestream ist derzeit ausgelastet.'} Neuer Versuch in ${retrySeconds}s…`);
      streamReconnectTimer = window.setTimeout(() => {
        streamReconnectTimer = null;
        if (!isStreamActive()) {
          return;
        }
      void reconnectStreamAsync('client-limit');
      }, retryMs);
    };

    // -----------------------------------------------------------------------
    // Erstes Bild und Hänger ohne verwertbaren Frame erkennen
    // -----------------------------------------------------------------------
    // Ein erfolgreich gesetztes <img src> beweist bei MJPEG noch nicht, dass
    // Frames ankommen. Der Watchdog wartet deshalb auf ein echtes Load-Signal
    // beziehungsweise naturalWidth und stößt höchstens die dokumentierte
    // begrenzte Fehlerkette an.
    const onStreamFrame = () => {
      if (streamHadFrame) {
        return;
      }
      streamHadFrame = true;
      streamNoFrameFailures = 0;
      stopStreamNoFrameWatch();
      clearStreamStatus();
      if (isStreamDebug() && typeof console !== 'undefined' && console.info) {
        const root = getLiveRoot();
        console.info('[webcam] stream frame received', {
          slug: root?.dataset?.cameraSlug || '',
        });
      }
    };

    const scheduleStreamNoFrameWatch = (reason) => {
      if (!isStreamActive()) {
        stopStreamNoFrameWatch();
        return;
      }
      stopStreamNoFrameWatch();
      streamHadFrame = false;
      streamFirstFrameTimer = window.setTimeout(() => {
        streamFirstFrameTimer = null;
        if (!isStreamActive() || streamHadFrame) {
          return;
        }
        const root = getLiveRoot();
        const streamImg = root?.querySelector('[data-live-stream]');
        if (streamImg && streamImg.naturalWidth > 0) {
          onStreamFrame();
          return;
        }
        streamNoFrameFailures += 1;
        if (typeof console !== 'undefined' && console.error) {
          console.error('[webcam] stream timeout (no frames)', {
            slug: root?.dataset?.cameraSlug || '',
            reason,
            attempt: streamNoFrameFailures,
          });
        }
        if (streamNoFrameFailures >= STREAM_MAX_NO_FRAME_FAILURES) {
          markStreamDisconnected();
          handleMjpegStreamFailure('timeout');
          return;
        }
        markStreamDisconnected();
        handleMjpegStreamFailure('timeout');
      }, STREAM_FIRST_FRAME_TIMEOUT_MS);
    };

    const markStreamLoaded = () => {
      const root = getLiveRoot();
      const container = root?.querySelector('.live-image');
      if (!container) return;
      container.classList.add('is-loaded');
      container.classList.remove('is-placeholder');
      finishImageLoading({ stream: true });
    };

    // -----------------------------------------------------------------------
    // HLS-Renderer anbinden
    // -----------------------------------------------------------------------
    // Decoderzustand, Bereitschafts-Timeout und hls.js-Fehler leben im kleinen
    // Renderer-Modul. Dieser Koordinator entscheidet weiterhin, wann HLS
    // gewählt wird und ob anschließend auf Snapshot zurückgefallen werden darf.
    const hlsFactory = window.WebcamCameraStreamHls;
    if (!hlsFactory || typeof hlsFactory.create !== 'function') {
      throw new Error('HLS-Renderer wurde nicht vor camera-stream.js geladen.');
    }
    hlsController = hlsFactory.create({
      getLiveRoot,
      getVideo: getHlsVideo,
      getEndpoint: () => getStreamConfig().hlsEndpoint,
      buildSource: buildStreamSrc,
      probeAvailability: probeStreamAvailability,
      beforeStart: (container) => {
        stopStreamReconnect();
        resetStreamNoFrameState();
        stopMjpegStream();
        container.dataset.streamRenderer = 'hls';
        container.dataset.liveDisplay = 'stream';
        updateLiveDisplayMenu('stream');
        updateLiveModeBadge(resolveNavMode());
        container.classList.remove('is-loaded');
        container.classList.add('is-placeholder');
        startImageLoading();
      },
      onReady: (reason) => {
        streamFallbackActive = reason !== 'start';
        clearStreamStatus();
        markStreamLoaded();
      },
      onControlledStop: stopStreamAfterDeniedProbe,
      onFatal: (reason) => activateStreamFallback(reason),
      setStatus: setStreamStatus,
      firstFrameTimeoutMs: 30000,
    });
    const startHlsStream = (reason = 'start') => hlsController.start(reason);

    // -----------------------------------------------------------------------
    // Geplanter MJPEG-Neuaufbau und Laufzeitgrenze
    // -----------------------------------------------------------------------
    // Manche Kameras beziehungsweise Proxys beenden lange Antworten. Der
    // Timer baut die Verbindung kurz vor der konfigurierten Höchstlaufzeit
    // kontrolliert neu auf; eine Mindestpause verhindert Reconnect-Schleifen.
    const isStreamActive = () => {
      const root = getLiveRoot();
      if (!root) return false;
      const container = root.querySelector('.live-image');
      if (!container || (container.dataset.liveDisplay || 'snapshot') !== 'stream') {
        return false;
      }
      const { enabled } = getStreamConfig();
      return enabled;
    };

    const stopStreamReconnect = () => {
      if (streamReconnectTimer) {
        window.clearTimeout(streamReconnectTimer);
        streamReconnectTimer = null;
      }
    };

    const streamReconnectDelayMs = () => {
      const { maxRuntimeSeconds } = getStreamConfig();
      const runtimeMs = maxRuntimeSeconds > 0 ? maxRuntimeSeconds * 1000 : 0;
      if (runtimeMs > STREAM_MIN_RECONNECT_MS + STREAM_RUNTIME_GRACE_MS) {
        return Math.min(STREAM_DEFAULT_RECONNECT_MS, runtimeMs - STREAM_RUNTIME_GRACE_MS);
      }
      if (runtimeMs > 0) {
        return Math.max(10000, Math.floor(runtimeMs * 0.8));
      }
      return STREAM_DEFAULT_RECONNECT_MS;
    };

    const scheduleStreamReconnect = () => {
      if (!isStreamActive()) {
        stopStreamReconnect();
        return;
      }
      stopStreamReconnect();
      streamReconnectTimer = window.setTimeout(() => {
        streamReconnectTimer = null;
        if (!isStreamActive()) {
          return;
        }
        reconnectStream('timer');
      }, streamReconnectDelayMs());
    };

    const reconnectStream = (reason) => {
      void reconnectStreamAsync(reason);
    };

    const waitMs = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    const reconnectStreamAsync = async (reason) => {
      const root = getLiveRoot();
      if (!root) return;
      const container = root.querySelector('.live-image');
      if (!container || (container.dataset.liveDisplay || 'snapshot') !== 'stream') return;
      if (container.classList.contains('is-dayvideo')) return;
      const now = Date.now();
      if (now - lastStreamReconnectAt < 5000) {
        return;
      }
      lastStreamReconnectAt = now;
      const streamImg = container.querySelector('[data-live-stream]');
      if (!streamImg) return;
      const base = getStreamBaseSrc();
      if (!base) return;
      if (reason === 'timer' && streamImg.getAttribute('src')) {
        streamImg.removeAttribute('src');
        await waitMs(STREAM_RECONNECT_SETTLE_MS);
        if (!isStreamActive()) {
          return;
        }
      }
      const availability = await probeStreamAvailability(base);
      if (!availability.available) {
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[webcam] stream unavailable', {
            slug: root.dataset.cameraSlug || '',
            base,
            reason,
            message: availability.message || '',
          });
        }
        if (isStreamClientLimit(availability)) {
          scheduleStreamClientLimitRetry(availability);
          return;
        }
        streamFallbackActive = true;
        setStreamStatus(availability.message || STREAM_DISCONNECTED_MESSAGE);
        setLiveDisplayMode('snapshot', { persist: false, fallback: true });
        return;
      }
      if (reason === 'error' && typeof console !== 'undefined' && console.error) {
        console.error('[webcam] stream error → reconnect', {
          slug: root.dataset.cameraSlug || '',
          base,
          src: streamImg.getAttribute('src') || '',
        });
      } else if (isStreamDebug() && typeof console !== 'undefined' && console.warn) {
        console.warn('[webcam] stream reconnect', {
          reason,
          slug: root.dataset.cameraSlug || '',
          base,
        });
      }
      streamImg.dataset.streamBase = base;
      streamHadFrame = false;
      streamImg.setAttribute('src', buildStreamSrc(base));
      scheduleStreamNoFrameWatch(reason);
      scheduleStreamReconnect();
    };

    // -----------------------------------------------------------------------
    // Fehlerklassifizierung und fest definierte Fallback-Reihenfolge
    // -----------------------------------------------------------------------
    // Technischer MJPEG-Fehler: erneut prüfen, begrenzt reconnecten, einmal HLS
    // versuchen und erst dann Snapshot. Kontrollierte 403- und 503-Antworten
    // bleiben dagegen sichtbar und werden nicht als technischer HLS-Anlass
    // missverstanden.
    const handleMjpegStreamFailure = (reason) => {
      if (streamFailureProbeRunning) {
        return;
      }
      streamFailureProbeRunning = true;
      void handleMjpegStreamFailureAsync(reason).finally(() => {
        streamFailureProbeRunning = false;
      });
    };

    const handleMjpegStreamFailureAsync = async (reason) => {
      if (!isStreamActive()) {
        return;
      }
      const root = getLiveRoot();
      const container = root?.querySelector('.live-image');
      if (!container || (container.dataset.streamRenderer || 'mjpeg') === 'hls') {
        return;
      }
      const base = getStreamBaseSrc();
      if (base) {
        const availability = await probeStreamAvailability(base);
        if (!availability.available) {
          if (isStreamClientLimit(availability)) {
            scheduleStreamClientLimitRetry(availability);
            return;
          }
          stopStreamAfterDeniedProbe(availability);
          return;
        }
      }
      if (streamNoFrameFailures >= STREAM_MAX_NO_FRAME_FAILURES) {
        markStreamDisconnected();
        activateStreamFallback(reason);
        return;
      }
      markStreamDisconnected();
      reconnectStream(reason);
    };

    const activateStreamFallback = (reason) => {
      void activateStreamFallbackAsync(reason);
    };

    const activateStreamFallbackAsync = async (reason) => {
      if (!isStreamActive()) {
        return;
      }
      const root = getLiveRoot();
      const { hlsEndpoint } = getStreamConfig();
      if (hlsEndpoint && !hlsFallbackTried) {
        hlsFallbackTried = true;
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[webcam] stream fallback to hls', {
            slug: root?.dataset?.cameraSlug || '',
            reason,
          });
        }
        setStreamStatus('MJPEG-Livestream hängt, HLS-Fallback wird gestartet…');
        if (await startHlsStream(`fallback:${reason}`) !== false) {
          return;
        }
        if (!isStreamActive()) {
          return;
        }
      }
      streamFallbackActive = true;
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[webcam] stream fallback to snapshot', {
          slug: root?.dataset?.cameraSlug || '',
          reason,
        });
      }
      setStreamStatus(hlsFallbackTried && getStreamStatusText()
        ? `${getStreamStatusText()} Snapshot aktiv. Erneut starten über Optionen → Livestream.`
        : 'Livestream nicht verfügbar, Snapshot aktiv.');
      setLiveDisplayMode('snapshot', { persist: false, fallback: true });
    };

    const onStreamError = () => {
      if (!isStreamActive()) {
        return;
      }
      markStreamDisconnected();
      streamNoFrameFailures += 1;
      handleMjpegStreamFailure('error');
    };

    // -----------------------------------------------------------------------
    // Gewählter Anzeigemodus und kurzlebige Session-Präferenz
    // -----------------------------------------------------------------------
    // Die Auswahl gilt pro Kameraslug und nur für die Browsersitzung. Sie ist
    // Komfortzustand, keine Serverkonfiguration und kein persistenter Cache.
    const preferenceFactory = window.WebcamCameraStreamPreference;
    if (!preferenceFactory || typeof preferenceFactory.create !== 'function') {
      throw new Error('Stream-Präferenz wurde nicht vor camera-stream.js geladen.');
    }
    const preferenceController = preferenceFactory.create({
      getLiveRoot,
      getConfig: getStreamConfig,
      resolveNavMode,
      updateLiveModeBadge,
    });
    const writeLiveDisplayPreference = preferenceController.write;
    const getLiveDisplayMode = preferenceController.current;
    const updateLiveDisplayMenu = preferenceController.updateMenu;

    const setLiveDisplayMode = async (mode, options = {}) => {
      const root = getLiveRoot();
      if (!root) return;
      const container = root.querySelector('.live-image');
      if (!container) return;
      const { enabled, endpoint, hlsEndpoint, preferHls } = getStreamConfig();
      const streamImg = container.querySelector('[data-live-stream]');
      const snapshotImg = container.querySelector('[data-live-image]');
      const hlsVideo = container.querySelector('[data-live-hls]');
      const useStream = enabled && mode === 'stream' && (streamImg || hlsVideo);
      const nextMode = useStream ? 'stream' : 'snapshot';
      container.dataset.liveDisplay = nextMode;
      updateLiveDisplayMenu(nextMode);
      if (options.persist !== false) {
        writeLiveDisplayPreference(nextMode);
      }
      updateLiveModeBadge(resolveNavMode());
      if (useStream) {
        if ((preferHls || !streamImg) && hlsEndpoint) {
          hlsFallbackTried = true;
          if (await startHlsStream('start') !== false) {
            return;
          }
          if ((container.dataset.liveDisplay || 'snapshot') !== 'stream') {
            return;
          }
          streamFallbackActive = true;
          setStreamStatus(`${getStreamStatusText() || 'Video-Livestream nicht verfügbar.'} Snapshot aktiv. Erneut starten über Optionen → Livestream.`);
          setLiveDisplayMode('snapshot', { persist: false, fallback: true });
          return;
        }
        stopHlsStream();
        container.dataset.streamRenderer = 'mjpeg';
        const streamSrc = streamImg.dataset.streamSrc || endpoint;
        if (streamSrc) {
          streamImg.dataset.streamBase = streamSrc;
        }
        if (streamImg.getAttribute('src')) {
          streamImg.removeAttribute('src');
          await waitMs(STREAM_RECONNECT_SETTLE_MS);
          if ((container.dataset.liveDisplay || 'snapshot') !== 'stream') {
            return;
          }
        }
        const availability = await probeStreamAvailability(streamSrc || endpoint);
        if (!availability.available) {
          if (isStreamClientLimit(availability)) {
            scheduleStreamClientLimitRetry(availability);
            return;
          }
          if (isControlledStreamStop(availability)) {
            stopStreamAfterDeniedProbe(availability);
            return;
          }
          if (hlsEndpoint && !hlsFallbackTried) {
            hlsFallbackTried = true;
            setStreamStatus((availability.message || 'MJPEG-Livestream nicht verfügbar.') + ' HLS-Fallback wird gestartet…');
            if (await startHlsStream('mjpeg-unavailable') !== false) {
              return;
            }
          }
          streamFallbackActive = true;
          setStreamStatus(availability.message || 'Livestream nicht verfügbar, Snapshot aktiv.');
          container.dataset.liveDisplay = 'snapshot';
          updateLiveDisplayMenu('snapshot');
          updateLiveModeBadge(resolveNavMode());
          stopStreamReconnect();
          resetStreamNoFrameState();
          stopMjpegStream();
          if (snapshotImg) {
            initLiveImageLoad();
          }
          return;
        }
        streamFallbackActive = false;
        clearStreamStatus();
        if (streamSrc && streamImg.getAttribute('src') !== streamSrc) {
          streamImg.setAttribute('src', streamSrc);
        }
        container.classList.remove('is-loaded');
        container.classList.add('is-placeholder');
        startImageLoading();
        const markLoaded = () => {
          container.classList.add('is-loaded');
          container.classList.remove('is-placeholder');
          finishImageLoading({ stream: true });
        };
        if (streamImg.complete && streamImg.naturalWidth > 0) {
          markLoaded();
        } else {
          streamImg.addEventListener('load', markLoaded, { once: true });
          streamImg.addEventListener('error', markLoaded, { once: true });
        }
        if (streamImg.dataset.streamReconnectInit !== '1') {
          streamImg.dataset.streamReconnectInit = '1';
          streamImg.addEventListener('error', onStreamError);
        }
        if (streamImg.dataset.streamFrameInit !== '1') {
          streamImg.dataset.streamFrameInit = '1';
          streamImg.addEventListener('load', onStreamFrame);
        }
        scheduleStreamNoFrameWatch('start');
        scheduleStreamReconnect();
      } else {
        if (!options.fallback) {
          streamFallbackActive = false;
          hlsFallbackTried = false;
          clearStreamStatus();
        } else if (streamFallbackActive) {
          if (!getStreamStatusText()) {
            setStreamStatus('Livestream nicht verfügbar, Snapshot aktiv.');
          }
        }
        stopMjpegStream();
        stopHlsStream();
        stopStreamReconnect();
        resetStreamNoFrameState();
        if (snapshotImg) {
          initLiveImageLoad();
        }
      }
    };

    // Das Präferenzmodul bindet nur das Menü. Start, Stop und technischer
    // Fallback bleiben weiterhin vollständig in diesem Stream-Koordinator.
    const initLiveDisplay = () => preferenceController.init(setLiveDisplayMode);
    const restoreLiveDisplayPreference = (displayMode) => preferenceController.restore(
      displayMode,
      streamFallbackActive,
      setLiveDisplayMode
    );

    /**
     * Beendet einen laufenden Stream vor einer Archivnavigation.
     *
     * Anders als ein technischer Fallback ist dies eine bewusste Rückkehr zum
     * Snapshot. Deshalb wird die Sitzungspräferenz ebenfalls auf „Snapshot“
     * gesetzt: Der nachfolgend partiell geladene Kamera-Root darf den gerade
     * freigegebenen MJPEG-/HLS-Stream nicht unmittelbar wieder starten.
     */
    const stopForSnapshotNavigation = () => setLiveDisplayMode('snapshot', {
      persist: true,
      fallback: false,
    });

    return Object.freeze({
      getLiveDisplayMode,
      initLiveDisplay,
      restoreLiveDisplayPreference,
      stopForSnapshotNavigation,
    });
  };

  window.WebcamCameraStream = Object.freeze({ create });
})();
