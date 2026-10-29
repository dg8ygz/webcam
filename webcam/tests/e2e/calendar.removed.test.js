const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://192.0.2.34/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld?view=playback';

const url = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL.slice(0, -1) : BASE_URL;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH : `/${CAMERA_PATH}`;
  return `${base}${path}`;
};

test.describe('Calendar UI removal', () => {
  test('does not expose the removed calendar prototype', async ({ page }) => {
    await page.goto(url(), { waitUntil: 'domcontentloaded' });

    await expect(page.locator('[data-playback-tab="calendar"]')).toHaveCount(0);
    await expect(page.locator('[data-playback-panel="calendar"]')).toHaveCount(0);
    await expect(page.locator('[data-calendar-modal]')).toHaveCount(0);
  });
});
