const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const archiveUrl = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  const url = new URL(path, base);
  url.searchParams.set('view', 'history');
  url.searchParams.set('year', '2026');
  url.searchParams.set('month', '05');
  url.searchParams.set('day', '31');
  url.searchParams.set('time', '1600');
  return url.toString();
};

test.describe('Timeline hour picker', () => {
  test('opens and exposes the available full hours', async ({ page }) => {
    const response = await page.goto(archiveUrl(), { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);

    // Die Stundenbeschriftung ist nur dann ein Dropdown-Trigger, wenn der
    // Server mindestens eine Stunde für den ausgewählten Tag geliefert hat.
    const trigger = page.locator('[data-hour-picker-trigger]');
    await expect(trigger).toBeVisible();
    await expect(trigger).toContainText('16 Uhr');
    await trigger.click();

    const panel = page.locator('[data-hour-picker-panel]');
    await expect(panel).toBeVisible();

    // Dieser bekannte Archivtag deckt den ursprünglichen Fehlerfall ab:
    // Navigation per Pfeil funktionierte, 16–20 Uhr fehlten aber im Menü.
    for (const label of ['16 Uhr', '17 Uhr', '18 Uhr', '19 Uhr', '20 Uhr']) {
      await expect(
        panel.locator('[data-hour-picker-option]', { hasText: label })
      ).toHaveCount(1);
    }

    const selected = panel.locator('[data-hour-picker-option].is-selected');
    await expect(selected).toHaveCount(1);
    await expect(selected).toContainText('16 Uhr');

    // Der gemeinsame Controller darf dokumentweit nur eine Liste offen halten.
    // Beim Wechsel zum Tag schließt er daher zuerst die Stundenliste; Escape
    // räumt anschließend auch die neue Liste vollständig auf.
    const dayTrigger = page.locator('[data-day-picker-trigger]').first();
    const dayPanel = page.locator('[data-day-picker-panel]').first();
    await expect(dayTrigger).toBeVisible();
    await dayTrigger.click();
    await expect(panel).toBeHidden();
    await expect(dayPanel).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dayPanel).toBeHidden();
    await expect(dayTrigger).toHaveAttribute('aria-expanded', 'false');
  });
});
