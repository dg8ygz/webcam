// Interaktive Karten-Vorschau des Kameraeditors.
//
// Leseführung:
// 1. `create()` übernimmt nur den Admin-Wurzelknoten und den Zahlenparser.
// 2. Formularwerte werden gelesen, geprüft und auf sichere Anzeigebereiche begrenzt.
// 3. Wenn Leaflet verfügbar ist, zeigt eine echte Karte Standort und Sichtkegel.
// 4. Ohne Leaflet zeichnet ein Canvas denselben Zusammenhang als einfache Skizze.
// 5. `destroy()` entfernt Karte und Listener vollständig vor jedem Neuaufbau.
//
// Die Vorschau schreibt keine Konfiguration. Änderungen an den Eingabefeldern
// werden nur visualisiert; dauerhaft werden sie weiterhin erst über den
// zentralen Speichervorgang in admin.js übernommen.
(function registerAdminMapPreview(global) {
  "use strict";

  const DEFAULT_CENTER = Object.freeze([51.163361, 10.447683]);
  const EARTH_RADIUS_KM = 6371;

  /**
   * Die Abhängigkeiten werden von admin.js übergeben. Dadurch verwendet die
   * Komponente denselben DOM-Baum und dieselbe Zahlenkonvertierung wie der
   * übrige Kameraeditor, ohne einen eigenen Browserzustand einzuführen.
   */
  const create = ({ root, toNumber }) => {
    if (!root || typeof toNumber !== "function") {
      throw new Error("Admin map preview dependencies are incomplete");
    }

    let leafletMap = null;
    let resizeHandler = null;
    let inputCleanups = [];
    const pendingFrames = new Set();

    // -----------------------------------------------------------------------
    // Lebenszyklus und Listenerverwaltung.
    // Ein vollständiges Admin-Rendern ersetzt die Formularfelder. Trotzdem
    // räumen wir alle Listener kontrolliert auf, damit auch wiederholte
    // Tabwechsel innerhalb desselben Formulars keine Mehrfachaufrufe erzeugen.
    // -----------------------------------------------------------------------
    const removeListeners = () => {
      inputCleanups.forEach((cleanup) => cleanup());
      inputCleanups = [];

      // Ein bereits vorgemerkter Zeichenlauf darf nach dem Schließen nicht
      // mehr auf eine entfernte Leaflet-Karte oder ein altes Canvas zugreifen.
      pendingFrames.forEach((frameId) => global.cancelAnimationFrame(frameId));
      pendingFrames.clear();

      if (resizeHandler) {
        global.removeEventListener("resize", resizeHandler);
        resizeHandler = null;
      }
    };

    const scheduleFrame = (callback) => {
      const frameId = global.requestAnimationFrame(() => {
        pendingFrames.delete(frameId);
        callback();
      });
      pendingFrames.add(frameId);
    };

    const destroy = (showPlaceholder = true) => {
      removeListeners();

      if (leafletMap) {
        leafletMap.remove();
        leafletMap = null;
      }

      const container = root.querySelector("#camera-map-preview");
      if (container && showPlaceholder) {
        container.innerHTML =
          '<div class="map-preview__placeholder">Karte ausgeblendet.</div>';
      }
    };

    const attachInputListeners = (inputs, callback) => {
      inputs.forEach((input) => {
        if (!input) return;
        const handler = () => scheduleFrame(callback);
        input.addEventListener("input", handler);
        inputCleanups.push(() => input.removeEventListener("input", handler));
      });
    };

    // -----------------------------------------------------------------------
    // Formularwerte und fachliche Grenzen.
    // Ungültige Koordinaten werden nicht heimlich ersetzt: Die Ansicht zeigt
    // dann bewusst den Deutschland-Ausschnitt beziehungsweise einen Hinweis.
    // Nur reine Darstellungswerte wie Sichtfeld und Versatz werden begrenzt.
    // -----------------------------------------------------------------------
    const collectFields = (form) => {
      const query = (name) => form.querySelector(`[name="${name}"]`);
      return {
        latitude: query("latitude"),
        longitude: query("longitude"),
        bearing: query("orientation_deg"),
        fieldOfView: query("field_of_view_deg"),
        offsetY: query("map_center_offset"),
        offsetX: query("map_center_offset_x"),
        range: query("orientation_visualization_km"),
      };
    };

    const clamp = (value, minimum, maximum) =>
      Math.max(minimum, Math.min(maximum, value));

    const readValues = (fields) => ({
      lat: toNumber(fields.latitude?.value ?? "", Number.NaN),
      lng: toNumber(fields.longitude?.value ?? "", Number.NaN),
      bearing: toNumber(fields.bearing?.value ?? "", 0),
      fov: clamp(toNumber(fields.fieldOfView?.value ?? "", 60), 1, 180),
      offsetX: clamp(toNumber(fields.offsetX?.value ?? "", 0), -0.9, 0.9),
      offsetY: clamp(toNumber(fields.offsetY?.value ?? "", 0), -0.9, 0.9),
      rangeKm: Math.max(0.1, toNumber(fields.range?.value ?? "", 5)),
    });

    const hasValidCoordinates = ({ lat, lng }) =>
      Number.isFinite(lat) &&
      Number.isFinite(lng) &&
      Math.abs(lat) <= 90 &&
      Math.abs(lng) <= 180;

    const fieldList = (fields) => [
      fields.latitude,
      fields.longitude,
      fields.bearing,
      fields.fieldOfView,
      fields.offsetX,
      fields.offsetY,
      fields.range,
    ];

    // -----------------------------------------------------------------------
    // Geografische Hilfsrechnung.
    // Für die beiden Außenkanten und die Mittellinie des Sichtkegels wird aus
    // Startpunkt, Entfernung und Peilung jeweils ein Zielpunkt berechnet.
    // -----------------------------------------------------------------------
    const destination = (lat, lng, distanceKm, bearingDeg) => {
      const bearing = (bearingDeg * Math.PI) / 180;
      const latRadians = (lat * Math.PI) / 180;
      const lngRadians = (lng * Math.PI) / 180;
      const angularDistance = distanceKm / EARTH_RADIUS_KM;
      const destinationLat = Math.asin(
        Math.sin(latRadians) * Math.cos(angularDistance) +
          Math.cos(latRadians) * Math.sin(angularDistance) * Math.cos(bearing),
      );
      const destinationLng =
        lngRadians +
        Math.atan2(
          Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(latRadians),
          Math.cos(angularDistance) - Math.sin(latRadians) * Math.sin(destinationLat),
        );

      return [destinationLat * 180 / Math.PI, destinationLng * 180 / Math.PI];
    };

    // -----------------------------------------------------------------------
    // Leaflet-Darstellung.
    // Marker, Sichtkegel und gestrichelte Blickrichtung liegen in einer
    // gemeinsamen Layer-Gruppe und werden bei jeder Eingabe neu aufgebaut.
    // Der Kartenmittelpunkt wird anschließend um den konfigurierten Versatz
    // verschoben, ohne die gespeicherten Kamerakoordinaten zu verändern.
    // -----------------------------------------------------------------------
    const renderLeaflet = (container, fields) => {
      container.innerHTML = "";
      const mapElement = document.createElement("div");
      mapElement.className = "map-preview__leaflet";
      container.appendChild(mapElement);

      const map = global.L.map(mapElement, {
        zoomControl: false,
        attributionControl: false,
      });
      leafletMap = map;
      const overlayGroup = global.L.layerGroup().addTo(map);
      global.L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 17,
        minZoom: 3,
      }).addTo(map);

      const redraw = () => {
        const values = readValues(fields);
        const valid = hasValidCoordinates(values);
        const center = valid ? [values.lat, values.lng] : DEFAULT_CENTER;

        map.setView(center, valid ? 13 : 5);
        overlayGroup.clearLayers();
        global.L.marker(center).addTo(overlayGroup);
        if (!valid) return;

        const halfFieldOfView = values.fov / 2;
        const left = destination(
          values.lat,
          values.lng,
          values.rangeKm,
          values.bearing - halfFieldOfView,
        );
        const right = destination(
          values.lat,
          values.lng,
          values.rangeKm,
          values.bearing + halfFieldOfView,
        );
        const arrowHead = destination(
          values.lat,
          values.lng,
          values.rangeKm,
          values.bearing,
        );

        global.L.polygon([center, left, right], {
          color: "#2563eb",
          fillColor: "#60a5fa",
          fillOpacity: 0.2,
          weight: 1.2,
        }).addTo(overlayGroup);
        global.L.polyline([center, arrowHead], {
          color: "#1d4ed8",
          weight: 2.5,
          opacity: 0.85,
          dashArray: "8 6",
        }).addTo(overlayGroup);

        const size = map.getSize();
        const point = map.latLngToContainerPoint(center);
        const desiredX = size.x * (0.5 + values.offsetX);
        const desiredY = size.y * (0.5 + values.offsetY);
        const deltaX = desiredX - point.x;
        const deltaY = desiredY - point.y;
        if (Math.abs(deltaX) > 1 || Math.abs(deltaY) > 1) {
          map.panBy([-deltaX, -deltaY], { animate: false });
        }
      };

      attachInputListeners(fieldList(fields), redraw);
      resizeHandler = () => map.invalidateSize();
      global.addEventListener("resize", resizeHandler);
      redraw();
    };

    // -----------------------------------------------------------------------
    // Canvas-Fallback.
    // Diese Darstellung benötigt weder Kartendienst noch Netzwerkzugriff. Sie
    // zeigt Position, Blickrichtung, Sichtfeld und Versatz schematisch, damit
    // das Formular auch bei fehlendem Leaflet verständlich bedienbar bleibt.
    // -----------------------------------------------------------------------
    const renderCanvas = (container, fields) => {
      const canvas = document.createElement("canvas");
      const context = canvas.getContext("2d");
      if (!context) {
        container.innerHTML =
          '<div class="map-preview__placeholder">Canvas nicht verfügbar.</div>';
        return;
      }

      const resizeCanvas = () => {
        canvas.width = container.clientWidth || 560;
        canvas.height = container.clientHeight || 280;
      };

      container.innerHTML = "";
      container.appendChild(canvas);

      const redraw = () => {
        resizeCanvas();
        const { width, height } = canvas;
        const values = readValues(fields);
        const valid = hasValidCoordinates(values);
        const centerX = width * (0.5 + values.offsetX);
        const centerY = height * (0.5 + values.offsetY);

        context.clearRect(0, 0, width, height);
        const gradient = context.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, "#0f172a");
        gradient.addColorStop(1, "#1e3a8a");
        context.fillStyle = gradient;
        context.fillRect(0, 0, width, height);

        context.strokeStyle = "rgba(255,255,255,0.08)";
        for (let y = 40; y < height; y += 40) {
          context.beginPath();
          context.moveTo(0, y);
          context.lineTo(width, y);
          context.stroke();
        }
        for (let x = 40; x < width; x += 40) {
          context.beginPath();
          context.moveTo(x, 0);
          context.lineTo(x, height);
          context.stroke();
        }

        context.fillStyle = "rgba(255,255,255,0.85)";
        context.font = "600 14px system-ui";
        context.textAlign = "left";
        context.fillText(
          valid
            ? `Lat ${values.lat.toFixed(4)}, Lon ${values.lng.toFixed(4)}`
            : "Koordinaten fehlen",
          12,
          22,
        );
        context.font = "12px system-ui";
        context.fillText(
          `Ausrichtung ${values.bearing.toFixed(1)}°, Sichtfeld ${values.fov.toFixed(1)}°`,
          12,
          40,
        );

        if (valid) {
          const baseReach = Math.min(height * 0.45, 220);
          const reachScale = clamp(values.rangeKm / 5, 0.2, 3);
          const reach = baseReach * reachScale;
          const bearingRadians = ((values.bearing - 90) * Math.PI) / 180;
          const halfFieldRadians = (values.fov * Math.PI) / 360;
          const leftAngle = bearingRadians - halfFieldRadians;
          const rightAngle = bearingRadians + halfFieldRadians;

          context.beginPath();
          context.moveTo(centerX, centerY);
          context.lineTo(
            centerX + Math.cos(leftAngle) * reach,
            centerY + Math.sin(leftAngle) * reach,
          );
          context.lineTo(
            centerX + Math.cos(rightAngle) * reach,
            centerY + Math.sin(rightAngle) * reach,
          );
          context.closePath();
          context.fillStyle = "rgba(96, 165, 250, 0.35)";
          context.fill();
          context.strokeStyle = "rgba(37, 99, 235, 0.9)";
          context.lineWidth = 2;
          context.stroke();

          context.strokeStyle = "#2563eb";
          context.setLineDash([10, 6]);
          context.lineWidth = 2.5;
          context.beginPath();
          context.moveTo(centerX, centerY);
          context.lineTo(
            centerX + Math.cos(bearingRadians) * reach,
            centerY + Math.sin(bearingRadians) * reach,
          );
          context.stroke();
          context.setLineDash([]);
        } else {
          context.fillStyle = "rgba(255,255,255,0.7)";
          context.textAlign = "center";
          context.font = "14px system-ui";
          context.fillText("Bitte Latitude und Longitude angeben", centerX, centerY);
          context.textAlign = "left";
        }

        context.fillStyle = "#fff";
        context.beginPath();
        context.arc(centerX, centerY, 6, 0, Math.PI * 2);
        context.fill();
      };

      attachInputListeners(fieldList(fields), redraw);
      resizeHandler = () => scheduleFrame(redraw);
      global.addEventListener("resize", resizeHandler);
      redraw();
    };

    // -----------------------------------------------------------------------
    // Öffentlicher Einstieg.
    // Nur ein sichtbarer Karten-Tab wird initialisiert. Vorheriger Zustand wird
    // immer entfernt; dadurch ist `init()` gefahrlos mehrfach aufrufbar.
    // -----------------------------------------------------------------------
    const init = () => {
      const mapPanel = root.querySelector('[data-tab-panel="map"]');
      if (mapPanel && !mapPanel.classList.contains("is-active")) {
        destroy(false);
        return;
      }

      destroy(false);
      const container = root.querySelector("#camera-map-preview");
      if (!container) return;

      const form = root.querySelector(".camera-form");
      if (!form) {
        container.innerHTML =
          '<div class="map-preview__placeholder">Kein Formular aktiv.</div>';
        return;
      }

      const fields = collectFields(form);
      if (global.L?.map && global.L?.layerGroup) {
        renderLeaflet(container, fields);
      } else {
        renderCanvas(container, fields);
      }
    };

    return Object.freeze({ init, destroy });
  };

  global.WebcamAdminMapPreview = Object.freeze({ create });
})(window);
