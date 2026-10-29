(() => {
  'use strict';

  /**
   * Besitzt genau den Lebenszyklus des HLS-Renderers.
   *
   * hls.js-Instanz, natives Videoelement, Bereitschafts-Timeout und fatale
   * Decoderfehler werden hier gemeinsam gestartet und beendet. Ob anschließend
   * MJPEG oder Snapshot verwendet wird, entscheidet weiterhin der übergeordnete
   * Stream-Koordinator über die übergebenen Rückrufe.
   */
  const create = ({
    getLiveRoot,
    getVideo,
    getEndpoint,
    buildSource,
    probeAvailability,
    beforeStart,
    onReady,
    onControlledStop,
    onFatal,
    setStatus,
    firstFrameTimeoutMs = 12000,
  } = {}) => {
    const dependencies = [
      getLiveRoot,
      getVideo,
      getEndpoint,
      buildSource,
      probeAvailability,
      beforeStart,
      onReady,
      onControlledStop,
      onFatal,
      setStatus,
    ];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new Error('HLS-Renderer: notwendige Seitenadapter fehlen.');
    }

    let hlsInstance = null;
    let generation = 0;
    let releaseListeners = () => {};
    let cancelStart = () => {};
    let activeVideo = null;

    /** Gibt Browserdecoder und hls.js-Ressourcen vollständig frei. */
    const stop = () => {
      generation += 1;
      releaseListeners();
      releaseListeners = () => {};
      cancelStart();
      cancelStart = () => {};
      if (hlsInstance && typeof hlsInstance.destroy === 'function') {
        hlsInstance.destroy();
      }
      hlsInstance = null;
      const video = activeVideo;
      activeVideo = null;
      if (!video) return;
      video.pause();
      video.removeAttribute('src');
      video.load();
    };

    const start = async (reason = 'start') => {
      const root = getLiveRoot();
      const container = root?.querySelector('.live-image') || null;
      const video = getVideo();
      const endpoint = getEndpoint();
      if (!container || !(video instanceof HTMLVideoElement) || !endpoint) return false;

      stop();
      const ownGeneration = generation;
      const current = () => generation === ownGeneration;
      activeVideo = video;
      video.controls = true;
      beforeStart(container);
      setStatus('Video-Livestream wird geladen …');
      const availability = await probeAvailability(endpoint);
      // null means superseded/stopped, not a failed current attempt.
      if (!current()) return null;
      if (!availability.available) {
        setStatus(availability.message || 'Video-Livestream ist nicht verfügbar.');
        if (availability.status === 403) onControlledStop(availability);
        return false;
      }

      const source = buildSource(endpoint);
      return new Promise((resolve) => {
        let settled = false;
        let autoplayBlocked = false;
        let lastSegmentRecoveryAt = -Infinity;
        const settle = (successful) => {
          if (settled) return;
          settled = true;
          window.clearTimeout(timeout);
          if (successful) {
            onReady(reason);
            if (autoplayBlocked) setStatus('Automatischer Videostart blockiert. Bitte ▶ im Video drücken.');
          }
          resolve(successful);
        };
        const fail = (message) => {
          if (!current()) return;
          setStatus(message);
          if (settled) onFatal('hls-error');
          else settle(false);
        };
        let timeout = window.setTimeout(() => fail('Video-Livestream: Zeitüberschreitung beim Laden.'), firstFrameTimeoutMs);
        cancelStart = () => settle(null);
        const play = () => video.play().catch((error) => {
          if (!current()) return;
          if (error?.name === 'NotAllowedError') {
            autoplayBlocked = true;
            setStatus('Automatischer Videostart blockiert. Bitte ▶ im Video drücken.');
          } else if (error?.name !== 'AbortError') {
            fail('Video kann in diesem Browser nicht wiedergegeben werden.');
          }
        });
        const metadata = () => { if (current()) { settle(true); play(); } };
        const playing = () => {
          if (!current()) return;
          window.clearTimeout(timeout);
          autoplayBlocked = false;
          settle(true);
          setStatus('');
        };
        const mediaError = () => fail('Video kann nicht geladen oder decodiert werden.');
        video.addEventListener('loadedmetadata', metadata, { once: true });
        video.addEventListener('playing', playing);
        video.addEventListener('error', mediaError);
        releaseListeners = () => {
          window.clearTimeout(timeout);
          video.removeEventListener('loadedmetadata', metadata);
          video.removeEventListener('playing', playing);
          video.removeEventListener('error', mediaError);
        };

        // Chromium meldet teilweise native HLS-Unterstützung, arbeitet bei der
        // dynamischen PHP-Playlist aber instabil. hls.js/MediaSource hat dort
        // Vorrang; Safari fällt auf seine ausgereifte native Wiedergabe zurück.
        if (window.Hls?.isSupported?.()) {
          const attachHls = (url) => {
            const instance = new window.Hls({
              lowLatencyMode: true,
              liveSyncDurationCount: 3,
              maxLiveSyncPlaybackRate: 1.5,
            });
            hlsInstance = instance;
            instance.on(window.Hls.Events.ERROR, (event, data) => {
              if (!current() || hlsInstance !== instance) return;
              const status = Number(data?.response?.code);
              if (status === 403) {
                settle(false);
                onControlledStop({ available: false, status: 403, message: 'Videozugriff verweigert (HTTP 403).' });
                return;
              }
              // A live segment can expire while a tab/video is paused. Discard
              // the old decoder/playlist instead of retrying its dead URL. Only
              // fragment expiry qualifies; auth/playlist failures never do.
              const expiredSegment = (status === 410 || status === 404)
                && data?.details === 'fragLoadError' && data?.frag;
              if (expiredSegment) {
                const now = Date.now();
                if (now - lastSegmentRecoveryAt < 60000) {
                  fail('Video-Livestream: Wiederanlauf fehlgeschlagen. Bitte erneut starten.');
                  return;
                }
                lastSegmentRecoveryAt = now;
                hlsInstance = null;
                instance.destroy();
                setStatus('Video-Livestream wird nach Unterbrechung neu verbunden …');
                window.clearTimeout(timeout);
                timeout = window.setTimeout(() => fail('Video-Livestream: Zeitüberschreitung beim Wiederanlauf.'), firstFrameTimeoutMs);
                // Leave the old library error dispatch before creating its
                // replacement; stop/navigation can cancel this microtask too.
                queueMicrotask(() => {
                  if (current()) attachHls(buildSource(endpoint));
                });
                return;
              }
              if (!data?.fatal) return;
              // Only fixed categories/status numbers; never URLs or raw errors.
              const http = Number.isInteger(status) && status >= 400 && status <= 599 ? ` (HTTP ${status})` : '';
              fail(data.type === 'networkError'
                ? `Video-Livestream: Netzwerkfehler${http}. Playlist oder Videosegmente nicht erreichbar.`
                : 'Video-Livestream: Wiedergabe- oder Formatfehler.');
            });
            instance.on(window.Hls.Events.MANIFEST_PARSED, () => {
              if (current() && hlsInstance === instance) play();
            });
            instance.attachMedia(video);
            instance.loadSource(url);
          };
          attachHls(source);
          return;
        }
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = source;
          video.load();
          return;
        }
        setStatus('HLS-Fallback wird von diesem Browser nicht unterstützt.');
        settle(false);
      });
    };

    return Object.freeze({ start, stop });
  };

  window.WebcamCameraStreamHls = Object.freeze({ create });
})();
