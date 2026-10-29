const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8082';
const ADMIN_TOKEN = process.env.ADMIN_TOKEN || 'unittest-token';

test.use({
  extraHTTPHeaders: {
    Authorization: `Bearer ${ADMIN_TOKEN}`,
  },
});

const adminUrl = () => new URL('admin/?test=1', `${BASE_URL.replace(/\/$/, '')}/`).toString();

const openScriptsWorkspace = async (page) => {
  await page.goto(adminUrl(), { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Scripte', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Script-Arbeitsfläche' })).toBeVisible();
};

test.describe('Admin script workspace', () => {
  test('keeps timeline controls without a top-level timeline tab', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.addInitScript(() => localStorage.setItem('adminActiveTab', 'timeline'));
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });

    const adminScripts = await page.locator('script[src*="/js/admin"]').evaluateAll((scripts) =>
      scripts.map((script) => script.getAttribute('src')),
    );
    expect(adminScripts).toHaveLength(13);
    expect(adminScripts[0]).toContain('admin-status.js?v=');
    expect(adminScripts[1]).toContain('admin-maintenance.js?v=');
    expect(adminScripts[2]).toContain('admin-archive-cache.js?v=');
    expect(adminScripts[3]).toContain('admin-settings.js?v=');
    expect(adminScripts[4]).toContain('admin-capture-editor.js?v=');
    expect(adminScripts[5]).toContain('admin-map-preview.js?v=');
    expect(adminScripts[6]).toContain('admin-overlay-editor.js?v=');
    expect(adminScripts[7]).toContain('admin-label-editor.js?v=');
    expect(adminScripts[8]).toContain('admin-form-validation.js?v=');
    expect(adminScripts[9]).toContain('admin-camera-editor.js?v=');
    expect(adminScripts[10]).toContain('admin-camera-payload.js?v=');
    expect(adminScripts[11]).toContain('admin-page.js?v=');
    expect(adminScripts[12]).toContain('admin.js?v=');
    const adminScriptRevisions = adminScripts.map((src) => new URL(src, page.url()).searchParams.get('v'));
    expect(new Set(adminScriptRevisions)).toEqual(new Set(['159']));
    await expect(page.locator('.rail-sub')).toContainText(/Stand .+ · Iteration 159/);

    await expect(page.getByRole('button', { name: 'Timeline', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Meta', exact: true })).toHaveClass(/is-active/);
    await expect(page.getByLabel('Timeline-Limit')).toBeVisible();

    await page.getByRole('button', { name: 'Status', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Livestreams' })).toBeVisible();
    const legacyDetails = page.locator('details').filter({ hasText: 'Legacy Timeline-Cache (Diagnose)' });
    await expect(legacyDetails).not.toHaveAttribute('open', '');
    await legacyDetails.locator('summary').click();
    await expect(page.getByRole('button', { name: 'Cache-Status aktualisieren' })).toBeVisible();
  });

  test('initializes the extracted camera map without changing the editor layout', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Kameras \(/ }).click();
    await page.locator('button.edit').first().click();

    // Der ausgelagerte Renderer muss im echten Admin weiterhin alle zehn
    // bearbeitbaren Fachbereiche und die lesende DB-Diagnose liefern.
    await expect(page.locator('.camera-form [data-tab-target]')).toHaveCount(11);
    await expect(page.locator('.camera-form [data-section-save]')).toHaveCount(10);
    await expect(page.locator('.camera-form [name="slug"]')).toHaveAttribute('readonly', '');
    await expect(page.locator('.camera-form [data-tab-panel="db"]')).toHaveCount(1);

    await page.locator('.camera-form [data-tab-target="map"]').click();

    const preview = page.locator('#camera-map-preview');
    await expect(preview).toBeVisible();
    await expect(preview.locator('.map-preview__leaflet, canvas')).toHaveCount(1);
    const bounds = await preview.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds.width).toBeGreaterThan(300);

    // Noch ungespeicherte Eingaben aktualisieren ausschließlich die Vorschau;
    // der Kameraeditor bleibt dabei vollständig sichtbar und bedienbar.
    await page.locator('.camera-form [name="orientation_deg"]').fill('123');
    await expect(preview.locator('.map-preview__leaflet, canvas')).toHaveCount(1);
    await expect(page.locator('.camera-form')).toBeVisible();
  });

  test('sends a camera section through the shared payload builder', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Kameras \(/ }).click();
    await page.locator('button.edit').first().click();

    const form = page.locator('.camera-form');
    const slug = await form.locator('[name="slug"]').inputValue();
    const sectionPath = `cameras/${slug}/sections/general`;
    await page.route('**/admin/api.php?*', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).searchParams.get('path');
      if (request.method() !== 'PUT' || path !== sectionPath) {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true }),
      });
    });

    await form.locator('[name="name"]').fill('Payload E2E');
    const sectionRequest = page.waitForRequest((request) => {
      const path = new URL(request.url()).searchParams.get('path');
      return request.method() === 'PUT' && path === sectionPath;
    });
    await form.locator('[data-tab-panel="general"] [data-section-save="general"]').click();
    const request = await sectionRequest;
    const payload = request.postDataJSON();

    // Ein Abschnitt darf ausschließlich seine eigenen Felder senden. So wird
    // beim Speichern von Allgemein weder Capture noch Speicher überschrieben.
    expect(Object.keys(payload).sort()).toEqual(['name', 'short_title', 'tags', 'title']);
    expect(payload.name).toBe('Payload E2E');
    expect(Array.isArray(payload.tags)).toBe(true);
    await expect(page.getByText('Sektion "general" gespeichert').first()).toBeVisible();
  });

  test('uploads overlays as CSRF-protected multipart data', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Kameras \(/ }).click();
    await page.locator('button.edit').first().click();
    await page.locator('.camera-form [data-tab-target="overlay"]').click();
    const slug = await page.locator('.camera-form [name="slug"]').inputValue();

    await page.route('**/admin/api.php?*', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).searchParams.get('path');
      if (request.method() !== 'POST' || path !== `cameras/${slug}/overlay`) {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          relative_path: `overlay.php?slug=${encodeURIComponent(slug)}&file=e2e.png`,
          width: 16,
          height: 8,
        }),
      });
    });

    const uploadRequest = page.waitForRequest((request) => {
      const path = new URL(request.url()).searchParams.get('path');
      return request.method() === 'POST' && path === `cameras/${slug}/overlay`;
    });
    await page.locator('#overlay-file').setInputFiles({
      name: 'e2e.png',
      mimeType: 'image/png',
      buffer: Buffer.from('e2e-overlay'),
    });
    const request = await uploadRequest;
    expect(request.headers()['content-type']).toContain('multipart/form-data; boundary=');
    expect(request.headers()['x-csrf-token']).toBeTruthy();
    await expect(page.locator('#overlay-url')).toHaveValue(
      `overlay.php?slug=${slug}&file=e2e.png`,
    );
    await expect(page.getByText('Overlay hochgeladen').first()).toBeVisible();
  });

  test('adds and removes labels through the extracted editor', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Kameras \(/ }).click();
    await page.locator('button.edit').first().click();
    await page.locator('.camera-form [data-tab-target="labels"]').click();

    const rows = page.locator('.camera-form [data-label-row]');
    const initialCount = await rows.count();
    await page.locator('.camera-form [data-label-add]').click();
    await expect(rows).toHaveCount(initialCount + 1);

    const addedRow = rows.last();
    await addedRow.locator('[data-label-field="x"]').fill('25');
    await addedRow.locator('[data-label-field="y"]').fill('40');
    await addedRow.locator('[data-label-field="text"]').fill('Testlabel');
    await addedRow.locator('[data-label-field="color"]').selectOption('custom');
    await expect(addedRow.locator('[data-label-field="color_custom"]')).toBeVisible();
    await addedRow.locator('[data-label-field="color_custom"]').fill('#123456');

    await addedRow.locator('[data-label-remove]').click();
    await expect(rows).toHaveCount(initialCount);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });

  test('shows camera validation errors before an API request', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(adminUrl(), { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Kameras \(/ }).click();
    await page.locator('#btn-add-camera').click();

    const form = page.locator('.camera-form');
    const slug = form.locator('[name="slug"]');
    let cameraWriteRequests = 0;
    page.on('request', (request) => {
      const path = new URL(request.url()).searchParams.get('path') || '';
      if (request.method() === 'POST' && path === 'cameras') {
        cameraWriteRequests += 1;
      }
    });
    await slug.fill('Ungültiger Slug');
    await form.evaluate((node) => node.dispatchEvent(new Event('submit', {
      bubbles: true,
      cancelable: true,
    })));

    await expect(slug.locator('xpath=..')).toHaveClass(/has-error/);
    await expect(slug.locator('xpath=..').locator('.form-error')).toContainText(
      'Nur Kleinbuchstaben, Zahlen und Bindestriche',
    );
    await expect(slug).toBeFocused();
    await expect(form.locator('[data-tab-target="general"]')).toHaveClass(/is-active/);
    expect(cameraWriteRequests).toBe(0);
  });

  test('shows all built-in schedules and persists changes', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await openScriptsWorkspace(page);

    await expect(page.getByRole('heading', { name: 'Archiv-Rollover' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Datenbank-Backup' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Wetterdaten Vortag' })).toBeVisible();

    // Der Test darf nicht vom aktuellen Inhalt der als Temp-Kopie verwendeten
    // Config-DB abhängen. Beide später geprüften Aus-Werte daher ausdrücklich
    // setzen, bevor der Persistenzvertrag ausgelöst wird.
    await page.locator('[name="archive_rollover_enabled"]').uncheck();
    await page.locator('[name="weather_quality_enabled"]').uncheck();
    await page.locator('[name="backup_daily_time"]').fill('04:15');
    await page.locator('[name="weather_quality_time"]').fill('04:35');

    const responsePromise = page.waitForResponse((response) =>
      response.url().includes('path=config%2Fmaintenance-scripts')
        && response.request().method() === 'PUT'
    );
    await page.getByRole('button', { name: 'Scripte speichern' }).click();
    await expect((await responsePromise).status()).toBe(200);
    await expect(page.getByText('Scripte gespeichert').first()).toBeVisible();

    await page.reload({ waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Scripte', exact: true }).click();
    await expect(page.locator('[name="archive_rollover_enabled"]')).not.toBeChecked();
    await expect(page.locator('[name="backup_daily_enabled"]')).toBeChecked();
    await expect(page.locator('[name="weather_quality_enabled"]')).not.toBeChecked();
    await expect(page.locator('[name="backup_daily_time"]')).toHaveValue('04:15');
    await expect(page.locator('[name="weather_quality_time"]')).toHaveValue('04:35');
  });

  test('adds a script with editable path and arguments', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await openScriptsWorkspace(page);

    await page.getByRole('button', { name: 'Script hinzufügen' }).click();
    const customCard = page.locator('.script-card').last();
    await customCard.locator('[data-script-field="label"]').fill('Overlay-Bereinigung');
    await customCard.locator('[data-script-field="path"]').fill('bin/cleanup_overlays.php');
    await customCard.locator('[data-script-field="arguments"]').fill('--delete\n--verbose');
    await customCard.locator('[data-script-field="time"]').fill('05:00');

    const responsePromise = page.waitForResponse((response) =>
      response.url().includes('path=config%2Fmaintenance-scripts')
        && response.request().method() === 'PUT'
    );
    await page.getByRole('button', { name: 'Scripte speichern' }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    const payload = response.request().postDataJSON();
    const custom = Object.values(payload).find((entry) => entry.label === 'Overlay-Bereinigung');
    expect(custom.path).toBe('bin/cleanup_overlays.php');
    expect(custom.arguments).toEqual(['--delete', '--verbose']);
  });

  test('fits a narrow mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openScriptsWorkspace(page);

    const cards = page.locator('.script-card');
    expect(await cards.count()).toBeGreaterThanOrEqual(3);
    const first = await cards.nth(0).boundingBox();
    const second = await cards.nth(1).boundingBox();
    expect(first).not.toBeNull();
    expect(second).not.toBeNull();
    expect(second.y).toBeGreaterThan(first.y + first.height - 2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
});
