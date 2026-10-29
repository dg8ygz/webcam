const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH =
  process.env.CAMERA_PATH || '/camera/stromberg?view=history#days';

const resolveCameraUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const gotoHistory = async (page) => {
  const url = resolveCameraUrl();
  await page.goto(url, { waitUntil: 'networkidle' });
};

const waitForHistoryMode = async (page, mode) => {
  const tab = page.locator(`[data-history-tab="${mode}"]`);
  const root = page.locator('[data-history-root]');
  if (!(await root.count())) {
    test.skip('History view not rendered (feature disabled)');
  }
  await expect(root).toBeVisible({ timeout: 10000 });
  if (await tab.count()) {
    await tab.click();
  }

  const placeholder = page.locator('[data-history-placeholder]');
  const cards = page.locator('[data-history-list] .history-card');

  const handle = await page.waitForFunction(() => {
    const placeholderEl = document.querySelector('[data-history-placeholder]');
    if (!placeholderEl) return null;
    const list = document.querySelectorAll('[data-history-list] .history-card');
    const text = placeholderEl.textContent || '';
    const hasError = /konnte.*geladen|Timeout/i.test(text);
    const ready =
      list.length > 0 ||
      /Keine Aufnahmen gefunden/i.test(text) ||
      placeholderEl.classList.contains('is-hidden');
    const state = { hasError, ready, text, count: list.length };
    return ready || hasError ? state : null;
  }, { timeout: 20000 });
  const result = handle ? await handle.jsonValue() : null;

  if (result?.hasError) {
    throw new Error(`History load failed for ${mode}: ${result.text || 'unknown error'}`);
  }
  if (!result?.ready) {
    throw new Error(`History not ready for ${mode}`);
  }

  // If we have cards, assert at least one is visible
  if ((result?.count || 0) > 0) {
    await expect(cards.first()).toBeVisible({ timeout: 5000 });
  }
};

test.describe('History smoke', () => {
  test('days tab loads entries or empty state', async ({ page }) => {
    await gotoHistory(page);
    await waitForHistoryMode(page, 'days');
  });

  test('months tab loads entries or empty state', async ({ page }) => {
    await gotoHistory(page);
    await waitForHistoryMode(page, 'months');
  });
});
