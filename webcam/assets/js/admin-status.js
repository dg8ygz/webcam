// Status- und Livestream-Bereich der Admin-Oberfläche.
//
// Diese Datei kennt weder das vollständige Admin-Formular noch dessen übrige
// Tabs. Sie erhält beim Start nur die ausdrücklich benötigten Funktionen und
// den gemeinsamen Browserzustand. Dadurch bleiben Laden, Darstellung und
// Bedienung des Statusbereichs an einer Stelle verständlich testbar.
(function registerAdminStatusController(global) {
  "use strict";

  /**
   * Baut den Status-Controller aus den Hilfsfunktionen der Hauptanwendung.
   * Die Abhängigkeiten werden absichtlich übergeben, damit dieses Modul keine
   * zweite API-, CSRF- oder Meldungslogik neben admin.js erfindet.
   */
  const create = ({
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
    requestRender,
    wcTest = false,
    confirmAction = (message) => global.confirm(message),
  }) => {
    if (!state || typeof api !== "function" || typeof requestRender !== "function") {
      throw new Error("Admin status controller dependencies are incomplete");
    }

    // Die Admin-API liefert alle Kamerastatuswerte in einer Antwort. Der
    // CSRF-Header wird auch beim Lesen mitgesendet, weil die Admin-API ihn je
    // nach Serverkonfiguration bereits an dieser Grenze prüfen kann.
    const fetchStatusFromAdmin = async () => {
      const headers = new Headers();
      const csrf = getCsrfToken();
      if (csrf) headers.set("X-CSRF-Token", csrf);
      const response = await api("/api/admin/status", { headers });
      return Array.isArray(response.items) ? response.items : [];
    };

    // Fällt die Admin-Statusroute aus, bleibt die Anzeige lesbar: Die
    // öffentliche Kameraliste wird geladen und mit begrenzt parallelen
    // Detailabfragen ergänzt. Maximal sechs Anfragen laufen gleichzeitig,
    // damit ein großer Kamerabestand Browser und Server nicht überlastet.
    const fetchStatusFromPublic = async () => {
      const listUrl = new URL(buildPublicApiUrl("cameras"), global.location.href);
      listUrl.searchParams.set("per_page", "200");
      const headers = new Headers({ Accept: "application/json" });
      if (wcTest) headers.set("X-WC-TEST", "1");

      const listResponse = await fetch(listUrl.toString(), {
        headers,
        credentials: "same-origin",
      });
      if (!listResponse.ok) {
        throw new Error(`HTTP ${listResponse.status}`);
      }

      const payload = await listResponse.json();
      const cameras = Array.isArray(payload.cameras) ? payload.cameras : [];
      if (!cameras.length) return [];

      const detailHeaders = new Headers({ Accept: "application/json" });
      if (wcTest) detailHeaders.set("X-WC-TEST", "1");
      const results = new Array(cameras.length);
      const workerCount = Math.min(6, cameras.length);
      let cursor = 0;

      const workers = Array.from({ length: workerCount }, async () => {
        while (cursor < cameras.length) {
          const index = cursor;
          cursor += 1;
          const camera = cameras[index];
          if (!camera || !camera.slug) {
            results[index] = null;
            continue;
          }

          const detailUrl = new URL(
            buildPublicApiUrl(`cameras/${encodeURIComponent(camera.slug)}`),
            global.location.href,
          );
          let detail = null;
          try {
            const detailResponse = await fetch(detailUrl.toString(), {
              headers: detailHeaders,
              credentials: "same-origin",
            });
            if (detailResponse.ok) detail = await detailResponse.json();
          } catch {
            // Ein einzelnes Detail darf den Status der übrigen Kameras nicht
            // verwerfen. Fehlende Werte erscheinen weiter sichtbar als leer.
            detail = null;
          }

          const status = detail && typeof detail.status === "object" ? detail.status : {};
          const interval = Number(camera.interval_seconds) || 600;
          const age = camera.age_seconds ?? computeAgeSeconds(status.last_success_at);
          results[index] = {
            slug: camera.slug,
            title: camera.title || camera.slug,
            interval_seconds: interval,
            last_capture_at: status.last_capture_at || camera.last_capture_at || null,
            last_success_at: status.last_success_at || null,
            age_seconds: age,
            consecutive_failures: status.consecutive_failures ?? null,
            resets_total: status.resets_total ?? null,
            last_reset_at: status.last_reset_at ?? null,
            severity: computeSeverity(age, interval),
          };
        }
      });

      await Promise.all(workers);
      return results.filter(Boolean);
    };

    // Ladephasen werden sofort gerendert. So ist der Aktualisieren-Knopf
    // gesperrt und die Oberfläche zeigt, dass tatsächlich gearbeitet wird.
    const fetchStatus = async () => {
      state.status = { ...state.status, loading: true, error: null };
      requestRender();
      try {
        const items = await fetchStatusFromAdmin();
        state.status = { items, loading: false, error: null, source: "admin" };
      } catch (adminError) {
        try {
          const items = await fetchStatusFromPublic();
          state.status = { items, loading: false, error: null, source: "public" };
        } catch (publicError) {
          state.status = {
            items: [],
            loading: false,
            error: publicError.message || adminError.message || "Fehler beim Laden",
            source: null,
          };
        }
      } finally {
        requestRender();
      }
    };

    const fetchStreams = async () => {
      state.streams = { ...state.streams, loading: true, error: null };
      requestRender();
      try {
        const response = await api("/api/admin/streams");
        state.streams = {
          items: Array.isArray(response.items) ? response.items : [],
          blocked_ips: Array.isArray(response.blocked_ips) ? response.blocked_ips : [],
          summary: response.summary && typeof response.summary === "object" ? response.summary : {},
          loading: false,
          error: null,
        };
      } catch (error) {
        state.streams = {
          ...state.streams,
          loading: false,
          error: error.message || "Stream-Status konnte nicht geladen werden.",
        };
      } finally {
        requestRender();
      }
    };

    // Kamera- und Streamstatus sind voneinander unabhängige Endpunkte. Beide
    // werden parallel aktualisiert, damit ein langsamer Teil den anderen nicht
    // unnötig aufhält.
    const refresh = () => {
      void fetchStatus();
      void fetchStreams();
    };

    const disconnectStreamClient = async (token) => {
      if (!token) return;
      try {
        await api("/api/admin/streams/disconnect", {
          method: "POST",
          body: JSON.stringify({ token }),
        });
        flash("Stream-Verbindung wird getrennt.", "ok");
        void fetchStreams();
      } catch (error) {
        flash(error.message || "Stream konnte nicht getrennt werden.", "error");
      }
    };

    const blockStreamIp = async (ip) => {
      if (!ip || !confirmAction(`IP ${ip} blockieren und aktive Streams trennen?`)) return;
      try {
        await api("/api/admin/streams/block", {
          method: "POST",
          body: JSON.stringify({ ip, note: "admin" }),
        });
        flash(`IP ${ip} blockiert.`, "ok");
        void fetchStreams();
      } catch (error) {
        flash(error.message || "IP konnte nicht blockiert werden.", "error");
      }
    };

    const unblockStreamIp = async (ip) => {
      if (!ip) return;
      try {
        await api("/api/admin/streams/unblock", {
          method: "POST",
          body: JSON.stringify({ ip }),
        });
        flash(`IP ${ip} freigegeben.`, "ok");
        void fetchStreams();
      } catch (error) {
        flash(error.message || "IP konnte nicht freigegeben werden.", "error");
      }
    };

    // Erzeugt ausschließlich den HTML-Teil dieses Tabs. Sämtliche Werte aus
    // API-Antworten werden vor dem Einfügen maskiert; nur feste Markup-Bausteine
    // dieses Moduls bleiben HTML.
    const renderSection = () => {
      const sourceLabel = state.status.source === "public"
        ? "Public API"
        : state.status.source === "admin" ? "Admin API" : "–";
      const streamItems = Array.isArray(state.streams.items) ? state.streams.items : [];
      const blockedIps = Array.isArray(state.streams.blocked_ips) ? state.streams.blocked_ips : [];
      const summary = state.streams.summary || {};
      const streamTotal = Number(summary.total_active ?? streamItems.length) || 0;

      const streamRows = streamItems.map((client) => {
        const token = client.token || "";
        const ip = client.ip || "";
        const kind = client.kind === "hls" ? "HLS" : "MJPEG";
        const blocked = client.blocked ? ' <span class="badge badge-warn">blockiert</span>' : "";
        return `
          <tr>
            <td>${escapeHtml(client.camera || "–")}</td>
            <td>${kind}${blocked}</td>
            <td><div>${ip ? escapeHtml(ip) : "–"}</div><small class="muted">${escapeHtml(client.ip_source || "")}</small></td>
            <td>${formatDateTime(client.started_at)}</td>
            <td>${formatAge(client.age_seconds)}</td>
            <td>${client.pid ? escapeHtml(String(client.pid)) : "–"}</td>
            <td style="display:flex;gap:0.4rem;flex-wrap:wrap;">
              <button class="btn small secondary" type="button" data-stream-disconnect="${escapeHtml(token)}" ${token ? "" : "disabled"}>Trennen</button>
              <button class="btn small secondary" type="button" data-stream-block="${escapeHtml(ip)}" ${ip ? "" : "disabled"}>Blockieren</button>
            </td>
          </tr>`;
      }).join("");

      const blockedRows = blockedIps.map((entry) => `
        <tr>
          <td>${escapeHtml(entry.ip || "")}</td>
          <td>${formatDateTime(entry.created_at)}</td>
          <td>${escapeHtml(entry.note || "")}</td>
          <td><button class="btn small secondary" type="button" data-stream-unblock="${escapeHtml(entry.ip || "")}">Freigeben</button></td>
        </tr>`).join("");

      const cameraRows = state.status.items.map((item) => {
        const severity = item.severity || computeSeverity(item.age_seconds, item.interval_seconds);
        const badge = severity === "ok"
          ? "badge-ok"
          : severity === "warn" ? "badge-warn" : severity === "error" ? "badge-error" : "";
        return `
          <tr>
            <td>${escapeHtml(item.title || item.slug || "–")}</td>
            <td>${formatDateTime(item.last_capture_at)}</td>
            <td>${formatDateTime(item.last_success_at)}</td>
            <td>${formatAge(item.age_seconds)}</td>
            <td>${escapeHtml(String(item.consecutive_failures ?? "—"))}</td>
            <td>${escapeHtml(String(item.resets_total ?? "—"))}</td>
            <td><span class="badge ${badge}">${escapeHtml(severity || "–")}</span></td>
          </tr>`;
      }).join("");

      return `
        <section class="card">
          <div class="card-header">
            <div>
              <h2>Status</h2>
              <p class="card-subtitle">Letzte Aufnahmen, Fehlerzähler und Livestream-Belegung · Quelle: ${sourceLabel}</p>
            </div>
            <button class="btn secondary" type="button" id="btn-refresh-status" ${state.status.loading || state.streams.loading ? "disabled" : ""}>Aktualisieren</button>
          </div>
          ${state.status.error ? `<div class="alert alert-error">${escapeHtml(state.status.error)}</div>` : ""}
          ${state.streams.error ? `<div class="alert alert-error">${escapeHtml(state.streams.error)}</div>` : ""}
          ${state.status.loading ? '<p class="muted">Lade Status …</p>' : ""}
          <div class="table-scroll">
            <table>
              <thead><tr><th>Kamera</th><th>Letzte Auslösung</th><th>Letzter Erfolg</th><th>Alter</th><th>Fehlerfolge</th><th>Resets</th><th>Status</th></tr></thead>
              <tbody>${cameraRows || '<tr><td colspan="7">Keine Daten geladen.</td></tr>'}</tbody>
            </table>
          </div>
          <hr>
          <div class="card-header">
            <div><h3>Livestreams</h3><p class="card-subtitle">${streamTotal} aktive Verbindung(en), ${blockedIps.length} blockierte IP(s)</p></div>
          </div>
          ${state.streams.loading ? '<p class="muted">Lade Stream-Status …</p>' : ""}
          <div class="table-scroll">
            <table>
              <thead><tr><th>Kamera</th><th>Typ</th><th>IP</th><th>Seit</th><th>Laufzeit</th><th>PID</th><th>Aktion</th></tr></thead>
              <tbody>${streamRows || '<tr><td colspan="7">Keine aktiven Stream-Viewer.</td></tr>'}</tbody>
            </table>
          </div>
          <div class="table-scroll" style="margin-top:1rem;">
            <table>
              <thead><tr><th>Blockierte IP</th><th>Seit</th><th>Notiz</th><th>Aktion</th></tr></thead>
              <tbody>${blockedRows || '<tr><td colspan="4">Keine blockierten Stream-IPs.</td></tr>'}</tbody>
            </table>
          </div>
        </section>`;
    };

    // Der Hauptcode besitzt bereits einen einzigen delegierten Click-Listener.
    // Dieses Modul entscheidet nur, ob der Klick zu seiner Verantwortung
    // gehört, und meldet das über den Rückgabewert zurück.
    const handleDelegatedClick = (event) => {
      const disconnect = event.target.closest?.("[data-stream-disconnect]");
      if (disconnect) {
        event.preventDefault();
        void disconnectStreamClient(disconnect.dataset.streamDisconnect || "");
        return true;
      }
      const block = event.target.closest?.("[data-stream-block]");
      if (block) {
        event.preventDefault();
        void blockStreamIp(block.dataset.streamBlock || "");
        return true;
      }
      const unblock = event.target.closest?.("[data-stream-unblock]");
      if (unblock) {
        event.preventDefault();
        void unblockStreamIp(unblock.dataset.streamUnblock || "");
        return true;
      }
      return false;
    };

    return { refresh, renderSection, handleDelegatedClick };
  };

  global.WebcamAdminStatus = Object.freeze({ create });
})(window);
