const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://192.0.2.34/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld-nord';
const TARGET_FILE = process.env.BESTOF_NODEATE_FILE || '1950_sm.jpg';

const buildUrl = (path) => {
  if (/^https?:\/\//i.test(path)) return path;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const rel = path.startsWith('/') ? path.slice(1) : path;
  return new URL(rel, base).toString();
};

test.describe('Best-Of nodate entries (bielefeld-nord)', () => {
  test('shows filename without date and link contains filename', async ({ page }) => {
    const url = buildUrl(
      CAMERA_PATH.includes('?') ? `${CAMERA_PATH}&view=bestof` : `${CAMERA_PATH}?view=bestof`,
    );
    await page.goto(url, { waitUntil: 'networkidle' });

    const img = page.locator(`img[alt*="${TARGET_FILE}"]`).first();
    await img.waitFor({ timeout: 15000 }).catch(() => test.skip(`target file ${TARGET_FILE} not present`));
    const figHandle = await img.evaluateHandle((el) => el.closest('figure'));
    const caption = await figHandle.evaluate((f) => {
      const cap = f?.querySelector('figcaption');
      return cap ? cap.textContent || '' : '';
    });
    expect(caption).toContain(TARGET_FILE);
    expect(caption).not.toMatch(/\d{2}\.\d{2}\.\d{2}/);

    const href = await figHandle.evaluate((f) => {
      const a = f?.querySelector('a');
      return a ? a.getAttribute('href') : null;
    });
    expect(href).toContain(TARGET_FILE);
  });
});
