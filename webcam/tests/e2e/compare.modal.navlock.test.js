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

// Der zweite Vergleichstest läuft am Desktop. Diesen kritischen Modal- und
// Timeline-Wechsel zusätzlich auf einem schmalen Telefon-Viewport absichern.
test.use({ viewport: { width: 390, height: 844 } });

test.describe('Compare modal releases timeline dropdowns', () => {
  test('month picker opens after remembering a compare image and closing the modal', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    const optionsToggle = page.locator('.tl-menu__toggle', { hasText: 'Optionen' }).first();
    await expect(optionsToggle).toBeVisible({ timeout: 15000 });
    await optionsToggle.click();

    const compareMenuToggle = page.locator('[data-compare-menu-toggle]').first();
    await expect(compareMenuToggle).toBeVisible({ timeout: 5000 });
    await compareMenuToggle.click();

    const rememberLink = page.locator('[data-remember-compare-image]').first();
    await expect(rememberLink).toBeVisible({ timeout: 5000 });
    await rememberLink.click();

    const modal = page.locator('[data-meta-modal]');
    await expect(modal).toBeVisible({ timeout: 5000 });

    // Die Bestätigung stammt bereits aus dem neuen Controller. Anschließend
    // auch die eigentliche Split-/Zoom-Oberfläche öffnen, damit der Test nicht
    // nur den Storage-Adapter abdeckt.
    await modal.getByRole('button', { name: 'Bildvergleich öffnen' }).click();
    const compareStage = modal.locator('.image-compare__stage');
    const compareDivider = modal.getByRole('slider', { name: 'Trennlinie des Bildvergleichs' });
    await expect(compareStage).toBeVisible({ timeout: 5000 });
    await expect(compareDivider).toHaveAttribute('aria-valuenow', '50');

    // Auf dem mobilen Repro den weißen Griff tatsächlich ziehen. Damit ist
    // nicht nur seine Darstellung, sondern auch Pointer-Capture und die neue
    // Bedienung ohne separate Range-Leiste abgesichert.
    const stageBox = await compareStage.boundingBox();
    const dividerBox = await compareDivider.boundingBox();
    expect(stageBox).not.toBeNull();
    expect(dividerBox).not.toBeNull();
    await page.mouse.move(
      dividerBox.x + (dividerBox.width / 2),
      dividerBox.y + (dividerBox.height / 2)
    );
    await page.mouse.down();
    await page.mouse.move(
      stageBox.x + (stageBox.width * 0.7),
      stageBox.y + (stageBox.height / 2)
    );
    await page.mouse.up();
    const splitAfterDrag = Number(await compareDivider.getAttribute('aria-valuenow'));
    expect(splitAfterDrag).toBeGreaterThanOrEqual(68);
    expect(splitAfterDrag).toBeLessThanOrEqual(72);
    await compareDivider.focus();
    await compareDivider.press('Home');
    await expect(compareDivider).toHaveAttribute('aria-valuenow', '0');
    await compareDivider.press('End');
    await expect(compareDivider).toHaveAttribute('aria-valuenow', '100');
    await compareDivider.press('ArrowLeft');
    await expect(compareDivider).toHaveAttribute('aria-valuenow', '99');
    await expect(modal.locator('.image-compare__slider')).toHaveCount(0);
    await expect(compareStage.locator('.image-compare__zoom-controls')).toBeVisible();

    const backdrop = modal.locator('.meta-modal__backdrop');
    await backdrop.click({ position: { x: 10, y: 10 } });
    await expect(modal).toBeHidden({ timeout: 5000 });

    const trigger = page.locator('.timeline-controls__group--month [data-month-picker-trigger], .timeline-controls__group--month [data-time-picker-trigger]').first();
    await expect(trigger).toBeVisible({ timeout: 10000 });
    await trigger.click();

    const panel = page.locator('.timeline-controls__group--month .timeline-time-picker').first();
    await expect(panel).toBeVisible({ timeout: 5000 });
  });
});
