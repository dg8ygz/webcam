(() => {
  'use strict';

  // Dieses Modul zeichnet ausschließlich die Standortkarte unter dem
  // Kamerabild. Die PHP-Seite liefert dafür ein kleines JSON-Paket am
  // Kartencontainer; Datenbank-, Timeline- und Navigationslogik bleiben auf
  // dem Server. So kann die Kartenlogik unabhängig von camera.php gelesen und
  // im Browser getestet werden.
  const container = document.getElementById('camera-map');
  if (!container) {
    return;
  }

  const parseMapConfig = () => {
    const encoded = container.dataset.mapConfig || '';
    if (!encoded) {
      return null;
    }
    try {
      const config = JSON.parse(encoded);
      return config && typeof config === 'object' ? config : null;
    } catch (error) {
      console.error('Kartenkonfiguration ist ungültig.', error);
      return null;
    }
  };

  const rawConfig = parseMapConfig();
  if (!rawConfig) {
    return;
  }

  // Zahlen aus der Konfiguration werden einmal an der Modulgrenze geprüft.
  // Damit rechnen die Geometriefunktionen nur noch mit endlichen Zahlen und
  // benötigen keine verteilten Fallbacks.
  const numberOr = (value, fallback) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
  };
  const data = {
    lat: numberOr(rawConfig.lat, NaN),
    lng: numberOr(rawConfig.lng, NaN),
    bearing: numberOr(rawConfig.bearing, 0),
    fov: numberOr(rawConfig.fov, 60),
    reachKm: numberOr(rawConfig.reachKm, 5),
    title: String(rawConfig.title || ''),
    centerOffsetY: numberOr(rawConfig.centerOffsetY, numberOr(rawConfig.centerOffset, 0)),
    centerOffsetX: numberOr(rawConfig.centerOffsetX, 0),
  };
  if (!Number.isFinite(data.lat) || !Number.isFinite(data.lng)) {
    console.error('Kartenkoordinaten fehlen oder sind ungültig.');
    return;
  }

  let initialized = false;

  /**
   * Berechnet von einem Startpunkt aus einen Zielpunkt auf der Erdkugel.
   * Die Funktion wird für die beiden Außenkanten des Sichtfeldes verwendet.
   */
  const destination = (lat, lng, distanceKm, bearingDeg) => {
    const radiusEarthKm = 6371;
    const bearing = bearingDeg * Math.PI / 180;
    const latitude = lat * Math.PI / 180;
    const longitude = lng * Math.PI / 180;
    const angularDistance = distanceKm / radiusEarthKm;
    const targetLatitude = Math.asin(
      Math.sin(latitude) * Math.cos(angularDistance)
      + Math.cos(latitude) * Math.sin(angularDistance) * Math.cos(bearing)
    );
    const targetLongitude = longitude + Math.atan2(
      Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(latitude),
      Math.cos(angularDistance) - Math.sin(latitude) * Math.sin(targetLatitude)
    );
    return [targetLatitude * 180 / Math.PI, targetLongitude * 180 / Math.PI];
  };

  const initializeMap = () => {
    if (initialized || typeof window.L === 'undefined') {
      return;
    }
    initialized = true;

    const map = window.L.map(container);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    map.setView([data.lat, data.lng], 13);

    // Der Popup-Titel wird als DOM-Knoten übergeben. Dadurch behandelt Leaflet
    // einen administrativ gepflegten Kameratitel als Text und niemals als HTML.
    const marker = window.L.marker([data.lat, data.lng]).addTo(map);
    const popupTitle = document.createElement('strong');
    popupTitle.textContent = data.title;
    marker.bindPopup(popupTitle);

    // Leaflets Standard-Polygon approximiert Kreisbögen mit Geraden. Für das
    // Blickfeld wird daher ein einzelner SVG-Pfad im Leaflet-Layer gepflegt.
    // Dessen Endpunkte werden nach Zoom oder Verschiebung neu projiziert.
    const buildSectorArcPath = () => {
      const span = Math.max(1, Math.min(220, data.fov));
      const radiusKm = Math.max(0.1, data.reachKm);
      const halfSpan = span / 2;
      const leftLatLng = destination(data.lat, data.lng, radiusKm, data.bearing - halfSpan);
      const rightLatLng = destination(data.lat, data.lng, radiusKm, data.bearing + halfSpan);
      const center = map.latLngToLayerPoint([data.lat, data.lng]);
      const left = map.latLngToLayerPoint(leftLatLng);
      const right = map.latLngToLayerPoint(rightLatLng);
      const radiusLeft = Math.hypot(left.x - center.x, left.y - center.y);
      const radiusRight = Math.hypot(right.x - center.x, right.y - center.y);
      const radius = Math.max(1, (radiusLeft + radiusRight) / 2);
      const largeArcFlag = span > 180 ? 1 : 0;
      return [
        `M${center.x} ${center.y}`,
        `L${left.x} ${left.y}`,
        `A${radius} ${radius} 0 ${largeArcFlag} 1 ${right.x} ${right.y}`,
        'Z',
      ].join(' ');
    };

    const svgRenderer = window.L.svg();
    svgRenderer.addTo(map);
    let wedgePath = null;
    const ensureWedgePath = () => {
      const root = svgRenderer
        && (svgRenderer._rootGroup
          || (svgRenderer._container && svgRenderer._container.querySelector('g')));
      if (!root) {
        return null;
      }
      if (!wedgePath) {
        wedgePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        wedgePath.setAttribute('stroke', '#2c6fbb');
        wedgePath.setAttribute('stroke-width', '1');
        wedgePath.setAttribute('stroke-linecap', 'round');
        wedgePath.setAttribute('stroke-linejoin', 'round');
        wedgePath.setAttribute('fill', '#2c6fbb');
        wedgePath.setAttribute('fill-opacity', '0.15');
        wedgePath.setAttribute('pointer-events', 'none');
        root.appendChild(wedgePath);
      }
      return wedgePath;
    };

    const renderWedge = () => {
      const path = ensureWedgePath();
      if (!path) {
        return;
      }
      const pathData = buildSectorArcPath();
      if (pathData) {
        path.setAttribute('d', pathData);
      } else {
        path.removeAttribute('d');
      }
    };
    map.on('zoom viewreset move resize', renderWedge);
    renderWedge();

    // Konfigurierbare Offsets verschieben nicht den Marker, sondern den
    // Kartenausschnitt. Positive Werte rücken den Marker optisch nach rechts
    // beziehungsweise unten; extreme Werte werden auf 90 Prozent begrenzt.
    map.whenReady(() => {
      const clampOffset = (value) => Math.max(-0.9, Math.min(0.9, value));
      const offsetY = clampOffset(data.centerOffsetY);
      const offsetX = clampOffset(data.centerOffsetX);
      if (offsetX !== 0 || offsetY !== 0) {
        const size = map.getSize();
        const desiredX = size.x * (0.5 + offsetX);
        const desiredY = size.y * (0.5 + offsetY);
        const point = map.latLngToContainerPoint([data.lat, data.lng]);
        const deltaX = desiredX - point.x;
        const deltaY = desiredY - point.y;
        if (Math.abs(deltaX) > 1 || Math.abs(deltaY) > 1) {
          map.panBy([-deltaX, -deltaY], { animate: false });
        }
      }
      renderWedge();
      container.dataset.mapInitialized = '1';
    });
  };

  // Die große Kameraaufnahme soll zuerst erscheinen. Sobald camera.js deren
  // Ladeereignis meldet, wird die Karte aufgebaut. Der Timeout stellt sicher,
  // dass die Karte auch bei einem fehlenden oder defekten Bild sichtbar wird.
  const launch = () => {
    if (window.cameraImageReady) {
      initializeMap();
      return;
    }
    let resolved = false;
    const trigger = () => {
      if (resolved) {
        return;
      }
      resolved = true;
      window.removeEventListener('camera:image-ready', trigger);
      initializeMap();
    };
    window.addEventListener('camera:image-ready', trigger);
    window.setTimeout(trigger, 2500);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', launch, { once: true });
  } else {
    launch();
  }
})();
