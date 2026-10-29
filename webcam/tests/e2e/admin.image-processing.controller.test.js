const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

// Dieser Test benutzt den echten isolierten Admin und die echte PHP/GD-API.
// Kein Aufruf eines Live-Servers und keine Bearbeitung echter Kamerabilder.
test('ordered image regions survive save and preview without a new capture', async ({ page, context, request }) => {
  const root = process.env.WEBCAM_REPOSITORY_ROOT;
  test.skip(!root || !fs.existsSync(path.join(root, '.isolated-e2e')), 'Isolated fixture required');
  await context.route('**/*', route => new URL(route.request().url()).origin === process.env.BASE_URL ? route.continue() : route.abort());
  await context.setExtraHTTPHeaders({ Authorization: `Bearer ${process.env.ADMIN_TOKEN}` });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto('/admin/?test=1');
  // Camera list selection uses the regular admin controls.
  await page.getByRole('button', { name: 'Bearbeiten', exact: true }).first().click();
  await expect(page.locator('.camera-form')).toBeVisible();
  await page.getByRole('button', { name: 'Bildbearbeitung', exact: true }).click();
  const editor = page.locator('[data-image-processing]');
  await expect(editor.locator('[data-ip-preview]')).toBeVisible();
  // Desktop und Mobil teilen nur diese private Fixture-DB. Den Entwurf
  // zuruecksetzen, damit der zweite Lauf keine Bereiche des ersten mitzaehlt.
  while (await editor.locator('[data-ip-delete]').count()) {
    await editor.locator('[data-ip-delete]').first().click();
  }
  await editor.locator('[data-ip-enable]').uncheck();
  await expect(editor.locator('[data-ip-preview]')).toBeVisible();
  await editor.locator('[data-ip-action]').selectOption('mask');
  const canvas = editor.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  for (const [x, y] of [[.05, .2], [.15, .25]]) {
    await page.mouse.move(box.x + box.width * x, box.y + box.height * y);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * (x + .25), box.y + box.height * (y + .3), { steps: 5 });
    await page.mouse.up();
  }
  await expect(editor.locator('[data-ip-list] li')).toHaveCount(2);
  await editor.locator('[data-ip-region="color"]').fill('#00ff00');
  await expect(editor.locator('[data-ip-preview]')).toBeVisible();
  const before = await editor.locator('[data-ip-preview]').getAttribute('src');
  const rows = editor.locator('[data-ip-list] li');
  await rows.nth(1).dragTo(rows.nth(0));
  await expect.poll(async () => JSON.parse(await editor.locator('[name="image_processing"]').inputValue()).regions[0].color).toBe('#00ff00');
  await expect(editor.locator('[data-ip-preview]')).toBeVisible();
  await expect.poll(() => editor.locator('[data-ip-preview]').getAttribute('src')).not.toBe(before);
  const config = JSON.parse(await editor.locator('[name="image_processing"]').inputValue());
  expect(config.enabled).toBe(false);
  await editor.locator('[data-ip-enable]').check();
  await page.locator('[data-tab-panel="image-processing"] [data-section-save]').click();
  await expect(page.locator('[data-tab-panel="image-processing"] [data-section-save]')).toBeEnabled();
  await page.reload();
  if (!(await page.locator('.camera-form').count())) await page.getByRole('button', { name: 'Bearbeiten', exact: true }).first().click();
  await page.getByRole('button', { name: 'Bildbearbeitung', exact: true }).click();
  await expect(page.locator('[data-ip-enable]')).toBeChecked();
  await expect(page.locator('[data-ip-list] li')).toHaveCount(2);
  await expect(page.locator('[data-ip-preview]')).toBeVisible();
  expect(JSON.parse(await page.locator('[name="image_processing"]').inputValue()).regions[0].color).toBe('#00ff00');
  expect(errors).toEqual([]);
  // Preview endpoint cannot be accessed without admin authentication.
  const forbidden = await request.post('/admin/api.php?path=cameras/bielefeld/image-preview', { data: { image_processing: config } });
  expect(forbidden.status()).toBe(401);
  await page.screenshot({ path: path.join(process.env.WEBCAM_E2E_REPORT, 'image-processing.png'), fullPage: true });
});
