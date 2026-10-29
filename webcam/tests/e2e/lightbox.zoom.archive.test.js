const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const cameraUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) return CAMERA_PATH;
  return new URL(CAMERA_PATH.replace(/^\//, ''), `${BASE_URL.replace(/\/$/, '')}/`).toString();
};

const expectZoomToggle = async (page) => {
  await page.locator('.live-image').first().click();
  const lightbox = page.locator('[data-livebox]');
  const image = page.locator('[data-livebox-img]');
  const zoom = page.locator('[data-livebox-zoom]');
  await expect(lightbox).toBeVisible({ timeout: 10000 });
  await expect(image).toHaveAttribute('src', /\S+/, { timeout: 10000 });

  const beforeTransform = await image.evaluate((element) => element.style.transform);
  await zoom.click();
  await expect.poll(
    () => image.evaluate((element) => element.style.transform),
    { timeout: 5000 }
  ).not.toBe(beforeTransform);

  const reducedTransform = await image.evaluate((element) => element.style.transform);
  await zoom.click();
  await expect.poll(
    () => image.evaluate((element) => element.style.transform),
    { timeout: 5000 }
  ).not.toBe(reducedTransform);
  await expect.poll(
    () => image.evaluate((element) => element.style.transform),
    { timeout: 5000 }
  ).toBe(beforeTransform);

  await page.locator('[data-livebox-close]').click();
  await expect(lightbox).toBeHidden();
};

test.describe('Lightbox zoom after archive navigation', () => {
  test('Zoom toggles once after an Ajax timeline swap', async ({ page }) => {
    await page.goto(cameraUrl(), { waitUntil: 'networkidle' });
    await expect(page.locator('[data-live-image]').first()).toBeVisible({ timeout: 20000 });
    await expectZoomToggle(page);

    const previousTime = page.locator(
      '.timeline-controls__group--time .timeline-controls__button:not(.is-disabled)'
    ).first();
    test.skip((await previousTime.count()) === 0, 'No archive navigation target available');

    const beforeUrl = page.url();
    await previousTime.click({ force: true });
    await page.waitForFunction(
      (url) => window.location.href !== url && /[?&]year=\d{4}/.test(window.location.search),
      beforeUrl,
      { timeout: 20000 }
    );
    await expect(page.locator('[data-live-image]').first()).toBeVisible({ timeout: 20000 });
    await expectZoomToggle(page);
  });
});

test.describe('Lightbox keyboard controls', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('pans with arrows, pages images and opens help with H', async ({ page }) => {
    await page.goto(cameraUrl(), { waitUntil: 'networkidle' });
    await expect(page.locator('[data-live-image]').first()).toBeVisible({ timeout: 20000 });
    await page.locator('.live-image').first().click();

    const lightbox = page.locator('[data-livebox]');
    const image = page.locator('[data-livebox-img]');
    const status = page.locator('[data-livebox-status]');
    const help = page.locator('[data-livebox-help-panel]');
    await expect(lightbox).toBeVisible({ timeout: 10000 });
    await expect(image).toHaveAttribute('src', /\S+/, { timeout: 10000 });

    await page.keyboard.press('h');
    await expect(help).toBeVisible();
    const helpBox = await help.boundingBox();
    expect(helpBox).not.toBeNull();
    expect(helpBox.x).toBeGreaterThanOrEqual(0);
    expect(helpBox.x + helpBox.width).toBeLessThanOrEqual(390);
    await page.keyboard.press('H');
    await expect(help).toBeHidden();

    const initialSrc = await image.getAttribute('src');
    const initialStatus = await status.textContent();
    const initialTransform = await image.evaluate((element) => element.style.transform);
    await page.keyboard.press('ArrowLeft');
    await expect.poll(
      () => image.evaluate((element) => element.style.transform),
      { timeout: 5000 }
    ).not.toBe(initialTransform);
    expect(await image.getAttribute('src')).toBe(initialSrc);
    expect(await status.textContent()).toBe(initialStatus);

    const statusMatch = String(initialStatus || '').match(/(\d+)\s*\/\s*(\d+)/);
    test.skip(!statusMatch || parseInt(statusMatch[2], 10) < 2, 'Not enough timeline images');
    await page.keyboard.press('PageUp');
    await expect.poll(() => status.textContent(), { timeout: 10000 }).not.toBe(initialStatus);

    await page.keyboard.press('Home');
    await expect(status).toContainText(/^1\s*\//);
    await page.keyboard.press('End');
    await expect.poll(async () => {
      const text = await status.textContent();
      const match = String(text || '').match(/(\d+)\s*\/\s*(\d+)/);
      return match ? match[1] === match[2] : false;
    }, { timeout: 10000 }).toBe(true);

    await page.keyboard.press('0');
    await expect.poll(
      () => image.evaluate((element) => element.style.transform),
      { timeout: 5000 }
    ).toMatch(/scale\(1\)$/);
  });
});
