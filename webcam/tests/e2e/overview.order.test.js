const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8082';
const overviewUrl = () => `${BASE_URL.replace(/\/$/, '')}/`;

async function seedRecentCamera(page) {
  const card = page.locator('[data-camera-card]').first();
  await expect(card).toBeVisible();
  const recent = await card.evaluate((node) => {
    const link = node.querySelector('.camera-image');
    const image = node.querySelector('.camera-image__img');
    return {
      slug: node.getAttribute('data-slug') || '',
      viewedAt: Date.now(),
      imageUrl: image?.getAttribute('src') || '',
      href: link?.getAttribute('href') || '',
      capturedAt: '',
      timestamp: Number.parseInt(node.getAttribute('data-last-ts') || '0', 10),
    };
  });
  await page.evaluate((entry) => {
    window.localStorage.setItem('webcam:recent-cameras:v1', JSON.stringify([entry]));
  }, recent);
  await page.reload({ waitUntil: 'domcontentloaded' });
}

test('does not request a stale thumbnail stored by an older browser session', async ({ page }) => {
  await page.goto(overviewUrl(), { waitUntil: 'domcontentloaded' });

  const card = page.locator('[data-camera-card]').first();
  await expect(card).toBeVisible();
  const entry = await card.evaluate((node) => ({
    slug: node.getAttribute('data-slug') || '',
    viewedAt: Math.floor(Date.now() / 1000),
    imageUrl: `/cache/${node.getAttribute('data-slug') || 'camera'}/2026/07/02/20260702_080000_sm.jpg`,
    href: node.querySelector('.camera-image')?.getAttribute('href') || '',
    capturedAt: '02.07.2026, 08:00',
    timestamp: 1782972000,
  }));

  const staleRequests = [];
  page.on('request', (request) => {
    if (request.url().includes('20260702_080000_sm.jpg')) {
      staleRequests.push(request.url());
    }
  });
  await page.evaluate((recentEntry) => {
    window.localStorage.setItem('webcam:recent-cameras:v1', JSON.stringify([recentEntry]));
  }, entry);
  await page.reload({ waitUntil: 'networkidle' });

  // Alte Storage-Daten werden beim Einlesen migriert: Die Zielseite bleibt,
  // die nicht mehr verlässlich auflösbare Bild-URL wird verworfen.
  const normalized = await page.evaluate((recentEntry) => (
    window.WebcamOverviewRecent.normalize([recentEntry])[0]
  ), entry);
  expect(normalized.href).toBe(entry.href);
  expect(Object.hasOwn(normalized, 'imageUrl')).toBe(false);
  expect(staleRequests).toEqual([]);

  const recentImage = page.locator('[data-recent-list] .overview-recent__thumb');
  await expect(recentImage).toBeVisible();
  await expect(recentImage).not.toHaveAttribute('src', /20260702_080000_sm\.jpg/);
});

async function headerVisualSignature(header) {
  return header.evaluate((node) => {
    const heading = node.querySelector('h1');
    const lead = node.querySelector('.lead');
    const button = node.querySelector('.hero-actions .btn');
    const headingStyle = window.getComputedStyle(heading);
    const leadStyle = window.getComputedStyle(lead);
    const buttonStyle = window.getComputedStyle(button);
    return {
      heading: {
        fontFamily: headingStyle.fontFamily,
        fontSize: headingStyle.fontSize,
        fontWeight: headingStyle.fontWeight,
        lineHeight: headingStyle.lineHeight,
      },
      lead: {
        fontFamily: leadStyle.fontFamily,
        fontSize: leadStyle.fontSize,
        fontWeight: leadStyle.fontWeight,
        lineHeight: leadStyle.lineHeight,
      },
      button: {
        fontFamily: buttonStyle.fontFamily,
        fontSize: buttonStyle.fontSize,
        fontWeight: buttonStyle.fontWeight,
        lineHeight: buttonStyle.lineHeight,
        borderRadius: buttonStyle.borderRadius,
        padding: buttonStyle.padding,
      },
    };
  });
}

