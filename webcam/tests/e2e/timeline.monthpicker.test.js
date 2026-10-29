const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) return CAMERA_PATH;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

test.describe('Timeline month picker', () => {
  test('opens dropdown after timeline render', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    // Wait for timeline controls to be present
    const trigger = page.locator('.timeline-controls__group--month [data-month-picker-trigger], .timeline-controls__group--month [data-time-picker-trigger]');
    await expect(trigger).toBeVisible({ timeout: 15000 });

    // Click to open
    await trigger.first().click();

    const panels = page.locator('.timeline-controls__group--month .timeline-time-picker');
    await expect(panels.first()).toBeVisible({ timeout: 5000 });

    // Ensure options are rendered
    const options = panels.first().locator('[data-month-picker-option]');
    await expect(options.first()).toBeVisible({ timeout: 5000 });
  });
});
