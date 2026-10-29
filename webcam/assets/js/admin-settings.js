// Globale Einstellungen der Admin-Oberfläche.
//
// Dieser Bereich bearbeitet ausschließlich installationsweite Werte: den Text
// und Kartenausschnitt der Übersicht, Schutzgrenzen der Timeline und Wetter-
// Commands sowie das sessionbasierte Public-API-Limit. Kameraeigenschaften
// gehören bewusst nicht hierher und bleiben im Kameraeditor.
(function registerAdminSettingsController(global) {
  "use strict";

  const DEFAULT_RATE_LIMIT = Object.freeze({
    limit: 120,
    window_seconds: 60,
    ttl_days: 7,
  });
  const DEFAULT_TIMELINE = Object.freeze({ max_entries: 150 });
  const DEFAULT_RUNTIME = Object.freeze({ weather_command_timeout_seconds: 20 });
  const DEFAULT_MAP = Object.freeze({ lat: 51.96, lng: 8.53, zoom: 11, height_px: 220 });

  /**
   * Nutzt API, Meldungen und den einzigen Adminzustand aus admin.js. Der dortige
   * API-Adapter ergänzt bei schreibenden Requests den CSRF-Header und bereitet
   * Fehler einheitlich auf; dieses Modul führt keinen zweiten Transportweg ein.
   */
  const create = ({ state, root, api, flash, requestRender, escapeHtml, formatDateTime }) => {
    if (!state || !root || typeof api !== "function" || typeof requestRender !== "function") {
      throw new Error("Admin settings controller dependencies are incomplete");
    }

    const safe = (value) => escapeHtml(String(value ?? ""));

    // Alte Installationen können einzelne Konfigurationsblöcke noch nicht
    // besitzen. Die Defaults dienen nur der sichtbaren Formularbelegung und
    // werden erst nach einer ausdrücklichen Benutzeraktion gespeichert.
    const hydrate = () => {
      if (!state.config) return;
      if (!state.config.public_rate_limit) {
        state.config.public_rate_limit = { ...DEFAULT_RATE_LIMIT };
      }
      if (!state.config.timeline) {
        state.config.timeline = { ...DEFAULT_TIMELINE };
      }
      if (!state.config.runtime) {
        state.config.runtime = { ...DEFAULT_RUNTIME };
      }
    };

    // Der Meta-Tab zeigt zusätzlich den geladenen Konfigurationsstand. Auch
    // diese Werte werden maskiert, da Version, base_url und Zeitstempel aus
    // Konfiguration beziehungsweise Datenbank stammen.
    const renderMetaSection = ({ version, updatedAt, apiBase }) => {
      const runtime = state.config?.runtime || DEFAULT_RUNTIME;
      const map = state.config?.overview_map || DEFAULT_MAP;
      return `
        <section class="card card--meta">
          <details class="meta-details" open>
            <summary>Metadaten</summary>
            <div class="meta-details__body">
              <p>Version <strong>${safe(version)}</strong>, letzte Änderung <strong>${safe(formatDateTime(updatedAt))}</strong></p>
              <p>API-Endpunkt: <code>${safe(apiBase)}</code></p>
              <p>Auth: per Proxy oder HTTP-Auth</p>
            </div>
          </details>
          <form id="hero-form" class="form-grid" style="margin-top:1rem;">
            <div class="form-field form-field--full">
              <label for="hero-text">Übersichts-Teaser</label>
              <textarea id="hero-text" name="overview_hero" rows="2" required>${safe(state.config?.overview_hero || "")}</textarea>
              <small class="form-hint">Text auf der Übersichtsseite unter der Headline.</small>
            </div>
            <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
              <button class="btn" type="submit" ${state.savingHero ? "disabled" : ""}>Speichern</button>
              ${state.savingHero ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
          <form id="map-form" class="form-grid" style="margin-top:1rem;">
            <div class="form-field">
              <label for="map-lat">Kartenmittelpunkt (Lat)</label>
              <input id="map-lat" name="map_lat" type="number" min="-90" max="90" step="0.0001" required value="${safe(map.lat ?? DEFAULT_MAP.lat)}">
            </div>
            <div class="form-field">
              <label for="map-lng">Kartenmittelpunkt (Lon)</label>
              <input id="map-lng" name="map_lng" type="number" min="-180" max="180" step="0.0001" required value="${safe(map.lng ?? DEFAULT_MAP.lng)}">
            </div>
            <div class="form-field">
              <label for="map-zoom">Zoom (1–18)</label>
              <input id="map-zoom" name="map_zoom" type="number" min="1" max="18" required value="${safe(map.zoom ?? DEFAULT_MAP.zoom)}">
            </div>
            <div class="form-field">
              <label for="map-height">Höhe (px)</label>
              <input id="map-height" name="map_height" type="number" min="120" max="800" required value="${safe(map.height_px ?? DEFAULT_MAP.height_px)}">
            </div>
            <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
              <button class="btn" type="submit" ${state.savingMap ? "disabled" : ""}>Karte speichern</button>
              ${state.savingMap ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
          <form id="runtime-form" class="form-grid" style="margin-top:1rem;">
            <div class="form-field">
              <label for="runtime-weather-timeout">Wetter-Command Timeout (Sek.)</label>
              <input id="runtime-weather-timeout" name="weather_command_timeout_seconds" type="number" min="1" max="300" required value="${safe(runtime.weather_command_timeout_seconds ?? DEFAULT_RUNTIME.weather_command_timeout_seconds)}">
              <small class="form-hint">Harte Laufzeitgrenze für konfigurierte Wetter-Commands im Capture-/Upload-Pfad.</small>
            </div>
            <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
              <button class="btn" type="submit" ${state.savingRuntime ? "disabled" : ""}>Betrieb speichern</button>
              ${state.savingRuntime ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
          <form id="timeline-form" class="form-grid" style="margin-top:1rem;">
            <div class="form-field">
              <label for="tl-max">Timeline-Limit</label>
              <input id="tl-max" name="max_entries" type="number" min="50" max="5000" required value="${safe(state.config?.timeline?.max_entries ?? DEFAULT_TIMELINE.max_entries)}">
              <small class="form-hint">Begrenzt unbeschränkte DB-Timeline-Abfragen als Schutz vor unnötig großen Antwortmengen.</small>
            </div>
            <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
              <button class="btn" type="submit" ${state.savingTimeline ? "disabled" : ""}>Timeline-Limit speichern</button>
              ${state.savingTimeline ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
        </section>`;
    };

    const renderRateSection = () => {
      const rate = state.config?.public_rate_limit || DEFAULT_RATE_LIMIT;
      return `
        <section class="card" data-tab-panel="rate">
          <div class="card-header">
            <div>
              <h2>Public API Rate Limit</h2>
              <p class="card-subtitle">Session-basiertes Limit (keine IPs)</p>
            </div>
          </div>
          <form id="rate-limit-form" class="form-grid">
            <div class="form-field">
              <label for="rl-limit">Requests pro Fenster</label>
              <input id="rl-limit" name="limit" type="number" min="1" required value="${safe(rate.limit ?? DEFAULT_RATE_LIMIT.limit)}">
            </div>
            <div class="form-field">
              <label for="rl-window">Fenster (Sek.)</label>
              <input id="rl-window" name="window_seconds" type="number" min="5" required value="${safe(rate.window_seconds ?? DEFAULT_RATE_LIMIT.window_seconds)}">
            </div>
            <div class="form-field">
              <label for="rl-ttl">Aufbewahrung (Tage)</label>
              <input id="rl-ttl" name="ttl_days" type="number" min="1" required value="${safe(rate.ttl_days ?? DEFAULT_RATE_LIMIT.ttl_days)}">
            </div>
            <div class="form-field form-field--full" style="display:flex;gap:0.5rem;align-items:center;">
              <button class="btn" type="submit" ${state.savingRateLimit ? "disabled" : ""}>Speichern</button>
              ${state.savingRateLimit ? '<span class="muted">Speichere…</span>' : ""}
            </div>
          </form>
        </section>`;
    };

    // Jeder Speichervorgang setzt genau sein eigenes Flag. Andere Formulare
    // bleiben dadurch bedienbar und Fehler können dem betroffenen Block
    // zugeordnet werden. Das Backend validiert alle Grenzen unabhängig erneut.
    const saveRateLimit = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      const limit = Number(form.limit?.value ?? 0);
      const windowSeconds = Number(form.window_seconds?.value ?? 0);
      const ttlDays = Number(form.ttl_days?.value ?? 0);
      if (!Number.isInteger(limit) || limit < 1
          || !Number.isInteger(windowSeconds) || windowSeconds < 5
          || !Number.isInteger(ttlDays) || ttlDays < 1) {
        flash("Bitte gültige Ganzzahlen eingeben (Limit ≥1, Fenster ≥5s, TTL ≥1 Tag)", "warn");
        return;
      }
      state.savingRateLimit = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/public-rate-limit", {
          method: "PUT",
          body: JSON.stringify({ limit, window_seconds: windowSeconds, ttl_days: ttlDays }),
        });
        state.config.public_rate_limit = saved;
        flash("Rate-Limit gespeichert", "ok");
      } catch (error) {
        flash(error.message, "error");
      } finally {
        state.savingRateLimit = false;
        requestRender();
      }
    };

    const saveTimeline = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      const maxEntries = Number(form.max_entries?.value ?? 0);
      if (!Number.isInteger(maxEntries) || maxEntries < 50 || maxEntries > 5000) {
        flash("Bitte eine Ganzzahl zwischen 50 und 5000 angeben.", "warn");
        return;
      }
      state.savingTimeline = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/timeline", {
          method: "PUT",
          body: JSON.stringify({ max_entries: maxEntries }),
        });
        state.config.timeline = saved;
        flash("Timeline-Limit gespeichert", "ok");
      } catch (error) {
        flash(error.message, "error");
      } finally {
        state.savingTimeline = false;
        requestRender();
      }
    };

    const saveRuntime = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      const timeoutSeconds = Number(form.weather_command_timeout_seconds?.value ?? 0);
      if (!Number.isInteger(timeoutSeconds) || timeoutSeconds < 1 || timeoutSeconds > 300) {
        flash("Bitte einen ganzzahligen Wetter-Timeout zwischen 1 und 300 Sekunden angeben.", "warn");
        return;
      }
      state.savingRuntime = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/runtime", {
          method: "PUT",
          body: JSON.stringify({ weather_command_timeout_seconds: timeoutSeconds }),
        });
        state.config.runtime = saved;
        flash("Betriebseinstellungen gespeichert", "ok");
      } catch (error) {
        flash(error.message, "error");
      } finally {
        state.savingRuntime = false;
        requestRender();
      }
    };

    const saveHero = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      const text = form.overview_hero?.value?.trim() || "";
      if (!text) {
        flash("Text darf nicht leer sein.", "error");
        return;
      }
      state.savingHero = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/overview-hero", {
          method: "PUT",
          body: JSON.stringify({ overview_hero: text }),
        });
        state.config.overview_hero = saved.overview_hero;
        flash("Übersichts-Teaser gespeichert.", "ok");
      } catch (error) {
        flash(error.message || "Fehler beim Speichern", "error");
      } finally {
        state.savingHero = false;
        requestRender();
      }
    };

    const saveOverviewMap = async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form) return;
      const latRaw = String(form.map_lat?.value ?? "").trim();
      const lngRaw = String(form.map_lng?.value ?? "").trim();
      const zoomRaw = String(form.map_zoom?.value ?? "").trim();
      const heightRaw = String(form.map_height?.value ?? "").trim();
      const lat = Number(latRaw);
      const lng = Number(lngRaw);
      const zoom = Number(zoomRaw);
      const height = Number(heightRaw);
      // Number("") wäre 0 und damit scheinbar gültig. Die Rohwerte werden
      // deshalb zusätzlich geprüft, damit auch programmgesteuerte Submits
      // ohne die native required-Prüfung zuverlässig abgewiesen werden.
      if (!latRaw || !lngRaw || !zoomRaw || !heightRaw
          || !Number.isFinite(lat) || lat < -90 || lat > 90
          || !Number.isFinite(lng) || lng < -180 || lng > 180
          || !Number.isInteger(zoom) || zoom < 1 || zoom > 18
          || !Number.isInteger(height) || height < 120 || height > 800) {
        flash("Bitte gültige Kartenwerte eingeben (Lat −90…90, Lon −180…180, Zoom 1…18, Höhe 120…800).", "error");
        return;
      }
      state.savingMap = true;
      requestRender();
      try {
        const saved = await api("/api/admin/config/overview-map", {
          method: "PUT",
          body: JSON.stringify({ lat, lng, zoom, height_px: height }),
        });
        state.config.overview_map = saved;
        flash("Kartenausschnitt gespeichert.", "ok");
      } catch (error) {
        flash(error.message || "Fehler beim Speichern", "error");
      } finally {
        state.savingMap = false;
        requestRender();
      }
    };

    // render() ersetzt den gesamten Admin-Inhalt. Die Listener werden daher
    // nach jedem Aufbau nur an die fünf aktuell vorhandenen Formulare gehängt.
    const bind = () => {
      root.querySelector("#rate-limit-form")?.addEventListener("submit", saveRateLimit);
      root.querySelector("#hero-form")?.addEventListener("submit", saveHero);
      root.querySelector("#map-form")?.addEventListener("submit", saveOverviewMap);
      root.querySelector("#timeline-form")?.addEventListener("submit", saveTimeline);
      root.querySelector("#runtime-form")?.addEventListener("submit", saveRuntime);
    };

    return { hydrate, renderMetaSection, renderRateSection, bind };
  };

  global.WebcamAdminSettings = Object.freeze({ create });
})(window);