for (const viewport of [
  { name: 'desktop', width: 1366, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test(`orders overview content on ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(overviewUrl(), { waitUntil: 'domcontentloaded' });
    await seedRecentCamera(page);

    // Die Übersichtslogik liegt vollständig im versionierten Asset. PHP gibt
    // nur den nicht ausführbaren JSON-Datenblock für API, Zeitzone und Karte
    // aus; so darf später kein zweiter Inline-Initialisierungspfad entstehen.
    const overviewScript = page.locator('script[src*="assets/js/overview.js"]');
    await expect(overviewScript).toHaveCount(1);
    await expect(overviewScript).toHaveAttribute('src', /assets\/js\/overview\.js\?v=/);
    await expect(page.locator('script:not([src]):not([type="application/json"])')).toHaveCount(0);
    const overviewConfig = await page.locator('[data-overview-config]').evaluate((node) => (
      JSON.parse(node.textContent || '{}')
    ));
    expect(overviewConfig.apiUrl).toMatch(/api\/public\.php\/cameras\?per_page=200$/);
    expect(Array.isArray(overviewConfig.markers)).toBe(true);

    const toolbar = page.locator('.overview-toolbar');
    const toolbarActions = page.locator('.toolbar-actions');
    const grid = page.locator('[data-camera-grid]');
    const recent = page.locator('[data-overview-recent]');
    const map = page.locator('.overview-map-card');
    const globalBestOf = page.locator('.overview-hero').getByRole('link', { name: 'Globales Best-Of' });
    const status = page.locator('.overview-hero').getByRole('link', { name: 'Status', exact: true });
    const weather = page.locator('.overview-hero').getByRole('link', { name: 'Wetter', exact: true });

    await expect(globalBestOf).toBeVisible();
    await expect(globalBestOf).toHaveAttribute('href', /bestof$/);
    await expect(status).toBeVisible();
    await expect(status).toHaveAttribute('href', /status\/$/);
    await expect(weather).toBeVisible();
    await expect(weather).toHaveAttribute('href', /weather$/);
    await expect(globalBestOf).toHaveAttribute('class', await status.getAttribute('class'));
    await expect(weather).toHaveAttribute('class', await status.getAttribute('class'));
    const overviewHeaderStyle = await headerVisualSignature(page.locator('.overview-hero'));
    await expect(page.locator('[data-action="quick-view"]')).toHaveCount(0);
    await expect(page.locator('[data-camera-card] .meta-grid')).toHaveCount(0);
    await expect(page.locator('[data-camera-card] .tag-badge').first()).toBeVisible();
    await expect(recent).toBeVisible();
    await expect(map).toBeVisible();
    await expect(page.locator('#webcam-map.leaflet-container')).toBeVisible();

    const boxes = await Promise.all([grid, toolbar, recent, map].map((locator) => locator.boundingBox()));
    for (const box of boxes) expect(box).not.toBeNull();
    expect(boxes[0].y + boxes[0].height).toBeLessThanOrEqual(boxes[1].y);
    expect(boxes[1].y + boxes[1].height).toBeLessThanOrEqual(boxes[2].y);
    expect(boxes[2].y + boxes[2].height).toBeLessThanOrEqual(boxes[3].y);
    const actionBox = await toolbarActions.boundingBox();
    expect(actionBox).not.toBeNull();
    expect(Math.abs((actionBox.x + actionBox.width) - (boxes[0].x + boxes[0].width))).toBeLessThanOrEqual(1);
    if (viewport.name === 'desktop') {
      const titleBox = await page.locator('.overview-hero h1').boundingBox();
      const leadBox = await page.locator('.overview-hero .lead').boundingBox();
      const heroActionsBox = await page.locator('.overview-hero .hero-actions').boundingBox();
      expect(titleBox).not.toBeNull();
      expect(leadBox).not.toBeNull();
      expect(heroActionsBox).not.toBeNull();
      expect(Math.abs((titleBox.y + titleBox.height / 2) - (leadBox.y + leadBox.height / 2))).toBeLessThanOrEqual(2);
      expect(Math.abs((titleBox.y + titleBox.height / 2) - (heroActionsBox.y + heroActionsBox.height / 2))).toBeLessThanOrEqual(2);
      expect(leadBox.x).toBeGreaterThan(titleBox.x + titleBox.width);
      expect(heroActionsBox.x).toBeGreaterThan(titleBox.x + titleBox.width);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      weather.click(),
    ]);
    const weatherHeader = page.locator('.overview-hero');
    await expect(weatherHeader.getByRole('heading', { name: 'Webcam-Übersicht' })).toBeVisible();
    await expect(weatherHeader.getByRole('link', { name: 'Globales Best-Of', exact: true })).toBeVisible();
    await expect(weatherHeader.getByRole('link', { name: 'Status', exact: true })).toBeVisible();
    await expect(weatherHeader.getByRole('link', { name: 'Wetter', exact: true })).toBeVisible();
    const weatherPage = page.locator('.weather-page');
    await expect(weatherPage.getByRole('heading', { name: 'Wetterdaten' })).toBeVisible();
    await expect(weatherPage.locator('.overview-hero')).toHaveCount(0);
    expect(await headerVisualSignature(weatherHeader)).toEqual(overviewHeaderStyle);
    const weatherMainStyle = await page.locator('.main-content--weather').evaluate((node) => {
      const style = window.getComputedStyle(node);
      return {
        backgroundColor: style.backgroundColor,
        boxShadow: style.boxShadow,
        padding: style.padding,
      };
    });
    expect(weatherMainStyle).toEqual({
      backgroundColor: 'rgba(0, 0, 0, 0)',
      boxShadow: 'none',
      padding: '0px',
    });
    const weatherHeaderBox = await weatherHeader.boundingBox();
    const weatherPageBox = await weatherPage.boundingBox();
    expect(weatherHeaderBox).not.toBeNull();
    expect(weatherPageBox).not.toBeNull();
    expect(weatherHeaderBox.y + weatherHeaderBox.height).toBeLessThan(weatherPageBox.y);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      weatherHeader.getByRole('link', { name: 'Status', exact: true }).click(),
    ]);
    const statusHeader = page.locator('.overview-hero');
    const statusPage = page.locator('.status-overview');
    await expect(statusHeader.getByRole('heading', { name: 'Webcam-Übersicht' })).toBeVisible();
    await expect(statusHeader.getByRole('link', { name: 'Globales Best-Of', exact: true })).toBeVisible();
    await expect(statusHeader.getByRole('link', { name: 'Status', exact: true })).toBeVisible();
    await expect(statusHeader.getByRole('link', { name: 'Wetter', exact: true })).toBeVisible();
    await expect(statusPage.getByRole('heading', { name: 'Systemstatus' })).toBeVisible();
    await expect(statusPage.locator('.overview-hero')).toHaveCount(0);
    expect(await headerVisualSignature(statusHeader)).toEqual(overviewHeaderStyle);
    const statusMainStyle = await page.locator('.main-content--status').evaluate((node) => {
      const style = window.getComputedStyle(node);
      return {
        backgroundColor: style.backgroundColor,
        boxShadow: style.boxShadow,
        padding: style.padding,
      };
    });
    expect(statusMainStyle).toEqual({
      backgroundColor: 'rgba(0, 0, 0, 0)',
      boxShadow: 'none',
      padding: '0px',
    });
    const statusHeaderBox = await statusHeader.boundingBox();
    const statusPageBox = await statusPage.boundingBox();
    expect(statusHeaderBox).not.toBeNull();
    expect(statusPageBox).not.toBeNull();
    expect(statusHeaderBox.y + statusHeaderBox.height).toBeLessThan(statusPageBox.y);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });
}
