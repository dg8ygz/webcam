// Leseführung für die Admin-Oberfläche:
//
// 1. Pfade, gemeinsamer Seitenzustand und Eingabevalidierung
// 2. authentifizierte API-Aufrufe und Laden/Speichern der Konfiguration
// 3. Rendern der Haupttabs und der Kameraformulare
// 4. Umwandeln von Formularwerten in klar abgegrenzte API-Payloads
// 5. Label- und HTML-Editoren sowie Overlay-/Karten-/Capture-Adapter
// 6. delegierte Ereignisse und einmaliger Seitenstart
//
// `state` ist die einzige veränderliche Sollquelle im Browser. `render()` baut
// die sichtbare Oberfläche daraus neu auf; dauerhafte Änderungen entstehen
// erst nach erfolgreicher Antwort der Admin-API.
const root = document.getElementById("admin-app");
if (!root) {
  throw new Error("Admin root element not found");
}

const apiBaseAttr = root.dataset.apiBase ? root.dataset.apiBase.trim() : "";
const apiBase = apiBaseAttr.replace(/\/+$/, "");
const wcTest = root.dataset.wcTest === "1";
const assetsBaseAttr = root.dataset.assetsBase ? root.dataset.assetsBase.trim() : "";
const assetsBase = assetsBaseAttr.replace(/\/+$/, "");
const csrfTokenAttr = root.dataset.csrfToken ? root.dataset.csrfToken.trim() : "";
const iterationAttr = root.dataset.iteration ? root.dataset.iteration.trim() : "";

const collapseDoubleSlashes = (value) =>
  value.replace(/([^:])\/{2,}/g, "$1/");

const cameraUrlAttr = root.dataset.cameraUrl ? root.dataset.cameraUrl.trim() : "";
const cameraUrlBase = cameraUrlAttr ? collapseDoubleSlashes(cameraUrlAttr) : "";
const appBase = (() => {
  if (!cameraUrlBase) {
    return "./";
  }
  const trimmed = cameraUrlBase
    .replace(/camera\.php(?:\?.*)?$/i, "")
    .replace(/camera\/?$/i, "");
  return trimmed === "" ? "./" : trimmed;
})();
const publicApiBase = collapseDoubleSlashes(`${appBase}api/public.php`);

const buildPublicApiUrl = (path) => {
  const clean = String(path || "").replace(/^\/+/, "");
  return collapseDoubleSlashes(`${publicApiBase.replace(/\/+$/, "")}/${clean}`);
};

const tinymceBaseUrl = assetsBase
  ? `${assetsBase}/vendor/tinymce/js/tinymce`
  : "./assets/vendor/tinymce/js/tinymce";

// ---------------------------------------------------------------------------
// Zentraler Browserzustand.
// Lade- und Speicherflags verhindern doppelte Aktionen und erlauben sichtbare
// Zwischenzustände, ohne bereits gespeicherte Serverdaten zu erfinden.
// ---------------------------------------------------------------------------
const state = {
  config: null,
  selectedSlug: null,
  flash: null,
  isCreating: false,
  dbPreview: {},
  dbLoading: {},
  captureEditor: {},
  editorTabs: {},
  savingRateLimit: false,
  savingTimeline: false,
  savingRuntime: false,
  savingScripts: false,
  savingArchiveCache: false,
  activeTab: localStorage.getItem("adminActiveTab") || "cameras",
  cacheStatus: { enabled: false, size_bytes: 0, last_built_at: null, admin_allowed: false },
  cacheForm: { slug: "", year: "", dry: false },
  status: { items: [], loading: false, error: null, source: null },
  streams: { items: [], blocked_ips: [], summary: {}, loading: false, error: null },
  scripts: { status: {}, definitions: {}, trigger: { mode: "camera_poll", last_tick_at: null } },
  savingHero: false,
  savingMap: false,
  sectionSaving: {},
  // Nicht persistierter Betriebszustand der Kamera-Current-Verzeichnisse.
  // Die Admin-API trennt ihn bewusst von der eigentlichen Kamera-Konfiguration.
  cameraRuntime: {},
  cameraAutoIso: {},
};

