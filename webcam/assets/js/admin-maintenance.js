// Wartungsscript- und Scheduler-Arbeitsfläche der Admin-Oberfläche.
//
// Der interne Scheduler wird serverseitig durch regelmäßige Kamera-Polls
// angestoßen. Dieses Browsermodul führt daher selbst keine Scripte aus. Es
// erklärt und kapselt ausschließlich das Laden, Bearbeiten, Validieren und
// Speichern der dazugehörigen Konfiguration sowie die Anzeige letzter Läufe.
(function registerAdminMaintenanceController(global) {
  "use strict";

  const BUILTIN_TASKS = new Set([
    "archive_rollover",
    "backup_daily",
    "weather_quality",
  ]);

  // Diese Werte sind nur die sichtbare Ausgangskonfiguration, falls eine alte
  // Installation den neuen Konfigurationsblock noch nicht besitzt. Gespeichert
  // wird erst nach einem bewussten Klick; es gibt keinen stillen API-Schreibzug.
  const DEFAULT_SETTINGS = Object.freeze({
    archive_rollover: Object.freeze({
      enabled: false,
      time: "01:00",
      label: "Archiv-Rollover",
      description: "Prüft und archiviert den abgeschlossenen Vortag auf dem autorisierten Archivschreiber.",
      path: "bin/archive_rollover.php",
      arguments: Object.freeze([]),
      timeout_seconds: 7200,
    }),
    backup_daily: Object.freeze({
      enabled: true,
      time: "03:20",
      label: "Datenbank-Backup",
      description: "Sichert Config- und Kamera-Datenbanken konsistent.",
      path: "bin/backup_daily.php",
      arguments: Object.freeze(["--force", "--verbose"]),
      timeout_seconds: 1800,
    }),
    weather_quality: Object.freeze({
      enabled: true,
      time: "03:30",
      label: "Wetterdaten Vortag",
      description: "Markiert nach erfolgreichem Tagesbackup ungültige Wetterwerte des Vortags.",
      path: "bin/weather_quality_previous_day.sh",
      arguments: Object.freeze([]),
      timeout_seconds: 600,
    }),
  });

  const cloneDefaultSettings = () => Object.fromEntries(
    Object.entries(DEFAULT_SETTINGS).map(([task, settings]) => [
      task,
      { ...settings, arguments: [...settings.arguments] },
    ]),
  );

  /**
   * Erstellt die Komponente mit den bereits vorhandenen Admin-Hilfsfunktionen.
   * API, Meldungen, Formatierung und Browserzustand bleiben damit zentral in
   * admin.js; dieses Modul baut keine konkurrierende Infrastruktur auf.
   */
  const create = ({
    state,
    root,
    api,
    flash,
    requestRender,
    escapeHtml,
    formatDateTime,
    requestAnimationFrame = (callback) => global.requestAnimationFrame(callback),
  }) => {
    if (!state || !root || typeof api !== "function" || typeof requestRender !== "function") {
      throw new Error("Admin maintenance controller dependencies are incomplete");
    }
    const expandedTaskLogs = new Set();

    // Führt Config-Antwort und separaten Laufstatus kontrolliert in den einen
    // gemeinsamen Browserzustand zusammen. Die speziellere Script-Antwort darf
    // ihre normalisierten Settings übernehmen; fehlt sie, bleibt die Config-
    // Antwort beziehungsweise eine frische Kopie der Defaults erhalten.
    const hydrate = (scriptsResponse = null) => {
      if (!state.config) return;
      if (!state.config.maintenance_scripts) {
        state.config.maintenance_scripts = cloneDefaultSettings();
      }
      if (!scriptsResponse || typeof scriptsResponse !== "object") return;

      state.scripts = scriptsResponse;
      if (scriptsResponse.settings && typeof scriptsResponse.settings === "object") {
        state.config.maintenance_scripts = scriptsResponse.settings;
      }
    };

    const renderTaskStatus = (task) => {
      const status = state.scripts.status?.[task];
      if (!status) return '<span class="muted">Noch kein Lauf protokolliert.</span>';

      const badgeClass = status.status === "ok"
        ? "badge-ok"
        : status.status === "error" ? "badge-error" : "badge-warn";
      const finished = status.finished_at ? formatDateTime(status.finished_at) : "läuft";
      const exitCode = status.exit_code === null || status.exit_code === undefined
        ? ""
        : ` · Exit ${escapeHtml(String(status.exit_code))}`;
      const isError = status.status === "error";
      const expanded = isError && expandedTaskLogs.has(task);
      const badge = isError
        ? `<button class="badge ${badgeClass} script-status__error-toggle" type="button"
            data-script-error-toggle="${escapeHtml(task)}" aria-expanded="${expanded ? "true" : "false"}"
            aria-controls="script-log-${escapeHtml(task)}" title="Fehlerdetails dieses Scripts anzeigen">error</button>`
        : `<span class="badge ${badgeClass}">${escapeHtml(status.status || "unbekannt")}</span>`;
      const entries = Array.isArray(status.log_entries)
        ? status.log_entries.filter((entry) => typeof entry === "string").slice(-5)
        : [];
      const details = expanded
        ? `<div class="script-status__log" id="script-log-${escapeHtml(task)}">
            <strong>Letzte Logeinträge dieses Scripts</strong>
            ${entries.length > 0
              ? `<ol>${entries.map((entry) => `<li><code>${escapeHtml(entry)}</code></li>`).join("")}</ol>`
              : '<p class="muted">Für diesen Scriptlauf sind keine Logeinträge vorhanden.</p>'}
          </div>`
        : "";
      return `${badge}
        <span class="muted">Letzter Lauf: ${finished}${exitCode}</span>
        ${details}`;
    };

    // Eine Scriptkarte enthält nur editierbare Konfiguration und den letzten
    // bekannten Laufstatus. Eingebaute Aufgaben können deaktiviert, aber nicht
    // aus der Liste entfernt werden, weil serverseitige Abhängigkeiten auf
    // ihren stabilen Schlüsseln beruhen.
    const renderTaskCard = ([task, script]) => {
      const definition = state.scripts.definitions?.[task] || {};
      const builtin = definition.builtin === true || BUILTIN_TASKS.has(task);
      const label = script.label || definition.label || task;
      const description = script.description ?? definition.description ?? "";
      const argumentsText = Array.isArray(script.arguments) ? script.arguments.join("\n") : "";
      return `
        <article class="script-card" data-script-id="${escapeHtml(task)}">
          <div class="script-card__header">
            <div>
              <h3>${escapeHtml(label)}</h3>
              <p><code>${escapeHtml(task)}</code>${builtin ? " · eingebaut" : " · zusätzlich"}</p>
            </div>
            <div class="script-card__header-actions">
              <label class="script-toggle">
                <input type="checkbox" name="${escapeHtml(task)}_enabled" data-script-field="enabled" ${script.enabled ? "checked" : ""}>
                <span>Aktiv</span>
              </label>
              ${builtin ? "" : `<button class="btn secondary danger" type="button" data-script-remove="${escapeHtml(task)}">Entfernen</button>`}
            </div>
          </div>
          <div class="script-card__controls">
            <label class="script-card__field">
              <span>Name</span>
              <input name="${escapeHtml(task)}_label" data-script-field="label" type="text" maxlength="80" required value="${escapeHtml(label)}">
            </label>
            <label class="script-card__field">
              <span>Startzeit</span>
              <input name="${escapeHtml(task)}_time" data-script-field="time" type="time" required value="${escapeHtml(script.time || "04:00")}">
            </label>
            <label class="script-card__field">
              <span>Timeout (Sekunden)</span>
              <input name="${escapeHtml(task)}_timeout_seconds" data-script-field="timeout_seconds" type="number" min="10" max="7200" step="1" required value="${Number(script.timeout_seconds || 300)}">
            </label>
            <label class="script-card__field script-card__field--full">
              <span>Ausführungspfad</span>
              <input name="${escapeHtml(task)}_path" data-script-field="path" type="text" maxlength="255" required value="${escapeHtml(script.path || "bin/")}" placeholder="bin/mein_script.php">
              <small class="form-hint">Relativ zur jeweiligen Installation und ausschließlich unter <code>bin/</code>.</small>
            </label>
            <label class="script-card__field script-card__field--full">
              <span>Argumente – eines pro Zeile</span>
              <textarea name="${escapeHtml(task)}_arguments" data-script-field="arguments" rows="3" spellcheck="false">${escapeHtml(argumentsText)}</textarea>
            </label>
            <label class="script-card__field script-card__field--full">
              <span>Beschreibung</span>
              <textarea name="${escapeHtml(task)}_description" data-script-field="description" rows="2" maxlength="300">${escapeHtml(description)}</textarea>
            </label>
          </div>
          <div class="script-card__status">${renderTaskStatus(task)}</div>
        </article>`;
    };

    const renderSection = () => {
      const settings = state.config?.maintenance_scripts || {};
      const lastTick = state.scripts.trigger?.last_tick_at
        ? formatDateTime(state.scripts.trigger.last_tick_at)
        : "noch kein Tick protokolliert";
      const cards = Object.entries(settings).map(renderTaskCard).join("");

      return `
        <section class="card" data-tab-panel="scripts">
          <div class="card-header">
            <div>
              <h2>Script-Arbeitsfläche</h2>
              <p class="card-subtitle">Tägliche Wartung hinzufügen, konfigurieren und zeitlich steuern</p>
            </div>
            <button class="btn secondary" id="btn-add-maintenance-script" type="button">Script hinzufügen</button>
          </div>
          <div class="alert alert-success">Interner Zeitgeber über die regelmäßigen Kamera-Polls aktiv. Fällige Scripte starten einmal täglich beim ersten Poll ab ihrer Startzeit. Letzte Prüfung: ${escapeHtml(lastTick)}.</div>
          <div class="alert alert-warning">Ausführung ohne Shell-Auswertung: Pfade müssen relativ unter <code>bin/</code> liegen, bereits existieren und zur jeweiligen Installation gehören; Argumente werden einzeln übergeben.</div>
          <form id="maintenance-scripts-form">
            <div class="script-workspace">${cards}</div>
            <div class="script-workspace__actions">
              <button class="btn" type="submit" ${state.savingScripts ? "disabled" : ""}>Scripte speichern</button>
              ${state.savingScripts ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
        </section>`;
    };

    // Formulardaten werden ausschließlich aus den zugehörigen Scriptkarten
    // gelesen. Neben den HTML-Grenzen prüft dieser Schritt Pfadsegmente,
    // Argumentanzahl und fachliche Reihenfolge von Backup und Wettercheck.
    // Das Backend validiert unabhängig davon erneut und bleibt maßgeblich.
    const buildPayload = (form) => {
      const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
      const pathPattern = /^bin\/[A-Za-z0-9._/-]+$/;
      // Ein Objekt ohne Prototyp verhindert, dass ein unerwarteter Taskname
      // wie "__proto__" beim clientseitigen Zusammenstellen Eigenschaften des
      // Payload-Objekts verändert. Die verbindliche Schlüsselprüfung bleibt
      // zusätzlich im Backend bestehen.
      const payload = Object.create(null);
      const cards = Array.from(form.querySelectorAll("[data-script-id]"));

      for (const card of cards) {
        const task = String(card.dataset.scriptId || "");
        const field = (name) => card.querySelector(`[data-script-field="${name}"]`);
        const label = String(field("label")?.value || "").trim();
        const description = String(field("description")?.value || "").trim();
        const time = String(field("time")?.value || "");
        const path = String(field("path")?.value || "").trim();
        const timeoutSeconds = Number(field("timeout_seconds")?.value ?? 0);
        const args = String(field("arguments")?.value || "")
          .split(/\r?\n/)
          .map((argument) => argument.trim())
          .filter((argument) => argument !== "");

        if (!task || !label || label.length > 80) {
          throw new Error("Jedes Script benötigt einen Namen mit höchstens 80 Zeichen.");
        }
        if (!timePattern.test(time)) {
          throw new Error(`Bitte für „${label}“ eine gültige Uhrzeit angeben.`);
        }
        const pathSegments = path.split("/");
        if (!pathPattern.test(path) || pathSegments.some((segment) => !segment || segment === "." || segment === "..")) {
          throw new Error(`Der Ausführungspfad für „${label}“ muss relativ unter bin/ liegen.`);
        }
        if (!Number.isInteger(timeoutSeconds) || timeoutSeconds < 10 || timeoutSeconds > 7200) {
          throw new Error(`Der Timeout für „${label}“ muss zwischen 10 und 7200 Sekunden liegen.`);
        }
        if (args.length > 32 || args.some((argument) => argument.length > 255)) {
          throw new Error(`„${label}“ hat zu viele oder zu lange Argumente.`);
        }

        payload[task] = {
          enabled: !!field("enabled")?.checked,
          time,
          label,
          description,
          path,
          arguments: args,
          timeout_seconds: timeoutSeconds,
        };
      }

      if (!Array.from(BUILTIN_TASKS).every((task) => payload[task])) {
        throw new Error("Die eingebauten Archiv-, Backup- und Wetterscripte dürfen nicht entfernt werden.");
      }
      if (payload.weather_quality.enabled && !payload.backup_daily.enabled) {
        throw new Error("Der Wettercheck benötigt ein aktiviertes Datenbank-Backup.");
      }
      if (payload.weather_quality.enabled && payload.weather_quality.time <= payload.backup_daily.time) {
        throw new Error("Der Wettercheck muss zeitlich nach dem Datenbank-Backup liegen.");
      }
      return payload;
    };

    const save = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;

      let payload;
      try {
        payload = buildPayload(form);
      } catch (error) {
        flash(error.message, "warn");
        return;
      }

      state.savingScripts = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/maintenance-scripts", {
          method: "PUT",
          body: JSON.stringify(payload),
        });
        state.config.maintenance_scripts = saved;
        state.scripts.settings = saved;
        flash("Scripte gespeichert", "ok");
      } catch (error) {
        flash(error.message, "error");
      } finally {
        state.savingScripts = false;
        requestRender();
      }
    };

    const add = () => {
      const scripts = state.config.maintenance_scripts || {};
      const suffix = Date.now().toString(36);
      let task = `script_${suffix}`;
      let counter = 1;
      while (scripts[task]) {
        task = `script_${suffix}_${counter}`;
        counter += 1;
      }

      scripts[task] = {
        enabled: false,
        time: "04:00",
        label: "Neues Script",
        description: "",
        path: "bin/",
        arguments: [],
        timeout_seconds: 300,
      };
      state.config.maintenance_scripts = scripts;
      state.scripts.definitions = state.scripts.definitions || {};
      state.scripts.definitions[task] = { label: "Neues Script", description: "", builtin: false };
      requestRender();

      // Das erneute Rendern ersetzt die Karte. Der Fokus wird deshalb erst im
      // nächsten Browserframe auf das dann vorhandene Namensfeld gesetzt.
      requestAnimationFrame(() => {
        root.querySelector(`[data-script-id="${task}"] [data-script-field="label"]`)?.focus();
      });
    };

    const remove = (task) => {
      if (!task || BUILTIN_TASKS.has(task)) return;
      delete state.config.maintenance_scripts?.[task];
      delete state.scripts.definitions?.[task];
      flash("Script entfernt. Zum Übernehmen bitte speichern.", "warn");
    };

    // render() ersetzt den sichtbaren Tab vollständig. Deshalb bindet die
    // Komponente ihre wenigen Handler nach jedem Rendern an die frischen
    // Elemente; alte Elemente und ihre Listener werden gemeinsam verworfen.
    const bind = () => {
      root.querySelector("#maintenance-scripts-form")?.addEventListener("submit", save);
      root.querySelector("#btn-add-maintenance-script")?.addEventListener("click", add);
      root.querySelectorAll("[data-script-remove]").forEach((button) => {
        button.addEventListener("click", () => remove(button.dataset.scriptRemove || ""));
      });
      root.querySelectorAll("[data-script-error-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
          const task = button.dataset.scriptErrorToggle || "";
          if (!task) return;
          if (expandedTaskLogs.has(task)) {
            expandedTaskLogs.delete(task);
          } else {
            expandedTaskLogs.add(task);
          }
          requestRender();
        });
      });
    };

    return { hydrate, renderSection, bind };
  };

  global.WebcamAdminMaintenance = Object.freeze({ create });
})(window);
