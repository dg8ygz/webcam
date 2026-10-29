const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const SETTINGS_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-settings.js');

test.describe('Ausgelagerte globale Admin-Einstellungen', () => {
  test('zeigt sichere Defaults und sendet nur vollständig validierte Werte', async ({ page }) => {
    await page.setContent('<main id="settings-root"></main>');
    await page.addScriptTag({ path: SETTINGS_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('settings-root');
      const state = {
        config: {
          version: '<img src=x onerror="window.__settingsXss=1">',
          overview_hero: '<img src=x onerror="window.__settingsXss=2">Willkommen',
        },
        savingRateLimit: false,
        savingTimeline: false,
        savingRuntime: false,
        savingHero: false,
        savingMap: false,
      };
      window.__settingsApiCalls = [];
      window.__settingsMessages = [];
      let controller = null;

      const render = () => {
        root.innerHTML = [
          controller.renderMetaSection({
            version: state.config.version,
            updatedAt: 'gefährlich',
            apiBase: '<svg onload="window.__settingsXss=3">',
          }),
          controller.renderRateSection(),
        ].join('');
        controller.bind();
      };
      const api = async (endpoint, options = {}) => {
        const payload = JSON.parse(options.body || '{}');
        window.__settingsApiCalls.push({ endpoint, method: options.method || 'GET', payload });
        return payload;
      };

      controller = window.WebcamAdminSettings.create({
        state,
        root,
        api,
        flash: (message, level) => window.__settingsMessages.push({ message, level }),
        requestRender: render,
        escapeHtml: (value) => String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
        formatDateTime: () => '<script>window.__settingsXss=4</script>',
      });
      controller.hydrate();
      window.__settingsState = state;
      render();
    });

    // Fehlende Alt-Konfiguration wird nur sichtbar ergänzt. Hydrierung allein
    // darf keinen schreibenden Request auslösen.
    await expect(page.getByLabel('Requests pro Fenster')).toHaveValue('120');
    await expect(page.getByLabel('Timeline-Limit')).toHaveValue('150');
    await expect(page.getByLabel('Wetter-Command Timeout (Sek.)')).toHaveValue('20');
    expect(await page.evaluate(() => window.__settingsApiCalls.length)).toBe(0);

    // Alle in HTML-Kontext ausgegebenen Configwerte bleiben Text/Formularwert.
    await expect(page.locator('#settings-root img, #settings-root svg, #settings-root script')).toHaveCount(0);
    expect(await page.evaluate(() => window.__settingsXss)).toBeUndefined();
    await expect(page.getByLabel('Übersichts-Teaser')).toHaveValue(
      '<img src=x onerror="window.__settingsXss=2">Willkommen',
    );

    // Programmgesteuerte Submits umgehen die native required-Prüfung. Auch
    // dann darf ein leeres Koordinatenfeld nicht als Number("") === 0 gelten.
    await page.getByLabel('Kartenmittelpunkt (Lat)').fill('');
    await page.locator('#map-form').evaluate((form) => form.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    ));
    expect(await page.evaluate(() => window.__settingsApiCalls.length)).toBe(0);
    await expect.poll(() => page.evaluate(
      () => window.__settingsMessages.at(-1)?.message || '',
    )).toContain('gültige Kartenwerte');

    await page.getByLabel('Kartenmittelpunkt (Lat)').fill('52.0123');
    await page.getByLabel('Kartenmittelpunkt (Lon)').fill('8.5432');
    await page.getByLabel('Zoom (1–18)').fill('12');
    await page.getByLabel('Höhe (px)').fill('260');
    await page.locator('#map-form').evaluate((form) => form.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    ));
    await expect.poll(() => page.evaluate(() => window.__settingsApiCalls.length)).toBe(1);

    await page.getByLabel('Requests pro Fenster').fill('90');
    await page.getByLabel('Fenster (Sek.)').fill('45');
    await page.getByLabel('Aufbewahrung (Tage)').fill('5');
    await page.locator('#rate-limit-form').evaluate((form) => form.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    ));
    await expect.poll(() => page.evaluate(() => window.__settingsApiCalls.length)).toBe(2);

    const calls = await page.evaluate(() => window.__settingsApiCalls);
    expect(calls).toEqual([
      {
        endpoint: '/api/admin/config/overview-map',
        method: 'PUT',
        payload: { lat: 52.0123, lng: 8.5432, zoom: 12, height_px: 260 },
      },
      {
        endpoint: '/api/admin/config/public-rate-limit',
        method: 'PUT',
        payload: { limit: 90, window_seconds: 45, ttl_days: 5 },
      },
    ]);
  });
});