const INTERNAL_CAMERA_TAG_PREFIX = "__";
const INTERNAL_CAMERA_TAGS = {
  nativeDisplay: "__native_display",
  nightDenoise: "__night_denoise",
  testCamera: "__test_camera",
};

const normalizeTagList = (tags) => {
  if (!Array.isArray(tags)) {
    return [];
  }
  return Array.from(
    new Set(
      tags
        .map((tag) => (typeof tag === "string" ? tag.trim() : ""))
        .filter(Boolean),
    ),
  );
};

const isInternalCameraTag = (tag) =>
  typeof tag === "string" && tag.startsWith(INTERNAL_CAMERA_TAG_PREFIX);

const splitCameraTags = (tags) => {
  const all = normalizeTagList(tags);
  return {
    visible: all.filter((tag) => !isInternalCameraTag(tag)),
    internal: all.filter((tag) => isInternalCameraTag(tag)),
  };
};

const cameraHasInternalTag = (camera, tag) =>
  splitCameraTags(camera?.tags ?? []).internal.includes(tag);

const buildCameraTagsFromForm = (formData, slug) => {
  const visibleTags = normalizeTagList(
    parseArray(cleanString(formData.get("tags"))),
  ).filter((tag) => !isInternalCameraTag(tag));

  const existingCamera =
    !state.isCreating && state.config?.cameras
      ? state.config.cameras[slug] ??
        state.config.cameras[state.selectedSlug || ""] ??
        null
      : null;

  const preservedInternalTags = splitCameraTags(existingCamera?.tags ?? []).internal.filter(
    (tag) =>
      tag !== INTERNAL_CAMERA_TAGS.nativeDisplay &&
      tag !== INTERNAL_CAMERA_TAGS.nightDenoise &&
      tag !== INTERNAL_CAMERA_TAGS.testCamera,
  );

  if (formData.get("native_display")) {
    preservedInternalTags.push(INTERNAL_CAMERA_TAGS.nativeDisplay);
  }

  if (formData.get("night_denoise")) {
    preservedInternalTags.push(INTERNAL_CAMERA_TAGS.nightDenoise);
  }

  if (formData.get("test_camera")) {
    preservedInternalTags.push(INTERNAL_CAMERA_TAGS.testCamera);
  }

  return [...visibleTags, ...normalizeTagList(preservedInternalTags)];
};

const getCookie = (name) => {
  return document.cookie
    .split(";")
    .map((c) => c.trim())
    .filter(Boolean)
    .map((c) => c.split("="))
    .reduce((acc, [k, v]) => {
      acc[k] = v ? decodeURIComponent(v) : "";
      return acc;
    }, {})[name];
};

const getCsrfToken = () => {
  const fromCookie = getCookie("admin_csrf");
  if (fromCookie) return fromCookie;
  if (csrfTokenAttr) return csrfTokenAttr;
  return "";
};

