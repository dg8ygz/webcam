const path = require('path');
const { test, expect } = require('@playwright/test');
const repo = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';

test('status reloads on reopening and never overwrites a newer live timestamp', async ({ page }) => {
  await page.setContent(`<main id="camera-live-root" data-api-camera-detail="/fixture/status" data-last-ts="0">
    <button data-open-status>Status</button></main>
    <div data-status-modal hidden><button data-status-close>Close</button><div data-status-content></div></div>`);
  await page.addScriptTag({ path: path.join(repo, 'assets/js/camera-status.js') });
  await page.evaluate(() => {
    window.statusRequests = 0;
    window.fetch = async () => {
      window.statusRequests++;
      return { ok: true, json: async () => ({ status: {
        last_capture_at: window.statusRequests === 1 ? '2026-10-01T10:00:00Z' : '2026-10-01T11:00:00Z',
        consecutive_failures: window.statusRequests === 1 ? 0 : 9,
      } }) };
    };
    window.WebcamCameraStatus.create({ getLiveRoot: () => document.getElementById('camera-live-root') }).init();
  });
  await page.click('[data-open-status]');
  await expect(page.locator('[data-status-content]')).toContainText('12:00');
  await page.click('[data-status-close]');
  const latest = String(Date.parse('2026-10-01T12:00:00Z') / 1000);
  await page.locator('#camera-live-root').evaluate((root, stamp) => { root.dataset.lastTs = stamp; }, latest);
  await page.click('[data-open-status]');
  await expect(page.locator('[data-status-content]')).toContainText('13:00');
  await expect(page.locator('[data-status-content]')).toContainText('Fehler in Folge9');
  expect(await page.evaluate(() => window.statusRequests)).toBe(2);
  await expect(page.locator('#camera-live-root')).toHaveAttribute('data-last-ts', latest);
});

test('simultaneous status opens share one pending request', async ({ page }) => {
  await page.setContent(`<main id="camera-live-root" data-api-camera-detail="/fixture/status">
    <button data-open-status>Status</button></main>
    <div data-status-modal hidden><div data-status-content></div></div>`);
  await page.addScriptTag({ path: path.join(repo, 'assets/js/camera-status.js') });
  const count = await page.evaluate(() => {
    let requests = 0;
    window.fetch = () => { requests++; return new Promise(() => {}); };
    window.WebcamCameraStatus.create({ getLiveRoot: () => document.getElementById('camera-live-root') }).init();
    document.querySelector('[data-open-status]').click();
    document.querySelector('[data-open-status]').click();
    return requests;
  });
  expect(count).toBe(1);
});
