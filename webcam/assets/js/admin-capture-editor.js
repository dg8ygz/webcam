// Datenbank- und Capture-Editor des Admin-Panels.
//
// Leseführung:
// 1. Die kleine DB-Vorschau lädt die letzten fünf Capture-Einträge.
// 2. Ein konkreter Slot wird per Unix-Timestamp oder Datum/Uhrzeit gesucht.
// 3. Varianten, EXIF und Wetter werden als strukturierte JSON-Arrays gezeigt.
// 4. Erst der Speichern-Klick baut einen geprüften API-Payload auf.
// 5. Delegierte Listener bleiben trotz vollständiger Admin-Neurenders gültig.
//
// Das Modul ändert keine Bilddateien. Es bearbeitet ausschließlich Metadaten
// der aktiven Kamera-SQLite über die bereits authentifizierte Admin-API.
(function registerAdminCaptureEditor(global) {
  "use strict";

  const EMPTY_CAPTURE = Object.freeze({
    capture: null,
    variants: [],
    exif: [],
    weather: [],
    logo: null,
  });

  /**
   * Der gemeinsame Zustand, API-/CSRF-Transport und die Meldungsfunktion
   * werden von admin.js injiziert. So entstehen weder ein zweiter Store noch
   * ein abweichender Authentifizierungs- oder Fehlerpfad.
   */
  const create = ({ state, root, api, flash, requestRender, escapeHtml }) => {
    if (!state || !root || typeof api !== "function" || typeof requestRender !== "function") {
      throw new Error("Admin capture editor dependencies are incomplete");
    }

    const safe = (value) => escapeHtml(String(value ?? ""));
    let handlersBound = false;

    const emptyEditor = (error = null) => ({
      loading: false,
      error,
      ...EMPTY_CAPTURE,
    });

    // API-Antworten existieren historisch in zwei Formen: Capturefelder direkt
    // im Objekt oder als `capture` plus Relationslisten. Die Normalisierung
    // hält den restlichen Editor unabhängig von dieser Transportform.
    const normalizeCaptureItem = (item, envelope = {}, meta = {}) => {
      const capture = item?.capture ?? item ?? null;
      if (!capture || typeof capture !== "object") {
        return emptyEditor("Kein Capture gefunden");
      }
      return {
        loading: false,
        saving: false,
        error: null,
        slot_ts: capture.slot_ts ?? item?.slot_ts ?? null,
        capture,
        variants: item?.variants ?? envelope.variants ?? [],
        exif: item?.exif ?? envelope.exif ?? [],
        weather: item?.weather ?? envelope.weather ?? [],
        logo: item?.logo ?? envelope.logo ?? null,
        ...meta,
      };
    };

    // Die Vorschau ist rein diagnostisch. Pro Kamera läuft höchstens ein
    // Request; ein Fehler wird im Tab sichtbar und nicht als leere DB getarnt.
    const ensurePreview = (slug) => {
      if (!slug || state.dbPreview[slug] || state.dbLoading[slug]) return;
      state.dbLoading[slug] = true;
      api(`/api/admin/cameras/${encodeURIComponent(slug)}/db?limit=5`)
        .then((result) => {
          state.dbPreview[slug] = result?.entries || [];
        })
        .catch((error) => {
          state.dbPreview[slug] = { error: error.message };
        })
        .finally(() => {
          delete state.dbLoading[slug];
          requestRender();
        });
    };

    // Jahreswerte werden separat geladen, weil die kompakte DB-Vorschau nicht
    // zwingend den gesamten historischen Zeitraum abbildet. Bei einem Fehler
    // bleiben die aus der Vorschau ableitbaren Jahre als sichtbarer Fallback.
    const ensureYears = (slug, fallbackYears) => {
      state.yearsCache = state.yearsCache || {};
      state.yearsLoading = state.yearsLoading || {};
      if (!slug || state.yearsCache[slug] || state.yearsLoading[slug]) return;
      state.yearsLoading[slug] = true;
      api(`/api/admin/cameras/${encodeURIComponent(slug)}/years`)
        .then((result) => {
          state.yearsCache[slug] = Array.isArray(result?.years) && result.years.length
            ? result.years
            : fallbackYears;
        })
        .catch(() => {
          state.yearsCache[slug] = fallbackYears;
        })
        .finally(() => {
          delete state.yearsLoading[slug];
          requestRender();
        });
    };

    const yearsFromPreview = (preview) => {
      const years = new Set();
      if (Array.isArray(state.config?.years)) {
        state.config.years.forEach((year) => years.add(Number(year)));
      }
      if (Array.isArray(preview)) {
        preview.forEach((item) => {
          if (!item?.slot_ts) return;
          const year = new Date(Number(item.slot_ts) * 1000).getFullYear();
          if (Number.isInteger(year)) years.add(year);
        });
      }
      if (!years.size) years.add(new Date().getFullYear());
      return Array.from(years).sort((left, right) => left - right);
    };

    const renderOptions = (values, selected) => values
      .map((value) => `<option value="${safe(value)}" ${String(value) === String(selected) ? "selected" : ""}>${safe(value)}</option>`)
      .join("");

    const renderPreviewTable = (preview, isLoading) => {
      if (isLoading) return "<p>Lade Einträge…</p>";
      if (!preview) return "<p>Noch keine Daten geladen.</p>";
      if (preview.error) return `<p class="form-error">Fehler: ${safe(preview.error)}</p>`;
      if (!Array.isArray(preview) || preview.length === 0) return "<p>Keine Einträge gefunden.</p>";

      const buildRow = (item) => {
        const hash = item?.hash ? String(item.hash).slice(0, 12) : "";
        const exifItems = Array.isArray(item?.exif)
          ? item.exif.map((entry) => `${entry?.tag ?? ""}: ${entry?.value ?? ""}`)
          : [];
        const exifPreview = exifItems.slice(0, 2).join("; ");
        const exifRest = exifItems.slice(2).join("; ");
        const exifHtml = exifItems.length === 0
          ? "–"
          : `<details class="exif-collapsible"><summary>${safe(exifPreview || exifItems[0])}${exifRest ? " …" : ""}</summary><div class="exif-body">${safe(exifItems.join("; "))}</div></details>`;
        return `<tr>
          <td>${safe(item?.datetime || item?.slot_ts || "")}</td>
          <td>${safe(item?.captured_at || "")}</td>
          <td>${safe(item?.relative_path || "")}</td>
          <td>${safe(item?.likes ?? 0)}</td>
          <td>${item?.luminance !== "" && item?.luminance !== undefined ? safe(item.luminance) : "–"}</td>
          <td>${hash ? safe(hash) : "–"}</td>
          <td>${exifHtml}</td>
          <td>${item?.weather ? safe(item.weather) : "–"}</td>
        </tr>`;
      };

      const [first, ...rest] = preview;
      const table = (rows) => `<div style="overflow-x:auto;">
        <table class="table-compact">
          <thead><tr><th>Zeit (Slot)</th><th>Aufgenommen</th><th>Pfad</th><th>Likes</th><th>Luminanz</th><th>Hash</th><th>EXIF</th><th>Wetter</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
      const firstTable = table(buildRow(first));
      if (!rest.length) return firstTable;
      return `${firstTable}<details class="db-preview-more"><summary>Weitere ${rest.length} Einträge anzeigen</summary>
        <div style="margin-top:8px;">${table(rest.map(buildRow).join(""))}</div>
      </details>`;
    };

    const renderPulldownField = (label, name, value, rows, buttonLabel) => `
      <div class="form-field pulldown" data-pulldown>
        <label>${label}</label>
        <div class="pulldown-bar">
          <button class="btn ghost" type="button" data-action="toggle-pulldown">${buttonLabel}</button>
        </div>
        <div class="pulldown-panel" hidden>
          <textarea rows="${rows}" name="${name}" data-capture-json>${safe(value)}</textarea>
        </div>
      </div>`;

    const renderDetail = (slug, editor) => {
      if (!editor || editor.loading) {
        return '<p class="hint">Capture laden, um Details zu bearbeiten.</p>';
      }
      if (editor.error) return `<p class="form-error">${safe(editor.error)}</p>`;
      if (!editor.capture) return '<p class="hint">Kein Capture ausgewählt.</p>';

      const capture = editor.capture;
      const logo = editor.logo || null;
      const score = Number(logo?.score);
      const checkedAt = Number(logo?.checked_at);
      const logoText = logo
        ? `${logo.detected ? "Gefunden" : "Nicht gefunden"}${Number.isFinite(score) ? ` (Score ${score.toFixed(3)})` : ""}`
          + (Number.isFinite(checkedAt) && checkedAt > 0 ? ` · geprüft am ${new Date(checkedAt * 1000).toLocaleString()}` : "")
        : "Nicht geprüft";

      return `
        <div class="capture-editor" data-capture-editor="${safe(slug)}">
          <div class="form-grid">
            <div class="form-field"><label>Slot</label><input value="${safe(capture.slot_ts || "")}" readonly></div>
            <div class="form-field"><label>Captured TS</label><input name="captured_ts" type="number" value="${safe(capture.captured_ts ?? "")}" data-capture-field></div>
            <div class="form-field"><label>Relative Path</label><input name="relative_path" value="${safe(capture.relative_path || "")}" data-capture-field></div>
            <div class="form-field"><label>Likes</label><input name="likes_count" type="number" min="0" value="${safe(capture.likes_count ?? 0)}" data-capture-field></div>
            <div class="form-field"><label>Luminanz</label><input name="luminance" type="number" step="0.01" value="${safe(capture.luminance ?? "")}" data-capture-field></div>
            <div class="form-field"><label>Hash</label><input name="hash_sha256" value="${safe(capture.hash_sha256 || "")}" data-capture-field></div>
          </div>
          <div class="form-field"><label>Logo-Check</label><input value="${safe(logoText)}" readonly></div>
          ${renderPulldownField("Variants (JSON Array)", "variants", JSON.stringify(editor.variants || [], null, 2), 6, "JSON bearbeiten (Variants)")}
          ${renderPulldownField("EXIF (JSON Array)", "exif", JSON.stringify(editor.exif || [], null, 2), 6, "JSON bearbeiten (EXIF)")}
          ${renderPulldownField("Wetter (JSON Array)", "weather", JSON.stringify(editor.weather || [], null, 2), 4, "JSON bearbeiten (Wetter)")}
          <div class="form-actions">
            <button class="btn primary" type="button" data-action="save-capture" data-slug="${safe(slug)}" data-slot="${safe(capture.slot_ts || "")}" ${editor.saving ? "disabled" : ""}>${editor.saving ? "Speichere…" : "Speichern"}</button>
          </div>
        </div>`;
    };

    // Der komplette Datenbank-Tab ist ein reines Abbild des gemeinsamen
    // Zustands. Nebenläufige Preview-/Jahresrequests werden nur angestoßen,
    // wenn für die Kamera noch kein Ergebnis oder laufender Request existiert.
    const renderSection = (slug) => {
      const preview = state.dbPreview[slug];
      const editor = state.captureEditor[slug] || {};
      const fallbackYears = yearsFromPreview(preview);
      ensurePreview(slug);
      ensureYears(slug, fallbackYears);

      const slotValue = editor.slot_ts || "";
      const slotMode = editor.slot_mode || (slotValue ? "auto" : "");
      const query = editor.query || null;
      const selectedDate = (() => {
        if ((slotMode === "user" || slotMode === "auto") && slotValue) {
          return new Date(Number(slotValue) * 1000);
        }
        if (query?.year && query?.month && query?.day) {
          const match = String(query.time || "").match(/^(\d{2}):?(\d{2})/);
          return new Date(
            Number(query.year),
            Number(query.month) - 1,
            Number(query.day),
            match ? Number(match[1]) : 0,
            match ? Number(match[2]) : 0,
          );
        }
        if (Array.isArray(preview) && preview.length) {
          return new Date(Number(preview[0].slot_ts) * 1000);
        }
        return new Date();
      })();
      const selectedYear = query?.year ?? selectedDate.getFullYear();
      const selectedMonth = query?.month ?? selectedDate.getMonth() + 1;
      const selectedDay = query?.day ?? selectedDate.getDate();
      const selectedTime = query?.time || `${selectedDate.getHours().toString().padStart(2, "0")}:${selectedDate.getMinutes().toString().padStart(2, "0")}`;
      const months = Array.from({ length: 12 }, (_, index) => index + 1);
      const days = Array.from({ length: 31 }, (_, index) => index + 1);
      const times = Array.from({ length: 24 * 6 }, (_, index) => {
        const hour = Math.floor(index / 6).toString().padStart(2, "0");
        const minute = ((index % 6) * 10).toString().padStart(2, "0");
        return `${hour}:${minute}`;
      });

      const loadForm = `
        <div class="capture-load-form" data-slug="${safe(slug)}">
          <div class="form-grid form-grid--4">
            <div class="form-field"><label>Jahr</label><select name="year">${renderOptions(state.yearsCache?.[slug] || fallbackYears, selectedYear)}</select></div>
            <div class="form-field"><label>Monat</label><select name="month">${renderOptions(months, selectedMonth)}</select></div>
            <div class="form-field"><label>Tag</label><select name="day">${renderOptions(days, selectedDay)}</select></div>
            <div class="form-field">
              <label>Uhrzeit</label><select name="time">${renderOptions(times, selectedTime)}</select>
              <small class="hint">Optional: hh:mm, sonst nächster Treffer am Tag</small>
            </div>
            <div class="form-field">
              <label>Slot-Timestamp</label>
              <input name="slot_ts" type="number" min="0" value="${safe(slotValue)}" placeholder="Unix-Timestamp" data-slot-user="0">
              <small class="hint">Wenn gesetzt, hat Vorrang.</small>
            </div>
            <div class="form-field">
              <label>&nbsp;</label>
              <div class="btn-split">
                <button class="btn secondary" type="button" data-action="load-capture">Laden</button>
                <button class="btn ghost" type="button" data-action="load-capture-latest">Aktuell</button>
              </div>
            </div>
          </div>
        </div>`;

      return `${renderPreviewTable(preview, Boolean(state.dbLoading[slug]))}<div class="form-section__spacer"></div>${loadForm}${renderDetail(slug, editor)}`;
    };

    const loadBySlot = async (slug, slotTs, meta) => {
      state.captureEditor[slug] = {
        ...(state.captureEditor[slug] || {}),
        ...meta,
        loading: true,
        error: null,
      };
      requestRender();
      try {
        const data = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/captures?slot_ts=${slotTs}`);
        state.captureEditor[slug] = normalizeCaptureItem(data?.capture, data, meta);
      } catch (error) {
        state.captureEditor[slug] = emptyEditor(error.message);
      }
      requestRender();
    };

    const loadByDate = async (slug, query) => {
      const params = new URLSearchParams({
        year: String(query.year),
        month: String(query.month),
        day: String(query.day),
      });
      if (query.time) params.set("time", query.time);
      state.captureEditor[slug] = {
        ...(state.captureEditor[slug] || {}),
        loading: true,
        error: null,
        slot_mode: "query",
        query,
      };
      requestRender();
      try {
        const data = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/captures?${params}`);
        const item = data?.capture || (Array.isArray(data?.captures) ? data.captures[0] : null);
        state.captureEditor[slug] = normalizeCaptureItem(item, data, {
          slot_mode: "query",
          query,
        });
      } catch (error) {
        state.captureEditor[slug] = emptyEditor(error.message);
        flash(error.message, "error");
      }
      requestRender();
    };

    const loadLatest = async (slug) => {
      state.captureEditor[slug] = {
        ...(state.captureEditor[slug] || {}),
        loading: true,
        error: null,
      };
      requestRender();
      try {
        const data = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/captures?latest=1`);
        const item = data?.capture || (Array.isArray(data?.captures) ? data.captures.at(-1) : null);
        state.captureEditor[slug] = normalizeCaptureItem(item, data, {
          slot_mode: "auto",
          query: null,
        });
      } catch (error) {
        state.captureEditor[slug] = emptyEditor(error.message);
        flash(error.message, "error");
      }
      requestRender();
    };

    const parseJsonArray = (container, name) => {
      const field = container.querySelector(`textarea[name='${name}']`);
      if (!field) return undefined;
      const raw = field.value?.trim();
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) throw new Error("muss ein Array sein");
      return parsed;
    };

    // Skalare Felder werden typisiert, Relationslisten müssen echte Arrays
    // sein. Ungültige JSON- oder Zahlenwerte verlassen den Browser nicht; die
    // Admin-API validiert den gültigen Payload anschließend erneut.
    const buildSavePayload = (container) => {
      const payload = {};
      for (const input of container.querySelectorAll("[data-capture-field]")) {
        const name = input.name;
        const value = input.value?.toString() ?? "";
        if (!name || value === "") continue;
        if (["likes_count", "captured_ts", "luminance"].includes(name)) {
          const numericValue = Number(value);
          if (!Number.isFinite(numericValue)) throw new Error(`${name}: ungültige Zahl`);
          payload[name] = numericValue;
        } else {
          payload[name] = value;
        }
      }
      for (const name of ["variants", "exif", "weather"]) {
        try {
          const value = parseJsonArray(container, name);
          if (value !== undefined) payload[name] = value;
        } catch (error) {
          throw new Error(`${name}: ${error.message}`);
        }
      }
      return payload;
    };

    const saveCapture = async (slug, slotTs, container) => {
      if (!state.captureEditor[slug]?.capture) {
        flash("Bitte zuerst einen Capture laden", "warn");
        return;
      }
      let payload;
      try {
        payload = buildSavePayload(container);
      } catch (error) {
        flash(error.message, "error");
        return;
      }
      state.captureEditor[slug] = {
        ...state.captureEditor[slug],
        saving: true,
        error: null,
      };
      requestRender();
      try {
        const data = await api(`/api/admin/cameras/${encodeURIComponent(slug)}/captures/${slotTs}`, {
          method: "PUT",
          body: JSON.stringify(payload),
        });
        state.captureEditor[slug] = normalizeCaptureItem(data?.capture, data, {
          slot_mode: state.captureEditor[slug]?.slot_mode ?? null,
          query: state.captureEditor[slug]?.query ?? null,
        });
        flash("Capture gespeichert", "success");
      } catch (error) {
        state.captureEditor[slug] = {
          ...state.captureEditor[slug],
          saving: false,
          error: error.message,
        };
        flash(error.message, "error");
      }
      requestRender();
    };

    const handleLoadClick = (button) => {
      const form = button.closest(".capture-load-form");
      const slug = form?.dataset.slug || state.selectedSlug;
      if (!slug) {
        flash("Bitte Kamera auswählen", "warn");
        return;
      }
      if (button.dataset.action === "load-capture-latest") {
        loadLatest(slug);
        return;
      }
      const slotField = form?.querySelector('input[name="slot_ts"]');
      const slotRaw = slotField?.value?.toString().trim() ?? "";
      const slotValue = slotRaw === "" ? null : Number(slotRaw);
      if (slotField?.dataset.slotUser === "1" && Number.isFinite(slotValue) && slotValue > 0) {
        loadBySlot(slug, slotValue, { slot_mode: "user", query: null });
        return;
      }

      const readNumber = (name) => {
        const value = form?.querySelector(`[name="${name}"]`)?.value ?? "";
        return value === "" ? null : Number(value);
      };
      const year = readNumber("year");
      const month = readNumber("month");
      const day = readNumber("day");
      const time = form?.querySelector('[name="time"]')?.value ?? "";
      if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
        flash("Bitte Jahr, Monat und Tag angeben oder Slot-Timestamp nutzen", "warn");
        return;
      }
      loadByDate(slug, { year, month, day, time });
    };

    // Listener werden genau einmal auf dem stabilen Admin-Root registriert.
    // Die dynamischen Formulare können dadurch beliebig oft ersetzt werden,
    // ohne Handler zu verlieren oder mehrfach auszuführen.
    const bind = () => {
      if (handlersBound) return;
      handlersBound = true;
      root.addEventListener("input", (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.matches('.capture-load-form input[name="slot_ts"]')) {
          target.dataset.slotUser = target.value?.toString().trim() ? "1" : "0";
        }
      });
      root.addEventListener("change", (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.matches('.capture-load-form select[name="year"], .capture-load-form select[name="month"], .capture-load-form select[name="day"], .capture-load-form select[name="time"]')) return;
        const slotField = target.closest(".capture-load-form")?.querySelector('input[name="slot_ts"]');
        if (slotField) {
          slotField.value = "";
          slotField.dataset.slotUser = "0";
        }
      });
      root.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const action = target.closest("[data-action]");
        if (!(action instanceof HTMLElement)) return;
        if (action.dataset.action === "toggle-pulldown") {
          const panel = action.closest("[data-pulldown]")?.querySelector(".pulldown-panel");
          if (panel) panel.toggleAttribute("hidden");
          return;
        }
        if (["load-capture", "load-capture-latest"].includes(action.dataset.action)) {
          handleLoadClick(action);
          return;
        }
        if (action.dataset.action !== "save-capture") return;
        const slug = action.dataset.slug || state.selectedSlug;
        const slot = Number(action.dataset.slot || 0);
        const container = action.closest("[data-capture-editor]");
        if (!slug || !Number.isFinite(slot) || slot <= 0) {
          flash("Slot ungültig", "warn");
          return;
        }
        if (!container) {
          flash("Editor nicht gefunden", "error");
          return;
        }
        saveCapture(slug, slot, container);
      });
    };

    return { renderSection, bind };
  };

  global.WebcamAdminCaptureEditor = Object.freeze({ create });
})(window);
