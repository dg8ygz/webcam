const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const ARCHIVE_CACHE_SCRIPT = path.join(
  REPOSITORY_ROOT,
  'assets/js/admin-archive-cache.js',
);

test.describe('Ausgelagerte Admin-Archivcache-Diagnose', () => {
  test('maskiert Status, prüft Rebuild-Daten und pollt nur einen laufenden Job', async ({ page }) => {
    await page.setContent('<main id="archive-cache-root"></main>');
    await page.addScriptTag({ path: ARCHIVE_CACHE_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('archive-cache-root');
      const state = {
        config: { archive_cache: { enabled: true, last_built_at: null } },
        cacheStatus: {},
        cacheForm: { slug: '', year: '', dry: false },
        cacheBusy: false,
        savingArchiveCache: false,
        activeTab: 'status',
        selectedSlug: null,
      };
      window.__archiveCacheCalls = [];
      window.__archiveCacheMessages = [];
      window.__archiveCacheIntervals = [];
      let controller = null;

      const render = () => {
        root.innerHTML = controller.renderSection([
          { slug: 'bielefeld' },
          { slug: '<img src=x onerror="window.__cacheXss=1">' },
        ]);
        controller.bind();
        controller.syncPolling();
      };
      const api = async (endpoint, options = {}) => {
        window.__archiveCacheCalls.push({
          endpoint,
          method: options.method || 'GET',
          body: options.body || '',
        });
        if (endpoint === '/api/admin/archive-cache/rebuild') {
          return { status: 'started' };
        }
        if (endpoint.startsWith('/api/admin/archive-cache')) {
          return {
            enabled: true,
            admin_allowed: true,
            scope: 'bielefeld',
            size_bytes: 1024,
            rebuild_state: {
              status: 'running',
              last_message: '<img src=x onerror="window.__cacheXss=1">läuft',
              current: { slug: 'bielefeld', year: 2026, progress: 12 },
            },
          };
        }
        return {};
      };

      controller = window.WebcamAdminArchiveCache.create({
        state,
        root,
        api,
        getCsrfToken: () => 'csrf-test',
        flash: (message, level) => window.__archiveCacheMessages.push({ message, level }),
        requestRender: render,
        escapeHtml: (value) => String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
        formatDateTime: (value) => value || '–',
        formatBytes: (value) => `${value} B`,
        setIntervalFn: (callback, delay) => {
          window.__archiveCacheIntervals.push({ callback, delay });
          return window.__archiveCacheIntervals.length;
        },
        clearIntervalFn: () => {},
        notifyConflict: (message) => window.__archiveCacheMessages.push({ message, level: 'warn' }),
      });
      controller.hydrate({
        enabled: true,
        admin_allowed: true,
        scope: 'bielefeld',
        rebuild_state: {
          status: 'running',
          last_message: '<img src=x onerror="window.__cacheXss=1">läuft',
          current: { slug: 'bielefeld', year: 2026, progress: 12 },
        },
      });
      window.__archiveCacheController = controller;
      render();
    });

    await page.getByText('Legacy Timeline-Cache (Diagnose)').click();
    await expect(page.getByText(/läuft/)).toBeVisible();
    await expect(page.locator('#archive-cache-root img')).toHaveCount(0);
    expect(await page.evaluate(() => window.__cacheXss)).toBeUndefined();
    expect(await page.evaluate(() => window.__archiveCacheIntervals.at(-1)?.delay)).toBe(5000);

    await page.getByLabel('Jahr (optional)').fill('1999');
    await page.locator('#archive-cache-form').evaluate((form) => form.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    ));
    expect(await page.evaluate(() => window.__archiveCacheCalls.length)).toBe(0);
    await expect.poll(() => page.evaluate(
      () => window.__archiveCacheMessages.at(-1)?.message || '',
    )).toContain('zwischen 2000 und 2100');

    await page.getByLabel('Jahr (optional)').fill('2026');
    await page.getByLabel('Dry-Run').check();
    await page.getByRole('button', { name: 'Rebuild starten' }).click();
    await expect.poll(() => page.evaluate(
      () => window.__archiveCacheCalls.some(
        (call) => call.endpoint === '/api/admin/archive-cache/rebuild',
      ),
    )).toBe(true);

    const rebuildPayload = await page.evaluate(() => {
      const call = window.__archiveCacheCalls.find(
        (entry) => entry.endpoint === '/api/admin/archive-cache/rebuild',
      );
      return JSON.parse(call.body);
    });
    expect(rebuildPayload).toEqual({ year: 2026, dry_run: true });
  });
});
