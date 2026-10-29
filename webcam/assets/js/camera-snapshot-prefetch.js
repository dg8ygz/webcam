(() => {
  'use strict';

  /**
   * Leseführung
   * -----------
   * Dieses Modul beschleunigt das Blättern zwischen benachbarten Snapshots.
   * Es hält ausschließlich kurzlebigen Zustand im aktuellen Browserdokument;
   * es erzeugt weder Dateien noch einen serverseitigen Cache.
   *
   * Die Funktionsblöcke sind bewusst nach dem Datenfluss angeordnet:
   *
   * 1. Cachegrenzen und interner Zustand.
   * 2. URL-Auflösung und Schlüssel des aktuell sichtbaren Snapshots.
   * 3. Vorgeholte HTML-Dokumente speichern und einmalig entnehmen.
   * 4. Bildantworten als begrenzte Blob-URLs halten.
   * 5. Ein Bild aus dem Cache in einen neu eingesetzten Kamera-Root übernehmen.
   * 6. Benachbarte interne Seiten und deren Bilder kontrolliert vorladen.
   * 7. Kleine öffentliche Schnittstelle für `camera.js`.
   *
   * Fehler beim Vorladen bleiben absichtlich ohne sichtbare Fehlermeldung:
   * Prefetch ist nur eine Optimierung. Die reguläre Navigation führt bei einem
   * Fehlschlag weiterhin ihren normalen, sichtbaren Request aus.
   */

  const PREFETCH_DOCUMENT_LIMIT = 6;
  const IMAGE_BLOB_LIMIT = 10;

  const create = ({ getLiveRoot, isPrefetchEnabled } = {}) => {
    if (typeof getLiveRoot !== 'function' || typeof isPrefetchEnabled !== 'function') {
      throw new TypeError('CameraSnapshotPrefetch benötigt Root- und Aktivierungsadapter.');
    }

    // -----------------------------------------------------------------------
    // 1. Cachegrenzen und Zustand
    // -----------------------------------------------------------------------

    // `prefetchedDocuments` enthält bereits geparstes HTML. Laufende Requests
    // stehen getrennt, damit derselbe Nachbar nicht mehrfach parallel geladen
    // wird. Blob-URLs werden beim Verdrängen ausdrücklich freigegeben.
    const prefetchedDocuments = new Map();
    const pendingPrefetches = new Map();
    const imageBlobCache = new Map();

    // -----------------------------------------------------------------------
    // 2. URL-Auflösung und Snapshot-Schlüssel
    // -----------------------------------------------------------------------

    const resolveUrl = (value, base = window.location.href) => {
      if (!value) return null;
      try {
        return new URL(value, base).toString();
      } catch {
        return null;
      }
    };

    const getCurrentSnapshotKey = () => {
      const container = getLiveRoot()?.querySelector('.live-image');
      const value = container?.getAttribute('data-current-snapshot') || '';
      return value !== '' ? value : null;
    };

    // -----------------------------------------------------------------------
    // 3. Vorgeholte HTML-Dokumente
    // -----------------------------------------------------------------------

    const storePrefetchedDocument = (key, documentNode, snapshotKey) => {
      if (!key || !documentNode) return;
      prefetchedDocuments.set(key, { doc: documentNode, snapshotKey });
      while (prefetchedDocuments.size > PREFETCH_DOCUMENT_LIMIT) {
        const oldestKey = prefetchedDocuments.keys().next().value;
        prefetchedDocuments.delete(oldestKey);
      }
    };

    /**
     * Gibt ein Dokument höchstens einmal zurück. Der optionale Snapshot-Key
     * schützt davor, dass eine Antwort für einen anderen Zeitschritt nach einer
     * schnellen Navigation in den aktuellen Root eingesetzt wird.
     */
    const consumePrefetchedDocument = (targetUrl, expectedKey = null) => {
      const key = targetUrl.toString();
      const entry = prefetchedDocuments.get(key);
      if (!entry) return null;
      if (expectedKey
        && entry.snapshotKey
        && String(expectedKey) !== String(entry.snapshotKey)) {
        return null;
      }
      prefetchedDocuments.delete(key);
      return entry.doc;
    };

    // -----------------------------------------------------------------------
    // 4. Begrenzter Bild-Blob-Cache
    // -----------------------------------------------------------------------

    const cleanupImageCache = () => {
      while (imageBlobCache.size > IMAGE_BLOB_LIMIT) {
        const oldestKey = imageBlobCache.keys().next().value;
        const entry = imageBlobCache.get(oldestKey);
        if (entry) URL.revokeObjectURL(entry.objectUrl);
        imageBlobCache.delete(oldestKey);
      }
    };

    const storeImageBlob = (sourceUrl, blob) => {
      if (!sourceUrl || !blob) return;
      const previous = imageBlobCache.get(sourceUrl);
      if (previous) URL.revokeObjectURL(previous.objectUrl);
      imageBlobCache.set(sourceUrl, {
        objectUrl: URL.createObjectURL(blob),
        createdAt: Date.now(),
      });
      cleanupImageCache();
    };

    const preloadImageFromDocument = async (documentNode, baseUrl) => {
      const image = documentNode.querySelector('[data-live-image]');
      if (!(image instanceof HTMLImageElement)) return;
      const absoluteSource = resolveUrl(image.getAttribute('src'), baseUrl);
      if (!absoluteSource || imageBlobCache.has(absoluteSource)) return;

      try {
        const response = await fetch(absoluteSource, {
          credentials: 'same-origin',
          cache: 'reload',
        });
        if (!response.ok) return;
        storeImageBlob(absoluteSource, await response.blob());
      } catch {
        // Das HTML bleibt verwendbar; nur die Bildbeschleunigung entfällt.
      }
    };

    // -----------------------------------------------------------------------
    // 5. Bildcache nach partiellem DOM-Austausch verwenden
    // -----------------------------------------------------------------------

    const hydrateLiveImageFromCache = () => {
      const container = getLiveRoot()?.querySelector('.live-image');
      if (!container || container.classList.contains('is-dayvideo')) return;
      const image = container.querySelector('[data-live-image]');
      if (!(image instanceof HTMLImageElement)) return;

      const rawSource = image.getAttribute('src');
      const absoluteSource = resolveUrl(rawSource);
      if (!absoluteSource) return;
      const cached = imageBlobCache.get(absoluteSource);
      if (!cached) return;

      // Der Originalpfad bleibt für spätere Anzeigewechsel erhalten. Nur die
      // aktuelle Browserquelle wird auf die bereits geladene Blob-URL gesetzt.
      image.dataset.originalSrc = rawSource || '';
      image.src = cached.objectUrl;
    };

    // -----------------------------------------------------------------------
    // 6. Benachbarte Seiten und Bilder vorladen
    // -----------------------------------------------------------------------

    const ensurePrefetchPage = (href, expectedKey = null) => {
      const normalized = resolveUrl(href);
      if (!normalized) return;
      const targetUrl = new URL(normalized);
      // Fremde Seiten dürfen weder mit Anmeldedaten vorab geladen noch als
      // Kamera-Dokument in den lokalen Navigationscache gelangen.
      if (targetUrl.origin !== window.location.origin) return;

      const target = targetUrl.toString();
      if (prefetchedDocuments.has(target) || pendingPrefetches.has(target)) return;

      const request = (async () => {
        try {
          const response = await fetch(target, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-Preload': '1',
            },
            credentials: 'same-origin',
          });
          if (!response.ok) return;

          const parser = new DOMParser();
          const documentNode = parser.parseFromString(await response.text(), 'text/html');
          const documentRoot = documentNode.getElementById('camera-live-root');
          const marker = documentRoot?.querySelector('[data-current-snapshot]') || null;
          const snapshotKey = marker?.getAttribute('data-current-snapshot') || null;
          if (expectedKey && snapshotKey && String(expectedKey) !== String(snapshotKey)) return;

          storePrefetchedDocument(target, documentNode, snapshotKey);
          await preloadImageFromDocument(documentNode, target);
        } catch {
          // Reguläre Navigation bleibt der sichtbare Fallback.
        } finally {
          pendingPrefetches.delete(target);
        }
      })();
      pendingPrefetches.set(target, request);
    };

    const prefetchAdjacentSnapshots = () => {
      if (!isPrefetchEnabled()) return;
      const liveRoot = getLiveRoot();
      if (!liveRoot) return;

      // Map dedupliziert Ziele, die sowohl als Hotspot als auch als sichtbarer
      // Pfeillink auftreten. Der zuletzt gelesene Snapshot-Key bleibt erhalten.
      const targets = new Map();
      liveRoot.querySelectorAll('[data-timeline-hotspot]').forEach((element) => {
        const normalized = resolveUrl(element.getAttribute('data-url'));
        if (normalized) {
          targets.set(normalized, element.getAttribute('data-target-key') || null);
        }
      });
      liveRoot.querySelectorAll('[data-prefetch-link="snapshot"]').forEach((element) => {
        if (!(element instanceof HTMLAnchorElement) || !element.href) return;
        const normalized = resolveUrl(element.href);
        if (normalized) {
          targets.set(normalized, element.getAttribute('data-target-key') || null);
        }
      });
      targets.forEach((expectedKey, href) => ensurePrefetchPage(href, expectedKey));
    };

    // -----------------------------------------------------------------------
    // 7. Öffentliche Controller-Schnittstelle
    // -----------------------------------------------------------------------

    return Object.freeze({
      resolveUrl,
      getCurrentSnapshotKey,
      consumePrefetchedDocument,
      hydrateLiveImageFromCache,
      prefetchAdjacentSnapshots,
    });
  };

  window.WebcamCameraSnapshotPrefetch = Object.freeze({ create });
})();
