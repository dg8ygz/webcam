const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const CAPTURE_EDITOR_SCRIPT = path.join(
  REPOSITORY_ROOT,
  'assets/js/admin-capture-editor.js',
);

test.describe('Ausgelagerter Admin-Capture-Editor', () => {
  test('maskiert die DB-Vorschau, lädt einen Slot und speichert nur gültige Arrays', async ({ page }) => {
    await page.setContent('<main id="capture-root"></main>');
    await page.addScriptTag({ path: CAPTURE_EDITOR_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('capture-root');
      const slotTs = 1787866200;
      const state = {
        config: { years: [2025] },
        selectedSlug: 'bielefeld',
        dbPreview: {},
        dbLoading: {},
        captureEditor: {},
      };
      window.__captureApiCalls = [];
      window.__captureMessages = [];
      let controller = null;

      const captureResponse = {
        capture: {
          slot_ts: slotTs,
          captured_ts: slotTs + 4,
          relative_path: 'cache/bielefeld/2026/08/27/test.jpg',
          likes_count: 2,
          luminance: 41.5,
          hash_sha256: 'abcd1234',
        },
        variants: [{ width: 1920 }],
        exif: [{ tag: 'Model', value: 'Test' }],
        weather: [{ tag: 'Temperature', value: '20.1' }],
        logo: { detected: true, score: 0.9123, checked_at: slotTs },
      };
      const api = async (endpoint, options = {}) => {
        const call = {
          endpoint,
          method: options.method || 'GET',
          body: options.body || '',
        };
        window.__captureApiCalls.push(call);
        if (endpoint.endsWith('/db?limit=5')) {
          return {
            entries: [{
              slot_ts: slotTs,
              captured_at: '<img src=x onerror="window.__captureXss=1">',
              relative_path: '<svg onload="window.__captureXss=2">',
              likes: '<script>window.__captureXss=3</script>',
              luminance: 41.5,
              hash: 'abcdef0123456789',
              exif: [{ tag: '<b>Model</b>', value: '<img src=x>' }],
              weather: '<iframe srcdoc="x"></iframe>',
            }],
          };
        }
        if (endpoint.endsWith('/years')) return { years: [2025, 2026] };
        if (endpoint.includes(`slot_ts=${slotTs}`)) return captureResponse;
        if (options.method === 'PUT') {
          return { ...captureResponse, capture: { ...captureResponse.capture, ...JSON.parse(options.body) } };
        }
        return captureResponse;
      };
      const render = () => {
        root.innerHTML = controller.renderSection('bielefeld');
      };

      controller = window.WebcamAdminCaptureEditor.create({
        state,
        root,
        api,
        flash: (message, level) => window.__captureMessages.push({ message, level }),
        requestRender: render,
        escapeHtml: (value) => String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
      });
      controller.bind();
      window.__captureState = state;
      window.__captureSlot = slotTs;
      render();
    });

    await expect(page.locator('.table-compact tbody tr')).toHaveCount(1);
    await expect(page.locator('#capture-root img, #capture-root svg, #capture-root script, #capture-root iframe')).toHaveCount(0);
    expect(await page.evaluate(() => window.__captureXss)).toBeUndefined();
    await expect(page.locator('select[name="year"] option')).toHaveCount(2);

    // Gleichzeitige Neurenders während Preview und Jahresabruf dürfen je
    // Endpunkt nur einen Request auslösen.
    const initialCalls = await page.evaluate(() => window.__captureApiCalls.map((call) => call.endpoint));
    expect(initialCalls.filter((endpoint) => endpoint.endsWith('/db?limit=5'))).toHaveLength(1);
    expect(initialCalls.filter((endpoint) => endpoint.endsWith('/years'))).toHaveLength(1);

    const slot = await page.evaluate(() => String(window.__captureSlot));
    await page.locator('input[name="slot_ts"]').fill(slot);
    await page.locator('input[name="slot_ts"]').dispatchEvent('input');
    await page.getByRole('button', { name: 'Laden', exact: true }).click();
    await expect(page.locator('[data-capture-editor]')).toBeVisible();
    await expect(page.locator('input[name="relative_path"]')).toHaveValue(
      'cache/bielefeld/2026/08/27/test.jpg',
    );

    await page.getByRole('button', { name: 'JSON bearbeiten (Variants)' }).click();
    await page.locator('textarea[name="variants"]').fill('{"width":1920}');
    const callsBeforeInvalidSave = await page.evaluate(() => window.__captureApiCalls.length);
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    expect(await page.evaluate(() => window.__captureApiCalls.length)).toBe(callsBeforeInvalidSave);
    await expect.poll(() => page.evaluate(
      () => window.__captureMessages.at(-1)?.message || '',
    )).toContain('variants: muss ein Array sein');

    await page.locator('textarea[name="variants"]').fill('[{"width":1280}]');
    await page.locator('input[name="likes_count"]').fill('4');
    await page.locator('input[name="luminance"]').fill('39.25');
    await page.getByRole('button', { name: 'Speichern', exact: true }).click();
    await expect.poll(() => page.evaluate(
      () => window.__captureApiCalls.some((call) => call.method === 'PUT'),
    )).toBe(true);

    const saveCall = await page.evaluate(() => window.__captureApiCalls.find(
      (call) => call.method === 'PUT',
    ));
    expect(saveCall.endpoint).toBe(`/api/admin/cameras/bielefeld/captures/${slot}`);
    expect(JSON.parse(saveCall.body)).toEqual({
      captured_ts: 1787866204,
      relative_path: 'cache/bielefeld/2026/08/27/test.jpg',
      likes_count: 4,
      luminance: 39.25,
      hash_sha256: 'abcd1234',
      variants: [{ width: 1280 }],
      exif: [{ tag: 'Model', value: 'Test' }],
      weather: [{ tag: 'Temperature', value: '20.1' }],
    });
    await expect.poll(() => page.evaluate(
      () => window.__captureMessages.at(-1)?.message || '',
    )).toBe('Capture gespeichert');
  });
});
