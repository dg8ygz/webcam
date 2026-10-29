// Übersetzer zwischen Kameraformular und Admin-API-Payload.
//
// Leseführung:
// 1. `readContext()` synchronisiert den Rich-Text-Editor und liest FormData.
// 2. Kleine Parser normalisieren Listen, Zahlen, Wetterblöcke und JSON.
// 3. Ein Builder pro Fachabschnitt erzeugt genau dessen API-Struktur.
// 4. `buildSection()` verwendet einen einzelnen Builder für Teilspeicherungen.
// 5. `buildComplete()` setzt den vollständigen Kamera-Payload aus denselben
//    Buildern zusammen. Dadurch gibt es keine zweite Feldzuordnung mehr.
//
// Das Modul sendet keine Requests und verändert weder Browserzustand noch
// Datenbank. Es übersetzt ausschließlich bereits browserseitig validierte
// Formularwerte. Die Admin-API bleibt die verbindliche Sicherheits- und
// Plausibilitätsgrenze für jeden zurückgegebenen Payload.
(function registerAdminCameraPayload(global) {
  "use strict";

  const SECTION_IDS = Object.freeze([
    "general",
    "info",
    "liveview",
    "map",
    "capture",
    "image-processing",
    "storage",
    "overlay",
    "labels",
    "weather",
    "watchdog",
  ]);

  /**
   * Fachabhängigkeiten werden injiziert, damit Overlay- und Labelregeln nur an
   * einer Stelle existieren. Zahlen-, String- und Tag-Normalisierung stammen
   * ebenfalls aus dem zentralen Admin und behalten so ihr bisheriges Verhalten.
   */
  const create = ({
    toNumber,
    cleanString,
    splitCommaSeparated,
    buildCameraTagsFromForm,
    overlayEditor,
    labelEditor,
    syncRichText,
  }) => {
    if (
      typeof toNumber !== "function"
      || typeof cleanString !== "function"
      || typeof splitCommaSeparated !== "function"
      || typeof buildCameraTagsFromForm !== "function"
      || typeof overlayEditor?.parseFormData !== "function"
      || typeof labelEditor?.collect !== "function"
      || typeof syncRichText !== "function"
    ) {
      throw new Error("Admin camera payload dependencies are incomplete");
    }

    // ---------------------------------------------------------------------
    // Gemeinsamer Formularkontext.
    // TinyMCE hält Änderungen zunächst in seiner eigenen Oberfläche. Vor dem
    // FormData-Snapshot muss es den aktuellen Inhalt ins Textarea schreiben.
    // ---------------------------------------------------------------------
    const readContext = (form, existingStream = null) => {
      if (!form || typeof form.querySelector !== "function") {
        throw new Error("Kameraformular fehlt");
      }
      syncRichText();
      const formData = new FormData(form);
      const slug = cleanString(formData.get("slug"));
      if (!slug) {
        throw new Error("Slug darf nicht leer sein");
      }
      return { form, formData, slug, existingStream };
    };

    // ---------------------------------------------------------------------
    // Parser für zusammengesetzte Eingaben.
    // Diese Helfer erfinden keine Werte: Leere Eingaben werden zu leeren
    // Listen beziehungsweise den ausdrücklich angegebenen Defaults.
    // ---------------------------------------------------------------------
    const parseResolutionArray = (value) => Array.from(
      new Set(
        splitCommaSeparated(value)
          .map((entry) => Number.parseInt(entry, 10))
          .filter((entry) => Number.isFinite(entry)),
      ),
    ).sort((a, b) => a - b);

    const parseWeatherCommands = (value) => {
      const normalized = String(value ?? "").replace(/\r\n/g, "\n").trim();
      if (!normalized) return [];

      // Ein Script mit Shebang darf Leerzeilen enthalten und bleibt deshalb
      // bewusst ein einziger ausführbarer Block.
      if (normalized.startsWith("#!")) return [normalized];

      // Ohne Shebang trennen Leerzeilen mehrere unabhängige Kommandoblöcke.
      const commands = [];
      let buffer = [];
      normalized.split("\n").forEach((line) => {
        if (line.trim() !== "") {
          buffer.push(line);
          return;
        }
        const command = buffer.join("\n").trim();
        if (command) commands.push(command);
        buffer = [];
      });
      const finalCommand = buffer.join("\n").trim();
      if (finalCommand) commands.push(finalCommand);
      return commands;
    };

    const parseResetSequence = (formData) => {
      const raw = String(formData.get("reset_sequence") ?? "[]");
      try {
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
          throw new Error("Reset-Sequenz muss ein Array sein");
        }
        if (parsed.length > 20) {
          throw new Error("Maximal 20 Reset-Schritte erlaubt");
        }
        return parsed;
      } catch (error) {
        throw new Error(`Reset-Sequenz ungültig: ${error.message}`);
      }
    };

    const parseOptionalStreamNumber = (
      formData,
      fieldName,
      min,
      max,
      fallback = null,
    ) => {
      // Verborgene RTSP-Felder sind disabled und fehlen in FormData. In diesem
      // Fall bleibt ein existierender Streamwert unverändert.
      if (!formData.has(fieldName)) return fallback ?? null;
      const parsed = toNumber(formData.get(fieldName), null);
      if (typeof parsed === "number" && parsed >= min && parsed <= max) {
        return Math.round(parsed);
      }
      return null;
    };

    const parseStream = ({ formData, existingStream }) => ({
      // Modus und URLs werden nicht in diesem Formular bearbeitet. Sie müssen
      // bei einer allgemeinen Kameraänderung deshalb unverändert mitreisen.
      mode: existingStream?.mode ?? "none",
      playlist_url: existingStream?.playlist_url ?? null,
      snapshot_url: existingStream?.snapshot_url ?? null,
      fps: parseOptionalStreamNumber(formData, "stream_fps", 1, 30, existingStream?.fps),
      quality: parseOptionalStreamNumber(formData, "stream_quality", 2, 31, existingStream?.quality),
      max_clients: parseOptionalStreamNumber(formData, "stream_max_clients", 1, 20, existingStream?.max_clients),
    });

    // ---------------------------------------------------------------------
    // Fachliche Payload-Builder.
    // Jeder Builder besitzt nur die Felder seines Admin-Tabs. Das schützt eine
    // Teilspeicherung davor, fremde Kameraabschnitte zu überschreiben.
    // ---------------------------------------------------------------------
    const buildGeneral = ({ formData, slug }) => ({
      name: cleanString(formData.get("name")),
      title: cleanString(formData.get("title")),
      short_title: cleanString(formData.get("short_title")),
      tags: buildCameraTagsFromForm(formData, slug),
    });

    const buildInfo = ({ formData }) => ({
      description: cleanString(formData.get("description")),
      // Info-HTML darf innere Leerzeichen behalten; nur äußerer Ballast wird
      // entfernt. Die serverseitige Sanitizer-Stufe bleibt danach verbindlich.
      info_html: String(formData.get("info_html") ?? "").trim(),
    });

    const buildLiveView = ({ formData, slug }) => ({
      // Die zwei Live-Schalter werden als reservierte interne Tags gespeichert.
      tags: buildCameraTagsFromForm(formData, slug),
    });

    const buildMap = ({ formData }) => ({
      location: {
        latitude: toNumber(formData.get("latitude"), 0),
        longitude: toNumber(formData.get("longitude"), 0),
        elevation: toNumber(formData.get("elevation"), 0),
        orientation_deg: toNumber(formData.get("orientation_deg"), 0),
        field_of_view_deg: toNumber(formData.get("field_of_view_deg"), 60),
        map_height_px: toNumber(formData.get("map_height_px"), 500),
        map_center_offset: toNumber(formData.get("map_center_offset"), 0),
        map_center_offset_x: toNumber(formData.get("map_center_offset_x"), 0),
        orientation_visualization_km: toNumber(
          formData.get("orientation_visualization_km"),
          5,
        ),
      },
    });

    const buildCapture = (context) => ({
      mode: String(context.formData.get("mode") ?? "") || "active-capture",
      capture: {
        ...(context.formData.has("auto_iso_mode") ? { auto_iso: {
          mode: cleanString(context.formData.get("auto_iso_mode")),
          min_iso: toNumber(context.formData.get("auto_iso_min"), 100),
          max_iso: toNumber(context.formData.get("auto_iso_max"), 800),
          ...(context.formData.has("night_ev_max") ? { night_ev_max: toNumber(context.formData.get("night_ev_max"), 0) } : {}),
        } } : {}),
        interval_seconds: toNumber(context.formData.get("interval_seconds"), 600),
        transport: cleanString(context.formData.get("transport")) || "http-get",
        endpoint: cleanString(context.formData.get("endpoint")),
        timeout_seconds: toNumber(context.formData.get("timeout_seconds"), 10),
        expected_response: toNumber(context.formData.get("expected_response"), 200),
        extra_times: splitCommaSeparated(cleanString(context.formData.get("extra_times"))),
        upload_key: cleanString(context.formData.get("upload_key")),
        ...(context.formData.has("helper_upload_mode")
          ? { helper_upload_mode: cleanString(context.formData.get("helper_upload_mode")) } : {}),
        active_upload_fallback_failures: toNumber(
          context.formData.get("active_upload_fallback_failures"),
          0,
        ),
      },
      stream: parseStream(context),
    });

    const buildStorage = ({ formData, slug }) => ({
      storage: {
        base_path: cleanString(formData.get("base_path")) || `storage/${slug}`,
        current_dir: cleanString(formData.get("current_dir")) || "Current",
        archive_dir: cleanString(formData.get("archive_dir")) || "Archive",
        best_of_dir: cleanString(formData.get("best_of_dir")) || "BestOf",
      },
    });

    const buildOverlay = ({ formData }) => ({
      // Datei-Upload und URL-/Maßprüfung gehören weiterhin dem Overlay-Editor.
      overlay_logo: overlayEditor.parseFormData(formData, "light"),
      overlay_logo_dark: overlayEditor.parseFormData(formData, "dark"),
    });

    const buildLabels = ({ form }) => ({
      // Der Label-Editor prüft Link, Farbe, Koordinaten und Gültigkeitsfenster.
      labels: labelEditor.collect(form),
    });

    const buildWeather = ({ formData }) => ({
      weather_commands: parseWeatherCommands(formData.get("weather_commands")),
    });

    const buildWatchdog = ({ form, formData }) => ({
      status: {
        watchdog: {
          enabled: Boolean(
            form.querySelector('input[name="watchdog_enabled"]')?.checked,
          ),
          max_consecutive_failures: toNumber(formData.get("watchdog_failures"), 3),
          reset_sequence: parseResetSequence(formData),
          cooldown: {
            min_successes_after_reset: toNumber(formData.get("cooldown_successes"), 2),
            min_seconds_after_reset: toNumber(formData.get("cooldown_seconds"), 1200),
          },
        },
      },
    });

    const sectionBuilders = Object.freeze({
      general: buildGeneral,
      info: buildInfo,
      liveview: buildLiveView,
      map: buildMap,
      capture: buildCapture,
      "image-processing": ({ formData }) => ({ capture: { image_processing: JSON.parse(String(formData.get("image_processing") || "{}")) } }),
      storage: buildStorage,
      overlay: buildOverlay,
      labels: buildLabels,
      weather: buildWeather,
      watchdog: buildWatchdog,
    });

    // ---------------------------------------------------------------------
    // Öffentliche Schnittstelle.
    // Abschnitts- und Gesamtpayload benutzen dasselbe Kontextobjekt und exakt
    // dieselben Builder. Änderungen an einem Feld erfolgen daher nur einmal.
    // ---------------------------------------------------------------------
    const buildSection = (form, sectionId, { existingStream = null } = {}) => {
      const builder = sectionBuilders[sectionId];
      if (!builder) throw new Error(`Unbekannte Sektion: ${sectionId}`);
      return builder(readContext(form, existingStream));
    };

    const buildComplete = (form, { existingStream = null } = {}) => {
      const context = readContext(form, existingStream);
      const general = buildGeneral(context);
      const payload = {
        id: context.slug,
        slug: context.slug,
        ...general,
        ...buildInfo(context),
        ...buildMap(context),
        ...buildCapture(context),
        ...(context.formData.has("image_processing") ? { capture: { ...buildCapture(context).capture, image_processing: JSON.parse(context.formData.get("image_processing")) } } : {}),
        ...buildStorage(context),
        ...buildWatchdog(context),
        ...buildWeather(context),
        ...buildOverlay(context),
        ...buildLabels(context),
        resolutions: parseResolutionArray(
          String(context.formData.get("resolutions") ?? ""),
        ),
      };

      // Die Neuanlage akzeptiert eine leere Kurzbezeichnung und verwendet dann
      // denselben verständlichen Namen, den auch die Oberfläche zeigt.
      if (!payload.short_title) payload.short_title = payload.name;
      return payload;
    };

    return Object.freeze({
      sectionIds: SECTION_IDS,
      buildSection,
      buildComplete,
    });
  };

  global.WebcamAdminCameraPayload = Object.freeze({ create });
})(window);
