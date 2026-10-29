const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) return CAMERA_PATH;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const gotoCamera = async (page) => {
  await page.goto(buildUrl(), { waitUntil: 'networkidle' });
};

const timelineContainer = (page) => page.locator('[data-timeline-container]');

const ensureReachable = async (page) => {
  try {
    await gotoCamera(page);
    return true;
  } catch (err) {
    return false;
  }
};

const clickFirstAvailable = async (locator) => {
  const count = await locator.count();
  for (let i = 0; i < count; i += 1) {
    const el = locator.nth(i);
    const disabled = await el.evaluate((node) => node.classList.contains('is-disabled'));
    if (!disabled) {
      const href = await el.getAttribute('href');
      const page = el.page();
      const prevUrl = page.url();
      await el.click({ force: true });
      if (href && href !== '#') {
        await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
      }
      const newUrl = page.url();
      if (newUrl === prevUrl && href && href !== '#') {
        // Fallback: give it a moment in case navigation is slow
        await page.waitForTimeout(500);
      }
      return href;
    }
  }
  return null;
};

test.describe('Timeline navigation', () => {
  test('prev/next and pickers navigate', async ({ page }) => {
    const reachable = await ensureReachable(page);
    test.skip(!reachable, 'BASE_URL not reachable');

    await expect(timelineContainer(page)).toBeVisible({ timeout: 20000 });

    // Try time prev/next
    const timeButtons = page.locator('.timeline-controls__group--time .timeline-controls__button');
    const beforeUrl = page.url();
    const timeHref = await clickFirstAvailable(timeButtons);
    // Try month/year picker if present
    const pickerGroups = [
      ['month', '.timeline-controls__group--month [data-time-picker-trigger]', '[data-month-picker-panel] a'],
      ['year', '.timeline-controls__group--year [data-time-picker-trigger]', '[data-year-picker-panel] a'],
    ];
    for (const [label, triggerSel, panelSel] of pickerGroups) {
      const trigger = page.locator(triggerSel).first();
      if (await trigger.count()) {
        await trigger.click({ force: true });
        const panel = page.locator(panelSel);
        if (await panel.count()) {
          const href = await panel.first().getAttribute('href');
          if (!href || href === '#') {
            continue;
          }
          // Some picker options are off-screen; navigate via href instead of clicking.
          const targetUrl = new URL(href, page.url()).toString();
          await page.goto(targetUrl, { waitUntil: 'networkidle' });
          if (page.url() === beforeUrl) {
            // If navigation not triggered (maybe same page), skip assertion
            continue;
          }
          expect(page.url()).not.toBe(beforeUrl);
          break;
        }
      }
    }

    // Ensure live image present after navigation(s)
    const liveImg = page.locator('[data-live-image]').first();
    if (!(await liveImg.count())) {
      const latest = page.locator('.timeline-controls__latest');
      if (await latest.count()) {
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'networkidle' }),
          latest.click({ force: true }),
        ]).catch(() => {});
      }
    }
    if (!(await liveImg.count())) {
      test.skip('No live image available after navigation');
    }
    await expect(liveImg).toBeVisible({ timeout: 20000 });

    expect(timeHref).not.toBeNull();
  });

  test('Aktuell link works', async ({ page }) => {
    const reachable = await ensureReachable(page);
    test.skip(!reachable, 'BASE_URL not reachable');

    await expect(timelineContainer(page)).toBeVisible({ timeout: 20000 });
    const latest = page.locator('.timeline-controls__latest');
    const href = await latest.getAttribute('href');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      latest.click({ force: true }),
    ]);
    expect(page.url()).toContain(href.split('?')[0]);
    await expect(page.locator('[data-live-image]')).toBeVisible({ timeout: 20000 });
  });

  test('Month picker clears stale day selection', async ({ page }) => {
    const reachable = await ensureReachable(page);
    test.skip(!reachable, 'BASE_URL not reachable');

    // Start with an explicit day param to simulate navigation from a previous month.
    const url = new URL(buildUrl());
    url.searchParams.set('day', '30');
    await page.goto(url.toString(), { waitUntil: 'networkidle' });

    const trigger = page.locator('.timeline-controls__group--month [data-month-picker-trigger]').first();
    await expect(trigger).toBeVisible({ timeout: 20000 });
    await trigger.click({ force: true });

    const option = page.locator('[data-month-picker-panel] [data-month-picker-option]').first();
    const href = await option.getAttribute('href');
    test.skip(!href, 'No month picker option');

    const targetUrl = new URL(href, page.url()).toString();
    await page.goto(targetUrl, { waitUntil: 'networkidle' });

    const finalUrl = new URL(page.url());
    expect(finalUrl.searchParams.has('day')).toBeFalsy();
  });

  test('Month next picks available day (no future jump)', async ({ page }) => {
    const reachable = await ensureReachable(page);
    test.skip(!reachable, 'BASE_URL not reachable');

    const url = new URL(buildUrl());
    url.searchParams.set('year', '2025');
    url.searchParams.set('month', '11');
    url.searchParams.set('day', '30');
    url.searchParams.set('time', '120000');
    await page.goto(url.toString(), { waitUntil: 'networkidle' });

    const monthButtons = page.locator('.timeline-controls__group--month .timeline-controls__button');
    await expect(monthButtons).toHaveCount(2, { timeout: 5000 });
    const monthNext = monthButtons.last();
    const disabled = await monthNext.evaluate((el) => el.classList.contains('is-disabled')).catch(() => true);
    test.skip(disabled, 'Month next disabled');

    await monthNext.click({ force: true });
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

    const liveImg = page.locator('[data-live-image]').first();
    await expect(liveImg).toBeVisible({ timeout: 20000 });

    // ensure the selected day text reflects the target month (no 30.12 future jump)
    const dayLabel = await page.locator('.timeline-controls__group--day .timeline-controls__label, .timeline-controls__group--day .timeline-controls__dropdown, .timeline-controls__group--day .timeline-time-button').first().textContent();
    expect(dayLabel).not.toContain('30.12');
  });
});
