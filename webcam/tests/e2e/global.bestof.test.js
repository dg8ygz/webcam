const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8082';
const globalBestOfUrl = () => new URL('bestof', `${BASE_URL.replace(/\/$/, '')}/`).toString();
const cameraUrl = () => new URL(
  (process.env.CAMERA_PATH || '/camera/bielefeld').replace(/^\//, ''),
  `${BASE_URL.replace(/\/$/, '')}/`
).toString();

test.describe('Global Best Of', () => {
  test('shows a chronologically ordered cross-camera gallery', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(`${BASE_URL.replace(/\/$/, '')}/`, { waitUntil: 'domcontentloaded' });
    const startHeaderBox = await page.locator('.overview-hero').boundingBox();
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('.overview-hero').getByRole('link', { name: 'Globales Best-Of', exact: true }).click(),
    ]);

    await expect(page.locator('.site-header')).toHaveCount(0);
    const overviewHeader = page.locator('.overview-hero');
    await expect(overviewHeader.getByRole('heading', { name: 'Webcam-Übersicht' })).toBeVisible();
    await expect(overviewHeader.locator('.lead')).toHaveText('Testsystem');
    await expect(overviewHeader.getByRole('link', { name: 'Globales Best-Of', exact: true })).toBeVisible();
    await expect(overviewHeader.getByRole('link', { name: 'Status', exact: true })).toBeVisible();
    await expect(overviewHeader.getByRole('link', { name: 'Wetter', exact: true })).toBeVisible();
    const globalHeaderBox = await overviewHeader.boundingBox();
    expect(globalHeaderBox).not.toBeNull();
    expect(startHeaderBox).not.toBeNull();
    expect(globalHeaderBox.x).toBe(startHeaderBox.x);
    expect(globalHeaderBox.width).toBe(startHeaderBox.width);
    await expect(page.locator('.global-bestof__header h1')).toHaveCount(0);
    await expect(page.locator('.global-bestof__header p')).toHaveCount(0);
    await expect(page.locator('[name="camera"]')).toBeVisible();
    await expect(page.locator('[name="year"]')).toBeVisible();
    const filterBox = await page.locator('.global-bestof__filters').boundingBox();
    const countBox = await page.locator('.global-bestof__filters [data-global-bestof-count]').boundingBox();
    expect(filterBox).not.toBeNull();
    expect(countBox).not.toBeNull();
    expect(countBox.x + countBox.width).toBeLessThanOrEqual(filterBox.x + filterBox.width);
    expect(filterBox.x + filterBox.width - (countBox.x + countBox.width)).toBeLessThanOrEqual(17);
    await expect(page.locator('.global-bestof__header [data-global-bestof-count]')).toHaveCount(0);
    const backBox = await page.locator('.global-bestof__back').boundingBox();
    expect(backBox).not.toBeNull();
    expect(filterBox.y - (backBox.y + backBox.height)).toBeLessThanOrEqual(1);
    const shellStyle = await page.locator('.main-content--global-bestof').evaluate((node) => {
      const style = window.getComputedStyle(node);
      return {
        backgroundColor: style.backgroundColor,
        boxShadow: style.boxShadow,
        padding: style.padding,
      };
    });
    expect(shellStyle).toEqual({
      backgroundColor: 'rgba(0, 0, 0, 0)',
      boxShadow: 'none',
      padding: '0px',
    });

    const cards = page.locator('[data-global-bestof-grid] .global-bestof-card');
    await expect(cards.first()).toBeVisible();
    await expect.poll(async () => cards.first().locator('img').evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);
    const timestamps = await cards.evaluateAll((nodes) => nodes
      .map((node) => Number.parseInt(node.getAttribute('data-slot-ts') || '0', 10))
      .filter((value) => value > 0));
    expect(timestamps).toEqual([...timestamps].sort((a, b) => b - a));

    const firstLink = await cards.first().getAttribute('href');
    expect(firstLink).toContain('bestof=');

    const initialCount = await cards.count();
    const moreButton = page.getByRole('button', { name: 'Mehr laden' });
    await expect(moreButton).toBeVisible();
    const responsePromise = page.waitForResponse((response) =>
      response.url().includes('api/public.php/bestof')
        && response.url().includes('page=2')
    );
    await moreButton.click();
    await expect((await responsePromise).status()).toBe(200);
    await expect.poll(() => cards.count()).toBeGreaterThan(initialCount);

    const loadedTimestamps = await cards.evaluateAll((nodes) => nodes
      .map((node) => Number.parseInt(node.getAttribute('data-slot-ts') || '0', 10))
      .filter((value) => value > 0));
    expect(loadedTimestamps).toEqual([...loadedTimestamps].sort((a, b) => b - a));

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('.global-bestof__back').click(),
    ]);
    const returnedHeaderBox = await page.locator('.overview-hero').boundingBox();
    expect(returnedHeaderBox.x).toBe(startHeaderBox.x);
    expect(returnedHeaderBox.width).toBe(startHeaderBox.width);
  });

  test('fits a narrow mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(globalBestOfUrl(), { waitUntil: 'networkidle' });

    await expect(page.locator('.overview-hero').getByRole('link', { name: 'Globales Best-Of', exact: true })).toBeVisible();
    await expect(page.locator('.global-bestof__header h1')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const cameraFilter = await page.locator('[name="camera"]').boundingBox();
    expect(cameraFilter).not.toBeNull();
    expect(cameraFilter.width).toBeLessThanOrEqual(358);
  });

  for (const viewport of [
    { name: 'desktop', width: 1366, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`keeps the camera header for camera-origin navigation (${viewport.name})`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(cameraUrl(), { waitUntil: 'networkidle' });

      const cameraName = (await page.locator('.timeline-controls__camera-name').textContent() || '').trim();
      const cameraHeaderBox = await page.locator('#timeline-controls').boundingBox();
      const cameraContentBox = await page.locator('.camera-live').boundingBox();
      expect(cameraHeaderBox).not.toBeNull();
      expect(cameraContentBox).not.toBeNull();
      const cameraHeaderGap = cameraContentBox.y - (cameraHeaderBox.y + cameraHeaderBox.height);
      const overviewMenu = page.locator('.timeline-controls__group--menus .tl-menu').first();
      await overviewMenu.locator('.tl-menu__toggle').click();
      const globalLink = overviewMenu.getByRole('link', { name: 'Globales Best Of', exact: true });

      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        globalLink.click(),
      ]);

      await expect(page).toHaveURL(/view=global-bestof/);
      await expect(page.locator('.overview-hero')).toHaveCount(0);
      await expect(page.locator('[data-global-bestof]')).toBeVisible();
      await expect(page.locator('#timeline-controls')).toBeVisible();
      await expect(page.locator('.timeline-controls__camera-name')).toHaveText('Globales Best-Of');
      expect(cameraName).not.toBe('Globales Best-Of');
      await expect(page.locator('.tl-menu__toggle', { hasText: 'Übersicht' })).toBeVisible();
      await expect(page.locator('.tl-menu__toggle[aria-label="Infos"]')).toBeVisible();
      await expect(page.locator('.tl-menu__toggle[aria-label="Optionen"]')).toBeVisible();

      const globalHeaderBox = await page.locator('#timeline-controls').boundingBox();
      expect(cameraHeaderBox).not.toBeNull();
      expect(globalHeaderBox).not.toBeNull();
      expect(globalHeaderBox.x).toBe(cameraHeaderBox.x);
      expect(globalHeaderBox.width).toBe(cameraHeaderBox.width);
      expect(globalHeaderBox.height).toBe(cameraHeaderBox.height);
      const galleryBox = await page.locator('[data-global-bestof]').boundingBox();
      expect(galleryBox).not.toBeNull();
      expect(galleryBox.y - (globalHeaderBox.y + globalHeaderBox.height)).toBe(cameraHeaderGap);

      const timelineGroups = page.locator('.timeline-controls__group--year, .timeline-controls__group--month, .timeline-controls__group--day, .timeline-controls__group--hour, .timeline-controls__group--time');
      await expect(timelineGroups).toHaveCount(5);
      await expect(timelineGroups.filter({ has: page.locator('.timeline-controls__label') })).toHaveCount(5);
      await expect(page.locator('.timeline-controls__button.is-disabled')).not.toHaveCount(0);
      await expect(page.locator('[data-time-picker-trigger], [data-year-picker-trigger], [data-month-picker-trigger], [data-day-picker-trigger], [data-hour-picker-trigger]')).toHaveCount(0);
      await expect(page.locator('[name="global_camera"]')).toBeVisible();
      await expect(page.locator('[name="global_year"]')).toBeVisible();
    });
  }
});
