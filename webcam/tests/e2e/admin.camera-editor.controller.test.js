const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const CAMERA_EDITOR_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-camera-editor.js');

test.describe('Ausgelagerter Admin-Kameraeditor', () => {
  test('rendert alle Fachabschnitte, maskiert Configwerte und delegiert Untereditoren', async ({ page }, testInfo) => {
    await page.setContent('<main id="camera-editor-root"></main>');
    await page.addScriptTag({ path: CAMERA_EDITOR_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('camera-editor-root');
      const internalTags = {
        nativeDisplay: '__native_display',
        nightDenoise: '__night_denoise',
        testCamera: '__test_camera',
      };
      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
      const defaultCamera = () => ({
        slug: '',
        name: '',
        title: '',
        short_title: '',
        description: '',
        info_html: '',
        tags: [],
        labels: [],
        mode: 'active-capture',
        capture: {
          transport: 'http-get',
          endpoint: '',
          interval_seconds: 600,
          timeout_seconds: 10,
          expected_response: 200,
          extra_times: [],
          upload_key: '',
          active_upload_fallback_failures: 0,
        },
        stream: { mode: 'none', fps: '', quality: '', max_clients: '' },
        location: {},
        storage: {},
        weather_commands: [],
        status: {},
      });
      const camera = {
        ...defaultCamera(),
        slug: 'bielefeld',
        name: '<img src=x onerror="window.__cameraEditorXss=1">Bielefeld',
        title: 'Titel" autofocus onfocus="window.__cameraEditorXss=2',
        short_title: 'BI',
        description: '<svg><script>window.__cameraEditorXss=3</script></svg>',
        info_html: '<p onclick="window.__cameraEditorXss=4">Info</p>',
        tags: ['stadt', '__native_display', '__night_denoise', '__test_camera'],
        labels: [{ text: 'Delegierter Inhalt' }],
        mode: 'active-capture',
        capture: {
          transport: 'rtsp',
          endpoint: 'rtsp://camera.invalid/live?x=" onerror="window.__cameraEditorXss=5',
          interval_seconds: 600,
          timeout_seconds: 10,
          expected_response: 200,
          extra_times: ['06:10', '18:20'],
          upload_key: 'secret<&>',
          helper_upload_mode: 'chunked',
          auto_iso: { mode: 'observe', min_iso: 100, max_iso: 800, night_ev_max: 2 },
          active_upload_fallback_failures: 3,
        },
        stream: { mode: 'rtsp', fps: 3, quality: 5, max_clients: 2 },
        location: {
          latitude: 52.03,
          longitude: 8.53,
          orientation_deg: 180,
          field_of_view_deg: 60,
        },
        storage: {
          base_path: 'cache/bielefeld',
          current_dir: 'current',
          archive_dir: 'archive',
          best_of_dir: 'bestof',
        },
        weather_commands: ['#!/bin/sh\necho weather'],
        status: {
          watchdog: {
            enabled: true,
            max_consecutive_failures: 4,
            reset_sequence: [{ command: 'reset' }],
            cooldown: { min_successes_after_reset: 2, min_seconds_after_reset: 900 },
          },
        },
      };
      const calls = { overlays: [], labels: 0, captures: [] };
      const controller = window.WebcamAdminCameraEditor.create({
        state: {
          selectedSlug: camera.slug,
          config: { defaults: { resolutions: [800, 1600] }, cameras: { [camera.slug]: camera } },
          editorTabs: { [camera.slug]: 'map' },
          sectionSaving: { [camera.slug]: { weather: true } },
          cameraRuntime: {
            [camera.slug]: {
              current_path: '/var/www/html/webcam/bielefeld/current',
              writable: false,
              provision_required: true,
              provision_command: '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply',
            },
          },
        },
        defaultCamera,
        splitCameraTags: (tags) => ({
          visible: tags.filter((tag) => !String(tag).startsWith('__')),
          internal: tags.filter((tag) => String(tag).startsWith('__')),
        }),
        cameraHasInternalTag: (candidate, tag) => candidate.tags.includes(tag),
        internalCameraTags: internalTags,
        escapeHtml,
        overlayEditor: {
          renderVariantSection: (options) => {
            calls.overlays.push(options);
            return `<div data-overlay-stub="${escapeHtml(options.variant)}"></div>`;
          },
        },
        labelEditor: {
          renderRows: () => {
            calls.labels += 1;
            return '<tr data-label-stub><td>Labels</td></tr>';
          },
        },
        captureEditor: {
          renderSection: (slug) => {
            calls.captures.push(slug);
            return '<div data-capture-stub>Captures</div>';
          },
        },
      });

      root.innerHTML = controller.render(camera, { inline: true, activeTab: 'labels' });
      window.__cameraEditor = { controller, calls, camera };
    });

    // Vorhandene Kameras besitzen zehn speicherbare Fachbereiche plus die
    // ausschließlich lesende DB-Diagnose. Der explizit gewählte Tab gewinnt
    // dabei gegen den zuvor im Browserzustand gemerkten Tab.
    await expect(page.locator('[data-tab-target]')).toHaveCount(11);
    await expect(page.locator('[data-tab-panel]')).toHaveCount(11);
    await expect(page.locator('[data-section-save]')).toHaveCount(10);
    await expect(page.locator('[data-tab-target="labels"]')).toHaveClass(/is-active/);
    await expect(page.locator('[data-tab-panel="labels"]')).toHaveClass(/is-active/);
    await expect(page.locator('[data-tab-panel="db"] [data-capture-stub]')).toBeVisible();
    await expect(page.locator('[data-section-save="weather"]')).toBeDisabled();
    await expect(page.locator('[name="auto_iso_max"]')).toHaveValue('800');
    await expect(page.locator('[name="night_ev_max"]')).toHaveValue('2');

    // Interne Steuer-Tags werden verständlich als Schalter dargestellt und
    // dürfen nicht versehentlich in das öffentliche Tag-Freitextfeld geraten.
    await expect(page.locator('[name="tags"]')).toHaveValue('stadt');
    await expect(page.locator('[name="native_display"]')).toBeChecked();
    await expect(page.locator('[name="night_denoise"]')).toBeChecked();
    await expect(page.locator('[name="test_camera"]')).toBeChecked();

    // Administrative Inhalte bleiben editierbar, erzeugen aber weder Elemente
    // noch Ereignisattribute. Damit ist der Renderer auch gegenüber alten,
    // bereits verunreinigten Configwerten fail-closed.
    await expect(page.locator('#camera-editor-root img, #camera-editor-root script')).toHaveCount(0);
    await expect(page.locator('#camera-editor-root [onerror], #camera-editor-root [onfocus], #camera-editor-root [onclick]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__cameraEditorXss)).toBeUndefined();
    await expect(page.locator('[name="name"]')).toHaveValue('<img src=x onerror="window.__cameraEditorXss=1">Bielefeld');
    await expect(page.locator('[name="description"]')).toHaveValue('<svg><script>window.__cameraEditorXss=3</script></svg>');
    await expect(page.locator('[name="info_html"]')).toHaveValue('<p onclick="window.__cameraEditorXss=4">Info</p>');
    await expect(page.locator('[name="active_upload_fallback_failures"]')).toHaveValue('3');
    await expect(page.locator('[name="helper_upload_mode"]')).toHaveValue('chunked');
    await expect(page.locator('[name="auto_iso_mode"]')).toHaveValue('observe');
    await expect(page.locator('[name="auto_iso_min"]')).toHaveValue('100');
    await expect(page.locator('[name="auto_iso_max"]')).toHaveValue('800');
    await expect(page.getByText('Current-Pfad noch nicht schreibbar.')).toBeVisible();
    await expect(page.getByText('/usr/local/sbin/webcam-sync-apache-runtime-paths --apply')).toBeVisible();

    // RTSP-Eingaben müssen in der bestehenden RTSP-Kamera sofort verfügbar
    // sein; die eigentliche Umschaltlogik bleibt in der Validierungskomponente.
    await expect(page.locator('[data-rtsp-stream-setting]').first()).toBeVisible();
    await expect(page.locator('[data-rtsp-stream-field]').first()).toBeEnabled();
    expect(await page.evaluate(() => window.__cameraEditor.calls)).toEqual({
      overlays: [
        expect.objectContaining({ variant: 'light', slug: 'bielefeld', isCreating: false }),
        expect.objectContaining({ variant: 'dark', slug: 'bielefeld', isCreating: false }),
      ],
      labels: 1,
      captures: ['bielefeld'],
    });

    // Eine Neuanlage verwendet denselben Renderer, enthält aber bewusst keine
    // DB-Diagnose und keine abschnittsweisen Speicherknöpfe.
    await page.evaluate(() => {
      const root = document.getElementById('camera-editor-root');
      root.innerHTML = window.__cameraEditor.controller.render(null, { creating: true, inline: true });
    });
    await expect(page.locator('[data-tab-target]')).toHaveCount(10);
    await expect(page.locator('[data-tab-panel="db"]')).toHaveCount(0);
    await expect(page.locator('[data-section-save]')).toHaveCount(0);
    await expect(page.locator('[name="slug"]')).toBeEditable();
    await expect(page.locator('[name="test_camera"]')).not.toBeChecked();
    await expect(page.getByRole('button', { name: 'Kamera anlegen' })).toBeVisible();
    await expect(page.locator('[data-rtsp-stream-setting]').first()).toBeHidden();
    await expect(page.locator('[data-rtsp-stream-field]').first()).toBeDisabled();
    await page.getByRole('button', { name: 'Speicher' }).click();
    await expect(page.getByText('Das Current-Verzeichnis wird beim Anlegen automatisch eingerichtet.', { exact: false })).toBeVisible();

    // Zusätzlich den neuen Watchdog-Hinweis mit echten Admin-Styles prüfen.
    // Der reine Renderer bindet keine Tab-Controller; aktiven Tab explizit setzen.
    await page.addStyleTag({ path: path.join(REPOSITORY_ROOT, 'assets/vendor/bootstrap/bootstrap.min.css') });
    await page.addStyleTag({ path: path.join(REPOSITORY_ROOT, 'assets/css/admin.css') });
    await page.evaluate(() => {
      document.getElementById('camera-editor-root').innerHTML = window.__cameraEditor.controller.render(window.__cameraEditor.camera, { inline: true, activeTab: 'capture' });
    });
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(page.locator('[data-tunnel-action="open"]')).toBeVisible();
      await expect(page.locator('[data-tunnel-action="open"]')).toBeDisabled();
      await expect(page.locator('[data-tunnel-action="close"]')).toBeVisible();
      const help = page.locator('.tunnel-help');
      await help.locator('summary').click();
      await expect(help.getByText('ssh -p 22101 admin@127.0.0.1', { exact: true })).toBeVisible();
      await expect(help.locator('pre').last()).toContainText('-L 127.0.0.1:8086:127.0.0.1:80');
      await expect(help).toContainText('http://localhost:8086/camera-admin/');
      await expect(help).toContainText('Andere Kameras benötigen ihren separat zugewiesenen Relay-Port');
      await expect(help).toContainText('30 Minuten ab Freigabe');
      expect(await help.evaluate(element => element.scrollWidth <= element.clientWidth)).toBeTruthy();
      await page.screenshot({ path: testInfo.outputPath(`tunnel-help-${width}.png`), fullPage: true });
      await help.locator('summary').click();
      await expect(page.locator('[name="helper_upload_mode"]')).toBeVisible();
      await expect(page.getByRole('button', { name: 'Jetzt aufnehmen – bielefeld' })).toBeVisible();
      await expect(page.locator('[data-focus-camera="bielefeld"]')).toHaveCount(5);
      for (const mode of ['--', '-', 'auto', '+', '++']) {
        await expect(page.getByRole('button', { name: `Fokus ${mode}`, exact: true })).toBeVisible();
      }
      await page.screenshot({ path: testInfo.outputPath(`capture-controls-${width}.png`), fullPage: true });
    }
    await page.evaluate(() => {
      document.getElementById('camera-editor-root').innerHTML = window.__cameraEditor.controller.render(null, { creating: true, inline: true, activeTab: 'watchdog' });
    });
    await expect(page.locator('[name="reset_sequence"]')).toHaveAttribute('aria-describedby', /watchdog-help/);
    await expect(page.locator('[name="watchdog_failures"]')).toHaveAttribute('min', '1');
    await expect(page.locator('[name="cooldown_seconds"]')).toHaveAttribute('min', '0');
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(page.getByText('Maximal 20 Schritte.', { exact: false })).toBeVisible();
      expect(await page.locator('[id$="watchdog-help"]').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBeTruthy();
      await page.screenshot({ path: testInfo.outputPath(`watchdog-${width}.png`), fullPage: true });
    }
    await page.evaluate(() => {
      document.getElementById('camera-editor-root').innerHTML = window.__cameraEditor.controller.render(null, { creating: true, inline: true, activeTab: 'general' });
    });
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      const checkbox = page.getByRole('checkbox', { name: /Testkamera/ });
      await expect(checkbox).toBeVisible();
      await checkbox.check();
      await expect(checkbox).toBeChecked();
      expect(await checkbox.evaluate(element => element.closest('.form-field').scrollWidth <= element.closest('.form-field').clientWidth)).toBeTruthy();
      await page.screenshot({ path: testInfo.outputPath(`test-camera-${width}.png`), fullPage: true });
    }
  });
});
