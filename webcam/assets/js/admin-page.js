(function () {
  "use strict";

  // Leseführung für die vollständige Admin-Seite:
  //
  // 1. Kleine Formatierer bauen sichere Status- und Tabellenwerte.
  // 2. Renderer setzen die bereits spezialisierten Fachbereiche zusammen.
  // 3. Der DOM-Lebenszyklus räumt alte Widgets auf und bindet neue Formulare.
  // 4. Delegierte Listener bleiben über beliebig viele Neuaufbauten erhalten.
  // 5. render() koordiniert diese Schritte, besitzt aber weder API noch Daten.
  //
  // Die Komponente bekommt den zentralen `state` aus admin.js nur als Referenz.
  // Sie legt keinen zweiten Browserzustand an und speichert selbst nichts auf
  // dem Server. Dadurch bleibt nachvollziehbar: admin.js lädt und verändert
  // Daten, dieses Modul stellt sie dar und verbindet die Bedienelemente.

  const TOP_TABS = Object.freeze([
    { id: "meta", label: "Meta" },
    { id: "status", label: "Status" },
    { id: "scripts", label: "Scripte" },
    { id: "cameras", label: "Kameras" },
    { id: "rate", label: "Rate-Limit" },
  ]);

  const FLASH_LEVELS = new Set(["ok", "warn", "error"]);

  const create = ({
    state,
    root,
    apiBase,
    iteration,
    storage,
    escapeHtml,
    formatDateTime,
    buildCameraLink,
    defaultCamera,
    requestRender,
    confirmDelete,
    captureCamera,
    focusCamera,
    focusAvailable,
    tunnelStatus,
    tunnelAction,
    saveCameraSection,
    onSaveCamera,
    initEditorTabs,
    initRichTextEditor,
    removeRichTextEditors,
    cameraEditor,
    mapPreview,
    settings,
    status,
    maintenance,
    archiveCache,
    formValidation,
    overlayEditor,
    labelEditor,
  }) => {
    if (!state || !root) {
      throw new Error("Admin page requires state and root");
    }

    // Alle direkt in HTML eingesetzten Daten passieren denselben Adapter.
    // Fachkomponenten liefern bereits maskiertes, bewusst vertrauenswürdiges
    // Markup. Werte aus Config, Fehlerantworten oder data-Attributen dagegen
    // werden hier nochmals als Text behandelt.
    const safe = (value) => escapeHtml(String(value ?? ""));

    const rememberActiveTab = (tabId) => {
      state.activeTab = tabId;
      storage?.setItem?.("adminActiveTab", tabId);
    };

    const renderFlash = () => {
      if (!state.flash) {
        return "";
      }
      const level = FLASH_LEVELS.has(state.flash.level) ? state.flash.level : "ok";
      return `<div class="status-banner ${level}">${safe(state.flash.message)}</div>`;
    };

    const formatCoordinates = (location) => {
      const latitude = Number(location?.latitude);
      const longitude = Number(location?.longitude);
      const hasCoordinates = Boolean(location?.latitude || location?.longitude);
      if (!hasCoordinates || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return "–";
      }
      return `${latitude.toFixed(3)} / ${longitude.toFixed(3)}`;
    };

    // Eine Tabellenzeile zeigt ausschließlich die kompakte Zusammenfassung.
    // Der umfangreiche Kameraeditor kommt nur direkt unter die gewählte Zeile;
    // sein HTML bleibt Aufgabe von admin-camera-editor.js.
    const renderCameraRow = (camera) => {
      const slug = String(camera?.slug ?? "");
      const intervalMinutes = Math.round(
        Number(camera?.capture?.interval_seconds ?? 600) / 60,
      );
      const watchdogEnabled = Boolean(camera?.status?.watchdog?.enabled);
      const isSelected = !state.isCreating && state.selectedSlug === slug;
      const editorRow = isSelected
        ? `
          <tr class="editor-row" data-editor-slug="${safe(slug)}">
            <td colspan="5">${cameraEditor.render(camera, {
              inline: true,
              activeTab: state.editorTabs[slug] ?? null,
            })}</td>
          </tr>`
        : "";

      return `
        <tr data-slug="${safe(slug)}">
          <td class="cell-slug">
            <span class="pill">${safe(slug)}</span>
          </td>
          <td class="cell-title">
            <div class="cell-title__primary">${safe(camera?.title)}</div>
            <div class="cell-title__meta">${safe(formatCoordinates(camera?.location))}</div>
          </td>
          <td class="cell-mode">
            <span class="badge badge-outline">${safe(camera?.mode ?? "unknown")}</span>
          </td>
          <td class="cell-interval">
            <span>${safe(Number.isFinite(intervalMinutes) ? intervalMinutes : "–")} min</span>
            <span class="badge ${watchdogEnabled ? "badge-ok" : "badge-warn"}">
              ${watchdogEnabled ? "Watchdog" : "Kein Watchdog"}
            </span>
          </td>
          <td>
            <div class="table-actions">
              <button class="btn secondary edit" type="button" data-slug="${safe(slug)}">Bearbeiten</button>
              <a class="btn ghost" href="${safe(buildCameraLink(slug))}" target="_blank" rel="noopener">Anzeigen</a>
              <button class="btn danger delete" type="button" data-slug="${safe(slug)}">Löschen</button>
            </div>
          </td>
        </tr>${editorRow}`;
    };

    const renderCamerasSection = (cameraList) => {
      const rowsHtml = cameraList.map(renderCameraRow).join("");
      const showEditorActions = Boolean(state.isCreating || state.selectedSlug);
      const primaryActionLabel = state.isCreating ? "Anlegen" : "Speichern";
      const createRow = state.isCreating
        ? `
          <tr class="editor-row editor-row--creating">
            <td colspan="5">${cameraEditor.render(defaultCamera(), {
              inline: true,
              creating: true,
            })}</td>
          </tr>`
        : "";

      return `
        <section class="card" data-tab-panel="cameras">
          <div class="card-header">
            <div>
              <h2>Kameras</h2>
              <p class="card-subtitle">Anzahl: ${safe(cameraList.length)}</p>
            </div>
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;justify-content:flex-end;">
              ${showEditorActions ? `
                <button class="btn secondary" id="btn-cancel-top" type="button">Abbrechen</button>
                <button class="btn" id="btn-save-top" type="button">${primaryActionLabel}</button>
              ` : ""}
              <button class="btn secondary" id="btn-add-camera" type="button">Neue Kamera</button>
            </div>
          </div>
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Slug</th>
                  <th>Titel</th>
                  <th>Modus</th>
                  <th>Intervall</th>
                  <th>Aktionen</th>
                </tr>
              </thead>
              <tbody>
                ${rowsHtml || '<tr><td colspan="5">Noch keine Kameras vorhanden.</td></tr>'}
                ${createRow}
              </tbody>
            </table>
          </div>
        </section>`;
    };

    const resolveTabs = (cameraCount) => {
      const tabs = TOP_TABS.map((tab) => ({
        ...tab,
        label: tab.id === "cameras" ? `Kameras (${cameraCount})` : tab.label,
      }));
      if (!tabs.some((tab) => tab.id === state.activeTab)) {
        rememberActiveTab("meta");
      }
      return tabs;
    };

    const renderTabButtons = (tabs) => tabs
      .map((tab) => `
        <button
          class="tab-button ${state.activeTab === tab.id ? "is-active" : ""}"
          data-tab="${tab.id}"
          type="button"
        >${tab.label}</button>`)
      .join("");

    // Die fünf Fachcontroller liefern ihre fertigen Bereiche. Diese Funktion
    // ordnet sie nur den Haupttabs zu und verändert ihren Inhalt nicht.
    const renderTabPanels = ({ cameraList, version, updatedAt }) => {
      const metaSection = settings.renderMetaSection({ version, updatedAt, apiBase });
      const statusSection = status.renderSection();
      const legacyCacheSection = archiveCache.renderSection(cameraList);

      return [
        `<div data-tab-panel="meta">${metaSection}</div>`,
        `<div data-tab-panel="status">${statusSection}${legacyCacheSection}</div>`,
        maintenance.renderSection(),
        settings.renderRateSection(),
        renderCamerasSection(cameraList),
      ].join("");
    };

    const renderShell = ({ cameraList, updatedAt, tabButtons, tabPanels }) => {
      const cacheSummary = archiveCache.summary();
      const watchdogCount = cameraList.filter(
        (camera) => camera?.status?.watchdog?.enabled,
      ).length;

      return `
        ${renderFlash()}
        <div class="admin-shell">
          <aside class="side-rail">
            <div class="rail-brand">
              <span class="rail-dot"></span>
              <div>
                <div class="rail-title">Webcam Admin</div>
                <div class="rail-sub">Stand ${safe(formatDateTime(updatedAt))} · Iteration ${safe(iteration || "–")}</div>
              </div>
            </div>
            <div class="rail-metrics">
              <span class="pill">${safe(cameraList.length)} Kameras</span>
              <span class="pill ${safe(cacheSummary?.badgeClass)}">${safe(cacheSummary?.label)}</span>
              <span class="pill">${safe(watchdogCount)} Watchdog</span>
            </div>
            <div class="tab-bar tab-bar--rail">
              ${tabButtons}
            </div>
            <button class="btn ghost rail-add" type="button" data-action="nav-add-camera">+ Neue Kamera</button>
          </aside>
          <section class="admin-main">
            <div class="tab-panels">
              ${tabPanels}
            </div>
          </section>
        </div>`;
    };

    // Vor innerHTML müssen Leaflet/Canvas und TinyMCE ihre Referenzen auf die
    // alten DOM-Knoten freigeben. Sonst bleiben Resize-Listener oder Editor-
    // Instanzen nach einem Tabwechsel im Speicher hängen.
    const prepareDomReplacement = () => {
      mapPreview.destroy(false);
      removeRichTextEditors();
    };

    const selectCamera = (slug) => {
      state.selectedSlug = slug;
      state.isCreating = false;
      archiveCache.selectCamera(slug);
      requestRender();
    };

    const startCameraCreation = ({ switchToCameraTab = false } = {}) => {
      state.selectedSlug = null;
      state.isCreating = true;
      if (switchToCameraTab) {
        rememberActiveTab("cameras");
      }
      requestRender();
    };

    const cancelCameraEditing = (event) => {
      event?.preventDefault?.();
      state.selectedSlug = null;
      state.isCreating = false;
      requestRender();
    };

    // Diese Listener gehören zu gerade neu erzeugten Knoten. Sie dürfen bei
    // jedem render() neu gebunden werden, weil die alten Knoten ersetzt wurden.
    const bindRenderedActions = () => {
      root.querySelectorAll("button.edit").forEach((button) => {
        button.addEventListener("click", () => selectCamera(button.dataset.slug));
      });
      root.querySelectorAll("button.delete").forEach((button) => {
        button.addEventListener("click", () => confirmDelete(button.dataset.slug));
      });

      root.querySelector("#btn-add-camera")?.addEventListener(
        "click",
        () => startCameraCreation(),
      );
      root.querySelector("[data-action='nav-add-camera']")?.addEventListener(
        "click",
        () => startCameraCreation({ switchToCameraTab: true }),
      );
      root.querySelector("#btn-refresh-status")?.addEventListener(
        "click",
        () => status.refresh(),
      );

      // Je Kamera nur den Sollzustand abfragen/aendern. Eine Freigabe bzw.
      // running-Meldung ist noch kein nachgewiesener SSH-Zugang zum Pi.
      root.querySelectorAll(".tunnel-controls").forEach(async (group) => {
        const open = group.querySelector('[data-tunnel-action="open"]');
        const status = group.querySelector('[data-tunnel-status]');
        const refresh = async () => {
          try {
            const data = await tunnelStatus(open.dataset.tunnelCamera);
            if (!group.isConnected) return;
            open.disabled = !data.available || Boolean(data.expires_at);
            status.textContent = data.expires_at
              ? `Freigegeben bis ${new Date(data.expires_at * 1000).toLocaleTimeString()}. Letzte Pi-Meldung: ${data.reported_state}; kein SSH-Verbindungsnachweis.`
              : (data.available ? `Keine Freigabe. Letzte Pi-Meldung: ${data.reported_state}.` : 'Wartungsdienst nicht eingerichtet oder noch nicht aktuell gemeldet.');
          } catch (_) { open.disabled = true; status.textContent = 'Wartungsstatus nicht erreichbar.'; }
        };
        group.querySelectorAll('[data-tunnel-action]').forEach(button => button.addEventListener('click', async () => {
          if (button.disabled) return;
          button.disabled = true;
          try { await tunnelAction(button.dataset.tunnelCamera, button.dataset.tunnelAction); }
          finally { button.disabled = false; await refresh(); }
        }));
        await refresh();
      });
      // Fokus erst nach bestaetigter Helper-Capability freigeben; Ergebnisse eines
      // inzwischen ersetzten Editors nicht mehr auf dessen DOM anwenden.
      root.querySelectorAll(".focus-controls").forEach(async (group) => {
        const buttons = group.querySelectorAll("[data-focus-camera]");
        buttons.forEach(button => { button.disabled = true; });
        try {
          const available = await focusAvailable(buttons[0]?.dataset.focusCamera);
          if (group.isConnected) buttons.forEach(button => { button.disabled = !available; });
        } catch (_) { /* Fail closed; never enable an unknown helper. */ }
      });
      root.querySelectorAll("[data-focus-camera]").forEach((button) => {
        button.addEventListener("click", async () => {
          if (button.disabled) return;
          const buttons = button.closest(".focus-controls").querySelectorAll("button");
          buttons.forEach(item => { item.disabled = true; });
          try { await focusCamera(button.dataset.focusCamera, button.dataset.focusMode); }
          finally { buttons.forEach(item => { item.disabled = false; }); }
        });
      });
      // Button sperrt nur den eigenen Request; API-Queue uebernimmt Rate/Expiry
      // und der passende Helper/Pull-Tick die spaetere Aufnahme.
      root.querySelectorAll("[data-capture-camera]").forEach((button) => {
        button.addEventListener("click", async () => {
          if (button.disabled) return;
          button.disabled = true;
          try { await captureCamera(button.dataset.captureCamera); }
          finally { button.disabled = false; }
        });
      });
      root.querySelectorAll("[data-action='cancel-edit']").forEach((button) => {
        button.addEventListener("click", cancelCameraEditing);
      });
      root.querySelector("#btn-cancel-top")?.addEventListener(
        "click",
        cancelCameraEditing,
      );
      root.querySelector("#btn-save-top")?.addEventListener("click", (event) => {
        event.preventDefault();
        const activeForm = root.querySelector(".camera-form");
        if (!activeForm) {
          return;
        }
        if (typeof activeForm.requestSubmit === "function") {
          activeForm.requestSubmit();
        } else {
          activeForm.dispatchEvent(new Event("submit", { cancelable: true }));
        }
      });

      archiveCache.bind();
      settings.bind();
      maintenance.bind();
    };

    const changeTopTab = (tabId) => {
      rememberActiveTab(tabId);
      if (tabId !== "cameras") {
        state.selectedSlug = null;
        state.isCreating = false;
      }
      if (tabId === "status") {
        status.refresh();
        archiveCache.refresh(state.cacheForm.slug || null);
      } else {
        archiveCache.stopPolling();
      }
      requestRender();
    };

    const handleNestedTabClick = (event) => {
      const tabButton = event.target.closest?.("[data-tab-target]");
      const tabsContainer = tabButton?.closest("[data-tabs]");
      if (!tabButton || !tabsContainer) {
        return;
      }
      event.preventDefault();
      const targetId = tabButton.dataset.tabTarget;
      tabsContainer.querySelectorAll("[data-tab-target]").forEach((button) => {
        const active = button.dataset.tabTarget === targetId;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
      tabsContainer.querySelectorAll("[data-tab-panel]").forEach((panel) => {
        const active = panel.dataset.tabPanel === targetId;
        panel.classList.toggle("is-active", active);
        panel.style.display = active ? "block" : "none";
      });
    };

    const handleSectionSave = (event) => {
      const button = event.target.closest?.("[data-section-save]");
      if (!button) {
        return;
      }
      event.preventDefault();
      const sectionId = button.dataset.sectionSave || button.dataset.section || "";
      const form = button.closest(".camera-form");
      if (!sectionId || !form) {
        return;
      }
      const slug = form.querySelector('input[name="slug"]')?.value?.trim() || "";
      if (slug && state.sectionSaving[slug]?.[sectionId]) {
        return;
      }
      const panel = button.closest("[data-tab-panel]");
      if (!formValidation.validateSection(panel)) {
        return;
      }
      saveCameraSection(sectionId, form);
    };

    // Delegierte Ereignisse hängen am stabilen Wurzelelement. Die Markierung
    // verhindert, dass ein späterer render()-Aufruf dieselben Listener erneut
    // registriert und dadurch Aktionen doppelt ausführt.
    const bindDelegatedActionsOnce = () => {
      if (root.dataset.adminDelegatedHandlersBound === "1") {
        return;
      }
      root.dataset.adminDelegatedHandlersBound = "1";

      root.addEventListener("click", (event) => {
        if (status.handleDelegatedClick(event)) {
          return;
        }
        const topTab = event.target.closest?.(".tab-button[data-tab]");
        if (topTab) {
          event.preventDefault();
          const tabId = topTab.dataset.tab;
          if (tabId && state.activeTab !== tabId) {
            changeTopTab(tabId);
          }
        }
      });
      root.addEventListener("click", handleNestedTabClick);
      root.addEventListener("click", handleSectionSave);
    };

    const activateTopPanel = () => {
      root.querySelectorAll(".tab-panels > [data-tab-panel]").forEach((panel) => {
        const active = panel.dataset.tabPanel === state.activeTab;
        panel.classList.toggle("is-active", active);
        panel.style.display = active ? "block" : "none";
      });
    };

    // Spezialeditoren werden erst nach dem DOM-Austausch gestartet. Formulare
    // erhalten hier ihre Browservalidierung und genau einen Submit-Handler.
    const initializeVisibleEditors = () => {
      archiveCache.syncPolling();
      root.querySelectorAll(".camera-form").forEach((form) => {
        formValidation.init(form);
        form.addEventListener("submit", onSaveCamera);
      });
      overlayEditor.init();
      labelEditor.init();
      initEditorTabs();
      initRichTextEditor();
    };

    const render = () => {
      if (!state.config) {
        root.innerHTML = `
          <section class="card">
            ${renderFlash()}
            <p>Lade Konfiguration…</p>
          </section>`;
        return;
      }

      const cameraList = Object.values(state.config.cameras ?? {});
      const tabs = resolveTabs(cameraList.length);
      const renderContext = {
        cameraList,
        version: state.config.version,
        updatedAt: state.config.updated_at,
      };

      prepareDomReplacement();
      root.innerHTML = renderShell({
        cameraList,
        updatedAt: renderContext.updatedAt,
        tabButtons: renderTabButtons(tabs),
        tabPanels: renderTabPanels(renderContext),
      });

      bindRenderedActions();
      bindDelegatedActionsOnce();
      activateTopPanel();
      initializeVisibleEditors();
    };

    return { render };
  };

  window.WebcamAdminPage = Object.freeze({ create });
})();
