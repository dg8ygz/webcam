const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

test('embedded image overlay suppresses the complete label layer', async ({ page }) => {
  await page.goto(buildUrl(), { waitUntil: 'networkidle' });

  const root = page.locator('.camera-live-root');
  const labels = page.locator('[data-live-labels]');
  await expect(root).toBeVisible({ timeout: 15000 });
  await expect(labels).toHaveCount(1);

  await page.evaluate(() => {
    const liveRoot = document.querySelector('.camera-live-root');
    const container = document.querySelector('[data-live-labels]');
    if (!liveRoot || !container) return;
    liveRoot.dataset.labelsSuppressed = '1';
    container.hidden = false;
    container.innerHTML = '<span class="live-label">Darf nicht sichtbar sein</span>';
  });

  await expect(labels).toBeHidden();
});
