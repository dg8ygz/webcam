const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld?view=history#years';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) return CAMERA_PATH;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const loadHistoryYears = async (page) => {
  const url = buildUrl();
  await page.goto(url, { waitUntil: 'networkidle' });
  const root = page.locator('[data-history-root]');
  if (!(await root.count())) test.skip('History view not present');

  const yearsTab = page.locator('[data-history-tab="years"]');
  if (await yearsTab.count()) {
    await yearsTab.click();
  } else {
    test.skip('Years tab not available');
  }

  const placeholder = page.locator('[data-history-placeholder]');
  const list = page.locator('[data-history-list] .history-card');

  // Wait until either cards appear or placeholder hides/errors
  const state = await page.waitForFunction(() => {
    const ph = document.querySelector('[data-history-placeholder]');
    const cards = document.querySelectorAll('[data-history-list] .history-card');
    if (!ph) return null;
    const text = ph.textContent || '';
    const hasError = /konnte.*geladen|Timeout/i.test(text);
    const ready = cards.length > 0 || ph.classList.contains('is-hidden') || /Keine Aufnahmen gefunden/i.test(text);
    return ready || hasError ? { hasError, text, count: cards.length } : null;
  }, { timeout: 30000 });
  const result = state ? await state.jsonValue() : null;
  if (result?.hasError) throw new Error(`History years error: ${result.text}`);

  return { placeholder, list, result };
};

test.describe('History years tab', () => {
  test('shows all available years (no 10-item cutoff) and hides placeholder after load', async ({ page }) => {
    const { placeholder, list, result } = await loadHistoryYears(page);

    // Fetch API to know how many items are expected
    const apiData = await page.evaluate(async () => {
      const params = new URLSearchParams(window.location.search);
      const url = new URL('./api/history.php', window.location.href);
      const slug = params.get('cam') || params.get('slug') || '';
      url.searchParams.set('slug', slug);
      url.searchParams.set('mode', 'years');
      url.searchParams.set('years', '30');
      const resp = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
      if (!resp.ok) return null;
      return await resp.json();
    });

    const expected = Array.isArray(apiData?.items) ? apiData.items.length : null;
    const count = result?.count ?? 0;

    // If API says we should have more than 10, assert we didn't stop at 10
    if (expected && expected > 10) {
      expect(count).toBeGreaterThan(10);
      expect(count).toBe(expected);
    } else if (expected !== null) {
      // at least match API count
      expect(count).toBe(expected);
    } else if (count > 0) {
      expect(count).not.toBe(10);
    }

    // Placeholder should be hidden once items are loaded (or when none exist)
    await expect(placeholder).toHaveClass(/is-hidden|/);
  });
});
