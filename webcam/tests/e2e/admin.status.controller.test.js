const path = require('path');
const { test, expect } = require('@playwright/test');

// Die Tests laufen wegen des CIFS-Mounts aus /tmp. Das Asset selbst bleibt im
// Repository und kann bei abweichender Arbeitskopie explizit umgebogen werden.
const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const STATUS_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-status.js');

test.describe('Ausgelagerter Admin-Statusbereich', () => {
  test('lädt Statusdaten, maskiert API-Texte und delegiert Stream-Aktionen', async ({ page }) => {
    await page.setContent('<main id="status-root"></main>');
    await page.addScriptTag({ path: STATUS_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('status-root');
      const state = {
        status: { items: [], loading: false, error: null, source: null },
        streams: { items: [], blocked_ips: [], summary: {}, loading: false, error: null },
      };
      window.__statusCalls = [];
      let controller = null;

      const render = () => {
        if (controller) root.innerHTML = controller.renderSection();
      };
      const api = async (endpoint, options = {}) => {
        window.__statusCalls.push({ endpoint, method: options.method || 'GET' });
        if (endpoint === '/api/admin/status') {
          return {
            items: [{
              slug: 'test',
              title: '<img src=x onerror="window.__xss=1">Testkamera',
              interval_seconds: 600,
              age_seconds: 60,
              consecutive_failures: 0,
              resets_total: 1,
              severity: 'ok',
            }],
          };
        }
        if (endpoint === '/api/admin/streams') {
          return {
            items: [{ camera: 'test', kind: 'hls', ip: '192.0.2.1', token: 'viewer-1', age_seconds: 5 }],
            blocked_ips: [],
            summary: { total_active: 1 },
          };
        }
        return { ok: true };
      };

      controller = window.WebcamAdminStatus.create({
        state,
        api,
        getCsrfToken: () => 'csrf-test',
        buildPublicApiUrl: (value) => `/api/public.php/${value}`,
        computeAgeSeconds: () => 60,
        computeSeverity: () => 'ok',
        formatAge: (value) => `${value} s`,
        formatDateTime: (value) => value || '–',
        escapeHtml: (value) => String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;'),
        flash: () => {},
        requestRender: render,
        confirmAction: () => true,
      });
      root.addEventListener('click', (event) => controller.handleDelegatedClick(event));
      window.__statusController = controller;
      window.__statusState = state;
      controller.refresh();
    });

    await expect(page.getByText('Testkamera')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Livestreams' })).toBeVisible();
    await expect(page.locator('#status-root img')).toHaveCount(0);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();

    await page.getByRole('button', { name: 'Trennen' }).click();
    await expect.poll(() => page.evaluate(() => window.__statusCalls.some(
      (call) => call.endpoint === '/api/admin/streams/disconnect' && call.method === 'POST',
    ))).toBe(true);
  });
});
