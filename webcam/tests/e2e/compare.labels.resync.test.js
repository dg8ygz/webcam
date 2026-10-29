const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

test.describe('Compare modal keeps label toggle usable', () => {
  test('label controller follows a partially replaced camera root', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    // Der Klick ersetzt den Kamera-Root, ohne das vollständige Dokument neu zu
    // laden. Der ausgelagerte Controller muss danach den neuen Root auflösen
    // und darf nicht an einem entfernten DOM-Knoten weiterarbeiten.
    const previousSnapshot = page.locator('[aria-label="Vorherige Zeit"]').first();
    await expect(previousSnapshot).toBeVisible({ timeout: 15000 });
    const oldSnapshot = await page.locator('#camera-live-root').getAttribute('data-selected-ts');
    await previousSnapshot.click();
    await expect.poll(async () => (
      page.locator('#camera-live-root').getAttribute('data-selected-ts')
    ), { timeout: 15000 }).not.toBe(oldSnapshot);

    const infoToggle = page.locator('.tl-menu__toggle', { hasText: 'Infos' }).first();
    await infoToggle.click();
    const labelToggle = page.locator('[data-label-toggle]').first();
    await expect(labelToggle).toBeVisible({ timeout: 5000 });

    await labelToggle.click();
    await expect(page.locator('#camera-live-root')).toHaveClass(/labels-hidden/);
    await labelToggle.click();
    await expect(page.locator('#camera-live-root')).not.toHaveClass(/labels-hidden/);
  });

  test('labels can be restored after remembering a compare image', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    const infoToggle = page.locator('.tl-menu__toggle', { hasText: 'Infos' }).first();
    await expect(infoToggle).toBeVisible({ timeout: 15000 });

    await infoToggle.click();
    const labelToggle = page.locator('[data-label-toggle]').first();
    await expect(labelToggle).toBeVisible({ timeout: 5000 });

    const initialText = await labelToggle.textContent();
    expect(initialText || '').toContain('Beschriftung');

    await page.getByRole('button', { name: 'Optionen' }).click();
    await page.locator('[data-compare-menu-toggle]').click();
    await page.locator('[data-remember-compare-image]').click();
    await expect(page.locator('[data-meta-modal]')).toBeVisible({ timeout: 5000 });

    await page.keyboard.press('Escape');
    await expect(page.locator('[data-meta-modal]')).toBeHidden({ timeout: 5000 });

    await infoToggle.click();
    await expect(labelToggle).toBeVisible({ timeout: 5000 });

    const textAfterCompare = (await labelToggle.textContent()) || '';
    expect(textAfterCompare).toMatch(/Beschriftung (aus|an)zeigen|Beschriftung ausblenden/);

    await labelToggle.click();
    await page.waitForTimeout(200);
    await expect(page.locator('.camera-live-root')).toHaveClass(/labels-hidden/);

    await labelToggle.click();
    await page.waitForTimeout(200);
    await expect(page.locator('.camera-live-root')).not.toHaveClass(/labels-hidden/);
  });
});
