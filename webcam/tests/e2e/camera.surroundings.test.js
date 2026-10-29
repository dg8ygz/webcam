const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const cameraUrl = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

test('opens the extracted surroundings map with camera markers', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  const response = await page.goto(cameraUrl(), { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);

  // Die Umgebungskarte liegt im Info-Menü. Erst das Menü und dann den Eintrag
  // öffnen, genau wie ein Besucher; dadurch wird auch der Adapter in camera.js
  // und nicht nur die isolierte Moduldatei getestet.
  const infoMenu = page.locator('.tl-menu__toggle', { hasText: 'Infos' }).first();
  await expect(infoMenu).toBeVisible({ timeout: 15000 });
  await infoMenu.click();
  await page.locator('[data-open-surroundings-map]').first().click();

  const modal = page.locator('[data-meta-modal]');
  await expect(modal).toBeVisible();
  const canvas = modal.locator('.surroundings-map__canvas');
  await expect(canvas).toBeVisible({ timeout: 15000 });
  await expect(canvas.locator('.leaflet-map-pane')).toHaveCount(1);
  expect(await canvas.locator('.leaflet-interactive').count()).toBeGreaterThan(0);
  expect(pageErrors).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
});
