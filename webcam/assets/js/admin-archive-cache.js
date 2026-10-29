// Diagnose- und Rebuild-Arbeitsfläche für den optionalen Legacy-Archivcache.
//
// Der normale Archiv- und Timeline-Betrieb liest aus den Kamera-SQLite-DBs.
// Dieser Cache ist ausschließlich ein Werkzeug für bewusste Altanalysen. Das
// Browsermodul baut deshalb keinen Cache selbst auf: Es zeigt Serverzustand,
// übermittelt die ausdrücklich gewählte Aktion und verfolgt einen laufenden
// Rebuild in einem begrenzten Intervall.
(function registerAdminArchiveCacheController(global) {
  "use strict";

  const EMPTY_STATUS = Object.freeze({
    enabled: false,
    size_bytes: 0,
    last_built_at: null,
    rebuild_state: null,
    admin_allowed: false,
    scope: "all",
    root: null,
    error: null,
  });

  const normalizeStatus = (value = null, previous = EMPTY_STATUS) => ({
    ...EMPTY_STATUS,
    ...previous,
    ...(value && typeof value === "object" ? value : {}),
  });

  /**
   * Verbindet die fachliche Komponente mit dem zentralen Adminzustand.
   * Authentifizierung, CSRF, API-Fehlerformat und Haupt-Rendering bleiben in
   * admin.js. Dadurch gibt es weder einen zweiten Transportweg noch einen
   * versteckten lokalen Cache für Konfigurationsdaten.
   */
  const create = ({
    state,
    root,
    api,
    getCsrfToken,
    flash,
    requestRender,
    escapeHtml,
    formatDateTime,
    formatBytes,
    setIntervalFn = (callback, delay) => global.setInterval(callback, delay),
    clearIntervalFn = (handle) => global.clearInterval(handle),
    notifyConflict = (message) => global.alert(message),
  }) => {
    if (!state || !root || typeof api !== "function" || typeof requestRender !== "function") {
      throw new Error("Admin archive-cache controller dependencies are incomplete");
    }

    let pollHandle = null;

    // Die Config-API enthält nur den gespeicherten Ein-/Aus-Schalter. Größe,
    // Scope und Laufstatus kommen aus dem speziellen Diagnose-Endpunkt. Beim
    // Zusammenführen bleiben diese Laufzeitfelder erhalten.
    const hydrate = (cacheResponse = null, loadError = null) => {
      state.cacheStatus = normalizeStatus({
        ...(cacheResponse && typeof cacheResponse === "object" ? cacheResponse : {}),
        error: loadError?.message || (typeof loadError === "string" ? loadError : null),
      }, state.cacheStatus);
      if (!state.config?.archive_cache) return;

      state.cacheStatus = normalizeStatus({
        enabled: state.config.archive_cache.enabled ?? state.cacheStatus.enabled,
        last_built_at: state.config.archive_cache.last_built_at
          ?? state.cacheStatus.last_built_at,
      }, state.cacheStatus);
    };

    const summary = () => ({
      label: state.cacheStatus.enabled ? "Legacy-Cache aktiv" : "Legacy-Cache aus",
      badgeClass: state.cacheStatus.enabled ? "pill-ok" : "pill-warn",
    });

    // Statuswerte stammen teilweise aus einem extern gestarteten CLI-Lauf.
    // Sämtliche Texte werden deshalb maskiert, bevor sie in innerHTML landen.
    const renderSection = (cameraList = []) => {
      const status = normalizeStatus(state.cacheStatus, EMPTY_STATUS);
      const lastBuilt = status.last_built_at
        ? formatDateTime(status.last_built_at)
        : "–";
      const adminAllowed = status.admin_allowed === true;
      const current = status.rebuild_state?.current;
      const currentJob = current
        ? `${current.slug ?? ""} ${current.year ?? ""}`.trim() || "–"
        : "–";
      const cameraOptions = cameraList.map((camera) => {
        const slug = String(camera?.slug || "");
        const selected = state.cacheForm.slug === slug ? " selected" : "";
        return `<option value="${escapeHtml(slug)}"${selected}>${escapeHtml(slug)}</option>`;
      }).join("");
      const error = status.error
        ? `<p class="alert alert-error">Status konnte nicht geladen werden: ${escapeHtml(status.error)}</p>`
        : "";

      return `
        <section class="card" style="margin-top:1rem;">
          <details class="meta-details">
            <summary>Legacy Timeline-Cache (Diagnose)</summary>
            <div class="meta-details__body">
              <p class="muted">Optionaler Alt-Cache für bewusste Archivanalysen. Für den Normalbetrieb nicht erforderlich.</p>
              <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
                <button class="btn secondary" type="button" id="btn-refresh-cache-status">Cache-Status aktualisieren</button>
                <span class="muted">Lädt den aktuellen Status für den ausgewählten Slug.</span>
              </div>
              ${error}
              ${adminAllowed ? `
                <form id="archive-cache-settings-form" class="form-grid">
                  <div class="form-field form-field--full">
                    <label>
                      <input type="checkbox" name="enabled" ${status.enabled ? "checked" : ""}> Legacy-Cache aktiv halten
                    </label>
                    <p class="muted" style="margin:0.5rem 0 0;">Empfehlung: deaktiviert lassen und nur bei Bedarf vorübergehend aktivieren.</p>
                  </div>
                  <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
                    <button class="btn secondary" type="submit" ${state.savingArchiveCache ? "disabled" : ""}>Status speichern</button>
                    ${state.savingArchiveCache ? '<span class="muted">Speichert…</span>' : ""}
                  </div>
                </form>
                <form id="archive-cache-form" class="form-grid">
                  <div class="form-field">
                    <label for="ac-slug">Kamera</label>
                    <select id="ac-slug" name="slug">
                      <option value="">Alle</option>
                      ${cameraOptions}
                    </select>
                  </div>
                  <div class="form-field">
                    <label for="ac-year">Jahr (optional)</label>
                    <input id="ac-year" name="year" type="number" min="2000" max="2100" value="${escapeHtml(state.cacheForm.year)}">
                  </div>
                  <div class="form-field">
                    <label>
                      <input type="checkbox" name="dry_run" ${state.cacheForm.dry ? "checked" : ""}> Dry-Run
                    </label>
                  </div>
                  <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
                    <button class="btn" type="submit" ${state.cacheBusy || !status.enabled ? "disabled" : ""}>Rebuild starten</button>
                    ${state.cacheBusy ? '<span class="muted">Läuft…</span>' : ""}
                    ${!status.enabled ? '<span class="muted">Deaktiviert. Erst oben aktivieren.</span>' : ""}
                  </div>
                </form>
              ` : `
                <p class="muted">Legacy-Cache-Admin ist serverseitig deaktiviert. Status bleibt sichtbar, Rebuild und Umschalten sind hier gesperrt.</p>
              `}
              <div class="meta-details__body" style="margin-top:1rem;">
                <p>Legacy-Cache-Status (${escapeHtml(status.scope || "alle")}): <strong>${status.enabled ? "aktiv" : "deaktiviert"}</strong></p>
                <p>Letzter Build: <strong>${escapeHtml(lastBuilt)}</strong></p>
                <p>Größe: <strong>${escapeHtml(formatBytes(status.size_bytes ?? 0))}</strong></p>
                <p>Rebuild-Status: <strong>${escapeHtml(status.rebuild_state?.status || "–")}</strong></p>
                <p>Letzte Meldung: <strong>${escapeHtml(status.rebuild_state?.last_message || "–")}</strong></p>
                <p>Aktiver Job: <strong>${escapeHtml(currentJob)}</strong></p>
                <p>Progress: <strong>${escapeHtml(String(current?.progress ?? 0))}</strong> Einträge</p>
              </div>
            </div>
          </details>
        </section>`;
    };

    // Ein fehlgeschlagener Refresh verwirft den letzten bekannten Status nicht.
    // Der Fehler wird jedoch explizit im Diagnoseblock angezeigt; es gibt damit
    // keinen stillen Fallback auf möglicherweise veraltete Laufzeitdaten.
    const refresh = async (slug = null) => {
      try {
        const headers = new Headers();
        const csrf = getCsrfToken();
        if (csrf) headers.set("X-CSRF-Token", csrf);
        const url = new URL("/api/admin/archive-cache", global.location.origin);
        if (slug) url.searchParams.set("slug", slug);
        const result = await api(
          url.toString().replace(global.location.origin, ""),
          { headers },
        );
        state.cacheStatus = normalizeStatus({ ...result, error: null }, state.cacheStatus);
      } catch (error) {
        state.cacheStatus = {
          ...normalizeStatus(state.cacheStatus, EMPTY_STATUS),
          error: error?.message || "Unbekannter Fehler",
        };
      } finally {
        requestRender();
      }
    };

    const saveSettings = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      if (!state.cacheStatus.admin_allowed) {
        flash("Legacy-Cache-Admin serverseitig deaktiviert.", "warn");
        return;
      }

      const enabled = form.enabled?.checked ?? false;
      state.savingArchiveCache = true;
      requestRender();
      try {
        const saved = await api("/api/admin/archive-cache", {
          method: "PUT",
          body: JSON.stringify({ enabled }),
        });
        state.config.archive_cache = saved;
        state.cacheStatus = normalizeStatus({
          enabled: saved?.enabled ?? false,
          last_built_at: saved?.last_built_at ?? state.cacheStatus.last_built_at,
          error: null,
        }, state.cacheStatus);
        flash(enabled ? "Legacy-Cache aktiviert" : "Legacy-Cache deaktiviert", "ok");
        await refresh(state.cacheForm.slug || null);
      } catch (error) {
        flash(error.message, "error");
      } finally {
        state.savingArchiveCache = false;
        requestRender();
      }
    };

    // Der Browser übergibt nur Kamera, Jahr und Dry-Run. Pfadauflösung,
    // Autorisierung, Prozessstart und Parallelitätslock bleiben vollständig im
    // Backend. Ein Objekt ohne Prototyp verhindert besondere Eigenschaftsnamen.
    const triggerRebuild = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      if (!state.cacheStatus.admin_allowed) {
        flash("Legacy-Cache-Admin serverseitig deaktiviert.", "warn");
        return;
      }

      const slug = form.slug?.value?.trim() || null;
      const yearRaw = form.year?.value?.trim() || null;
      const year = yearRaw ? Number(yearRaw) : null;
      const dry = form.dry_run?.checked ?? false;
      if (yearRaw && (!Number.isInteger(year) || year < 2000 || year > 2100)) {
        flash("Das Jahr muss zwischen 2000 und 2100 liegen.", "warn");
        return;
      }

      state.cacheForm = { slug: slug || "", year: yearRaw || "", dry };
      state.cacheBusy = true;
      requestRender();
      try {
        const payload = Object.create(null);
        if (slug) payload.slug = slug;
        if (year !== null) payload.year = year;
        if (dry) payload.dry_run = true;
        const result = await api("/api/admin/archive-cache/rebuild", {
          method: "POST",
          body: JSON.stringify(payload),
        });
        flash(`Rebuild ausgeführt: ${result.status ?? "ok"}`, "ok");
        await refresh(slug);
      } catch (error) {
        const message = error?.message || "";
        if (message.startsWith("403 Legacy archive-cache admin disabled")) {
          flash("Legacy-Cache-Admin serverseitig deaktiviert.", "warn");
        } else if (message.startsWith("409 Archive cache disabled")) {
          flash("Legacy-Cache ist deaktiviert. Erst aktivieren, dann Rebuild starten.", "warn");
        } else if (message.startsWith("409 Rebuild already running")) {
          notifyConflict("Ein Rebuild läuft bereits. Bitte später erneut versuchen.");
        } else {
          flash(message || "Rebuild fehlgeschlagen", "error");
        }
      } finally {
        state.cacheBusy = false;
        requestRender();
      }
    };

    const stopPolling = () => {
      if (pollHandle === null) return;
      clearIntervalFn(pollHandle);
      pollHandle = null;
    };

    // Polling läuft nur im sichtbaren Status-Tab und nur solange der Server
    // einen aktiven Rebuild meldet. Jeder vollständige Admin-Render bindet die
    // aktuellen Formulare neu und synchronisiert genau einen Intervall-Handle.
    const syncPolling = () => {
      stopPolling();
      if (state.activeTab === "status"
          && state.cacheStatus.rebuild_state?.status === "running") {
        pollHandle = setIntervalFn(() => {
          const form = root.querySelector("#archive-cache-form");
          const liveSlug = form?.slug?.value?.trim() || "";
          refresh(liveSlug || state.cacheForm.slug || state.selectedSlug || null);
        }, 5000);
      }

      const form = root.querySelector("#archive-cache-form");
      if (!form) return;
      form.slug.value = state.cacheForm.slug;
      form.year.value = state.cacheForm.year;
      form.dry_run.checked = state.cacheForm.dry;
    };

    // Die Hauptseite ersetzt ihren Inhalt bei jedem render(). Deshalb werden
    // ausschließlich Listener an aktuell existierende Elemente gebunden. Die
    // Formwerte werden parallel im zentralen Zustand gehalten.
    const bind = () => {
      const rebuildForm = root.querySelector("#archive-cache-form");
      rebuildForm?.addEventListener("submit", triggerRebuild);
      rebuildForm?.querySelector("select[name='slug']")?.addEventListener("change", (event) => {
        const value = event.target.value.trim();
        state.cacheForm.slug = value;
        refresh(value || null);
      });
      rebuildForm?.querySelector("input[name='year']")?.addEventListener("input", (event) => {
        state.cacheForm.year = event.target.value;
      });

      root.querySelector("#archive-cache-settings-form")
        ?.addEventListener("submit", saveSettings);
      root.querySelector("#btn-refresh-cache-status")?.addEventListener("click", () => {
        const value = rebuildForm?.slug?.value?.trim() || state.cacheForm.slug || "";
        state.cacheForm.slug = value;
        refresh(value || null);
      });
    };

    // Beim Öffnen eines Kameraeditors dient dessen Slug als hilfreiche
    // Vorauswahl. Eine bereits manuell gesetzte Diagnoseauswahl bleibt erhalten.
    const selectCamera = (slug) => {
      if (!state.cacheForm.slug) state.cacheForm.slug = slug || "";
      refresh(slug || null);
    };

    return {
      hydrate,
      summary,
      renderSection,
      refresh,
      bind,
      syncPolling,
      stopPolling,
      selectCamera,
    };
  };

  global.WebcamAdminArchiveCache = Object.freeze({ create });
})(window);
