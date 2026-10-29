const { test, expect } = require('@playwright/test');

// Target an existing pair of captures; this regression never creates live data.
const base = process.env.BASE_URL || 'http://127.0.0.1/webcam/';
const camera = process.env.CAMERA_PATH || 'camera/stromberg/';
const before = process.env.MIDNIGHT_BEFORE || '2026-10-04';
const after = process.env.MIDNIGHT_AFTER || '2026-10-05';

const urlFor = (date, time) => {
  const url = new URL(camera.replace(/^\//, ''), base.replace(/\/?$/, '/'));
  const [year, month, day] = date.split('-');
  Object.entries({ year, month, day, time }).forEach(([key, value]) => url.searchParams.set(key, value));
  return url.toString();
};

const dateLabel = (date) => {
  const [, month, day] = date.split('-');
  return `${day}.${month}.`;
};

const selected = async (page, date, time) => {
  const [year, month, day] = date.split('-');
  const root = page.locator('#camera-live-root');
  await expect(root).toHaveAttribute('data-selected-year', String(Number(year)));
  await expect(root).toHaveAttribute('data-selected-month', String(Number(month)));
  await expect(root).toHaveAttribute('data-selected-day', String(Number(day)));
  await expect(root.locator('[data-time-picker-trigger]')).toHaveText(time);
};

test('time arrows and dropdown cross midnight in both directions', async ({ page }) => {
  test.setTimeout(90000);
  const response = await page.goto(urlFor(before, '235000'), { waitUntil: 'domcontentloaded' });
  expect(response.status()).toBe(200);
  await selected(page, before, '23:50');
  await page.locator('a[aria-label="Nächste Zeit"]').click();
  await selected(page, after, '00:00');
  await page.locator('a[aria-label="Vorherige Zeit"]').click();
  await selected(page, before, '23:50');
  await page.locator('[data-time-picker-trigger]').click();
  await page.locator('[data-time-picker-panel] a').filter({ hasText: `00:00 (${dateLabel(after)})` }).click();
  await selected(page, after, '00:00');
  await page.locator('[data-time-picker-trigger]').click();
  await page.locator('[data-time-picker-panel] a').filter({ hasText: `23:50 (${dateLabel(before)})` }).click();
  await selected(page, before, '23:50');
});
