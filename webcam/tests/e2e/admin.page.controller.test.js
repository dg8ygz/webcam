const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const PAGE_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-page.js');

test.describe('Ausgelagerter Admin-Seitenaufbau', () => {
  test('rendert sicher und hält Render- sowie Listener-Lebenszyklus zusammen', async ({ page }) => {
    await page.setContent('<main id="admin-root"></main>');
    await page.addScriptTag({ path: PAGE_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('admin-root');
      const calls = {
        renders: 0,
        removedEditors: 0,
        mapDestroyed: 0,
        deleted: [],
        captures: [],
        focuses: [],
        tunnels: [],
        sectionSaves: [],
        formSaves: 0,
        statusRefreshes: 0,
        archiveRefreshes: [],
        pollingStops: 0,
      };
      const state = {
        config: null,
        flash: {
          level: '" onclick="window.__adminPageXss=1',
          message: '<img src=x onerror="window.__adminPageXss=2">Fehler',
        },
        selectedSlug: null,
        isCreating: false,
        activeTab: 'alter-entfernter-tab',
        editorTabs: {},
        sectionSaving: {},
        cacheForm: { slug: 'bielefeld' },
      };
      const storage = {
        values: {},
        setItem(key, value) {
          this.values[key] = value;
        },
      };
      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
      const cameraEditor = {
        render(camera, options) {
          const slug = escapeHtml(camera?.slug || 'new-camera');
          return `
            <form class="camera-form" data-render-kind="${options.creating ? 'create' : 'edit'}">
              <input name="slug" value="${slug}">
              <button type="button" data-capture-camera="${slug}">Jetzt aufnehmen</button>
              <fieldset class="focus-controls"><button type="button" data-focus-camera="${slug}" data-focus-mode="++">Fokus ++</button></fieldset>
              <fieldset class="tunnel-controls"><button type="button" data-tunnel-camera="${slug}" data-tunnel-action="open">Für 30 Minuten öffnen</button><button type="button" data-tunnel-camera="${slug}" data-tunnel-action="close">Schließen</button><p data-tunnel-status></p></fieldset>
              <div data-tabs>
                <button type="button" data-tab-target="general">Allgemein</button>
                <section data-tab-panel="general">
                  <button type="button" data-section-save="general">Abschnitt speichern</button>
                </section>
              </div>
              <button type="button" data-action="cancel-edit">Abbrechen</button>
            </form>`;
        },
      };
      const sections = {
        settings: {
          renderMetaSection: () => '<section><h2>Meta-Inhalt</h2></section>',
          renderRateSection: () => '<section data-tab-panel="rate"><h2>Rate-Inhalt</h2></section>',
          bind: () => {},
        },
        status: {
          renderSection: () => '<section><button id="btn-refresh-status">Status aktualisieren</button></section>',
          refresh: () => { calls.statusRefreshes += 1; },
          handleDelegatedClick: () => false,
        },
        maintenance: {
          renderSection: () => '<section data-tab-panel="scripts"><h2>Script-Inhalt</h2></section>',
          bind: () => {},
        },
        archiveCache: {
          renderSection: () => '<section>Cache-Diagnose</section>',
          summary: () => ({
            badgeClass: 'badge-ok" onclick="window.__adminPageXss=3',
            label: '<script>window.__adminPageXss=4</script>Cache',
          }),
          selectCamera: () => {},
          bind: () => {},
          syncPolling: () => {},
          refresh: (slug) => { calls.archiveRefreshes.push(slug); },
          stopPolling: () => { calls.pollingStops += 1; },
        },
      };

      let controller;
      controller = window.WebcamAdminPage.create({
        state,
        root,
        apiBase: '.',
        iteration: '150',
        captureCamera: async (slug) => { calls.captures.push(slug); },
        focusCamera: async (slug, mode) => { calls.focuses.push([slug, mode]); },
        focusAvailable: async () => true,
        tunnelStatus: async () => ({ available: true, expires_at: null, reported_state: 'closed' }),
        tunnelAction: async (slug, action) => { calls.tunnels.push([slug, action]); },
        storage,
        escapeHtml,
        formatDateTime: () => '30.8.2026, 12:34:56',
        buildCameraLink: (slug) => `camera/${encodeURIComponent(slug)}`,
        defaultCamera: () => ({ slug: '' }),
        requestRender: () => {
          calls.renders += 1;
          controller.render();
        },
        confirmDelete: (slug) => { calls.deleted.push(slug); },
        saveCameraSection: (sectionId, form) => {
          calls.sectionSaves.push([sectionId, form.elements.slug.value]);
        },
        onSaveCamera: (event) => {
          event.preventDefault();
          calls.formSaves += 1;
        },
        initEditorTabs: () => {},
        initRichTextEditor: () => {},
        removeRichTextEditors: () => { calls.removedEditors += 1; },
        cameraEditor,
        mapPreview: { destroy: () => { calls.mapDestroyed += 1; } },
        settings: sections.settings,
        status: sections.status,
        maintenance: sections.maintenance,
        archiveCache: sections.archiveCache,
        formValidation: {
          init: () => {},
          validateSection: () => true,
        },
        overlayEditor: { init: () => {} },
        labelEditor: { init: () => {} },
      });

      // Der Ladezustand nutzt bereits dieselbe sichere Flashdarstellung. Erst
      // danach wird eine absichtlich verunreinigte Config gerendert.
      controller.render();
      state.config = {
        version: '6.0.6',
        updated_at: '2026-08-30T12:34:56+02:00',
        cameras: {
          bielefeld: {
            slug: 'bielefeld" autofocus onfocus="window.__adminPageXss=5',
            title: '<svg><script>window.__adminPageXss=6</script></svg>Bielefeld',
            mode: '<img src=x onerror="window.__adminPageXss=7">active',
            capture: { interval_seconds: 600 },
            location: { latitude: 52.03, longitude: 8.53 },
            status: { watchdog: { enabled: true } },
          },
        },
      };
      controller.render();
      window.__adminPageTest = { controller, state, storage, calls };
    });

    // Ein unbekannter alter Tab fällt reproduzierbar auf Meta zurück. Config-
    // und Fehlertexte dürfen weder Elemente noch Ereignisattribute erzeugen.
    await expect(page.getByRole('button', { name: 'Meta', exact: true })).toHaveClass(/is-active/);
    await expect(page.locator('.rail-sub')).toContainText('Iteration 150');
    await expect(page.locator('#admin-root img, #admin-root svg, #admin-root script')).toHaveCount(0);
    await expect(page.locator('#admin-root [onclick], #admin-root [onfocus], #admin-root [onerror]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__adminPageXss)).toBeUndefined();
    await expect(page.locator('.cell-title__primary')).toContainText('<svg><script>');
    expect(await page.evaluate(() => window.__adminPageTest.storage.values.adminActiveTab)).toBe('meta');

    // Haupttabwechsel erzeugen den DOM neu. Editieren, Speichern und Abbrechen
    // müssen danach weiterhin genau den zentralen Zustand/Callback verwenden.
    await page.getByRole('button', { name: /Kameras \(1\)/ }).click();
    await page.getByRole('button', { name: 'Bearbeiten' }).click();
    await expect(page.locator('.camera-form[data-render-kind="edit"]')).toBeVisible();
    await page.locator('[data-capture-camera]').click();
    await page.locator('[data-focus-camera]').click();
    await page.locator('[data-tunnel-action="open"]').click();
    await page.locator('[data-tunnel-action="close"]').click();
    expect(await page.evaluate(() => window.__adminPageTest.calls.tunnels)).toEqual([
      ['bielefeld" autofocus onfocus="window.__adminPageXss=5', 'open'],
      ['bielefeld" autofocus onfocus="window.__adminPageXss=5', 'close'],
    ]);
    expect(await page.evaluate(() => window.__adminPageTest.calls.focuses)).toEqual([
      ['bielefeld" autofocus onfocus="window.__adminPageXss=5', '++'],
    ]);
    expect(await page.evaluate(() => window.__adminPageTest.calls.captures)).toEqual([
      'bielefeld" autofocus onfocus="window.__adminPageXss=5',
    ]);
    await page.locator('[data-section-save="general"]').click();
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await page.getByRole('button', { name: 'Abbrechen', exact: true }).first().click();

    // Noch ein vollständiger Render darf die delegierte Abschnittsaktion nicht
    // verdoppeln. Danach prüfen wir Neuanlage, Löschen und Status-Nebenwirkungen.
    await page.evaluate(() => window.__adminPageTest.controller.render());
    await page.getByRole('button', { name: /Kameras \(1\)/ }).click();
    await page.getByRole('button', { name: 'Bearbeiten' }).click();
    await page.locator('[data-section-save="general"]').click();
    await page.getByRole('button', { name: 'Löschen' }).click();
    await page.locator('[data-action="nav-add-camera"]').click();
    await expect(page.locator('.camera-form[data-render-kind="create"]')).toBeVisible();
    await page.getByRole('button', { name: 'Status', exact: true }).click();

    const result = await page.evaluate(() => ({
      calls: window.__adminPageTest.calls,
      state: {
        activeTab: window.__adminPageTest.state.activeTab,
        selectedSlug: window.__adminPageTest.state.selectedSlug,
        isCreating: window.__adminPageTest.state.isCreating,
      },
    }));
    expect(result.calls.sectionSaves).toEqual([
      ['general', 'bielefeld" autofocus onfocus="window.__adminPageXss=5'],
      ['general', 'bielefeld" autofocus onfocus="window.__adminPageXss=5'],
    ]);
    expect(result.calls.formSaves).toBe(1);
    expect(result.calls.deleted).toEqual([
      'bielefeld" autofocus onfocus="window.__adminPageXss=5',
    ]);
    expect(result.calls.statusRefreshes).toBe(1);
    expect(result.calls.archiveRefreshes).toEqual(['bielefeld']);
    expect(result.state).toEqual({ activeTab: 'status', selectedSlug: null, isCreating: false });
    expect(result.calls.removedEditors).toBeGreaterThan(5);
    expect(result.calls.mapDestroyed).toBe(result.calls.removedEditors);
  });
});
