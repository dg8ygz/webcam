(() => {
  'use strict';

  /**
   * Umgebungskarte im Kamera-Modal.
   *
   * Diese Komponente besitzt bewusst nur die Fachlogik und Darstellung der
   * Karte. Sie kennt weder die globale Kamera-Seite noch deren Timeline-Cache.
   * Die wenigen benötigten Zugriffe werden beim Erzeugen als Funktionen
   * übergeben. Dadurch bleibt nachvollziehbar, wo Daten herkommen, und es
   * entsteht keine zweite, versteckte Datenquelle.
   */

  const parseInteger = (value) => {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const parseDecimal = (value) => {
    if (typeof value === 'number') {
      return Number.isFinite(value) ? value : null;
    }
    if (typeof value !== 'string') {
      return null;
    }
    const normalized = value.trim().replace(',', '.');
    if (!normalized) {
      return null;
    }
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const formatDateTime = (timestamp) => {
    if (!Number.isFinite(timestamp) || timestamp <= 0) {
      return '—';
    }
    const date = new Date(timestamp * 1000);
    if (!Number.isFinite(date.getTime())) {
      return '—';
    }
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

  const formatMinutes = (seconds) => {
    if (!Number.isFinite(seconds) || seconds <= 0) {
      return '—';
    }
    return `${Math.max(0, Math.round(seconds / 60))} min`;
  };

  /** Nur echte, nicht leere und nicht auf (0, 0) gesetzte Positionen zeigen. */
  const hasValidCoordinates = (camera) => {
    const latitude = parseDecimal(camera?.location?.latitude);
    const longitude = parseDecimal(camera?.location?.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
      return false;
    }
    if (Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
      return false;
    }
    return !(latitude === 0 && longitude === 0);
  };

  const resolveTitle = (camera) => camera?.short_title || camera?.title || camera?.slug || 'Kamera';

  const resolveTone = (camera, currentSlug) => {
    if ((camera?.slug || '') === currentSlug) {
      return {
        key: 'current',
        marker: '#d14343',
        wedge: '#d14343',
        label: 'Aktuelle Kamera',
      };
    }
    return {
      key: 'other',
      marker: '#2c7dff',
      wedge: '#2c7dff',
      label: 'Weitere Kamera',
    };
  };

  const resolveRadiusKm = (camera) => {
    const configured = parseDecimal(camera?.location?.orientation_visualization_km);
    return Number.isFinite(configured) && configured > 0 ? configured : 5;
  };

  /**
   * Timeline-Antworten enthalten außer den Tageseinträgen gegebenenfalls noch
   * den letzten Wert des Vortags, den ersten Wert des Folgetags und "latest".
   * Alle vier Quellen werden vereinheitlicht und anschließend nach Zeitabstand
   * bewertet. Das verhindert harte Sprünge an Tagesgrenzen.
   */
  const normalizeEntry = (rawEntry) => {
    if (!rawEntry || typeof rawEntry !== 'object') {
      return null;
    }
    const timestamp = parseInteger(rawEntry.timestamp);
    if (!timestamp) {
      return null;
    }
    return {
      ...rawEntry,
      timestamp,
      year: parseInteger(rawEntry.year),
      month: parseInteger(rawEntry.month),
      day: parseInteger(rawEntry.day),
      time: parseInteger(rawEntry.time),
    };
  };

  const findClosestEntry = (data, targetTimestamp) => {
    if (!data || !Number.isFinite(targetTimestamp)) {
      return null;
    }
    const seen = new Set();
    const candidates = [];
    const addCandidate = (rawEntry) => {
      const entry = normalizeEntry(rawEntry);
      if (!entry) {
        return;
      }
      const key = `${entry.timestamp}:${entry.relative_path || entry.url || ''}`;
      if (seen.has(key)) {
        return;
      }
      seen.add(key);
      candidates.push(entry);
    };

    (Array.isArray(data.entries) ? data.entries : []).forEach(addCandidate);
    addCandidate(data.day_prev_entry);
    addCandidate(data.day_next_entry);
    addCandidate(data.latest);

    return candidates.reduce((closest, entry) => {
      if (!closest) {
        return entry;
      }
      const delta = Math.abs(entry.timestamp - targetTimestamp);
      const closestDelta = Math.abs(closest.timestamp - targetTimestamp);
      return delta < closestDelta ? entry : closest;
    }, null);
  };

  /**
   * Ein Nachbarbild darf nicht beliebig weit vom gewünschten Zeitpunkt
   * entfernt sein. Langsamere Kameras erhalten etwas mehr Spielraum, maximal
   * jedoch drei Stunden. Ohne Intervallangabe gelten zwei Stunden.
   */
  const resolveSnapshotTolerance = (camera) => {
    const intervalSeconds = parseDecimal(camera?.interval_seconds);
    if (Number.isFinite(intervalSeconds) && intervalSeconds > 0) {
      return Math.max(1800, Math.min(10800, intervalSeconds * 6));
    }
    return 7200;
  };

  const destinationPoint = (latitude, longitude, distanceKm, bearingDegrees) => {
    const earthRadiusKm = 6371;
    const bearing = bearingDegrees * Math.PI / 180;
    const latitudeRadians = latitude * Math.PI / 180;
    const longitudeRadians = longitude * Math.PI / 180;
    const angularDistance = distanceKm / earthRadiusKm;
    const destinationLatitude = Math.asin(
      (Math.sin(latitudeRadians) * Math.cos(angularDistance))
      + (Math.cos(latitudeRadians) * Math.sin(angularDistance) * Math.cos(bearing))
    );
    const destinationLongitude = longitudeRadians + Math.atan2(
      Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(latitudeRadians),
      Math.cos(angularDistance) - (Math.sin(latitudeRadians) * Math.sin(destinationLatitude))
    );
    return [destinationLatitude * 180 / Math.PI, destinationLongitude * 180 / Math.PI];
  };

  /** Erzeugt die Polygonpunkte für den auf der Karte sichtbaren Blickkegel. */
  const buildSectorPoints = (latitude, longitude, radiusKm, bearingDegrees, fieldOfViewDegrees) => {
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
      || !Number.isFinite(radiusKm) || radiusKm <= 0
      || !Number.isFinite(bearingDegrees) || !Number.isFinite(fieldOfViewDegrees)
      || fieldOfViewDegrees <= 0) {
      return [];
    }
    const span = Math.max(1, Math.min(220, fieldOfViewDegrees));
    const start = bearingDegrees - (span / 2);
    const stop = bearingDegrees + (span / 2);
    const stepCount = Math.max(12, Math.min(48, Math.ceil(span / 4) + 2));
    const points = [[latitude, longitude]];
    for (let index = 0; index <= stepCount; index += 1) {
      const angle = start + ((stop - start) * (index / stepCount));
      points.push(destinationPoint(latitude, longitude, radiusKm, angle));
    }
    points.push([latitude, longitude]);
    return points;
  };

  const create = (dependencies = {}) => {
    const {
      getContext,
      loadCameras,
      loadTimelineDay,
      resolveCurrentSnapshot,
      resolvePreviewUrl,
      resolveAssetUrl,
      buildEntryHref,
      buildCameraHref,
    } = dependencies;

    // Leaflet-Instanzen müssen beim Schließen entfernt werden. Ein bloßes
    // Leeren des Modals ließe Event-Listener und Tile-Layer weiterleben.
    let map = null;

    const destroy = () => {
      if (map) {
        map.remove();
        map = null;
      }
    };

    const isAvailable = () => typeof window.L !== 'undefined';

    const resolveContext = () => {
      const rawContext = typeof getContext === 'function' ? getContext() : null;
      const currentTimestamp = parseInteger(rawContext?.currentTs);
      if (!rawContext?.cameraSlug || !currentTimestamp) {
        return null;
      }
      return {
        ...rawContext,
        currentTs: currentTimestamp,
        label: rawContext.label || formatDateTime(currentTimestamp),
      };
    };

    const buildCurrentSnapshot = (camera, context) => {
      const snapshot = typeof resolveCurrentSnapshot === 'function'
        ? resolveCurrentSnapshot(camera, context)
        : null;
      if (!snapshot || snapshot.slug !== camera?.slug) {
        return null;
      }
      const timestamp = parseInteger(snapshot.timestamp) || context.currentTs;
      const entry = timestamp ? { timestamp } : null;
      return {
        entry,
        previewUrl: snapshot.imageUrl || '',
        href: entry
          ? buildEntryHref?.(entry, camera.slug)
          : buildCameraHref?.(camera.slug),
        capturedAt: snapshot.capturedAt || formatDateTime(timestamp),
        deltaSeconds: Math.abs(timestamp - context.currentTs),
      };
    };

    const resolveSnapshotAtTime = async (camera, context) => {
      if (!camera?.slug || !context) {
        return null;
      }
      const isCurrentCamera = camera.slug === context.cameraSlug;
      if (isCurrentCamera) {
        const currentSnapshot = buildCurrentSnapshot(camera, context);
        if (currentSnapshot) {
          return currentSnapshot;
        }
      }

      const timeline = typeof loadTimelineDay === 'function'
        ? await loadTimelineDay(camera.slug, context, { preferCurrentView: isCurrentCamera })
        : null;
      const entry = findClosestEntry(timeline, context.currentTs);
      if (!entry) {
        return null;
      }
      const deltaSeconds = Math.abs(entry.timestamp - context.currentTs);
      if (deltaSeconds > resolveSnapshotTolerance(camera)) {
        return null;
      }
      return {
        entry,
        previewUrl: resolvePreviewUrl?.(entry) || '',
        href: buildEntryHref?.(entry, camera.slug) || '',
        capturedAt: formatDateTime(entry.timestamp),
        deltaSeconds,
      };
    };

    /** Lädt Nachbarbilder parallel; ein Fehler einer Kamera stoppt nicht alle. */
    const hydrateCameras = async (cameras, context) => {
      if (!context) {
        return cameras;
      }
      const snapshots = await Promise.all(cameras.map(async (camera) => {
        try {
          return await resolveSnapshotAtTime(camera, context);
        } catch (error) {
          console.warn(`surroundings snapshot failed for ${camera?.slug || 'unknown'}`, error);
          return null;
        }
      }));
      return cameras.map((camera, index) => ({
        ...camera,
        surroundings_snapshot: snapshots[index],
        surroundings_snapshot_missing: !snapshots[index],
      }));
    };

    const resolvePopupHref = (camera) => camera?.surroundings_snapshot?.href
      || (camera?.slug ? buildCameraHref?.(camera.slug) : '')
      || '';

    const resolvePopupPreview = (camera) => {
      const snapshot = camera?.surroundings_snapshot;
      const timedPreview = resolvePreviewUrl?.(snapshot?.entry)
        || resolveAssetUrl?.(snapshot?.previewUrl);
      if (timedPreview) {
        return timedPreview;
      }
      if (camera?.surroundings_snapshot_missing) {
        return '';
      }
      const candidates = [
        camera?.current_image_url,
        camera?.resolutions_urls?.['400'],
        camera?.resolutions_urls?.['320'],
        camera?.resolutions_urls?.['640'],
        camera?.full_image_url,
      ];
      for (let index = 0; index < candidates.length; index += 1) {
        const resolved = resolveAssetUrl?.(candidates[index]);
        if (resolved) {
          return resolved;
        }
      }
      return '';
    };

    /**
     * Popup-Inhalte werden ausschließlich mit DOM-APIs und textContent gebaut.
     * outerHTML wird erst ganz am Ende an Leaflet übergeben. Kameranamen oder
     * API-Daten können dadurch kein ausführbares HTML einschleusen.
     */
    const createPopup = (camera, tone, context) => {
      const popup = document.createElement('div');
      popup.className = `surroundings-map__popup surroundings-map__popup--${tone.key}`;

      const heading = document.createElement('div');
      heading.className = 'surroundings-map__popup-heading';
      const title = document.createElement('strong');
      const titleText = resolveTitle(camera);
      title.textContent = titleText;
      const badge = document.createElement('span');
      badge.className = 'surroundings-map__popup-badge';
      badge.textContent = tone.label;
      heading.append(title, badge);
      popup.appendChild(heading);

      const snapshot = camera?.surroundings_snapshot;
      const lines = [];
      if (snapshot?.capturedAt && snapshot.capturedAt !== '—') {
        lines.push(`Aufnahme: ${snapshot.capturedAt}`);
        if (Number.isFinite(snapshot.deltaSeconds) && snapshot.deltaSeconds > 90) {
          lines.push(`Abweichung: ${formatMinutes(snapshot.deltaSeconds)}`);
        }
      } else if (camera?.surroundings_snapshot_missing && context?.label) {
        lines.push('Zum gewählten Zeitpunkt keine passende Aufnahme gefunden');
      } else if (camera?.last_capture_at) {
        const lastTimestamp = Math.floor(new Date(camera.last_capture_at).getTime() / 1000);
        const lastCapture = formatDateTime(lastTimestamp);
        if (lastCapture !== '—') {
          lines.push(`Letzte Aufnahme: ${lastCapture}`);
        }
      }
      const orientation = parseDecimal(camera?.location?.orientation_deg);
      const fieldOfView = parseDecimal(camera?.location?.field_of_view_deg);
      if (Number.isFinite(orientation) && Number.isFinite(fieldOfView) && fieldOfView > 0) {
        lines.push(`Richtung ${Math.round(orientation)}° · Blickfeld ${Math.round(fieldOfView)}°`);
      }
      const meta = document.createElement('div');
      meta.className = 'surroundings-map__popup-meta';
      meta.textContent = (lines.length ? lines : [tone.label]).join(' · ');
      popup.appendChild(meta);

      const href = resolvePopupHref(camera);
      const previewUrl = resolvePopupPreview(camera);
      if (href && previewUrl) {
        const previewLink = document.createElement('a');
        previewLink.className = 'surroundings-map__popup-link';
        previewLink.href = href;
        previewLink.setAttribute('aria-label', `${titleText} öffnen`);
        const image = document.createElement('img');
        image.src = previewUrl;
        image.alt = snapshot?.capturedAt && snapshot.capturedAt !== '—'
          ? `Aufnahme von ${titleText} um ${snapshot.capturedAt}`
          : `Aktuelles Bild von ${titleText}`;
        image.loading = 'lazy';
        previewLink.appendChild(image);
        popup.appendChild(previewLink);
      }
      if (href) {
        const action = document.createElement('a');
        action.className = 'surroundings-map__popup-action';
        action.href = href;
        action.textContent = snapshot ? 'Zur Kamera zu diesem Zeitpunkt' : 'Zur Kamera';
        popup.appendChild(action);
      }
      return popup.outerHTML;
    };

    const bindTooltip = (layer, camera, tone) => {
      const title = resolveTitle(camera);
      if (!layer || !title) {
        return;
      }
      const direction = parseDecimal(camera?.location?.orientation_deg);
      layer.bindTooltip(title, {
        className: `surroundings-map__tooltip surroundings-map__tooltip--${tone.key}`,
        direction: Number.isFinite(direction) && direction > 180 ? 'left' : 'right',
        offset: [10, 0],
        sticky: true,
        opacity: 0.96,
      });
    };

    const createLegend = () => {
      const legend = document.createElement('div');
      legend.className = 'surroundings-map__legend';
      [['Aktuelle Kamera', '#d14343'], ['Weitere Kamera', '#2c7dff']]
        .forEach(([label, color]) => {
          const item = document.createElement('div');
          item.className = 'surroundings-map__legend-item';
          const swatch = document.createElement('span');
          swatch.className = 'surroundings-map__legend-swatch';
          swatch.style.setProperty('--surroundings-color', color);
          const text = document.createElement('span');
          text.textContent = label;
          item.append(swatch, text);
          legend.appendChild(item);
        });
      return legend;
    };

    const createContent = (context) => {
      const container = document.createElement('div');
      container.className = 'surroundings-map';
      const intro = document.createElement('div');
      intro.className = 'surroundings-map__intro';
      intro.textContent = context?.label
        ? `Übersicht zum gewählten Zeitpunkt ${context.label}. Bewege den Mauszeiger über Marker oder Sichtfelder. Ein Klick öffnet die jeweilige Kamera zum nächstliegenden Archivbild.`
        : 'Bewege den Mauszeiger über Marker oder Sichtfelder. Ein Klick öffnet die jeweilige Kamera.';
      const canvas = document.createElement('div');
      canvas.className = 'surroundings-map__canvas';
      container.append(intro, createLegend(), canvas);
      return { container, canvas };
    };

    /**
     * Bereitet den kompletten Modalinhalt vor. Die Leaflet-Karte wird noch nicht
     * erzeugt, weil ein versteckter oder noch nicht eingehängter Container eine
     * falsche Größe erhält.
     */
    const load = async (providedContext = undefined) => {
      const context = providedContext === undefined ? resolveContext() : providedContext;
      const allCameras = typeof loadCameras === 'function' ? await loadCameras() : [];
      const locatedCameras = (Array.isArray(allCameras) ? allCameras : []).filter(hasValidCoordinates);
      if (!locatedCameras.length) {
        return null;
      }
      const cameras = context ? await hydrateCameras(locatedCameras, context) : locatedCameras;
      const content = createContent(context);
      return {
        ...content,
        cameras,
        context,
        currentSlug: context?.cameraSlug || '',
      };
    };

    /** Erst nach renderModal() aufrufen; dann kennt Leaflet die echte Größe. */
    const mount = (prepared) => {
      if (!prepared?.canvas || !Array.isArray(prepared.cameras) || !isAvailable()) {
        return false;
      }
      destroy();
      const leaflet = window.L;
      map = leaflet.map(prepared.canvas, { scrollWheelZoom: false });
      leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(map);

      const focusBounds = leaflet.latLngBounds([]);
      prepared.cameras.forEach((camera) => {
        const latitude = parseDecimal(camera?.location?.latitude);
        const longitude = parseDecimal(camera?.location?.longitude);
        const tone = resolveTone(camera, prepared.currentSlug);
        const popupMarkup = createPopup(camera, tone, prepared.context);
        const isCurrentCamera = camera?.slug === prepared.currentSlug;
        const popupOptions = isCurrentCamera ? { offset: [0, 132] } : undefined;
        focusBounds.extend([latitude, longitude]);

        const bearing = parseDecimal(camera?.location?.orientation_deg);
        const fieldOfView = parseDecimal(camera?.location?.field_of_view_deg);
        const sectorPoints = buildSectorPoints(
          latitude,
          longitude,
          resolveRadiusKm(camera),
          bearing,
          fieldOfView
        );
        if (sectorPoints.length) {
          const sector = leaflet.polygon(sectorPoints, {
            color: tone.wedge,
            fillColor: tone.wedge,
            fillOpacity: tone.key === 'current' ? 0.24 : 0.12,
            weight: tone.key === 'current' ? 2 : 1,
            opacity: 0.9,
            lineCap: 'round',
            lineJoin: 'round',
          }).addTo(map);
          sector.bindPopup(popupMarkup, popupOptions);
          bindTooltip(sector, camera, tone);
        }

        const marker = leaflet.circleMarker([latitude, longitude], {
          radius: tone.key === 'current' ? 8.5 : 6.5,
          color: '#ffffff',
          fillColor: tone.marker,
          fillOpacity: 0.96,
          weight: tone.key === 'current' ? 3 : 2.25,
        }).addTo(map);
        marker.bindPopup(popupMarkup, popupOptions);
        bindTooltip(marker, camera, tone);
      });

      window.requestAnimationFrame(() => {
        if (!map) {
          return;
        }
        map.invalidateSize();
        if (!focusBounds.isValid()) {
          return;
        }
        if (prepared.cameras.length === 1) {
          map.setView(focusBounds.getCenter(), 12);
        } else {
          map.fitBounds(focusBounds, { padding: [18, 18], maxZoom: 11 });
          map.panTo(focusBounds.getCenter(), { animate: false });
        }
      });
      return true;
    };

    return {
      destroy,
      getContext: resolveContext,
      isAvailable,
      load,
      mount,
    };
  };

  window.WebcamCameraSurroundings = { create };
})();
