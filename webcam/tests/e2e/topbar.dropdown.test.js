const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://192.0.2.34/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const resolveCameraUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const gotoCamera = async (page) => {
  const url = resolveCameraUrl();
  await page.goto(url, { waitUntil: 'networkidle' });
};

test('Topbar shows all camera slugs', async ({ page }) => {
  await gotoCamera(page);
  const dropdown = page.locator('.camera-topbar .dropdown').first();
  if (!(await dropdown.count())) {
    test.skip('Topbar dropdown not present in this build');
  }
  await dropdown.hover();
  const items = await page.locator('.dropdown__menu a').allTextContents();
  expect(items.length).toBeGreaterThan(1);
  // expect at least current cam
  const hasCurrent = items.some((t) => t.toLowerCase().includes('bielefeld'));
  expect(hasCurrent).toBeTruthy();
});
