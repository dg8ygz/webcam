// Label-Editor des Admin-Panels.
//
// Leseführung:
// 1. Feste Auswahlwerte beschreiben Farben, Schriftkontrast und Positionstypen.
// 2. Gespeicherte Labeldaten werden als vollständig maskierte Tabellenzeilen gerendert.
// 3. Der Collector liest die Tabelle und baut daraus den kleinen API-Payload.
// 4. Link, Farbe und Pflichtfelder werden bereits im Browser verständlich geprüft.
// 5. Hinzufügen, Entfernen und benutzerdefinierte Farben bleiben lokale DOM-Aktionen.
// 6. `init()` bindet Listener nach jedem Admin-Neurender genau einmal.
//
// Die Browserprüfung dient der direkten Rückmeldung im Formular. Sie ersetzt
// ausdrücklich nicht die verbindliche Validierung in admin/api.php. Das Modul
// kennt weder API noch Kamerazustand und kann daher keine Daten selbst speichern.
(function registerAdminLabelEditor(global) {
  "use strict";

  // -------------------------------------------------------------------------
  // Fachlich erlaubte Auswahlwerte.
  // Diese Listen spiegeln die serverseitigen Regeln wider. Objekt und Arrays
  // sind eingefroren, damit ein anderes Admin-Skript sie nicht versehentlich
  // während der Laufzeit verändert.
  // -------------------------------------------------------------------------
  const CUSTOM_COLOR_VALUE = "custom";
  const COLOR_PRESETS = Object.freeze([
    Object.freeze({ value: "blue", label: "Blau" }),
    Object.freeze({ value: "teal", label: "Türkis" }),
    Object.freeze({ value: "green", label: "Grün" }),
    Object.freeze({ value: "yellow", label: "Gelb" }),
    Object.freeze({ value: "orange", label: "Orange" }),
    Object.freeze({ value: "red", label: "Rot" }),
    Object.freeze({ value: "purple", label: "Violett" }),
    Object.freeze({ value: "gray", label: "Grau" }),
  ]);
  const COLOR_PRESET_VALUES = new Set(COLOR_PRESETS.map((option) => option.value));
  const COLOR_OPTIONS = Object.freeze([
    Object.freeze({ value: "", label: "Standard" }),
    ...COLOR_PRESETS,
    Object.freeze({ value: CUSTOM_COLOR_VALUE, label: "Benutzerdefiniert…" }),
  ]);
  const TEXT_TONE_OPTIONS = Object.freeze([
    Object.freeze({ value: "auto", label: "Automatisch" }),
    Object.freeze({ value: "light", label: "Hell" }),
    Object.freeze({ value: "dark", label: "Dunkel" }),
  ]);
  const TEXT_TONE_VALUES = new Set(TEXT_TONE_OPTIONS.map((option) => option.value));
  const TYPE_OPTIONS = Object.freeze([
    Object.freeze({ value: 0, label: "Unten links (0)" }),
    Object.freeze({ value: 1, label: "Unten rechts (1)" }),
    Object.freeze({ value: 2, label: "Oben links (2)" }),
    Object.freeze({ value: 3, label: "Oben rechts (3)" }),
    Object.freeze({ value: 4, label: "Zentriert (4)" }),
  ]);
  const CUSTOM_COLOR_PATTERN = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i;

  /**
   * DOM-Wurzel, HTML-Maskierung und gemeinsamer Zahlenparser werden injiziert.
   * Dadurch bleibt die Komponente testbar und eröffnet keinen zweiten globalen
   * Zustand neben dem zentralen Admin-Skript.
   */
  const create = ({ root, escapeHtml, toNumber }) => {
    if (!root || typeof escapeHtml !== "function" || typeof toNumber !== "function") {
      throw new Error("Admin label editor dependencies are incomplete");
    }

    const safe = (value) => escapeHtml(String(value ?? ""));

    // -----------------------------------------------------------------------
    // Linkprüfung für die unmittelbare Formularkontrolle.
    // Dokumentrelative Links bleiben erlaubt. Bei absoluten URLs akzeptiert
    // der Admin ausschließlich HTTP(S); insbesondere javascript: und data:
    // dürfen nicht in die öffentliche Kameraansicht gelangen.
    // -----------------------------------------------------------------------
    const normalizeHref = (rawValue, rowIndex) => {
      const raw = String(rawValue ?? "").trim();
      if (!raw) return "";

      const lower = raw.toLowerCase();
      if (lower.startsWith("javascript:") || lower.startsWith("data:")) {
        throw new Error(
          `Label ${rowIndex + 1}: Link darf nicht mit "javascript:" oder "data:" beginnen.`,
        );
      }
      if (
        /^[a-z][a-z0-9+.-]*:/.test(lower)
        && !lower.startsWith("http://")
        && !lower.startsWith("https://")
      ) {
        throw new Error(`Label ${rowIndex + 1}: Nur http/https oder relative Links sind erlaubt.`);
      }
      return raw;
    };

    // -----------------------------------------------------------------------
    // Tabellenrenderer.
    // Auch Zahlen und IDs werden maskiert, weil alte oder manuell importierte
    // Konfigurationen keine vertrauenswürdige HTML-Quelle sind. Die sichtbaren
    // Auswahloptionen stammen dagegen ausschließlich aus obigen Konstanten.
    // -----------------------------------------------------------------------
    const renderEmptyRow = () => `
      <tr data-labels-empty>
        <td colspan="11" class="labels-empty">Keine Labels angelegt.</td>
      </tr>
    `;

    const renderOptions = (options, selectedValue) => options
      .map((option) => {
        const selected = String(option.value) === String(selectedValue) ? "selected" : "";
        return `<option value="${safe(option.value)}" ${selected}>${safe(option.label)}</option>`;
      })
      .join("");

    const renderRow = (label = {}) => {
      const x = label?.x ?? "";
      const y = label?.y ?? "";
      const text = label?.text ?? label?.txt ?? "";
      const href = label?.href ?? "";

      // Gespeicherte Hexfarben werden im separaten Freitextfeld dargestellt;
      // bekannte Farbnamen bleiben komfortabel im Pulldown ausgewählt.
      const rawColor = typeof label?.color === "string" ? label.color.trim() : "";
      const colorLower = rawColor.toLowerCase();
      const isPresetColor = COLOR_PRESET_VALUES.has(colorLower);
      const isCustomColor = !isPresetColor && CUSTOM_COLOR_PATTERN.test(colorLower);
      const colorValue = isPresetColor
        ? colorLower
        : (isCustomColor ? CUSTOM_COLOR_VALUE : "");
      const customColorValue = isCustomColor ? colorLower : "";
      const customHidden = colorValue === CUSTOM_COLOR_VALUE ? "" : "hidden";

      const rawTextTone = typeof label?.text_tone === "string"
        ? label.text_tone.trim().toLowerCase()
        : (typeof label?.textTone === "string" ? label.textTone.trim().toLowerCase() : "");
      const textToneValue = TEXT_TONE_VALUES.has(rawTextTone) ? rawTextTone : "auto";
      const typeValue = Number.isFinite(label?.type) ? label.type : (label?.type ?? 0);
      const resolutionValue = Number.isFinite(label?.resolution)
        ? label.resolution
        : (label?.resolution ?? 0);
      const validFrom = label?.valid_from ?? "";
      const validTo = label?.valid_to ?? "";
      const idValue = Number.isFinite(label?.id) ? label.id : (label?.id ?? "");

      return `
        <tr data-label-row>
          <td>
            <input type="number" step="0.1" min="0" max="100" data-label-field="x" value="${safe(x)}">
          </td>
          <td>
            <input type="number" step="0.1" min="0" max="100" data-label-field="y" value="${safe(y)}">
          </td>
          <td>
            <input type="text" data-label-field="text" value="${safe(text)}" placeholder="Beschriftung">
          </td>
          <td>
            <input type="text" data-label-field="href" value="${safe(href)}" placeholder="/webcam/... oder https://">
          </td>
          <td>
            <select data-label-field="color">${renderOptions(COLOR_OPTIONS, colorValue)}</select>
            <input class="label-color-custom" type="text" data-label-field="color_custom" value="${safe(customColorValue)}" placeholder="#RRGGBB" ${customHidden}>
          </td>
          <td>
            <select data-label-field="text_tone">${renderOptions(TEXT_TONE_OPTIONS, textToneValue)}</select>
          </td>
          <td>
            <select data-label-field="type">${renderOptions(TYPE_OPTIONS, typeValue)}</select>
          </td>
          <td>
            <input type="number" min="0" step="1" data-label-field="resolution" value="${safe(resolutionValue)}" placeholder="0">
          </td>
          <td>
            <input type="text" data-label-field="valid_from" value="${safe(validFrom)}" placeholder="2026-01-01 oder 2026-01-01 12:00">
          </td>
          <td>
            <input type="text" data-label-field="valid_to" value="${safe(validTo)}" placeholder="2026-12-31 oder 2026-12-31 23:59">
          </td>
          <td class="labels-row-actions">
            <button class="btn ghost" type="button" data-label-remove>Entfernen</button>
            <input type="hidden" data-label-field="id" value="${safe(idValue)}">
          </td>
        </tr>
      `;
    };

    const renderRows = (labels) => {
      const rows = Array.isArray(labels) ? labels : [];
      return rows.length ? rows.map((label) => renderRow(label)).join("") : renderEmptyRow();
    };

    // -----------------------------------------------------------------------
    // Tabelle -> API-Payload.
    // Leere Zeilen werden ignoriert, soweit sie tatsächlich keinerlei Wert
    // enthalten. Bei bearbeiteten Zeilen führen fehlende Pflichtangaben zu
    // einer verständlichen Fehlermeldung mit sichtbarer Zeilennummer.
    // -----------------------------------------------------------------------
    const collect = (form) => {
      if (!form || typeof form.querySelectorAll !== "function") {
        throw new Error("Admin label editor form is missing");
      }

      const rows = Array.from(form.querySelectorAll("[data-label-row]"));
      const labels = [];
      rows.forEach((row, index) => {
        const field = (name) => row.querySelector(`[data-label-field="${name}"]`)?.value ?? "";
        const rawX = String(field("x")).trim();
        const rawY = String(field("y")).trim();
        const rawText = String(field("text")).trim();
        const rawHref = String(field("href")).trim();
        const rawColor = String(field("color")).trim().toLowerCase();
        const rawCustomColor = String(field("color_custom")).trim();
        const rawTextTone = String(field("text_tone")).trim().toLowerCase();
        const rawType = String(field("type")).trim();
        const rawResolution = String(field("resolution")).trim();
        const rawValidFrom = String(field("valid_from")).trim();
        const rawValidTo = String(field("valid_to")).trim();
        const rawId = String(field("id")).trim();

        const hasAny = rawX
          || rawY
          || rawText
          || rawHref
          || rawColor
          || rawCustomColor
          || rawType
          || rawTextTone
          || rawResolution
          || rawValidFrom
          || rawValidTo;
        if (!hasAny) return;

        if (!rawText) {
          throw new Error(`Label ${index + 1}: Beschriftung fehlt.`);
        }
        if (!rawX || !rawY) {
          throw new Error(`Label ${index + 1}: X und Y sind Pflichtfelder.`);
        }
        const x = toNumber(rawX, NaN);
        const y = toNumber(rawY, NaN);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
          throw new Error(`Label ${index + 1}: X/Y müssen Zahlen sein.`);
        }

        const href = normalizeHref(rawHref, index);
        const type = Number.parseInt(rawType, 10);
        const resolvedType = TYPE_OPTIONS.some((option) => option.value === type) ? type : 0;
        const resolution = Number.parseInt(rawResolution, 10);
        const resolvedResolution = Number.isFinite(resolution) && resolution > 0 ? resolution : 0;

        // Eine eigene Farbe muss ein kurzer/langer Hexwert mit optionalem
        // Alphakanal sein. Unbekannte Farbnamen werden nicht still übernommen.
        let resolvedColor = "";
        if (rawColor === CUSTOM_COLOR_VALUE) {
          if (!rawCustomColor) {
            throw new Error(`Label ${index + 1}: Bitte einen Farbcode angeben.`);
          }
          if (!CUSTOM_COLOR_PATTERN.test(rawCustomColor)) {
            throw new Error(`Label ${index + 1}: Farbcode muss z.B. #RRGGBB sein.`);
          }
          resolvedColor = rawCustomColor.toLowerCase();
        } else if (rawColor) {
          resolvedColor = COLOR_PRESET_VALUES.has(rawColor) ? rawColor : "";
          if (!resolvedColor) {
            throw new Error(`Label ${index + 1}: Farbe ist nicht erlaubt.`);
          }
        }

        if (rawTextTone && !TEXT_TONE_VALUES.has(rawTextTone)) {
          throw new Error(`Label ${index + 1}: Schriftfarbe ist nicht erlaubt.`);
        }
        const resolvedTextTone = TEXT_TONE_VALUES.has(rawTextTone) ? rawTextTone : "auto";
        const entry = {
          x,
          y,
          text: rawText,
          href,
          type: resolvedType,
          resolution: resolvedResolution,
        };
        if (resolvedColor) entry.color = resolvedColor;
        if (resolvedTextTone !== "auto") entry.text_tone = resolvedTextTone;
        if (rawValidFrom) entry.valid_from = rawValidFrom;
        if (rawValidTo) entry.valid_to = rawValidTo;

        const idValue = Number.parseInt(rawId, 10);
        if (Number.isFinite(idValue)) entry.id = idValue;
        labels.push(entry);
      });
      return labels;
    };

    // -----------------------------------------------------------------------
    // Lokaler DOM-Lebenszyklus.
    // Der zentrale Admin ersetzt beim Rendern das Formular. Deshalb sucht
    // init() stets die aktuelle Instanz und markiert genau deren Listener.
    // Ereignisdelegation hält auch später hinzugefügte Zeilen bedienbar.
    // -----------------------------------------------------------------------
    const syncCustomColor = (row) => {
      if (!row) return;
      const select = row.querySelector('[data-label-field="color"]');
      const input = row.querySelector('[data-label-field="color_custom"]');
      if (!select || !input) return;

      const isCustom = select.value === CUSTOM_COLOR_VALUE;
      input.hidden = !isCustom;
      if (!isCustom) input.value = "";
    };

    const init = () => {
      const editor = root.querySelector("[data-labels-editor]");
      if (!editor) return;
      const tbody = editor.querySelector("[data-labels-body]");
      if (!tbody) return;

      const ensureEmptyState = () => {
        const hasRows = Boolean(tbody.querySelector("[data-label-row]"));
        const emptyRow = tbody.querySelector("[data-labels-empty]");
        if (hasRows && emptyRow) {
          emptyRow.remove();
        } else if (!hasRows && !emptyRow) {
          tbody.innerHTML = renderEmptyRow();
        }
      };

      const addRow = (label = {}) => {
        tbody.querySelector("[data-labels-empty]")?.remove();
        tbody.insertAdjacentHTML("beforeend", renderRow(label));
        syncCustomColor(tbody.lastElementChild);
      };

      const addButton = editor.querySelector("[data-label-add]");
      if (addButton && addButton.dataset.labelsInit !== "1") {
        addButton.dataset.labelsInit = "1";
        addButton.addEventListener("click", () => addRow({}));
      }

      if (editor.dataset.labelsInit !== "1") {
        editor.dataset.labelsInit = "1";
        editor.addEventListener("click", (event) => {
          const removeButton = event.target.closest?.("[data-label-remove]");
          if (!removeButton) return;
          event.preventDefault();
          removeButton.closest("[data-label-row]")?.remove();
          ensureEmptyState();
        });
        editor.addEventListener("change", (event) => {
          const colorSelect = event.target.closest?.('[data-label-field="color"]');
          if (!colorSelect) return;
          syncCustomColor(colorSelect.closest("[data-label-row]"));
        });
      }

      ensureEmptyState();
      tbody.querySelectorAll("[data-label-row]").forEach((row) => syncCustomColor(row));
    };

    return Object.freeze({ renderRows, collect, init });
  };

  global.WebcamAdminLabelEditor = Object.freeze({ create });
})(window);
