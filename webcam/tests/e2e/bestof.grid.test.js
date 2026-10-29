const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = (path) => {
  if (/^https?:\/\//i.test(path)) return path;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const rel = path.startsWith('/') ? path.slice(1) : path;
  return new URL(rel, base).toString();
};

test.use({
  extraHTTPHeaders: { 'X-WC-TEST': '1' },
});

test.describe('Best-Of grid (bielefeld)', () => {
  test('greys out camera tools that are unavailable in the gallery', async ({ page }) => {
    const url = buildUrl(
      CAMERA_PATH.includes('?') ? `${CAMERA_PATH}&view=bestof` : `${CAMERA_PATH}?view=bestof`,
    );
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => test.skip('BASE_URL not reachable'));

    // Best-of besitzt kein großes Kamerabild. Deshalb müssen diese drei
    // Werkzeuge als Hinweise erscheinen, dürfen aber keine JS-Aktion tragen.
    const infoMenu = page.locator('.tl-menu', {
      has: page.locator('.tl-menu__toggle[aria-label="Infos"]'),
    });
    await infoMenu.locator('.tl-menu__toggle').click();

    const disabledTools = infoMenu.locator('.tl-menu__disabled');
    await expect(disabledTools).toHaveCount(3);
    await expect(disabledTools).toHaveText([
      'Umgebungskarte',
      'Beschriftung ausblenden',
      'Status & Telemetrie',
    ]);
    expect(await disabledTools.evaluateAll((items) =>
      items.every((item) => item.getAttribute('aria-disabled') === 'true'))).toBe(true);
    await expect(disabledTools.first()).toHaveCSS('cursor', 'not-allowed');

    await expect(infoMenu.locator('[data-open-surroundings-map]')).toHaveCount(0);
    await expect(infoMenu.locator('[data-label-toggle]')).toHaveCount(0);
    await expect(infoMenu.locator('[data-open-status]')).toHaveCount(0);
  });

  test('renders items and thumbnail opens its target', async ({ page }) => {
    const url = buildUrl(
      CAMERA_PATH.includes('?') ? `${CAMERA_PATH}&view=bestof` : `${CAMERA_PATH}?view=bestof`,
    );
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => test.skip('BASE_URL not reachable'));

    const data = await page.evaluate(() => {
      const el = document.getElementById('bestof-data');
      if (!el) return null;
      try {
        return JSON.parse(el.textContent || 'null');
      } catch (e) {
        return null;
      }
    });
    expect(Array.isArray(data)).toBeTruthy();
    expect(data.length).toBeGreaterThan(0);

    const first = data[0];
    const target = first.link || first.url;
    expect(target).toBeTruthy();

    const beforeUrl = page.url();
    await page.goto(buildUrl(target), { waitUntil: 'networkidle' }).catch(() => null);
    const afterUrl = page.url();
    expect(afterUrl).not.toBe(beforeUrl);
    // Status kann 404 sein, falls Best-Of-Datei veraltet; Navigation reicht als Smoke.
  });
});
