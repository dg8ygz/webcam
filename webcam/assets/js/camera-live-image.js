(() => {
  'use strict';

  /**
   * Lebenszyklus des sichtbaren Kamera-Hauptbildes.
   *
   * Leseführung
   * -----------
   * 1. Abhängigkeiten und den einzigen Reload-Timer verwalten.
   * 2. Kamera-Inhalt kontrolliert in den sichtbaren Bereich scrollen.
   * 3. Ein schnelles Vorschaubild gegen die große Bildvariante austauschen.
   * 4. Ladeabschluss an Timeline, Prefetch und Profiling melden.
   * 5. Klicks auf das Bild von Schaltern und Hotspots unterscheiden.
   * 6. Snapshot-, Stream- und Fehlerzustände initialisieren.
   * 7. Die Rückkehr aus der reinen Informationsansicht anbinden.
   *
   * Das Modul lädt keine Timeline und entscheidet nicht zwischen MJPEG und
   * HLS. Diese Zuständigkeiten verbleiben in ihren Fachcontrollern. Es erhält
   * dafür kleine Adapter aus `camera.js` und kennt weder Serverpfade noch die
   * konkrete Implementierung von Lightbox, Prefetch oder Streamsteuerung.
   */

  const create = ({
    getLiveRoot,
    restoreLiveDisplayPreference,
    startImageLoading,
    finishImageLoading,
    prefetchAdjacentSnapshots,
    openLivebox,
    buildCameraPath,
  } = {}) => {
    const dependencies = [
      getLiveRoot,
      restoreLiveDisplayPreference,
      startImageLoading,
      finishImageLoading,
      prefetchAdjacentSnapshots,
      openLivebox,
      buildCameraPath,
    ];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new TypeError('CameraLiveImage benötigt alle Seitenadapter.');
    }

    // ---------------------------------------------------------------------
    // 1. Kurzlebiger Timerzustand
    // ---------------------------------------------------------------------

    let reloadTimer = null;

    const scheduleReload = () => {
      if (reloadTimer !== null) window.clearTimeout(reloadTimer);
      reloadTimer = window.setTimeout(() => window.location.reload(), 15 * 60 * 1000);
    };

    // ---------------------------------------------------------------------
    // 2. Hauptbild und Timeline in den sichtbaren Bereich rücken
    // ---------------------------------------------------------------------

    const prefersReducedMotion = () => (
      window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );

    const focus = ({ smooth = false, force = false } = {}) => {
      const root = getLiveRoot();
      if (!root) return;
      const imageContainer = root.querySelector('.live-image');
      if (imageContainer?.classList.contains('is-dayvideo')) return;
      const target = root.querySelector('.timeline-controls') || imageContainer;
      if (!target) return;

      const rect = target.getBoundingClientRect();
      if (!force) {
        const fullyVisible = rect.top >= 20 && rect.bottom <= window.innerHeight;
        if (fullyVisible) return;
      }
      const behavior = smooth && !prefersReducedMotion() ? 'smooth' : 'auto';
      const targetY = window.scrollY + rect.top - 12;
      window.scrollTo({ top: Math.max(targetY, 0), behavior });
    };

    // ---------------------------------------------------------------------
    // 3. Kleine Vorschau durch die große Bildvariante ersetzen
    // ---------------------------------------------------------------------

    const swapToFullImage = (image, container, { fullSrc, fullSrcset, profile }) => {
      if (!fullSrc || image.dataset.state === 'full') return;

      image.dataset.state = 'full-loading';
      const swapStartedAt = performance.now();
      const preloadImage = new Image();
      if (fullSrcset) preloadImage.srcset = fullSrcset;
      preloadImage.src = fullSrc;

      const applyFullImage = () => {
        image.src = fullSrc;
        if (fullSrcset) image.srcset = fullSrcset;
        image.dataset.state = 'full';
        container.classList.remove('is-placeholder');
        if (profile) {
          console.log(
            '[WC_PROFILE_CLIENT] highres-loaded',
            (performance.now() - swapStartedAt).toFixed(2),
            'ms'
          );
        }
      };

      if (preloadImage.complete && preloadImage.naturalWidth > 0) {
        applyFullImage();
      } else {
        preloadImage.addEventListener('load', applyFullImage, { once: true });
        preloadImage.addEventListener('error', () => {
          image.dataset.state = 'full-error';
        }, { once: true });
      }
    };

    // ---------------------------------------------------------------------
    // 4. Ladeabschluss veröffentlichen und Nachbarn vorladen
    // ---------------------------------------------------------------------

    const finishPlaceholderLoad = (
      container,
      image,
      { usePlaceholder, profile, loadStartedAt, fullSrc, fullSrcset }
    ) => {
      // Load und Error können in einzelnen Browsern dicht aufeinander folgen.
      // `is-loaded` macht den Abschluss idempotent.
      if (container.classList.contains('is-loaded')) return;

      if (container.dataset.nativeDisplay === '1' && image.naturalWidth > 0) {
        container.style.setProperty('--native-image-width', `${image.naturalWidth}px`);
      }
      container.classList.add('is-loaded');
      if (profile) {
        console.log(
          '[WC_PROFILE_CLIENT] placeholder-loaded',
          (performance.now() - loadStartedAt).toFixed(2),
          'ms'
        );
      }

      finishImageLoading({
        src: image.currentSrc || image.src || '',
        timestamp: image.dataset.captureTimestamp || null,
      });
      focus({ smooth: false, force: true });
      prefetchAdjacentSnapshots();

      if (!usePlaceholder) return;
      const swap = () => swapToFullImage(image, container, {
        fullSrc,
        fullSrcset,
        profile,
      });
      if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(swap, { timeout: 250 });
      } else {
        window.setTimeout(swap, 50);
      }
    };

    // ---------------------------------------------------------------------
    // 5. Nur einen echten Bildklick an die Lightbox weiterreichen
    // ---------------------------------------------------------------------

    const getEventTargetElement = (event) => {
      if (event.target instanceof Element) return event.target;
      return event.target?.parentElement instanceof Element ? event.target.parentElement : null;
    };

    const eventMatchesSelector = (event, selector) => {
      const target = getEventTargetElement(event);
      if (target?.closest(selector)) return true;
      if (typeof event.composedPath !== 'function') return false;
      return event.composedPath().some(
        (node) => node instanceof Element && node.matches(selector)
      );
    };

    const shouldOpenLightbox = (container, event) => {
      if (container.classList.contains('is-dayvideo')) return false;
      if ((container.dataset.liveDisplay || 'snapshot') === 'stream') return false;
      if (eventMatchesSelector(
        event,
        '[data-timeline-hotspot], [data-bestof-toggle], [data-bestof-hotspot], '
          + '[data-timelapse], [data-dayvideo-inline], [data-dayvideo-open]'
      )) return false;
      return container.classList.contains('is-loaded');
    };

    const bindLightboxClick = (container) => {
      if (container.dataset.liveImageInit === '1') return;
      container.dataset.liveImageInit = '1';
      container.addEventListener('click', (event) => {
        if (shouldOpenLightbox(container, event)) openLivebox();
      });
    };

    // ---------------------------------------------------------------------
    // 6. Snapshot-, Stream- und Fehlerzustände initialisieren
    // ---------------------------------------------------------------------

    const initLoad = () => {
      const root = getLiveRoot();
      const container = root?.querySelector('.live-image') || null;
      if (!root || !container || container.classList.contains('is-dayvideo')) return;

      const displayMode = container.dataset.liveDisplay || 'snapshot';
      if (restoreLiveDisplayPreference(displayMode)) return;

      const streamImage = container.querySelector('[data-live-stream]');
      const hlsVideo = container.querySelector('[data-live-hls]');
      if (displayMode === 'stream' && (streamImage || hlsVideo)) {
        container.classList.add('is-loaded');
        container.classList.remove('is-placeholder');
        finishImageLoading({ skipped: true, stream: true });
        return;
      }

      container.classList.remove('is-loaded');
      container.classList.add('is-placeholder');
      const image = container.querySelector('[data-live-image]');
      if (!(image instanceof HTMLImageElement)) {
        container.classList.add('is-loaded');
        container.classList.remove('is-placeholder');
        finishImageLoading({ skipped: true });
        return;
      }

      startImageLoading();
      const fullSrc = image.dataset.fullSrc || null;
      const fullSrcset = image.dataset.fullSrcset || null;
      const usePlaceholder = Boolean(fullSrc) && image.getAttribute('src') !== fullSrc;
      const profile = root.dataset.wcProfile === '1';
      const loadStartedAt = performance.now();
      const markLoaded = () => finishPlaceholderLoad(container, image, {
        usePlaceholder,
        profile,
        loadStartedAt,
        fullSrc,
        fullSrcset,
      });

      if (image.complete && image.naturalWidth > 0) {
        markLoaded();
      } else {
        // Auch ein defektes Bild beendet den globalen Ladeindikator. Der
        // sichtbare Bildfehler bleibt bestehen und wird nicht still ersetzt.
        image.addEventListener('load', markLoaded, { once: true });
        image.addEventListener('error', markLoaded, { once: true });
      }
      bindLightboxClick(container);
    };

    // ---------------------------------------------------------------------
    // 7. Rückkehr aus der Informationsansicht
    // ---------------------------------------------------------------------

    const initInfoExit = () => {
      const root = getLiveRoot();
      if (!root?.classList.contains('is-info-view')) return;

      const infoBox = root.querySelector('.live-image__info');
      const backLink = infoBox?.querySelector('.live-image__back') || null;
      if (!backLink || backLink.dataset.liveInfoExitInit === '1') return;
      backLink.dataset.liveInfoExitInit = '1';

      const liveLink = root.getAttribute('data-live-link') || '';
      const cameraSlug = root.getAttribute('data-camera-slug') || '';
      backLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (history.state?.cameraAjax) {
          history.back();
        } else if (liveLink) {
          window.location.href = liveLink;
        } else if (cameraSlug) {
          window.location.href = buildCameraPath(cameraSlug);
        }
      });
    };

    const destroy = () => {
      if (reloadTimer !== null) window.clearTimeout(reloadTimer);
      reloadTimer = null;
    };

    return Object.freeze({ initLoad, initInfoExit, focus, scheduleReload, destroy });
  };

  window.WebcamCameraLiveImage = Object.freeze({ create });
})();
