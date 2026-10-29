const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) return CAMERA_PATH;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

test.describe('Dayvideo inline player', () => {
  test('opens from Optionen, sets default selects, loads video + download link', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    // Öffne Optionen-Menü, damit der Link sichtbar ist
    const optionsToggle = page.locator('.tl-menu__toggle[aria-label="Optionen"]');
    if (await optionsToggle.count()) {
      await optionsToggle.first().click({ force: true });
    }

    const link = page.locator('[data-dayvideo-open]');
    if (!(await link.count())) test.skip('Dayvideo link not present');

    await link.first().click({ force: true });

    const inline = page.locator('[data-dayvideo-inline]');
    await expect(inline).toBeVisible({ timeout: 15000 });

    const fpsSelect = inline.locator('[data-dayvideo-fps]');
    const widthSelect = inline.locator('[data-dayvideo-width]');
    if (await fpsSelect.count()) {
      await expect(fpsSelect).toHaveValue('5');
    }
    if (await widthSelect.count()) {
      await expect(widthSelect).toHaveValue('1200');
    }

    const status = inline.locator('[data-dayvideo-status]');
    const video = inline.locator('[data-dayvideo-video]');
    const download = inline.locator('[data-dayvideo-download]');

    await expect(video).toHaveAttribute('src', /day_.*\.mp4/, { timeout: 20000 });
    await expect(download).toBeVisible({ timeout: 20000 });
    const initialStatus = (await status.textContent())?.trim().toLowerCase() || '';
    expect(initialStatus).not.toMatch(/fehler/);

    // Reload should keep video usable
    const reload = inline.locator('[data-dayvideo-reload]');
    if (await reload.count()) {
      await reload.click();
      await expect(video).toHaveAttribute('src', /day_.*\.mp4/, { timeout: 20000 });
      const after = await video.getAttribute('src');
      expect(after).toBeTruthy();
      // May remain same path; ensure at least still present
      expect(after).not.toBeNull();
      const reloadStatus = (await status.textContent())?.trim().toLowerCase() || '';
      expect(reloadStatus).not.toMatch(/fehler/);
    }
  });
});
