(() => {
  'use strict';

  /**
   * Partielle Navigation innerhalb einer Kameraansicht.
   *
   * Leseführung
   * -----------
   * 1. Abhängigkeiten und kurzlebigen Requestzustand vorbereiten.
   * 2. URLs und Ereignisziele sicher einordnen.
   * 3. Den Kamera-Root aus einem neuen HTML-Dokument übernehmen.
   * 4. Seiten laden, alte Requests abbrechen und Prefetch verwenden.
   * 5. Timeline-Links und seitliche Hotspots auswerten.
   * 6. Ereignisse an den jeweils aktuellen Kamera-Root binden.
   * 7. Kleine öffentliche Schnittstelle für `camera.js` bereitstellen.
   *
   * Das Modul navigiert nur innerhalb derselben Kamera partiell. Ein Link zu
   * einer anderen Kamera bleibt eine normale Browsernavigation, damit deren
   * Karte, Inline-Konfiguration und Seitenskripte vollständig neu starten.
   * Serverseitige Timeline-Daten werden hier weder berechnet noch gecacht.
   */

  const create = ({
    getLiveRoot,
    isTimelineFetchEnabled,
    cancelTimelineRequest,
    consumePrefetchedDocument,
    startImageLoading,
    beforeSnapshotNavigation,
    hydrateLiveImageFromCache,
    getBestOfNavTarget,
    onContentReplaced,
  } = {}) => {
    const dependencies = [
      getLiveRoot,
      isTimelineFetchEnabled,
      cancelTimelineRequest,
      consumePrefetchedDocument,
      startImageLoading,
      beforeSnapshotNavigation,
      hydrateLiveImageFromCache,
      getBestOfNavTarget,
      onContentReplaced,
    ];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new TypeError('CameraPartialNavigation benötigt alle Seitenadapter.');
    }

    // -----------------------------------------------------------------------
    // 1. Kurzlebiger Navigationszustand
    // -----------------------------------------------------------------------

    let requestGeneration = 0;
    let activeRequestController = null;
    let boundRoot = null;
    let popstateBound = false;

    // -----------------------------------------------------------------------
    // 2. URLs und Ereignisziele einordnen
    // -----------------------------------------------------------------------

    const parseUrl = (value) => {
      try {
        return new URL(value, window.location.href);
      } catch {
        return null;
      }
    };

    const isSameOrigin = (value) => {
      const target = parseUrl(value);
      return Boolean(target && target.origin === window.location.origin);
    };

    const getEventTargetElement = (event) => {
      if (event.target instanceof Element) return event.target;
      if (event.target?.parentElement instanceof Element) return event.target.parentElement;
      return null;
    };

    const isPrimaryClick = (event) => typeof event.button !== 'number' || event.button === 0;

    const hasAjaxTimeline = () => Boolean(
      getLiveRoot()?.querySelector('[data-timeline-container]')
    );

    const navigateFullPage = (value) => {
      const target = parseUrl(value);
      if (target) window.location.href = target.toString();
    };

    // -----------------------------------------------------------------------
    // 3. Neuen Kamera-Root übernehmen
    // -----------------------------------------------------------------------

    const synchronizeRootAttributes = (currentRoot, nextRoot) => {
      // Die ID bleibt stabil. Alle anderen Attribute gehören zum gewählten
      // Snapshot und werden vollständig aus der Serverantwort übernommen.
      currentRoot.getAttributeNames().forEach((name) => {
        if (name !== 'id') currentRoot.removeAttribute(name);
      });
      nextRoot.getAttributeNames().forEach((name) => {
        if (name !== 'id') currentRoot.setAttribute(name, nextRoot.getAttribute(name));
      });
    };

    const replaceContent = (documentNode, targetUrl, pushState) => {
      const currentRoot = getLiveRoot();
      const nextRoot = documentNode?.getElementById('camera-live-root') || null;
      if (!currentRoot || !nextRoot) {
        navigateFullPage(targetUrl);
        return false;
      }

      synchronizeRootAttributes(currentRoot, nextRoot);
      currentRoot.innerHTML = nextRoot.innerHTML;
      document.title = documentNode.title || document.title;

      // Ein vorab geladener Bild-Blob wird vor dem Wiederanlauf der übrigen
      // Controller eingesetzt, damit diese direkt den richtigen Bildzustand sehen.
      hydrateLiveImageFromCache();
      onContentReplaced({ targetUrl, documentNode, liveRoot: currentRoot });
      bindToCurrentRoot();

      if (pushState) {
        const relativeUrl = targetUrl.pathname + targetUrl.search + targetUrl.hash;
        history.pushState({ cameraAjax: true }, '', relativeUrl);
      }
      return true;
    };

    // -----------------------------------------------------------------------
    // 4. HTML laden, Prefetch nutzen und Race Conditions verhindern
    // -----------------------------------------------------------------------

    const navigate = async (value, pushState = true, expectedKey = null) => {
      const targetUrl = parseUrl(value);
      if (!targetUrl) return false;

      // Ein sichtbarer Livestream gehört immer zum bisher ausgewählten
      // Zeitpunkt. Vor dem Wechsel auf einen Archiv-Snapshot beendet der
      // Stream-Controller deshalb Decoder, Viewer-Slot und Reconnect-Timer.
      // Der Hook wird auch für Prefetch-Treffer und Browser-Zurück ausgeführt.
      await beforeSnapshotNavigation();

      // Jede neue Navigation erhöht die Generation – auch wenn ihr Dokument
      // bereits im Prefetch liegt. Dadurch darf ein älterer Netzwerkrequest
      // später niemals wieder den inzwischen neueren Inhalt überschreiben.
      const currentGeneration = ++requestGeneration;
      if (activeRequestController) {
        activeRequestController.abort();
        activeRequestController = null;
      }
      // Ein sofort verfügbares Prefetch-Dokument startet keinen neuen Loader.
      // Deshalb den Ladezustand des gerade abgebrochenen Requests hier lösen.
      document.body.classList.remove('content-loading');
      cancelTimelineRequest();

      const prefetched = expectedKey
        ? consumePrefetchedDocument(targetUrl, expectedKey)
        : null;
      if (prefetched) {
        return replaceContent(prefetched, targetUrl, pushState);
      }

      document.body.classList.add('content-loading');
      startImageLoading();
      const controller = new AbortController();
      activeRequestController = controller;
      const abortTimer = window.setTimeout(() => controller.abort(), 8000);

      try {
        const response = await fetch(targetUrl.toString(), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const html = await response.text();
        if (currentGeneration !== requestGeneration) return false;
        const documentNode = new DOMParser().parseFromString(html, 'text/html');
        return replaceContent(documentNode, targetUrl, pushState);
      } catch (error) {
        // Ein bewusst abgebrochener alter Request gehört zu einer neueren
        // Navigation. Nur der weiterhin aktuelle Fehler darf sichtbar auf eine
        // vollständige Browsernavigation zurückfallen.
        if (currentGeneration === requestGeneration) {
          navigateFullPage(targetUrl);
        }
        return false;
      } finally {
        window.clearTimeout(abortTimer);
        if (activeRequestController === controller) activeRequestController = null;
        if (currentGeneration === requestGeneration) {
          document.body.classList.remove('content-loading');
        }
      }
    };

    // -----------------------------------------------------------------------
    // 5. Timeline-Links und Bild-Hotspots auswerten
    // -----------------------------------------------------------------------

    const handleTimelineClick = (event) => {
      if (
        event.defaultPrevented
        || !isPrimaryClick(event)
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
      ) return;

      const anchor = getEventTargetElement(event)?.closest('a') || null;
      if (!anchor || anchor.target === '_blank' || anchor.dataset.noAjax === '1') return;
      if (!isTimelineFetchEnabled() || !anchor.closest('.timeline-controls')) return;

      const target = parseUrl(anchor.href);
      const currentSlug = getLiveRoot()?.dataset.cameraSlug || '';
      const targetSlug = anchor.dataset.cameraSlug || target?.searchParams.get('cam') || '';
      if (!target || target.origin !== window.location.origin) return;
      if (targetSlug && targetSlug !== currentSlug) return;

      const href = anchor.getAttribute('href');
      if (!href || !isSameOrigin(href)) return;
      event.preventDefault();
      navigate(anchor.href, true, anchor.getAttribute('data-target-key') || null);
    };

    const activateHotspot = async (element) => {
      const fallbackUrl = element?.getAttribute('data-url') || '';
      if (!fallbackUrl) return;

      const direction = element.classList.contains('timeline-hotspot--left')
        ? 'prev'
        : (element.classList.contains('timeline-hotspot--right') ? 'next' : null);
      const isBestOf = new URLSearchParams(window.location.search).has('bestof');

      if (isBestOf && direction) {
        try {
          const bestOfUrl = await getBestOfNavTarget(direction);
          if (bestOfUrl) {
            if (hasAjaxTimeline()) await navigate(bestOfUrl, true);
            else navigateFullPage(bestOfUrl);
            return;
          }
        } catch {
          // Die normale Timeline-URL unter dem Hotspot bleibt der Fallback.
        }
      }

      if (!hasAjaxTimeline()) {
        navigateFullPage(fallbackUrl);
        return;
      }
      await navigate(
        fallbackUrl,
        true,
        element.getAttribute('data-target-key') || null
      );
    };

    const handleHotspotClick = (event) => {
      if (event.defaultPrevented || !isPrimaryClick(event)) return;
      const hotspot = getEventTargetElement(event)?.closest('[data-timeline-hotspot]') || null;
      if (!hotspot) return;
      event.stopPropagation();
      event.preventDefault();
      activateHotspot(hotspot);
    };

    const handleHotspotKeydown = (event) => {
      const hotspot = getEventTargetElement(event)?.closest('[data-timeline-hotspot]') || null;
      if (!hotspot || (event.key !== 'Enter' && event.key !== ' ')) return;
      event.stopPropagation();
      event.preventDefault();
      activateHotspot(hotspot);
    };

    const handleBestOfHotspotClick = (event) => {
      if (event.defaultPrevented || !isPrimaryClick(event)) return;
      const target = getEventTargetElement(event);
      if (!target || target.closest('[data-bestof-toggle]')) return;
      const button = target.closest('[data-bestof-hotspot]')?.querySelector('[data-bestof-toggle]');
      if (!button) return;
      event.stopPropagation();
      event.preventDefault();
      button.click();
    };

    // -----------------------------------------------------------------------
    // 6. Handler genau einmal an den aktuellen Root binden
    // -----------------------------------------------------------------------

    const unbindRoot = () => {
      if (!boundRoot) return;
      boundRoot.removeEventListener('click', handleTimelineClick);
      boundRoot.removeEventListener('click', handleHotspotClick, true);
      boundRoot.removeEventListener('keydown', handleHotspotKeydown);
      boundRoot.removeEventListener('click', handleBestOfHotspotClick);
      boundRoot = null;
    };

    function bindToCurrentRoot() {
      const currentRoot = getLiveRoot();
      if (!currentRoot || currentRoot === boundRoot) return;
      unbindRoot();
      currentRoot.addEventListener('click', handleTimelineClick);
      currentRoot.addEventListener('click', handleHotspotClick, true);
      currentRoot.addEventListener('keydown', handleHotspotKeydown);
      currentRoot.addEventListener('click', handleBestOfHotspotClick);
      boundRoot = currentRoot;
    }

    const handlePopstate = (event) => {
      if (event.state?.cameraAjax) navigate(window.location.href, false);
    };

    // -----------------------------------------------------------------------
    // 7. Öffentliche Schnittstelle
    // -----------------------------------------------------------------------

    const init = () => {
      bindToCurrentRoot();
      if (!popstateBound) {
        window.addEventListener('popstate', handlePopstate);
        popstateBound = true;
      }
    };

    const destroy = () => {
      requestGeneration += 1;
      activeRequestController?.abort();
      activeRequestController = null;
      unbindRoot();
      if (popstateBound) {
        window.removeEventListener('popstate', handlePopstate);
        popstateBound = false;
      }
    };

    return Object.freeze({ init, navigate, destroy });
  };

  window.WebcamCameraPartialNavigation = Object.freeze({ create });
})();
