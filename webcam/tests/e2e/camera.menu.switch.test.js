const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const cameraUrl = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  return new URL(CAMERA_PATH.replace(/^\//, ''), base).toString();
};

for (const viewport of [
  { name: 'desktop', width: 1280, height: 800 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test(`camera menu uses a full navigation when switching cameras (${viewport.name})`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    try {
      await page.goto(cameraUrl(), { waitUntil: 'networkidle' });
    } catch {
      test.skip(true, 'BASE_URL not reachable');
    }

    const currentSlug = await page.locator('#camera-live-root').getAttribute('data-camera-slug');
    const menu = page.locator('.timeline-controls__group--menus .tl-menu').first();
    await menu.locator('.tl-menu__toggle').click();
    await expect(menu.getByRole('link', { name: 'Globales Best Of', exact: true })).toBeVisible();
    const optionsMenu = page.locator('.tl-menu', {
      has: page.locator('.tl-menu__toggle[aria-label="Optionen"]'),
    });
    await expect(optionsMenu.getByRole('link', { name: 'Globales Best Of', exact: true })).toHaveCount(0);

    const target = menu.locator(`.tl-menu__dropdown a[data-camera-slug]:not([data-camera-slug="${currentSlug}"])`).first();
    test.skip((await target.count()) === 0, 'No second camera available');
    await expect(target).toHaveAttribute('data-no-ajax', '1');

    const targetSlug = await target.getAttribute('data-camera-slug');
    const targetTitle = (await target.textContent() || '').trim();
    await page.evaluate(() => {
      window.__cameraSwitchSentinel = 'old-document';
    });

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      target.click(),
    ]);

    await expect(page.locator('#camera-live-root')).toHaveAttribute('data-camera-slug', targetSlug || '');
    expect(await page.title()).toContain(targetTitle);
    const sentinel = await page.evaluate(() => window.__cameraSwitchSentinel);
    expect(sentinel).toBeUndefined();
  });
}
