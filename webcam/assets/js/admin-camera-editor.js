// Renderer des Kamera-Editors im Admin-Panel.
//
// Leseführung:
// 1. `resolveCamera()` bestimmt, welche Kamera beziehungsweise Neuanlage gezeigt wird.
// 2. `buildContext()` bereitet gemeinsame, noch nicht gerenderte Formularwerte auf.
// 3. Kleine Renderer erzeugen jeweils genau einen fachlichen Kameraabschnitt.
// 4. `buildSections()` legt Reihenfolge, Titel und Speicherbarkeit der Tabs fest.
// 5. Navigation, Panels und Formularrahmen werden erst am Ende zusammengesetzt.
//
// Das Modul rendert ausschließlich HTML. Es bindet keine Listener, sendet keine
// API-Requests und verändert weder Config- noch Kamera-DB. Speichern,
// Validierung und DOM-Lebenszyklus bleiben bei den dafür vorgesehenen
// Komponenten. Sämtliche dynamischen Attribut- und Textwerte werden maskiert.
(function registerAdminCameraEditor(global) {
  "use strict";

  const CAPTURE_MODES = Object.freeze(["active-capture", "pull-snapshot", "passive-upload"]);
  const CAPTURE_TRANSPORTS = Object.freeze(["http-get", "rtsp"]);
  const DEFAULT_WATCHDOG = Object.freeze({
    enabled: true,
    max_consecutive_failures: 3,
    reset_sequence: Object.freeze([]),
    cooldown: Object.freeze({
      min_successes_after_reset: 2,
      min_seconds_after_reset: 1200,
    }),
  });

  /**
   * Der Renderer erhält den zentralen Browserzustand und die spezialisierten
   * Unterrenderer aus admin.js. Dadurch entstehen weder ein zweiter Store noch
   * Parallelimplementierungen für Overlay, Labels oder Capture-Metadaten.
   */
  const create = ({
    state,
    defaultCamera,
    splitCameraTags,
    cameraHasInternalTag,
    internalCameraTags,
    escapeHtml,
    overlayEditor,
    labelEditor,
    captureEditor,
  }) => {
    if (
      !state
      || typeof defaultCamera !== "function"
      || typeof splitCameraTags !== "function"
      || typeof cameraHasInternalTag !== "function"
      || !internalCameraTags
      || typeof escapeHtml !== "function"
      || typeof overlayEditor?.renderVariantSection !== "function"
      || typeof labelEditor?.renderRows !== "function"
      || typeof captureEditor?.renderSection !== "function"
    ) {
      throw new Error("Admin camera editor dependencies are incomplete");
    }

    const safe = (value) => escapeHtml(String(value ?? ""));
    const checked = (value) => (value ? "checked" : "");

    // -----------------------------------------------------------------------
    // Kameraquelle und gemeinsames View-Model.
    // Der Renderer darf fehlende Altwerte sichtbar mit denselben Formular-
    // defaults darstellen, speichert diese aber nicht eigenständig zurück.
    // -----------------------------------------------------------------------
    const resolveCamera = (cameraInput, creating) => {
      if (cameraInput) return cameraInput;
      if (creating) {
        const camera = defaultCamera();
        camera.resolutions = state.config?.defaults?.resolutions ?? [];
        return camera;
      }
      if (!state.selectedSlug) return null;
      return state.config?.cameras?.[state.selectedSlug] ?? null;
    };

    const buildContext = (camera, creating) => {
      const capture = camera.capture ?? {};
      const location = camera.location ?? {};
      const storage = camera.storage ?? {};
      const stream = camera.stream ?? {};
      const watchdog = camera.status?.watchdog ?? DEFAULT_WATCHDOG;
      const formId = creating ? "camera-form-new" : `camera-form-${camera.slug ?? ""}`;
      const hasRtspInput = capture.transport === "rtsp" || stream.mode === "rtsp";

      return {
        camera,
        capture,
        location,
        storage,
        stream,
        watchdog,
        creating,
        formId,
        id: (suffix) => safe(`${formId}-${suffix}`),
        tags: splitCameraTags(camera.tags ?? []).visible.join(", "),
        extraTimes: Array.isArray(capture.extra_times) ? capture.extra_times.join(", ") : "",
        weatherCommands: Array.isArray(camera.weather_commands)
          ? camera.weather_commands.join("\n\n")
          : "",
        resetSequence: JSON.stringify(watchdog.reset_sequence ?? [], null, 2),
        hasRtspInput,
        rtspSettingsAttribute: hasRtspInput ? "" : " hidden",
        rtspFieldAttribute: hasRtspInput ? "" : " disabled",
        sectionSaving: state.sectionSaving?.[camera.slug] ?? {},
        nativeDisplayEnabled: cameraHasInternalTag(camera, internalCameraTags.nativeDisplay),
        nightDenoiseEnabled: cameraHasInternalTag(camera, internalCameraTags.nightDenoise),
        testCameraEnabled: cameraHasInternalTag(camera, internalCameraTags.testCamera),
        runtimeAccess: state.cameraRuntime?.[camera.slug] ?? camera._runtime ?? null,
      };
    };

    const section = (id, title, subtitle, content, saveable = true) => ({
      id,
      title,
      subtitle,
      content,
      saveable,
    });

    // -----------------------------------------------------------------------
    // Basisdaten und redaktionelle Texte.
    // Info-HTML wird hier als maskierter Text im Editor ausgegeben. Erst die
    // serverseitige Sanitizer-Stufe entscheidet, welche Elemente öffentlich
    // gerendert werden dürfen.
    // -----------------------------------------------------------------------
    const renderGeneral = (ctx) => section(
      "general",
      "Allgemein",
      "Basisdaten und Anzeigeinformationen",
      `<div class="form-grid">
        <div class="form-field">
          <label for="${ctx.id("slug")}">Slug</label>
          <input id="${ctx.id("slug")}" name="slug" value="${safe(ctx.camera.slug)}" ${ctx.creating ? "" : "readonly"}
            required pattern="[a-z0-9-]+" data-validate="slug">
          <small class="form-hint">Nur Kleinbuchstaben, Zahlen und Bindestriche.</small>
        </div>
        <div class="form-field">
          <label for="${ctx.id("name")}">Name</label>
          <input id="${ctx.id("name")}" name="name" required value="${safe(ctx.camera.name)}" data-validate="text">
        </div>
        <div class="form-field">
          <label for="${ctx.id("title")}">Titel</label>
          <input id="${ctx.id("title")}" name="title" required value="${safe(ctx.camera.title)}" data-validate="text">
        </div>
        <div class="form-field">
          <label for="${ctx.id("short")}">Kurzbezeichnung</label>
          <input id="${ctx.id("short")}" name="short_title" value="${safe(ctx.camera.short_title)}" data-validate="text">
        </div>
        <div class="form-field">
          <label for="${ctx.id("tags")}">Tags</label>
          <input id="${ctx.id("tags")}" name="tags" value="${safe(ctx.tags)}">
        </div>
        <div class="form-field">
          <label class="checkbox-inline" for="${ctx.id("test-camera")}">
            <input id="${ctx.id("test-camera")}" name="test_camera" type="checkbox" ${checked(ctx.testCameraEnabled)}>
            <span>Testkamera – nicht öffentlich auflisten</span>
          </label>
          <small class="form-hint">Aus Übersicht, Karte, Kameramenüs, globalem Best-of, Status- und Wetterübersicht ausblenden.
            Direkter Aufruf über den frei gewählten Slug bleibt vollständig nutzbar. Kein Passwortschutz: Wer den Link kennt, kann die Kamera sehen.</small>
        </div>
      </div>`,
    );

    const renderInfo = (ctx) => section(
      "info",
      "Texte",
      "Beschreibung und Info-HTML",
      `<div class="form-field">
        <label for="${ctx.id("description")}">Beschreibung</label>
        <textarea id="${ctx.id("description")}" name="description" rows="4">${safe(ctx.camera.description)}</textarea>
      </div>
      <div class="form-field">
        <label for="${ctx.id("info-html")}">HTML-Inhalt</label>
        <textarea id="${ctx.id("info-html")}" name="info_html" rows="10">${safe(ctx.camera.info_html)}</textarea>
      </div>`,
    );

    // -----------------------------------------------------------------------
    // Anzeigeoptionen und Standort.
    // Reservierte interne Tags werden als verständliche Checkboxen gezeigt,
    // bleiben aber aus dem öffentlichen Tag-Freitextfeld ausgeschlossen.
    // -----------------------------------------------------------------------
    const renderLiveView = (ctx) => section(
      "liveview",
      "Live-Ansicht",
      "Hinweise zur Anzeige",
      `<div class="form-field">
        <p class="form-hint">
          Wenn ein Livestream konfiguriert ist, wird im Optionen-Menü der Kamera das
          <strong>Livebild</strong> ausgeblendet. Verfügbar sind dann nur
          <strong>Livestream</strong> und <strong>Snapshot</strong>.
        </p>
      </div>
      <div class="form-field">
        <label class="checkbox-inline" for="${ctx.id("native-display")}">
          <input id="${ctx.id("native-display")}" name="native_display" type="checkbox" ${checked(ctx.nativeDisplayEnabled)}>
          <span>Livebild nur in nativer Quellgröße anzeigen</span>
        </label>
        <small class="form-hint">
          Für Kameras mit bewusst kleiner Datenschutz-Quelle. Das Bild wird dann nicht mehr
          auf die volle Seitenbreite hochgezogen, sondern mittig in seiner echten Quellbreite dargestellt.
        </small>
        <div class="form-notice form-notice--warn">
          Nur aktivieren, wenn die Quelle absichtlich klein geliefert wird. Die sichtbare Bildfläche
          wird dadurch bewusst kleiner.
        </div>
      </div>
      <div class="form-field">
        <label class="checkbox-inline" for="${ctx.id("night-denoise")}">
          <input id="${ctx.id("night-denoise")}" name="night_denoise" type="checkbox" ${checked(ctx.nightDenoiseEnabled)}>
          <span>Leichte Nacht-Rauschminderung für Anzeigevarianten aktivieren</span>
        </label>
        <small class="form-hint">
          Nur für Kameras mit starkem Dunkelrauschen. Der Filter greift nur bei niedriger Luminanz
          und nur auf abgeleitete Anzeigevarianten, nicht auf das archivierte Original.
        </small>
        <div class="form-notice form-notice--warn">
          Sehr sparsam einsetzen: Das Bild wird in dunklen Phasen etwas weicher. Nicht aktivieren,
          wenn die Kamera nachts bereits sauber oder bewusst detailhart liefern soll.
        </div>
      </div>`,
    );

    const renderMap = (ctx) => {
      const location = ctx.location;
      return section(
        "map",
        "Standort",
        "Koordinaten und Karten-Vorschau",
        `<div class="form-grid">
          <div class="form-field">
            <label for="${ctx.id("lat")}">Breite</label>
            <input id="${ctx.id("lat")}" name="latitude" type="number" step="0.0001" value="${safe(location.latitude ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("lng")}">Länge</label>
            <input id="${ctx.id("lng")}" name="longitude" type="number" step="0.0001" value="${safe(location.longitude ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("elevation")}">Höhe (m)</label>
            <input id="${ctx.id("elevation")}" name="elevation" type="number" value="${safe(location.elevation ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("orientation")}">Ausrichtung (°)</label>
            <input id="${ctx.id("orientation")}" name="orientation_deg" type="number" value="${safe(location.orientation_deg ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("fov")}">Sichtfeld (°)</label>
            <input id="${ctx.id("fov")}" name="field_of_view_deg" type="number" value="${safe(location.field_of_view_deg ?? 60)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("map-height")}">Kartenhöhe (px)</label>
            <input id="${ctx.id("map-height")}" name="map_height_px" type="number" min="120" max="800" value="${safe(location.map_height_px ?? 500)}">
            <small class="form-hint">Höhe der Kartenbox unter dem Livebild.</small>
          </div>
          <div class="form-field">
            <label for="${ctx.id("offset-y")}">Karten-Offset Y</label>
            <input id="${ctx.id("offset-y")}" name="map_center_offset" type="number" step="0.1" value="${safe(location.map_center_offset ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("offset-x")}">Karten-Offset X</label>
            <input id="${ctx.id("offset-x")}" name="map_center_offset_x" type="number" step="0.1" value="${safe(location.map_center_offset_x ?? 0)}">
          </div>
          <div class="form-field">
            <label for="${ctx.id("reach-km")}">Darstellungsreichweite (km)</label>
            <input id="${ctx.id("reach-km")}" name="orientation_visualization_km" type="number" min="0.1" step="0.1" value="${safe(location.orientation_visualization_km ?? 5)}">
            <small class="form-hint">Bestimmt, wie lang das Sichtfeld-Dreieck dargestellt wird.</small>
          </div>
        </div>
        <div id="camera-map-preview" class="map-preview"></div>`,
      );
    };

    // -----------------------------------------------------------------------
    // Aufnahme und Speicher.
    // Die RTSP-Felder tragen sowohl hidden als auch disabled, solange weder
    // Capture noch Stream im RTSP-Modus laufen. Die Validierungskomponente hält
    // diesen Zustand bei einer noch ungespeicherten Auswahl aktuell.
    // -----------------------------------------------------------------------
    const renderOptions = (values, selectedValue) => values
      .map((value) => `<option value="${safe(value)}" ${value === selectedValue ? "selected" : ""}>${safe(value)}</option>`)
      .join("");

    const isoSteps = [50, 100, 200, 400, 800, 1600, 3200, 6400, 12800, 25600, 51200, 102400];
    const renderIsoStatus = (ctx) => {
      const status = state.cameraAutoIso?.[ctx.camera.slug];
      if (!status) return "Noch keine Auswertung vorhanden.";
      const labels = { skipped: "Keine Regelung möglich", idle: "Noch keine Auswertung", observed: "Nur beobachtet", hold: "Keine Änderung nötig",
        helper_unavailable: "Noch keine aktuelle Befehlsabfrage des Kamera-Helpers", cooldown: "Regelpause",
        queued: "Änderung vorgemerkt", dispatched: "An Pi zugestellt; Ausführung noch nicht bestätigt",
        day_reset_dispatched: "Rücknahme auf 0 EV angefordert; Bestätigung im Folgebild offen", night_command_cancelled: "Nachtauftrag wegen Tagesanbruch verworfen",
        expired: "Auftrag abgelaufen", cancelled: "Auftrag verworfen", unavailable: "Status nicht verfügbar" };
      const reasons = { unsupported_model: "Kameramodell nicht unterstützt", invalid_measurement: "EXIF-/Helligkeitswerte fehlen oder sind ungültig",
        hold: "Innerhalb der Regelgrenzen", dark_long: "Dunkles Bild bei langer Belichtung", short: "Sehr kurze Belichtung",
        bright_or_short: "Helles Bild oder kurze Belichtung", range: "ISO außerhalb der gewählten Grenzen" };
      const d = status.decision;
      let text = labels[status.state] ?? "Noch keine Auswertung";
      if (status.mode === "off") text = "Regelung aus. Letzter Stand: " + text;
      if (d) {
        text += `. ${reasons[d.reason] ?? ""}`;
        if (d.iso != null) text += ` · ISO ${d.iso} · ${d.seconds} s · Helligkeit ${d.luminance} %`;
        if (d.bias != null) text += ` · Belichtungskorrektur ${d.bias.toFixed(2)} EV`;
        if (d.target_ev != null) text += ` · Vorschlag ${d.target_ev} EV`;
        if (d.target_iso != null) text += ` · Vorschlag ISO ${d.target_iso}`;
        if (d.evaluated_at) text += ` · ${new Date(d.evaluated_at * 1000).toLocaleString("de-DE")}`;
      }
      return safe(text);
    };

    const renderCapture = (ctx) => section(
      "capture",
      "Aufnahme",
      "Intervall, Endpunkt und Transport",
      `<div class="form-grid">
        <div class="form-field">
          <label for="${ctx.id("mode")}">Aufnahmemodus</label>
          <select id="${ctx.id("mode")}" name="mode">${renderOptions(CAPTURE_MODES, ctx.camera.mode)}</select>
        </div>
        <div class="form-field">
          <label for="${ctx.id("interval")}">Intervall (Sek.)</label>
          <input id="${ctx.id("interval")}" type="number" min="30" name="interval_seconds" value="${safe(ctx.capture.interval_seconds ?? 600)}">
        </div>
        <div class="form-field">
          <label for="${ctx.id("transport")}">Transport</label>
          <select id="${ctx.id("transport")}" name="transport">${renderOptions(CAPTURE_TRANSPORTS, ctx.capture.transport)}</select>
        </div>
        <div class="form-field">
          <label for="${ctx.id("endpoint")}">Endpunkt / Skript</label>
          <input id="${ctx.id("endpoint")}" name="endpoint" value="${safe(ctx.capture.endpoint)}">
        </div>
        <div class="form-field">
          <label for="${ctx.id("timeout")}">Timeout (Sek.)</label>
          <input id="${ctx.id("timeout")}" type="number" min="1" name="timeout_seconds" value="${safe(ctx.capture.timeout_seconds ?? 10)}">
        </div>
        <div class="form-field">
          <label for="${ctx.id("expected")}">Erwarteter Statuscode</label>
          <input id="${ctx.id("expected")}" type="number" name="expected_response" value="${safe(ctx.capture.expected_response ?? 200)}">
        </div>
        <div class="form-field form-field--full">
          <label for="${ctx.id("extra")}">Zusatzzeiten (HH:MM)</label>
          <input id="${ctx.id("extra")}" name="extra_times" value="${safe(ctx.extraTimes)}" placeholder="06:30, 14:15">
        </div>
        <div class="form-field form-field--full">
          <label for="${ctx.id("upload_key")}">Upload-Key (optional)</label>
          <input id="${ctx.id("upload_key")}" name="upload_key" value="${safe(ctx.capture.upload_key)}" autocomplete="new-password">
          <small class="form-hint">Wird von Upload-Clients genutzt; leer lassen, wenn nicht erforderlich.</small>
        </div>
        <div class="form-field form-field--full">
          <label for="${ctx.id("active-upload-fallback-failures")}">Active-Upload-Fallback nach Pull-Fehlern</label>
          <input id="${ctx.id("active-upload-fallback-failures")}" type="number" min="0" max="100" step="1" name="active_upload_fallback_failures" value="${safe(ctx.capture.active_upload_fallback_failures ?? 0)}">
          <small class="form-hint">Nur bei Pull-Snapshot: 0 ignoriert externe Uploads immer. Ein höherer Wert erlaubt sie erst nach so vielen aufeinanderfolgenden Pull-Fehlern.</small>
        </div>
        <div class="form-field form-field--full">
          <label for="${ctx.id("helper-upload-mode")}">Pi-Upload-Verfahren</label>
          <select id="${ctx.id("helper-upload-mode")}" name="helper_upload_mode">
            <option value="local"${(ctx.capture.helper_upload_mode ?? 'local') === 'local' ? ' selected' : ''}>Lokal am Pi einstellen</option>
            <option value="legacy"${ctx.capture.helper_upload_mode === 'legacy' ? ' selected' : ''}>Legacy – vollständiger Upload</option>
            <option value="chunked"${ctx.capture.helper_upload_mode === 'chunked' ? ' selected' : ''}>Chunked v1 – kleine Teile</option>
          </select>
          <small class="form-hint">Gilt nur für diese Kamera ab der nächsten erfolgreichen Abfrage eines aktuellen Pi-Helpers. Legacy/Chunked überschreibt dessen lokale Auswahl; alte Helper bleiben unverändert.</small>
        </div>
        <div class="form-field form-field--full">
          <label for="${ctx.id("auto-iso-mode")}">Automatische ISO-Regelung</label>
          <select id="${ctx.id("auto-iso-mode")}" name="auto_iso_mode">
            <option value="off"${(ctx.capture.auto_iso?.mode ?? "off") === "off" ? " selected" : ""}>Aus</option>
            <option value="observe"${ctx.capture.auto_iso?.mode === "observe" ? " selected" : ""}>Nur beobachten</option>
            <option value="auto"${ctx.capture.auto_iso?.mode === "auto" ? " selected" : ""}>Automatisch regeln</option>
          </select>
          <small class="form-hint">Wertet neue Uploads anhand von Helligkeit und EXIF-Belichtungszeit aus. Automatik nutzt den vorhandenen ISOEXP-Befehl des EOS-Pi-Workers. Nur einen Server pro Kamera regeln lassen; eine Regelung am alten Server vorher abschalten. Nur von der Kamera unterstützte ISO-Grenzen wählen.</small>
        </div>
        <div class="form-field">
          <label for="${ctx.id("auto-iso-min")}">Minimale ISO</label>
          <select id="${ctx.id("auto-iso-min")}" name="auto_iso_min">${renderOptions(isoSteps, ctx.capture.auto_iso?.min_iso ?? 100)}</select>
        </div>
        <div class="form-field">
          <label for="${ctx.id("auto-iso-max")}">Maximale ISO</label>
          <select id="${ctx.id("auto-iso-max")}" name="auto_iso_max">${renderOptions(isoSteps, ctx.capture.auto_iso?.max_iso ?? 800)}</select>
        </div>
        <div class="form-field">
          <label for="${ctx.id("night-ev-max")}">Nachtaufhellung bis</label>
          <select id="${ctx.id("night-ev-max")}" name="night_ev_max">${renderOptions([0, 1, 2], ctx.capture.auto_iso?.night_ev_max ?? 0)}</select>
          <small class="form-hint">0 = aus; 1/2 = maximal +1/+2 EV. Nur mit geprüftem EV-Helfer. Rücknahme auf 0 EV ab Morgendämmerung.</small>
        </div>
        <div class="form-field form-field--full"><small class="form-hint" data-auto-iso-status>${renderIsoStatus(ctx)}</small></div>
        <div class="form-field" data-rtsp-stream-setting${ctx.rtspSettingsAttribute}>
          <label for="${ctx.id("stream-fps")}">Livestream FPS (1-30)</label>
          <input id="${ctx.id("stream-fps")}" type="number" min="1" max="30" name="stream_fps" value="${safe(ctx.stream.fps)}" placeholder="z.B. 3" data-rtsp-stream-field${ctx.rtspFieldAttribute}>
          <small class="form-hint">Optional; überschreibt <code>WEBCAM_STREAM_FPS</code>. Kleiner = weniger Bandbreite.</small>
        </div>
        <div class="form-field" data-rtsp-stream-setting${ctx.rtspSettingsAttribute}>
          <label for="${ctx.id("stream-quality")}">Livestream Qualität (2–31)</label>
          <input id="${ctx.id("stream-quality")}" type="number" min="2" max="31" name="stream_quality" value="${safe(ctx.stream.quality)}" placeholder="z.B. 5" data-rtsp-stream-field${ctx.rtspFieldAttribute}>
          <small class="form-hint">Kleinere Zahl = bessere Qualität, mehr Bandbreite.</small>
        </div>
        <div class="form-field" data-rtsp-stream-setting${ctx.rtspSettingsAttribute}>
          <label for="${ctx.id("stream-max-clients")}">Max. Stream-Clients (1–20)</label>
          <input id="${ctx.id("stream-max-clients")}" type="number" min="1" max="20" name="stream_max_clients" value="${safe(ctx.stream.max_clients)}" placeholder="z.B. 2" data-rtsp-stream-field${ctx.rtspFieldAttribute}>
          <small class="form-hint">Begrenzt parallele Livestream-Aufrufe dieser Kamera. Leer = Server-Default.</small>
        </div>
      </div>`,
    );

    const renderStorage = (ctx) => section(
      "storage",
      "Speicher",
      "Basis- und Unterordner",
      `${ctx.creating
        ? `<p class="form-hint">Das Current-Verzeichnis wird beim Anlegen automatisch eingerichtet.
            Die aktuellen Bilder sind nach der ersten Aufnahme unter /&lt;kamera&gt;/current/ erreichbar.</p>`
        : (ctx.runtimeAccess?.provision_required
          ? `<div class="form-notice form-notice--warn">
              <strong>Current-Pfad noch nicht schreibbar.</strong><br>
              Pfad: <code>${safe(ctx.runtimeAccess.current_path || `${ctx.camera.slug}/current`)}</code><br>
              Als root ausführen: <code>${safe(ctx.runtimeAccess.provision_command)}</code>
            </div>`
          : (ctx.runtimeAccess?.writable
            ? `<p class="form-hint">Current-Pfad ist für den Apache-Laufzeitprozess freigegeben.</p>`
            : `<p class="form-hint">Die Schreibfreigabe des Current-Pfads wird beim nächsten vollständigen Laden geprüft.</p>`))}
      <div class="form-grid">
        <div class="form-field">
          <label for="${ctx.id("base-path")}">Basis-Pfad</label>
          <input id="${ctx.id("base-path")}" name="base_path" value="${safe(ctx.storage.base_path)}" data-validate="path">
        </div>
        <div class="form-field">
          <label for="${ctx.id("current-dir")}">Current-Verzeichnis</label>
          <input id="${ctx.id("current-dir")}" name="current_dir" value="${safe(ctx.storage.current_dir || "Current")}" data-validate="path">
        </div>
        <div class="form-field">
          <label for="${ctx.id("archive-dir")}">Archive-Verzeichnis</label>
          <input id="${ctx.id("archive-dir")}" name="archive_dir" value="${safe(ctx.storage.archive_dir || "Archive")}" data-validate="path">
        </div>
        <div class="form-field">
          <label for="${ctx.id("bestof-dir")}">Best-Of Verzeichnis</label>
          <input id="${ctx.id("bestof-dir")}" name="best_of_dir" value="${safe(ctx.storage.best_of_dir || "BestOf")}" data-validate="path">
        </div>
      </div>`,
    );

    // -----------------------------------------------------------------------
    // Spezialisierte und betriebliche Abschnitte.
    // Overlay, Labels und DB-Diagnose liefern bereits maskiertes HTML aus ihren
    // eigenen Komponenten. Der Kamera-Renderer ordnet diese Blöcke nur ein.
    // -----------------------------------------------------------------------
    const renderOverlay = (ctx) => {
      const content = [
        overlayEditor.renderVariantSection({
          variant: "light",
          label: "Logo bei hellem Text (Standard)",
          overlay: ctx.camera.overlay_logo ?? null,
          slug: ctx.camera.slug ?? "",
          isCreating: ctx.creating,
        }),
        overlayEditor.renderVariantSection({
          variant: "dark",
          label: "Logo bei dunklem Text",
          overlay: ctx.camera.overlay_logo_dark ?? null,
          slug: ctx.camera.slug ?? "",
          isCreating: ctx.creating,
        }),
      ].join("");
      return section("overlay", "Overlay", "Logos und Positionierung", content);
    };

    const renderLabels = (ctx) => section(
      "labels",
      "Labels",
      "Beschriftungen und Positionsmarker im Livebild",
      `<div class="labels-editor" data-labels-editor>
        <p class="form-hint">X/Y sind Prozentwerte (0–100) relativ zur Bildgröße. Typ steuert die Ausrichtung. Gültig ab/bis akzeptiert Datum oder Datum+Uhrzeit, z. B. 2026-01-01 oder 2026-01-01 12:00.</p>
        <div class="labels-table">
          <table>
            <thead>
              <tr>
                <th>X %</th><th>Y %</th><th>Text</th><th>Link</th><th>Farbe</th>
                <th>Schriftfarbe</th><th>Typ</th><th>Min-Breite</th>
                <th>Gültig ab<br><small>Datum/Zeit</small></th>
                <th>Gültig bis<br><small>Datum/Zeit</small></th><th></th>
              </tr>
            </thead>
            <tbody data-labels-body>${labelEditor.renderRows(ctx.camera.labels)}</tbody>
          </table>
        </div>
        <div class="labels-actions">
          <button class="btn secondary" type="button" data-label-add>+ Label hinzufügen</button>
        </div>
      </div>`,
    );

    const renderWeather = (ctx) => section(
      "weather",
      "Wetter",
      "Kommandos pro Aufnahme",
      `<div class="form-field">
        <label for="${ctx.id("weather")}">Befehle / Skript</label>
        <textarea id="${ctx.id("weather")}" name="weather_commands" rows="6" placeholder="#!/bin/bash
# dein Script ...">${safe(ctx.weatherCommands)}</textarea>
      </div>`,
    );

    const renderWatchdog = (ctx) => section(
      "watchdog",
      "Watchdog",
      "Reset-Strategie und Cooldown",
      `<div class="form-grid">
        <div class="form-field">
          <label><input type="checkbox" name="watchdog_enabled" ${checked(ctx.watchdog.enabled)}> Aktiviert</label>
        </div>
        <div class="form-field">
          <label for="${ctx.id("watchdog-fail")}">Max. Fehlversuche</label>
          <input id="${ctx.id("watchdog-fail")}" type="number" min="1" step="1" name="watchdog_failures" value="${safe(ctx.watchdog.max_consecutive_failures ?? 3)}">
        </div>
        <div class="form-field">
          <label for="${ctx.id("watchdog-success")}">Min. Erfolge nach Reset</label>
          <input id="${ctx.id("watchdog-success")}" type="number" min="0" step="1" name="cooldown_successes" value="${safe(ctx.watchdog.cooldown?.min_successes_after_reset ?? 2)}">
        </div>
        <div class="form-field">
          <label for="${ctx.id("watchdog-seconds")}">Cooldown (Sek.)</label>
          <input id="${ctx.id("watchdog-seconds")}" type="number" min="0" step="1" name="cooldown_seconds" value="${safe(ctx.watchdog.cooldown?.min_seconds_after_reset ?? 1200)}">
        </div>
      </div>
      <div class="form-field form-field--full">
        <label for="${ctx.id("watchdog-seq")}">Reset-Sequenz (JSON)</label>
        <textarea id="${ctx.id("watchdog-seq")}" name="reset_sequence" rows="5" aria-describedby="${ctx.id("watchdog-help")}">${safe(ctx.resetSequence)}</textarea>
        <p id="${ctx.id("watchdog-help")}" class="muted">Maximal 20 Schritte. Pro Schritt: http-get mit url (nur http/https) und optional timeout (1–60 s, Standard 5); delay mit seconds (1–300 s, Standard 1); shell mit command und optional timeout_seconds (1–300 s, Standard 20 bzw. Servervorgabe). Nur ganze Zahlen und die genannten Felder; unbekannte Felder werden beim Speichern abgelehnt. Shell akzeptiert alternativ das alte Feld timeout, aber nicht beide Timeout-Felder zugleich. Cooldown ist die Pause zwischen Resetfolgen, kein delay-Schritt.</p>
      </div>`,
    );

    const buildSections = (ctx) => {
      const sections = [
        renderGeneral(ctx),
        renderInfo(ctx),
        renderLiveView(ctx),
        renderMap(ctx),
        renderCapture(ctx),
        section("image-processing", "Bildbearbeitung", "Bereiche in Listenreihenfolge bearbeiten", window.WebcamAdminImageProcessing.render(ctx.camera, ctx.creating)),
        renderStorage(ctx),
        renderOverlay(ctx),
        renderLabels(ctx),
        renderWeather(ctx),
        renderWatchdog(ctx),
      ];
      if (!ctx.creating && ctx.camera.slug) {
        sections.push(section(
          "db",
          "Datenbank",
          "Letzte 5 Captures aus der aktiven Kamera-DB",
          captureEditor.renderSection(ctx.camera.slug),
          false,
        ));
      }
      return sections;
    };

    // -----------------------------------------------------------------------
    // Tabrahmen und endgültiges Formular.
    // Abschnitts-IDs und Titel stammen ausschließlich aus obigen statischen
    // Definitionen. Nur gespeicherte Inhalte und Statuswerte werden dynamisch
    // eingesetzt und vorher maskiert beziehungsweise spezialisierten sicheren
    // Renderern entnommen.
    // -----------------------------------------------------------------------
    const renderSectionSummary = (ctx, item) => {
      const canSave = !ctx.creating && item.saveable !== false;
      const isSaving = canSave && Boolean(ctx.sectionSaving[item.id]);
      const action = canSave
        ? `<div class="form-section__actions">
            <button class="btn secondary" type="button" data-section-save="${safe(item.id)}" ${isSaving ? "disabled" : ""}>
              ${isSaving ? "Speichert…" : "Sektion speichern"}
            </button>
          </div>`
        : "";
      return `<div class="form-section__summary">
        <div class="form-section__title">
          <span>${safe(item.title)}</span>
          <small>${safe(item.subtitle)}</small>
        </div>
        ${action}
      </div>`;
    };

    const render = (cameraInput = null, {
      inline = false,
      creating = false,
      activeTab = null,
    } = {}) => {
      const camera = resolveCamera(cameraInput, creating);
      if (!camera) {
        return state.selectedSlug
          ? "<p>Ausgewählte Kamera wurde nicht gefunden.</p>"
          : "<p>Wähle eine Kamera oder lege eine neue an.</p>";
      }

      const ctx = buildContext(camera, creating);
      const sections = buildSections(ctx);
      const requestedTab = activeTab || state.editorTabs?.[camera.slug];
      const activeTabId = sections.some((item) => item.id === requestedTab)
        ? requestedTab
        : (sections[0]?.id ?? "general");
      const tabsNavigation = sections
        .map((item) => `<button type="button" class="tab-nav__btn${item.id === activeTabId ? " is-active" : ""}" data-tab-target="${safe(item.id)}">${safe(item.title)}</button>`)
        .join("");
      const panels = sections
        .map((item) => `<div class="tab-panel${item.id === activeTabId ? " is-active" : ""}" data-tab-panel="${safe(item.id)}">
          ${renderSectionSummary(ctx, item)}
          <div class="tab-panel__body">${item.content}</div>
        </div>`)
        .join("");

      const heading = creating ? "Neue Kamera" : `Bearbeite ${camera.slug ?? ""}`;
      const formHeader = inline
        ? `<div class="editor-header"><h3>${safe(heading)}</h3><button class="btn ghost" type="button" data-action="cancel-edit">Schließen</button></div>`
        : `<h2>${safe(heading)}</h2>`;
      const submitLabel = creating ? "Kamera anlegen" : "Änderungen speichern";

      return `${formHeader}
        <form class="camera-form" id="${safe(ctx.formId)}" data-stream-mode="${safe(ctx.stream.mode ?? "none")}">
          <div class="tabs" data-tabs>
            <div class="tabs__nav">${tabsNavigation}</div>
            ${panels}
          </div>
          <div class="form-actions">
            ${!creating && camera.slug ? `<fieldset class="focus-controls"><legend>gphoto2-Fokus – ${safe(camera.slug)}</legend>${['--', '-', 'auto', '+', '++'].map(mode => `<button class="btn secondary" type="button" data-focus-camera="${safe(camera.slug)}" data-focus-mode="${mode}" aria-label="Fokus ${mode}">${mode}</button>`).join('')}<small>Benötigt aktualisierten gphoto2-Helper. Ausführung beim nächsten Poll; wirkt auf das gemeinsame Objektiv.</small></fieldset>` : ""}
            ${!creating && camera.slug ? `<fieldset class="tunnel-controls"><legend>Wartungszugang – ${safe(camera.slug)}</legend><button class="btn secondary" type="button" disabled data-tunnel-camera="${safe(camera.slug)}" data-tunnel-action="open">Für 30 Minuten öffnen</button> <button class="btn secondary" type="button" data-tunnel-camera="${safe(camera.slug)}" data-tunnel-action="close">Schließen</button><p data-tunnel-status role="status">Wartungsdienst wird geprüft …</p><small>Nur nach separater Einrichtung. SSH ausschließlich am Server lokal erreichbar. Zustellung beim nächsten Poll; Ablauf unabhängig vom Kamera-Worker.</small>
              <details class="tunnel-help"><summary>So nutzt du den Wartungszugang (SSH und Pi-Webseite)</summary>
                <p>Zuerst „Für 30 Minuten öffnen“ anklicken und den nächsten Pi-Poll abwarten. Der Pi verbindet sich ausgehend per HTTPS; am Pi ist keine Portfreigabe nötig. Dies öffnet einen SSH-Zugang, keine eigene Tunnel-Webseite.</p>
                <p><strong>Beispielwerte – vor Verwendung anpassen:</strong> Pi-Ziel <code>dev-test</code>, Relay <code>192.0.2.34</code>, Port <code>22101</code>, Pi-Benutzer <code>admin</code>. Andere Kameras benötigen ihren separat zugewiesenen Relay-Port; diese Angaben werden nicht automatisch aus der ausgewählten Kamera ermittelt.</p>
                <p>Direkt in einer Shell auf dem Relay .34:</p>
                <pre><code>ssh -p 22101 admin@127.0.0.1</code></pre>
                <p>Vom eigenen PC / PowerShell über den SSH-Benutzer <code>analyse</code> auf .34:</p>
                <pre><code>ssh -J analyse@192.0.2.34 -p 22101 admin@127.0.0.1</code></pre>
                <p>Für die Pi-Webseite im Browser stattdessen diesen Befehl starten und die PowerShell geöffnet lassen:</p>
                <pre><code>ssh -J analyse@192.0.2.34 -p 22101 -N -L 127.0.0.1:8086:127.0.0.1:80 admin@127.0.0.1</code></pre>
                <p>Danach auf demselben PC öffnen: <code>http://localhost:8086/</code> (Infoseite) oder <code>http://localhost:8086/camera-admin/</code> (Pi-Verwaltung mit eigener Anmeldung). Falls Port 8086 belegt ist, einen anderen lokalen Port in Befehl und Browseradresse wählen.</p>
                <p>SSH-Zugang zu .34 und Pi bleibt erforderlich (Passwort oder eingerichteter Schlüssel). Beim ersten Verbinden den Pi-Hostschlüssel über einen bekannten Zugang vergleichen; Warnungen nicht blind bestätigen. Das ursprüngliche PowerShell-Reverse-Tunnel-Fenster wird für diesen neuen HTTPS-Zugang nicht benötigt.</p>
                <p>Nach spätestens 30 Minuten ab Freigabe endet der Zugang automatisch. „Schließen“ wirkt beim nächsten Poll und beendet dann auch bestehende Verbindungen. Zum erneuten Nutzen eine neue Freigabe erteilen. „running“ bestätigt nur den Tunnelprozess, nicht die SSH-Anmeldung.</p>
              </details></fieldset>` : ""}
            ${!creating && camera.slug ? `<button class="btn secondary" type="button" data-capture-camera="${safe(camera.slug)}">Jetzt aufnehmen – ${safe(camera.slug)}</button>` : ""}
            <button class="btn primary" type="submit">${submitLabel}</button>
            <button class="btn ghost" type="button" data-action="cancel-edit">Abbrechen</button>
          </div>
        </form>`;
    };

    return Object.freeze({ render });
  };

  global.WebcamAdminCameraEditor = Object.freeze({ create });
})(window);
