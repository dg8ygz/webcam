const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'http://127.0.0.1/webcam/';
const CAMERA = process.env.CAMERA_SLUG || 'steinhagen';

for (const view of ['playback', 'timelapse']) {
  test(`${view}: Aktuell leaves playback and its selected date`, async ({ page }) => {
    // Avoid downloading the film: navigation must work before frames load too.
    await page.route('**/timelapse?**', route => route.abort());
    const url = new URL(`camera/${CAMERA}`, BASE.endsWith('/') ? BASE : `${BASE}/`);
    url.search = `view=${view}&year=2026&month=10&day=15&time=120000`;
    await page.goto(url.toString(), { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-timelapse]')).toBeVisible();
    const current = page.locator('a.timeline-controls__latest');
    await expect(current).toBeVisible();
    await expect(current).toHaveAttribute('data-no-ajax', '1');
    await current.click();
    await expect(page).toHaveURL(new URL(`camera/${CAMERA}`, BASE.endsWith('/') ? BASE : `${BASE}/`).toString());
    await expect(page.locator('#camera-live-root')).toHaveAttribute('data-view-mode', 'live');
    await expect(page.locator('[data-timelapse]')).toHaveCount(0);
    await expect(page.locator('[data-live-image]').first()).toBeVisible();
    await expect.poll(() => page.locator('[data-live-image]').first().evaluate(el => {
      const img = el.matches('img') ? el : el.querySelector('img');
      return Boolean(img && img.complete && img.naturalWidth > 0);
    })).toBe(true);
  });
}