const formatBytes = (bytes) => {
  const b = Number(bytes);
  if (!b || b < 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  return `${(b / 1024 ** i).toFixed(1)} ${units[i]}`;
};

const formatAge = (seconds) => {
  if (seconds === null || seconds === undefined) return "—";
  const mins = Math.floor(seconds / 60);
  if (mins < 60) return `${mins} min`;
  const hrs = Math.floor(mins / 60);
  const rem = mins % 60;
  return `${hrs} h ${rem} min`;
};

const formatDateTime = (value) => {
  if (!value) return "–";
  const d = typeof value === "number" ? new Date(value * 1000) : new Date(value);
  if (Number.isNaN(d.getTime())) return "–";
  return d.toLocaleString();
};

const computeSeverity = (ageSeconds, intervalSeconds) => {
  if (ageSeconds === null || ageSeconds === undefined) return "unknown";
  const interval = Math.max(60, Number(intervalSeconds) || 600);
  if (ageSeconds <= interval * 2) return "ok";
  if (ageSeconds <= interval * 3) return "warn";
  return "error";
};

const computeAgeSeconds = (value) => {
  if (!value) return null;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  const now = Date.now();
  return Math.max(0, Math.floor((now - d.getTime()) / 1000));
};

const defaultCamera = () => ({
  id: "",
  slug: "",
  name: "",
  title: "",
  short_title: "",
  description: "",
  info_html: "",
  tags: [],
  mode: "active-capture",
  location: {
    latitude: 0,
    longitude: 0,
    elevation: 0,
    orientation_deg: 0,
    field_of_view_deg: 60,
    map_center_offset: 0,
    map_center_offset_x: 0,
    orientation_visualization_km: 5,
    map_height_px: 500,
  },
  capture: {
    interval_seconds: 600,
    transport: "http-get",
    endpoint: "",
    timeout_seconds: 10,
    expected_response: 200,
    extra_times: [],
    upload_key: "",
    active_upload_fallback_failures: 0,
  },
  storage: {
    base_path: "storage/",
    current_dir: "Current",
    archive_dir: "Archive",
    best_of_dir: "BestOf",
  },
  weather_commands: [],
  overlay_logo: null,
  overlay_logo_dark: null,
  labels: [],
  status: {
    watchdog: {
      enabled: true,
      max_consecutive_failures: 3,
      reset_sequence: [],
      cooldown: {
        min_successes_after_reset: 2,
        min_seconds_after_reset: 1200,
      },
    },
  },
  stream: {
    mode: "none",
    playlist_url: "",
    snapshot_url: "",
    fps: null,
    quality: null,
  },
  resolutions: [],
});

// ---------------------------------------------------------------------------
// Admin-API-Transport.
// Schreibende Aufrufe erhalten den CSRF-Header. Fehlertexte werden einheitlich
// aus JSON gelesen und erst danach für die Oberfläche aufbereitet.
// ---------------------------------------------------------------------------
const buildApiUrl = (path) => {
  // apiBase verweist auf den Admin-Root; PATH_INFO ist nicht überall verfügbar,
  // deshalb per Query-Param `path=...` routen.
  const normalizedPath = path.startsWith("/") ? path.slice(1) : path;
  let scriptPath = normalizedPath;
  let query = "";
  const queryIndex = scriptPath.indexOf("?");
  if (queryIndex >= 0) {
    query = scriptPath.slice(queryIndex + 1);
    scriptPath = scriptPath.slice(0, queryIndex);
  }
  if (scriptPath.startsWith("api/admin/")) {
    scriptPath = scriptPath.slice("api/admin/".length);
  } else if (scriptPath.startsWith("api/")) {
    scriptPath = scriptPath.slice("api/".length);
  }
  let base = apiBase || "./";
  if (!base.endsWith("/")) {
    base += "/";
  }
  const url = new URL(`${base}api.php`, window.location.href);
  url.searchParams.set("path", scriptPath);
  if (query) {
    const params = new URLSearchParams(query);
    params.forEach((value, key) => {
      if (key === "path") return;
      url.searchParams.set(key, value);
    });
  }
  return collapseDoubleSlashes(url.toString());
};

const buildCameraLink = (slug) => {
  const safeSlug = encodeURIComponent(slug || "");
  let base = cameraUrlBase || "camera/";
  if (/camera\.php/i.test(base)) {
    base = base.replace(/camera\.php(?:\?.*)?$/i, "camera/");
  }
  if (!base.endsWith("/")) {
    base = `${base}/`;
  }
  return `${base}${safeSlug}`;
};

const api = async (path, options = {}) => {
  const url = buildApiUrl(path);
  const headers = new Headers(options.headers || {});
  if (options.body && !(options.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }
  if (wcTest) {
    headers.set("X-WC-TEST", "1");
  }
  const method = (options.method || "GET").toUpperCase();
  if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
    const csrf = getCsrfToken();
    if (csrf) {
      headers.set("X-CSRF-Token", csrf);
    }
  }

  const response = await fetch(url, { ...options, headers });
  if (!response.ok) {
    const detail = await extractErrorDetail(response);
    throw new Error(`${response.status} ${detail}`);
  }
  if (response.status === 204) {
    return null;
  }
  return response.json();
};


window.WebcamAdminImageProcessing.bind({ root, api });

const extractErrorDetail = async (response) => {
  let detail = response.statusText;
  try {
    const payload = await response.json();
    if (payload.detail) {
      if (Array.isArray(payload.detail)) {
        detail = payload.detail
          .map((item) => {
            if (item?.msg) {
              const path = Array.isArray(item.loc) ? item.loc.join(".") : "";
              return path ? `${item.msg} (${path})` : item.msg;
            }
            return typeof item === "string" ? item : JSON.stringify(item);
          })
          .join("; ");
      } else if (typeof payload.detail === "object" && payload.detail.msg) {
        detail = payload.detail.msg;
      } else {
        detail =
          typeof payload.detail === "string"
            ? payload.detail
            : JSON.stringify(payload.detail);
      }
    } else {
      detail = JSON.stringify(payload);
    }
  } catch {
    // ignore JSON parsing errors
  }
  return detail;
};

const flash = (message, level = "ok") => {
  state.flash = { message, level };
  render();
};

// ---------------------------------------------------------------------------
// Laden und Speichern der globalen Admin-Bereiche.
// Unabhängige Datenquellen werden parallel geladen; ein Fehler bleibt im
// zugehörigen Bereich sichtbar, statt unbemerkt Defaultdaten zu speichern.
// ---------------------------------------------------------------------------
const loadConfig = async () => {
  try {
    const headers = new Headers();
    const csrf = getCsrfToken();
    if (csrf) headers.set("X-CSRF-Token", csrf);
    const [configResp, cacheResp, camerasResp, scriptsResp] = await Promise.allSettled([
      api("/api/admin/config", { headers }),
      api("/api/admin/archive-cache", { headers }),
      api("/api/admin/cameras", { headers }),
      api("/api/admin/scripts", { headers }),
    ]);

    if (configResp.status === "fulfilled") {
      state.config = configResp.value;
    } else {
      throw new Error(configResp.reason?.message || "Config laden fehlgeschlagen");
    }

    if (!state.config.defaults) state.config.defaults = { resolutions: [] };
    settingsController.hydrate();
    maintenanceController.hydrate(
      scriptsResp.status === "fulfilled" ? scriptsResp.value : null,
    );

    archiveCacheController.hydrate(
      cacheResp.status === "fulfilled" ? cacheResp.value : null,
      cacheResp.status === "rejected" ? cacheResp.reason : null,
    );

    if (camerasResp.status === "fulfilled") {
      if (Array.isArray(camerasResp.value?.cameras)) {
        state.config.cameras = camerasResp.value.cameras;
      }
      state.cameraRuntime = camerasResp.value?.runtime?.current_paths ?? {};
      state.cameraAutoIso = camerasResp.value?.runtime?.auto_iso ?? {};
    }

    archiveCacheController.syncPolling();
    state.cacheBusy = false;
  } catch (error) {
    flash(error.message, "error");
  }
};

// Der umfangreiche Seitenaufbau lebt in admin-page.js. Diese schmale Funktion
// bleibt als stabiler Einstieg für API-, Fachcontroller- und Flash-Callbacks
// erhalten; der Page-Controller wird nach allen Abhängigkeiten erzeugt.
const render = () => adminPageController.render();

// Gemeinsame Maskierung für alle Admin-Komponenten, die HTML-Strings erzeugen.
const escapeHtml = (value) =>
  (value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#39;");

// Der ausgelagerte Statusbereich erhält nur die Funktionen, die er wirklich
// benötigt. Der gemeinsame Zustand und render() bleiben bewusst in admin.js,
// damit weiterhin genau eine Sollquelle für die gesamte Admin-Seite existiert.
if (!window.WebcamAdminStatus?.create) {
  throw new Error("Admin status component not loaded");
}
const statusController = window.WebcamAdminStatus.create({
  state,
  api,
  getCsrfToken,
  buildPublicApiUrl,
  computeAgeSeconds,
  computeSeverity,
  formatAge,
  formatDateTime,
  escapeHtml,
  flash,
  requestRender: () => render(),
  wcTest,
});

if (!window.WebcamAdminMaintenance?.create) {
  throw new Error("Admin maintenance component not loaded");
}
const maintenanceController = window.WebcamAdminMaintenance.create({
  state,
  root,
  api,
  flash,
  requestRender: () => render(),
  escapeHtml,
  formatDateTime,
});

// Der Legacy-Archivcache hat einen eigenen Polling-Lebenszyklus und bleibt
// deshalb hinter einer kleinen Komponentenschnittstelle. API, CSRF und der
// zentrale Adminzustand werden weiterhin ausschließlich hier bereitgestellt.
if (!window.WebcamAdminArchiveCache?.create) {
  throw new Error("Admin archive-cache component not loaded");
}
const archiveCacheController = window.WebcamAdminArchiveCache.create({
  state,
  root,
  api,
  getCsrfToken,
  flash,
  requestRender: () => render(),
  escapeHtml,
  formatDateTime,
  formatBytes,
});

// Teaser, Übersichtskarte sowie Schutz- und Laufzeitgrenzen sind globale
// Einstellungen. Die Komponente besitzt nur Darstellung und Formularlogik;
// Transport, CSRF, Meldungen und der gemeinsame Zustand verbleiben hier.
if (!window.WebcamAdminSettings?.create) {
  throw new Error("Admin settings component not loaded");
}
const settingsController = window.WebcamAdminSettings.create({
  state,
  root,
  api,
  flash,
  requestRender: () => render(),
  escapeHtml,
  formatDateTime,
});

// Die DB-Vorschau und der Editor einzelner Capture-Slots bilden einen eigenen
// Diagnosebereich. Sie teilen Zustand und API mit dem Admin, bearbeiten aber
// weder Kamerakonfiguration noch Bilddateien.
if (!window.WebcamAdminCaptureEditor?.create) {
  throw new Error("Admin capture editor component not loaded");
}
const captureEditorController = window.WebcamAdminCaptureEditor.create({
  state,
  root,
  api,
  flash,
  requestRender: () => render(),
  escapeHtml,
});
captureEditorController.bind();

// Zusätzliche Feldregeln, Tab-Fokus und RTSP-abhängige Eingaben sind in einer
// zustandslosen Formular-Komponente gebündelt. Die API validiert die Werte
// weiterhin unabhängig; hier entsteht ausschließlich direkte Bedienrückmeldung.
if (!window.WebcamAdminFormValidation?.create) {
  throw new Error("Admin form validation component not loaded");
}
const formValidationController = window.WebcamAdminFormValidation.create({ root });
formValidationController.bind();

// ---------------------------------------------------------------------------
// Gemeinsame Parser und dokumentierte Adapter der Kamera-Untereditoren.
// Fachkomponenten erhalten nur die Abhängigkeiten, die sie tatsächlich für
// Darstellung beziehungsweise Payload-Aufbereitung benötigen.
// ---------------------------------------------------------------------------
const toNumber = (value, fallback = 0) => {
  if (value === undefined || value === null) {
    return fallback;
  }
  const raw = value.toString().trim();
  if (raw === "") {
    return fallback;
  }
  const normalized = raw.replace(/,/g, ".");
  const num = Number(normalized);
  return Number.isFinite(num) ? num : fallback;
};

// Standort, Blickrichtung und Sichtfeld werden von einer eigenen, rein
// visuellen Komponente dargestellt. Sie erhält nur DOM und Zahlenparser; das
// Speichern der Kartenfelder bleibt bewusst im zentralen Kamera-Payload.
if (!window.WebcamAdminMapPreview?.create) {
  throw new Error("Admin map preview component not loaded");
}
const mapPreviewController = window.WebcamAdminMapPreview.create({
  root,
  toNumber,
});

const cleanString = (value) => {
  if (value === undefined || value === null) {
    return "";
  }
  return value.toString().trim();
};

// Der Overlay-Editor besitzt Darstellung, Multipart-Upload, Vorschau und
// Payload-Normalisierung. API-/CSRF-Transport und Configzustand bleiben die
// zentralen Instanzen aus diesem Hauptskript.
if (!window.WebcamAdminOverlayEditor?.create) {
  throw new Error("Admin overlay editor component not loaded");
}
const overlayEditorController = window.WebcamAdminOverlayEditor.create({
  state,
  root,
  api,
  flash,
  escapeHtml,
  appBase,
  toNumber,
  cleanString,
});

// Öffentliche Bildlabels haben einen eigenen Tabelleneditor mit Link-, Farb-
// und Pflichtfeldprüfung. Die Komponente kennt weder API noch Configzustand;
// admin.js übernimmt weiterhin Laden, Speichern und Fehlermeldungen.
if (!window.WebcamAdminLabelEditor?.create) {
  throw new Error("Admin label editor component not loaded");
}
const labelEditorController = window.WebcamAdminLabelEditor.create({
  root,
  escapeHtml,
  toNumber,
});

// Der große Kameraformular-Renderer ist in fachliche Abschnitte ausgelagert.
// Er bekommt nur lesende Helfer und die drei bereits spezialisierten
// Untereditoren. Ereignisse, API-Aufrufe und Payload-Aufbereitung bleiben in
// admin.js, sodass der neue Baustein keine versteckten Nebenwirkungen besitzt.
if (!window.WebcamAdminCameraEditor?.create) {
  throw new Error("Admin camera editor component not loaded");
}
const cameraEditorController = window.WebcamAdminCameraEditor.create({
  state,
  defaultCamera,
  splitCameraTags,
  cameraHasInternalTag,
  internalCameraTags: INTERNAL_CAMERA_TAGS,
  escapeHtml,
  overlayEditor: overlayEditorController,
  labelEditor: labelEditorController,
  captureEditor: captureEditorController,
});

const parseArray = (value) =>
  value
    .split(",")
    .map((entry) => entry.trim())
    .filter(Boolean);

// Abschnittsweise und vollständige Kamera-Payloads entstehen aus denselben
// fachlichen Buildern. Diese Komponente kennt weder API noch Ladezustand;
// sie synchronisiert lediglich TinyMCE und übersetzt das aktive Formular.
if (!window.WebcamAdminCameraPayload?.create) {
  throw new Error("Admin camera payload component not loaded");
}
const cameraPayloadController = window.WebcamAdminCameraPayload.create({
  toNumber,
  cleanString,
  splitCommaSeparated: parseArray,
  buildCameraTagsFromForm,
  overlayEditor: overlayEditorController,
  labelEditor: labelEditorController,
  syncRichText: () => window.tinymce?.triggerSave?.(),
});

// Streammodus und Quell-URLs werden außerhalb des Kameraformulars gepflegt.
// Der Payload-Builder erhält deshalb den vorhandenen Stream ausdrücklich,
// damit eine normale Kameraänderung diese Felder nicht zurücksetzt.
const resolveExistingStream = (slug) => {
  if (!slug) return null;
  if (state.config?.cameras?.[slug]?.stream) {
    return state.config.cameras[slug].stream;
  }
  if (state.selectedSlug && state.config?.cameras?.[state.selectedSlug]?.stream) {
    return state.config.cameras[state.selectedSlug].stream;
  }
  return null;
};
const saveCameraSection = async (sectionId, form) => {
  if (state.isCreating) {
    flash("Bitte Kamera zuerst anlegen.", "warn");
    return;
  }
  const slugField = form?.querySelector('input[name="slug"]');
  const slug = slugField?.value?.trim() || "";
  if (!slug) {
    flash("Slug fehlt im Formular.", "error");
    return;
  }
  if (state.sectionSaving[slug]?.[sectionId]) {
    return;
  }

  state.sectionSaving[slug] = state.sectionSaving[slug] || {};
  state.sectionSaving[slug][sectionId] = true;
  render();
  try {
    const payload = cameraPayloadController.buildSection(form, sectionId, {
      existingStream: resolveExistingStream(slug),
    });
    const res = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/sections/${sectionId}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
    if (res?.camera && state.config?.cameras) {
      state.config.cameras[slug] = res.camera;
    }
    flash(`Sektion "${sectionId}" gespeichert`, "ok");
  } catch (error) {
    flash(error.message, "error");
  } finally {
    if (state.sectionSaving[slug]) {
      state.sectionSaving[slug][sectionId] = false;
    }
    render();
  }
};

const onSaveCamera = async (event) => {
  event.preventDefault();
  const form = event.target;

  if (!formValidationController.validateForm(form)) {
    return;
  }

  const slug = form.querySelector('input[name="slug"]')?.value?.trim() || "";
  if (!slug) {
    flash("Slug darf nicht leer sein", "error");
    return;
  }

  // Parserfehler aus JSON, Overlay oder Labels bleiben vor dem ersten Request
  // sichtbar. Der Builder besitzt selbst keinen stillen Fallback.
  let payload;
  try {
    payload = cameraPayloadController.buildComplete(form, {
      existingStream: state.isCreating ? null : resolveExistingStream(slug),
    });
  } catch (error) {
    flash(error.message, "error");
    return;
  }

  try {
    if (state.isCreating) {
      const created = await api("/api/admin/cameras", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      if (created?._runtime?.provision_required) {
        flash(
          `Kamera ${slug} angelegt. Current-Pfad noch als root freigeben: ${created._runtime.provision_command}`,
          "warn",
        );
      } else {
        flash(`Kamera ${slug} angelegt`, "ok");
      }
      state.selectedSlug = slug;
      state.isCreating = false;
    } else {
      await api(`/api/admin/cameras/${encodeURIComponent(slug)}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
      flash(`Kamera ${slug} aktualisiert`, "ok");
    }
    await loadConfig();
    render();
  } catch (error) {
    flash(error.message, "error");
  }
};

// ---------------------------------------------------------------------------
// Verbleibender DOM-Lebenszyklus für Untertabs und Rich-Text.
// Der TinyMCE-Inhalt wird erst beim bewussten Speichern in den gemeinsamen
// Kamera-Payload übernommen.
// ---------------------------------------------------------------------------
const initEditorTabs = () => {
  const containers = root.querySelectorAll('[data-tabs]');
  containers.forEach((container) => {
    const buttons = Array.from(container.querySelectorAll('[data-tab-target]'));
    const panels = Array.from(container.querySelectorAll('[data-tab-panel]'));
    if (!buttons.length || !panels.length) {
      return;
    }
    const activate = (targetId) => {
      buttons.forEach((btn) => {
        const isActive = btn.dataset.tabTarget === targetId;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      panels.forEach((panel) => {
        const active = panel.dataset.tabPanel === targetId;
        panel.classList.toggle('is-active', active);
      });
      const slug = container.closest('[data-editor-slug]')?.dataset.editorSlug;
      if (slug) {
        state.editorTabs[slug] = targetId;
      }
      if (targetId === 'map') {
        window.requestAnimationFrame(() => mapPreviewController.init());
      } else {
        mapPreviewController.destroy(false);
      }
    };
    buttons.forEach((btn) => {
      btn.addEventListener('click', () => activate(btn.dataset.tabTarget));
    });
    // Respektiere gemerkten Tab pro Kamera, sonst ersten aktivieren
    const slug = container.closest('[data-editor-slug]')?.dataset.editorSlug;
    const initial = slug && state.editorTabs[slug]
      ? state.editorTabs[slug]
      : buttons[0].dataset.tabTarget;
    activate(initial);
  });
};

const initTinyMce = () => {
  const textarea = root.querySelector(".camera-form textarea[name='info_html']");
  if (!textarea) {
    return;
  }
  if (!textarea.id) {
    textarea.id = `info-html-${Date.now()}`;
  }
  if (typeof window.tinymce === "undefined") {
    window.setTimeout(initTinyMce, 100);
    return;
  }
  window.tinymce.remove(`#${textarea.id}`);
  window.tinymce.init({
    selector: `#${textarea.id}`,
    base_url: tinymceBaseUrl,
    suffix: ".min",
    menubar: false,
    statusbar: false,
    branding: false,
    height: 320,
    plugins: "link lists",
    toolbar: "undo redo | bold italic | bullist numlist | link removeformat",
    default_link_target: "_blank",
    link_context_toolbar: true,
    setup(editor) {
      editor.on("change keyup blur", () => {
        editor.save();
      });
      editor.on("init", () => {
        const form = editor.targetElm?.closest("form");
        if (form) {
          const sync = () => window.tinymce?.triggerSave();
          form.addEventListener("submit", sync);
        }
      });
    },
  });
};

// ---------------------------------------------------------------------------
// Destruktive Aktion und delegierte Ereignisse.
// Die Listener hängen einmal am stabilen Admin-Wurzelelement. Deshalb bleiben
// sie auch nach einem vollständigen `render()` aktiv und werden nicht doppelt
// registriert.
// ---------------------------------------------------------------------------
const confirmDelete = async (slug) => {
  if (!window.confirm(`Kamera ${slug} wirklich löschen?`)) {
    return;
  }
  try {
    await api(`/api/admin/cameras/${encodeURIComponent(slug)}`, {
      method: "DELETE",
    });
    flash(`Kamera ${slug} gelöscht`, "warn");
    await loadConfig();
    state.selectedSlug = null;
    render();
  } catch (error) {
    flash(error.message, "error");
  }
};

// Der Seitencontroller wird bewusst erst hier erzeugt: Zu diesem Zeitpunkt
// existieren alle Fachcontroller, Speicherfunktionen und Editor-Adapter. Die
// injizierten Callbacks verhindern globale Seiteneffekte in admin-page.js und
// machen dessen Render-/Listenervertrag isoliert prüfbar.
if (!window.WebcamAdminPage?.create) {
  throw new Error("Admin page component not loaded");
}
const adminPageController = window.WebcamAdminPage.create({
  state,
  root,
  apiBase,
  iteration: iterationAttr,
  storage: localStorage,
  escapeHtml,
  formatDateTime,
  buildCameraLink,
  defaultCamera,
  requestRender: () => render(),
  confirmDelete,
  focusAvailable: async (slug) => (await api(`/api/admin/cameras/${encodeURIComponent(slug)}/focus`)).available === true,
  tunnelStatus: async (slug) => api(`/api/admin/cameras/${encodeURIComponent(slug)}/tunnel`),
  // Nur die ausgewählte Kamera freigeben/schließen; die API begrenzt die Lease.
  tunnelAction: async (slug, action) => {
    if (!slug || !['open', 'close'].includes(action)) return;
    try {
      const result = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/tunnel`, { method: 'POST', body: JSON.stringify({ action }) });
      flash(`${slug}: ${result.detail}`, 'ok');
    } catch (error) { flash(error.message, 'error'); }
  },
  // Erlaubtes Fokus-Enum vormerken, kein freier Remote-Shell-Befehl.
  focusCamera: async (slug, mode) => {
    if (!slug || !['--', '-', 'auto', '+', '++'].includes(mode)) return;
    try {
      const result = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/focus`, { method: "POST", body: JSON.stringify({ mode }) });
      flash(`${slug}: ${result.detail}`, "ok");
    } catch (error) { flash(error.message, "error"); }
  },
  // Einmalauftrag für genau diesen Slug; die Rückmeldung ist noch kein fertiges Bild.
  captureCamera: async (slug) => {
    if (!slug) return;
    try {
      const result = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/capture`, { method: "POST" });
      flash(`${slug}: ${result.detail}`, "ok");
    } catch (error) {
      flash(error.message, "error");
    }
  },
  saveCameraSection,
  onSaveCamera,
  initEditorTabs,
  initRichTextEditor: initTinyMce,
  removeRichTextEditors: () => window.tinymce?.remove?.(),
  cameraEditor: cameraEditorController,
  mapPreview: mapPreviewController,
  settings: settingsController,
  status: statusController,
  maintenance: maintenanceController,
  archiveCache: archiveCacheController,
  formValidation: formValidationController,
  overlayEditor: overlayEditorController,
  labelEditor: labelEditorController,
});

// Einziger Startpunkt: zuerst Serverzustand laden, danach den zuletzt gewählten
// Haupttab rendern. Es gibt keinen zweiten versteckten Initialisierungspfad.
loadConfig().then(() => {
  if (state.activeTab === "status") {
    statusController.refresh();
  } else {
    render();
  }
});
