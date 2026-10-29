const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const cameraUrl = (query = '') => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  const url = new URL(path, base);
  if (query) {
    url.search = query;
  }
  return url.toString();
};

test.describe('Camera location map', () => {
  test('initializes marker and field of view from the server payload', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    const response = await page.goto(cameraUrl(), { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);

    const map = page.locator('#camera-map');
    await expect(map).toBeVisible();
    await expect(map).toHaveAttribute('data-map-initialized', '1', { timeout: 10000 });
    await expect(map.locator('.leaflet-marker-icon')).toHaveCount(1);
    await expect(map.locator('path[stroke="#2c6fbb"]')).toHaveCount(1);

    const config = await map.evaluate((element) => JSON.parse(element.dataset.mapConfig || '{}'));
    expect(config.title).toBe('Bielefeld');
    expect(Number.isFinite(Number(config.lat))).toBeTruthy();
    expect(Number.isFinite(Number(config.lng))).toBeTruthy();
    expect(pageErrors).toEqual([]);
  });

  test('fits into a narrow mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(cameraUrl(), { waitUntil: 'domcontentloaded' });

    const map = page.locator('#camera-map');
    await expect(map).toHaveAttribute('data-map-initialized', '1', { timeout: 10000 });
    const bounds = await map.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.width).toBeLessThanOrEqual(390);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
  });

  test('does not load the location map in history view', async ({ page }) => {
    const response = await page.goto(
      cameraUrl('?view=history&year=2026&month=05&day=31&time=1600'),
      { waitUntil: 'domcontentloaded' }
    );
    expect(response?.status()).toBe(200);
    await expect(page.locator('#camera-map')).toHaveCount(0);
    await expect(page.locator('script[src*="camera-map.js"]')).toHaveCount(0);
  });
});
