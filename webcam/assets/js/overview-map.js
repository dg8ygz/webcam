(() => {
  'use strict';

  // Eigenständige Leaflet-Karte der Übersicht. Sie erhält ausschließlich die
  // bereits vom Server normalisierten Kartendaten und verändert keine Karten.
  // -------------------------------------------------------------------------
  // Übersichtskarte.
  //
  // Marker und Sichtfelder teilen sich dasselbe Popup. Alle veränderlichen
  // Texte werden vor der Übergabe an Leaflet escaped; navigiert wird nur über
  // den Bild- oder Detaillink, nicht durch einen bloßen Markerklick.
  // -------------------------------------------------------------------------
  const initOverviewMap = (config) => {
    const shell = document.getElementById('webcam-map');
    if (!shell) return;
    if (!window.L) {
      console.warn('overview map unavailable: Leaflet was not loaded');
      return;
    }

    const markers = Array.isArray(config.markers) ? config.markers : [];
    const baseUrl = typeof config.baseUrl === 'string' ? config.baseUrl : '';
    const mapConfig = config.map && typeof config.map === 'object' ? config.map : {};
    const map = window.L.map(shell);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    const escapeHtml = (value) => String(value ?? '').replace(
      /[&<>"']/g,
      (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
      })[character],
    );
    const destination = (lat, lng, distanceKm, bearingDeg) => {
      const radiusEarthKm = 6371;
      const bearing = bearingDeg * Math.PI / 180;
      const latRad = lat * Math.PI / 180;
      const lngRad = lng * Math.PI / 180;
      const angularDistance = distanceKm / radiusEarthKm;
      const destinationLat = Math.asin(
        Math.sin(latRad) * Math.cos(angularDistance)
        + Math.cos(latRad) * Math.sin(angularDistance) * Math.cos(bearing),
      );
      const destinationLng = lngRad + Math.atan2(
        Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(latRad),
        Math.cos(angularDistance) - Math.sin(latRad) * Math.sin(destinationLat),
      );
      return [
        destinationLat * 180 / Math.PI,
        destinationLng * 180 / Math.PI,
      ];
    };

    const bounds = [];
    markers.forEach((marker) => {
      const latitude = Number(marker?.lat);
      const longitude = Number(marker?.lng);
      const bearing = Number(marker?.bearing);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

      const normalizedBearing = Number.isFinite(bearing) ? bearing : 0;
      const icon = window.L.divIcon({
        className: 'webcam-marker',
        html: `<div class="webcam-marker-arrow" style="transform: rotate(${normalizedBearing}deg)"></div>`,
      });
      const leafletMarker = window.L.marker([latitude, longitude], { icon });
      const bearingText = `${normalizedBearing.toFixed(0)}°`;
      const fovValue = Number(marker?.fov);
      const fov = Number.isFinite(fovValue) && fovValue > 0 ? fovValue : 45;
      const fovText = marker?.fov ? ` · FOV ${marker.fov}` : '';
      const targetUrl = `${baseUrl}camera/${encodeURIComponent(String(marker?.slug || ''))}`;
      const thumbHtml = marker?.thumbUrl
        ? `<a class="overview-map-popup__image" href="${escapeHtml(targetUrl)}"><img src="${escapeHtml(marker.thumbUrl)}" alt="Aktuelles Bild von ${escapeHtml(marker.title)}" loading="lazy"></a>`
        : '';
      const captureHtml = marker?.lastCapture
        ? `<div class="overview-map-popup__time">${escapeHtml(marker.lastCapture)}</div>`
        : '';
      const popupHtml = `<div class="overview-map-popup">${thumbHtml}<div class="overview-map-popup__body"><strong>${escapeHtml(marker?.title)}</strong>${captureHtml}<div class="overview-map-popup__meta">Blickrichtung: ${escapeHtml(bearingText)}${escapeHtml(fovText)}</div><a class="overview-map-popup__action" href="${escapeHtml(targetUrl)}">Details ansehen</a></div></div>`;
      const popupOptions = { maxWidth: 280, autoPan: true };

      leafletMarker.bindPopup(popupHtml, popupOptions);
      leafletMarker.on('click', () => leafletMarker.openPopup());
      leafletMarker.on('mouseover focus', () => leafletMarker.openPopup());

      const halfFov = fov / 2;
      const reachKm = 15;
      const left = destination(latitude, longitude, reachKm, normalizedBearing - halfFov);
      const right = destination(latitude, longitude, reachKm, normalizedBearing + halfFov);
      const wedge = window.L.polygon([[latitude, longitude], left, right], {
        color: '#2c6fbb',
        fillColor: '#2c6fbb',
        fillOpacity: 0.1,
        weight: 1,
      });
      wedge.bindPopup(popupHtml, popupOptions);
      wedge.on('click', () => wedge.openPopup());
      wedge.on('mouseover', () => wedge.openPopup());
      wedge.addTo(map);
      leafletMarker.addTo(map);
      bounds.push([latitude, longitude]);
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [20, 20] });
    }

    const latitude = Number(mapConfig.lat);
    const longitude = Number(mapConfig.lng);
    if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
      map.setView([latitude, longitude], Number(mapConfig.zoom) || 11);
      shell.style.height = `${Number(mapConfig.height_px) || 220}px`;
    } else if (!bounds.length) {
      map.setView([51.96, 8.53], 11);
    }
  };

  window.WebcamOverviewMap = Object.freeze({ init: initOverviewMap });
})();
