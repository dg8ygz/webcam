// Formularvalidierung des Admin-Panels.
//
// Leseführung:
// 1. Kleine Validatoren beschreiben die zusätzlichen Regeln für Slug, Text und Pfad.
// 2. Feldfehler werden direkt am zugehörigen Formularblock sichtbar gemacht.
// 3. Eingabe und Fokusverlust prüfen nur das gerade bearbeitete Feld.
// 4. Gesamt- und Abschnittsspeichern prüfen alle betroffenen Felder erzwungen.
// 5. Der erste Fehler öffnet automatisch seinen Untertab und erhält den Fokus.
// 6. RTSP-Zusatzfelder folgen sichtbar und technisch dem gewählten Transport.
// 7. `bind()` und `init()` schützen vor mehrfach registrierten Listenern.
//
// Diese frühe Browserprüfung soll verständliche Rückmeldungen geben. Sie ist
// keine Sicherheitsgrenze: Die Admin-API validiert jeden gespeicherten Wert
// nochmals unabhängig und verbindlich.
(function registerAdminFormValidation(global) {
  "use strict";

  // -------------------------------------------------------------------------
  // Zusätzliche fachliche Feldregeln.
  // HTML-Attribute wie required, min oder max bleiben am jeweiligen Feld. Die
  // folgenden Regeln liefern dafür projektspezifische deutsche Fehlermeldungen
  // und schützen insbesondere Slugs und relative Speicherpfade frühzeitig.
  // -------------------------------------------------------------------------
  const FIELD_VALIDATORS = Object.freeze({
    slug(value) {
      if (!value) {
        return { valid: false, message: "Slug darf nicht leer sein." };
      }
      return /^[a-z0-9-]+$/.test(value)
        ? { valid: true }
        : { valid: false, message: "Nur Kleinbuchstaben, Zahlen und Bindestriche sind erlaubt." };
    },

    text(value, field) {
      if (!value) {
        return field.required
          ? { valid: false, message: "Pflichtfeld darf nicht leer sein." }
          : { valid: true };
      }
      const pattern = /^[\p{L}\p{N}\s.,;:_\-+()&\/]*$/u;
      return pattern.test(value)
        ? { valid: true }
        : { valid: false, message: "Unzulässige Sonderzeichen." };
    },

    path(value, field) {
      if (!value) {
        return field.required
          ? { valid: false, message: "Pfad darf nicht leer sein." }
          : { valid: true };
      }
      if (value.includes("..")) {
        return { valid: false, message: "Pfad darf keine '..'-Segmente enthalten." };
      }
      if (value.includes("\\") || value.includes("//")) {
        return { valid: false, message: "Nur relative Pfade mit '/' verwenden." };
      }
      if (/^[a-z]+:/i.test(value)) {
        return { valid: false, message: "Nur relative Pfade sind erlaubt." };
      }
      return { valid: true };
    },
  });

  /**
   * Die stabile Admin-Wurzel wird injiziert. Die Komponente besitzt keinen
   * Configzustand, kennt keine API und kann daher selbst nichts speichern.
   */
  const create = ({ root }) => {
    if (!root || typeof root.addEventListener !== "function") {
      throw new Error("Admin form validation dependencies are incomplete");
    }

    let delegatedHandlersBound = false;

    // -----------------------------------------------------------------------
    // Sichtbaren Fehlerzustand eines einzelnen Feldes verwalten.
    // `textContent` verhindert, dass eine Fehlermeldung als HTML interpretiert
    // wird. Bestehende Hinweise werden wiederverwendet statt vervielfacht.
    // -----------------------------------------------------------------------
    const clearFieldError = (field) => {
      const wrapper = field.closest(".form-field");
      if (!wrapper) return;
      wrapper.classList.remove("has-error");
      wrapper.querySelector(".form-error")?.remove();
    };

    const showFieldError = (field, message) => {
      const wrapper = field.closest(".form-field");
      if (!wrapper) return;
      wrapper.classList.add("has-error");
      let hint = wrapper.querySelector(".form-error");
      if (!hint) {
        hint = field.ownerDocument.createElement("div");
        hint.className = "form-error";
        wrapper.appendChild(hint);
      }
      hint.textContent = message;
    };

    const validateField = (field, { force = false } = {}) => {
      const validatorName = field.dataset.validate;
      const validator = validatorName ? FIELD_VALIDATORS[validatorName] : null;
      if (typeof validator !== "function") return true;

      const value = String(field.value ?? "").trim();
      const result = validator(value, field);
      if (result.valid) {
        clearFieldError(field);
        return true;
      }
      if (force || field.dataset.dirty === "1") {
        showFieldError(field, result.message);
      }
      return false;
    };

    // -----------------------------------------------------------------------
    // Fehler in einem momentan unsichtbaren Kamera-Untertab zugänglich machen.
    // Der vorhandene Tab-Handler bleibt die einzige Instanz, die sichtbare
    // Panels und den pro Kamera gemerkten Tabzustand tatsächlich umschaltet.
    // -----------------------------------------------------------------------
    const activateTabForField = (field) => {
      const panel = field.closest("[data-tab-panel]");
      if (!panel || panel.classList.contains("is-active")) return;
      const targetId = panel.dataset.tabPanel;
      if (!targetId) return;
      const container = panel.closest("[data-tabs]");
      const button = container?.querySelector(`[data-tab-target="${targetId}"]`);
      if (!button) return;

      const EventConstructor = field.ownerDocument.defaultView?.Event ?? global.Event;
      button.dispatchEvent(new EventConstructor("click", { bubbles: true }));
    };

    // Gesamtformular und einzelne Sektion nutzen denselben Prüfalgorithmus.
    // Nur das erste ungültige Feld wechselt den Tab und erhält den Fokus;
    // dennoch werden alle Fehler markiert, damit sie gesammelt sichtbar sind.
    const validateContainer = (container) => {
      if (!container) return true;
      let isValid = true;
      container.querySelectorAll("[data-validate]").forEach((field) => {
        field.dataset.dirty = "1";
        const fieldIsValid = validateField(field, { force: true });
        if (!fieldIsValid) {
          if (isValid) {
            activateTabForField(field);
            field.focus();
          }
          isValid = false;
        }
      });
      return isValid;
    };

    // -----------------------------------------------------------------------
    // Abhängige RTSP-Felder synchronisieren.
    // Verstecken allein wäre unzureichend: Deaktivierte Inputs werden auch
    // nicht in FormData aufgenommen und können damit keinen HTTP-Capture-
    // Payload unbeabsichtigt beeinflussen.
    // -----------------------------------------------------------------------
    const syncRtsp = (form) => {
      if (!form) return;
      const transport = form.querySelector('select[name="transport"]')?.value ?? "";
      const hasRtspInput = transport === "rtsp" || form.dataset.streamMode === "rtsp";
      form.querySelectorAll("[data-rtsp-stream-setting]").forEach((field) => {
        field.hidden = !hasRtspInput;
      });
      form.querySelectorAll("[data-rtsp-stream-field]").forEach((field) => {
        field.disabled = !hasRtspInput;
      });
    };

    // Direkte Feldlistener gehören zur jeweils neu gerenderten Formularinstanz.
    // Das Data-Merkmal macht wiederholte init()-Aufrufe auf demselben DOM-Knoten
    // idempotent, ohne neue Formulare nach einem Render zu überspringen.
    const init = (form) => {
      if (!form) return;
      form.querySelectorAll("[data-validate]").forEach((field) => {
        if (field.dataset.validationInit === "1") return;
        field.dataset.validationInit = "1";
        field.addEventListener("input", () => {
          field.dataset.dirty = "1";
          validateField(field);
        });
        field.addEventListener("blur", () => {
          field.dataset.dirty = "1";
          validateField(field, { force: true });
        });
      });
      syncRtsp(form);
    };

    // Der Transportwechsel wird an der stabilen Admin-Wurzel delegiert. Damit
    // bleibt er nach vollständigen Neurenders aktiv und wird nur einmal für die
    // gesamte Seitenlaufzeit registriert.
    const bind = () => {
      if (delegatedHandlersBound) return;
      delegatedHandlersBound = true;
      root.addEventListener("change", (event) => {
        const target = event.target;
        if (!target || typeof target.matches !== "function") return;
        if (!target.matches('.camera-form select[name="transport"]')) return;
        syncRtsp(target.closest(".camera-form"));
      });
    };

    return Object.freeze({
      bind,
      init,
      syncRtsp,
      validateForm: validateContainer,
      validateSection: validateContainer,
    });
  };

  global.WebcamAdminFormValidation = Object.freeze({ create });
})(window);
