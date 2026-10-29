const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const MAINTENANCE_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-maintenance.js');

test.describe('Ausgelagerte Admin-Wartungsarbeitsfläche', () => {
  test('erzeugt Defaults, ergänzt Scripte und verwirft unsichere Pfade vor der API', async ({ page }) => {
    await page.setContent('<main id="maintenance-root"></main>');
    await page.addScriptTag({ path: MAINTENANCE_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('maintenance-root');
      const state = {
        config: { maintenance_scripts: null },
        scripts: { status: {}, definitions: {}, trigger: {} },
        savingScripts: false,
      };
      window.__maintenanceApiCalls = [];
      window.__maintenanceMessages = [];
      let controller = null;

      const render = () => {
        if (!controller) return;
        root.innerHTML = controller.renderSection();
        controller.bind();
      };
      controller = window.WebcamAdminMaintenance.create({
        state,
        root,
        api: async (endpoint, options = {}) => {
          window.__maintenanceApiCalls.push({ endpoint, body: options.body || '' });
          return JSON.parse(options.body || '{}');
        },
        flash: (message, level) => window.__maintenanceMessages.push({ message, level }),
        requestRender: render,
        escapeHtml: (value) => String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
        formatDateTime: (value) => value || '–',
        requestAnimationFrame: (callback) => callback(),
      });
      controller.hydrate(null);
      window.__maintenanceState = state;
      render();
    });

    await expect(page.locator('.script-card')).toHaveCount(3);
    await page.getByRole('button', { name: 'Script hinzufügen' }).click();
    await expect(page.locator('.script-card')).toHaveCount(4);

    const customCard = page.locator('.script-card').last();
    await customCard.locator('[data-script-field="path"]').fill('../evil.sh');
    await page.getByRole('button', { name: 'Scripte speichern' }).click();

    expect(await page.evaluate(() => window.__maintenanceApiCalls.length)).toBe(0);
    await expect.poll(() => page.evaluate(() => window.__maintenanceMessages.at(-1)?.message || ''))
      .toContain('relativ unter bin/');

    await customCard.locator('[data-script-field="path"]').fill('bin/cleanup_overlays.php');
    await page.getByRole('button', { name: 'Scripte speichern' }).click();
    await expect.poll(() => page.evaluate(() => window.__maintenanceApiCalls.length)).toBe(1);

    const savedPayload = await page.evaluate(() => JSON.parse(window.__maintenanceApiCalls[0].body));
    const customTask = Object.keys(savedPayload).find((task) => task.startsWith('script_'));
    expect(customTask).toBeTruthy();
    expect(savedPayload[customTask].path).toBe('bin/cleanup_overlays.php');
  });

  test('zeigt beim Klick nur die Logeinträge des fehlerhaften Scripts', async ({ page }) => {
    await page.setContent('<main id="maintenance-root"></main>');
    await page.addScriptTag({ path: MAINTENANCE_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('maintenance-root');
      const state = {
        config: { maintenance_scripts: null },
        scripts: { status: {}, definitions: {}, trigger: {} },
        savingScripts: false,
      };
      let controller = null;
      const render = () => {
        root.innerHTML = controller.renderSection();
        controller.bind();
      };
      controller = window.WebcamAdminMaintenance.create({
        state,
        root,
        api: async () => ({}),
        flash: () => {},
        requestRender: render,
        escapeHtml: (value) => String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
        formatDateTime: (value) => value || '–',
      });
      controller.hydrate({
        status: {
          archive_rollover: {
            status: 'error',
            finished_at: '2026-08-30T13:17:24+02:00',
            exit_code: 255,
            log_entries: ['Archiv Zeile 1', 'Archiv Zeile 2', '<img src=x onerror="window.__logXss=true">'],
          },
          backup_daily: {
            status: 'error',
            finished_at: '2026-08-30T13:20:00+02:00',
            exit_code: 1,
            log_entries: ['Backup darf nicht im Archiv erscheinen'],
          },
        },
        definitions: {},
        trigger: {},
      });
      render();
    });

    const archiveCard = page.locator('[data-script-id="archive_rollover"]');
    await expect(archiveCard.locator('.script-status__log')).toHaveCount(0);
    await archiveCard.getByRole('button', { name: 'error' }).click();
    await expect(archiveCard.locator('.script-status__log')).toContainText('Archiv Zeile 1');
    await expect(archiveCard.locator('.script-status__log')).toContainText('Archiv Zeile 2');
    await expect(archiveCard.locator('.script-status__log')).not.toContainText('Backup darf nicht');
    await expect(archiveCard.locator('.script-status__log img')).toHaveCount(0);
    expect(await page.evaluate(() => window.__logXss === true)).toBe(false);
    await expect(archiveCard.getByRole('button', { name: 'error' })).toHaveAttribute('aria-expanded', 'true');
  });
});
