const { test, expect } = require('@playwright/test');

// Read-only regression against the reported pair of existing captures.
const base = process.env.BASE_URL || 'http://127.0.0.1/webcam/';

for (const javaScriptEnabled of [true, false]) {
  test(`day arrows preserve 08:30 in both directions (JS ${javaScriptEnabled})`, async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled });
    const page = await context.newPage();
    try {
      const url = new URL('camera/steinhagen', base.replace(/\/?$/, '/'));
      url.search = 'cam=steinhagen&year=2026&month=10&day=07&time=0830';
      await page.goto(url.toString(), { waitUntil: 'networkidle' });
      const root = page.locator('#camera-live-root');
      const selected = async (day) => {
        await expect(root).toHaveAttribute('data-selected-day', day);
        await expect(root.locator('[data-time-picker-trigger]')).toHaveText('08:30');
      };
      await selected('7');
      await page.locator('a[aria-label="Vorherige Tag"]').click();
      await selected('6');
      await page.locator('a[aria-label="Nächste Tag"]').click();
      await selected('7');
    } finally {
      await context.close();
    }
  });
}
