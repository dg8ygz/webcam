// Overlay-Logo-Editor des Admin-Panels.
//
// Leseführung:
// 1. Helle und dunkle Logo-Varianten verwenden dieselbe Feldbeschreibung.
// 2. Gespeicherte Werte werden maskiert als Formular und Vorschau gerendert.
// 3. Ein Upload läuft über denselben CSRF-geschützten Admin-API-Adapter.
// 4. Erfolgreiche Uploads aktualisieren Formular, Vorschau und Browserzustand.
// 5. Erst der zentrale Kamera-Speichervorgang persistiert die normalisierten Werte.
// 6. Direkte Listener werden nach jedem Admin-Render an die neuen DOM-Knoten gebunden.
//
// Das Modul entscheidet nicht über Dateipfade oder Bildformate. Diese
// Sicherheitsgrenzen bleiben im PHP-Backend, das ausschließlich PNG/JPEG
// annimmt und eine kontrollierte overlay.php-URL zurückgibt.
(function registerAdminOverlayEditor(global) {
  "use strict";

  const VARIANTS = Object.freeze({
    light: Object.freeze({
      idPrefix: "overlay",
      fieldPrefix: "overlay",
      stateKey: "overlay_logo",
      filenameHint: "logo.png",
    }),
    dark: Object.freeze({
      idPrefix: "overlay-dark",
      fieldPrefix: "overlay_dark",
      stateKey: "overlay_logo_dark",
      filenameHint: "logo-dark.png",
    }),
  });

  /**
   * Zustand, Transport und Meldungen stammen aus admin.js. Die Komponente
   * eröffnet damit weder einen zweiten Config-Store noch einen abweichenden
   * Authentifizierungsweg.
   */
  const create = ({
    state,
    root,
    api,
    flash,
    escapeHtml,
    appBase,
    toNumber,
    cleanString,
  }) => {
    if (
      !state ||
      !root ||
      typeof api !== "function" ||
      typeof flash !== "function" ||
      typeof escapeHtml !== "function" ||
      typeof toNumber !== "function" ||
      typeof cleanString !== "function"
    ) {
      throw new Error("Admin overlay editor dependencies are incomplete");
    }

    const safe = (value) => escapeHtml(String(value ?? ""));
    const variantConfig = (variant) =>
      variant === "dark" ? VARIANTS.dark : VARIANTS.light;

    // -----------------------------------------------------------------------
    // Dokumentrelative Vorschau-URLs.
    // Relative Backendwerte werden gegen die ermittelte Anwendungsbasis
    // aufgelöst. Absolute URLs bleiben unverändert; sämtliche Ausgaben werden
    // später zusätzlich für den konkreten HTML-Attributkontext maskiert.
    // -----------------------------------------------------------------------
    const collapseDoubleSlashes = (value) =>
      String(value).replace(/([^:])\/{2,}/g, "$1/");

    const resolveAppUrl = (value) => {
      const raw = String(value ?? "").trim();
      if (!raw) return "";
      if (/^(?:[a-z]+:)?\/\//i.test(raw)) return raw;
      if (raw.startsWith("/")) return collapseDoubleSlashes(raw);
      return collapseDoubleSlashes(`${appBase || "./"}${raw}`);
    };

    const loadImageDimensions = (url, onReady) => {
      if (!url) return;
      const image = new Image();
      image.onload = () => onReady(image.naturalWidth, image.naturalHeight);
      image.src = url;
    };

    // -----------------------------------------------------------------------
    // Formularrendering für beide Tonvarianten.
    // Alle Werte können administrativ beziehungsweise aus einer Legacy-Quelle
    // stammen und werden deshalb auch bei vermeintlichen Zahlenfeldern
    // maskiert. Neue Kameras zeigen nur den erklärenden Uploadhinweis.
    // -----------------------------------------------------------------------
    const renderVariantSection = ({ variant, label, overlay, slug, isCreating }) => {
      const config = variantConfig(variant);
      const urlValue = overlay?.url ?? "";
      const widthValue = overlay?.width ?? "";
      const heightValue = overlay?.height ?? "";
      const offsetTopValue = overlay?.offset_top ?? 10;
      const offsetRightValue = overlay?.offset_right ?? 10;
      const opacityValue = overlay?.opacity ?? 1;
      const previewUrl = resolveAppUrl(urlValue);
      const uploadControls = isCreating
        ? '<p class="hint">Bitte speichere die Kamera, bevor du ein Logo hochlädst.</p>'
        : `<div class="overlay-upload" data-overlay-controls="${safe(variant)}">
            <button class="btn secondary" type="button" id="${config.idPrefix}-upload">Datei auswählen</button>
            <button class="btn danger" type="button" id="${config.idPrefix}-clear">Logo entfernen</button>
            <input type="file" accept="image/png,image/jpeg" id="${config.idPrefix}-file" style="display:none">
          </div>`;

      return `
        <div class="overlay-variant" data-overlay-variant="${safe(variant)}">
          <h4>${safe(label)}</h4>
          <div class="form-grid">
            <div class="form-field">
              <label for="${config.idPrefix}-url">Logo-URL</label>
              <input id="${config.idPrefix}-url" name="${config.fieldPrefix}_url" value="${safe(urlValue)}" placeholder="overlay.php?slug=${safe(slug)}&amp;file=${config.filenameHint}">
              <p class="hint">Wird nach dem Upload automatisch gesetzt.</p>
            </div>
            <div class="form-field">
              <label for="${config.idPrefix}-width">Breite (px)</label>
              <input type="number" min="1" id="${config.idPrefix}-width" name="${config.fieldPrefix}_width" value="${safe(widthValue)}">
            </div>
            <div class="form-field">
              <label for="${config.idPrefix}-height">Höhe (px)</label>
              <input type="number" min="1" id="${config.idPrefix}-height" name="${config.fieldPrefix}_height" value="${safe(heightValue)}">
            </div>
            <div class="form-field">
              <label for="${config.idPrefix}-offset-top">Abstand oben (px)</label>
              <input type="number" min="0" id="${config.idPrefix}-offset-top" name="${config.fieldPrefix}_offset_top" value="${safe(offsetTopValue)}">
            </div>
            <div class="form-field">
              <label for="${config.idPrefix}-offset-right">Abstand rechts (px)</label>
              <input type="number" min="0" id="${config.idPrefix}-offset-right" name="${config.fieldPrefix}_offset_right" value="${safe(offsetRightValue)}">
            </div>
            <div class="form-field">
              <label for="${config.idPrefix}-opacity">Deckkraft (0–1)</label>
              <input type="number" step="0.05" min="0" max="1" id="${config.idPrefix}-opacity" name="${config.fieldPrefix}_opacity" value="${safe(opacityValue)}">
            </div>
            <div class="form-field form-field--full">
              <label>Grafik hochladen</label>
              ${uploadControls}
              <div id="${config.idPrefix}-preview" class="overlay-preview">
                ${previewUrl
                  ? `<img src="${safe(previewUrl)}" alt="Overlay Logo" class="overlay-preview__img">`
                  : '<p class="hint">Noch kein Logo hochgeladen.</p>'}
              </div>
            </div>
          </div>
        </div>`;
    };

    // -----------------------------------------------------------------------
    // Normalisierung für den Kamera-Payload.
    // Eine leere URL bedeutet bewusst „kein Logo“. Existiert eine URL, sind
    // positive Bildmaße Pflicht; Deckkraft wird auf 0–1 begrenzt. Das Backend
    // prüft den resultierenden Payload anschließend nochmals verbindlich.
    // -----------------------------------------------------------------------
    const parseFormData = (formData, variant = "light") => {
      const config = variantConfig(variant);
      const url = cleanString(formData.get(`${config.fieldPrefix}_url`));
      if (!url) return null;

      const width = toNumber(formData.get(`${config.fieldPrefix}_width`), 0);
      const height = toNumber(formData.get(`${config.fieldPrefix}_height`), 0);
      if (width <= 0 || height <= 0) {
        throw new Error("Overlay benötigt Breite und Höhe größer 0.");
      }

      const offsetTop = toNumber(formData.get(`${config.fieldPrefix}_offset_top`), 10);
      const offsetRight = toNumber(formData.get(`${config.fieldPrefix}_offset_right`), 10);
      const opacity = Math.min(
        1,
        Math.max(0, toNumber(formData.get(`${config.fieldPrefix}_opacity`), 1)),
      );
      return {
        url,
        width,
        height,
        offset_top: offsetTop,
        offset_right: offsetRight,
        opacity,
      };
    };

    // -----------------------------------------------------------------------
    // Multipart-Upload über den gemeinsamen Admin-Transport.
    // Der API-Adapter ergänzt CSRF und bereitet Fehler einheitlich auf. Der
    // Browser setzt den Multipart-Content-Type samt Boundary selbst.
    // -----------------------------------------------------------------------
    const upload = async (slug, file) => {
      const body = new FormData();
      body.append("file", file);
      return api(`/api/admin/cameras/${encodeURIComponent(slug)}/overlay`, {
        method: "POST",
        body,
      });
    };

    const fieldsFor = (form, variant) => {
      const config = variantConfig(variant);
      return {
        config,
        uploadButton: form.querySelector(`#${config.idPrefix}-upload`),
        clearButton: form.querySelector(`#${config.idPrefix}-clear`),
        fileInput: form.querySelector(`#${config.idPrefix}-file`),
        preview: form.querySelector(`#${config.idPrefix}-preview`),
        slugInput: form.querySelector('input[name="slug"]'),
        urlInput: form.querySelector(`#${config.idPrefix}-url`),
        widthInput: form.querySelector(`#${config.idPrefix}-width`),
        heightInput: form.querySelector(`#${config.idPrefix}-height`),
        offsetTopInput: form.querySelector(`#${config.idPrefix}-offset-top`),
        offsetRightInput: form.querySelector(`#${config.idPrefix}-offset-right`),
        opacityInput: form.querySelector(`#${config.idPrefix}-opacity`),
      };
    };

    const updateDimensions = (fields, width, height, onlyWhenEmpty = false) => {
      if (width && fields.widthInput && (!onlyWhenEmpty || !fields.widthInput.value)) {
        fields.widthInput.value = String(width);
      }
      if (height && fields.heightInput && (!onlyWhenEmpty || !fields.heightInput.value)) {
        fields.heightInput.value = String(height);
      }
    };

    const resetForm = (fields) => {
      if (fields.urlInput) fields.urlInput.value = "";
      if (fields.widthInput) fields.widthInput.value = "";
      if (fields.heightInput) fields.heightInput.value = "";
      if (fields.offsetTopInput) fields.offsetTopInput.value = "10";
      if (fields.offsetRightInput) fields.offsetRightInput.value = "10";
      if (fields.opacityInput) fields.opacityInput.value = "1";
      if (fields.fileInput) fields.fileInput.value = "";
      if (fields.preview) {
        fields.preview.innerHTML = '<p class="hint">Noch kein Logo hochgeladen.</p>';
      }
    };

    // -----------------------------------------------------------------------
    // Erfolgreichen Upload in die sichtbare Formularinstanz übernehmen.
    // Der Browserzustand wird ebenfalls aktualisiert, weil `flash()` einen
    // vollständigen Neurender auslösen kann. Ohne diese Kopie würde der gerade
    // vom Server bestätigte Pfad dabei wieder durch den Altwert ersetzt.
    // -----------------------------------------------------------------------
    const applyUploadResult = (fields, slug, result) => {
      const storedPath = result?.relative_path || result?.url || "";
      const previewUrl = resolveAppUrl(storedPath);
      if (fields.urlInput) fields.urlInput.value = storedPath;
      updateDimensions(fields, result?.width, result?.height);

      if (fields.preview) {
        if (previewUrl || storedPath) {
          fields.preview.innerHTML = `<img src="${safe(previewUrl || storedPath)}" alt="Overlay Logo" class="overlay-preview__img">`;
          loadImageDimensions(previewUrl || storedPath, (width, height) => {
            updateDimensions(fields, width, height);
          });
        } else {
          fields.preview.innerHTML = '<p class="hint">Vorschau nicht verfügbar.</p>';
        }
      }

      const camera = state.config?.cameras?.[slug];
      if (camera) {
        camera[fields.config.stateKey] = {
          url: storedPath,
          width: toNumber(fields.widthInput?.value ?? result?.width, 0),
          height: toNumber(fields.heightInput?.value ?? result?.height, 0),
          offset_top: toNumber(fields.offsetTopInput?.value, 10),
          offset_right: toNumber(fields.offsetRightInput?.value, 10),
          opacity: Math.min(1, Math.max(0, toNumber(fields.opacityInput?.value, 1))),
        };
      }
    };

    // -----------------------------------------------------------------------
    // DOM-Bindung nach einem Admin-Render.
    // Jeder Render erzeugt neue Formularelemente. Ein Data-Merker verhindert
    // trotzdem doppelte Listener, falls `init()` auf demselben DOM wiederholt
    // wird. Während eines Uploads bleiben Button und Dateifeld gesperrt.
    // -----------------------------------------------------------------------
    const bindVariant = (form, variant) => {
      const fields = fieldsFor(form, variant);
      const controls = fields.uploadButton?.closest("[data-overlay-controls]");
      if (!fields.uploadButton || !fields.fileInput || controls?.dataset.overlayInitialized === "1") {
        return;
      }
      if (controls) controls.dataset.overlayInitialized = "1";

      const existingImage = fields.preview?.querySelector("img");
      if (existingImage) {
        loadImageDimensions(existingImage.src, (width, height) => {
          updateDimensions(fields, width, height, true);
        });
      }

      if (state.isCreating) {
        fields.uploadButton.disabled = true;
        if (fields.clearButton) fields.clearButton.disabled = true;
        return;
      }

      const originalLabel = fields.uploadButton.textContent;
      fields.uploadButton.addEventListener("click", () => fields.fileInput.click());
      fields.fileInput.addEventListener("change", async () => {
        const file = fields.fileInput.files?.[0];
        if (!file) return;

        const slug = fields.slugInput?.value?.trim();
        if (!slug) {
          flash("Slug fehlt, bitte speichern.", "error");
          fields.fileInput.value = "";
          return;
        }

        fields.uploadButton.disabled = true;
        fields.uploadButton.textContent = "Lade hoch…";
        try {
          const result = await upload(slug, file);
          applyUploadResult(fields, slug, result);
          flash("Overlay hochgeladen", "ok");
        } catch (error) {
          flash(error.message, "error");
        } finally {
          fields.uploadButton.disabled = false;
          fields.uploadButton.textContent = originalLabel || "Datei auswählen";
          fields.fileInput.value = "";
        }
      });

      fields.clearButton?.addEventListener("click", (event) => {
        event.preventDefault();
        const slug = fields.slugInput?.value?.trim();
        resetForm(fields);
        const camera = slug ? state.config?.cameras?.[slug] : null;
        if (camera) camera[fields.config.stateKey] = null;
        flash("Overlay entfernt – bitte speichern.", "warn");
      });
    };

    const init = () => {
      const form = root.querySelector(".camera-form");
      if (!form) return;
      bindVariant(form, "light");
      bindVariant(form, "dark");
    };

    return Object.freeze({ renderVariantSection, parseFormData, init });
  };

  global.WebcamAdminOverlayEditor = Object.freeze({ create });
})(window);
