const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8082';
const CAMERA_PATH = (process.env.CAMERA_PATH || '/camera/bielefeld-nord').replace(/\/+$/, '');

// Die Regression prueft bewusst nur den serverseitigen Modus-Schalter. Ohne
// JavaScript wird weder der echte HLS-Runner noch ein MJPEG-Slot gestartet.
test.use({ javaScriptEnabled: false });

for (const query of ['stream=hls', 'format=hls']) {
  test(`${query} explicitly selects HLS without opening MJPEG first`, async ({ page }) => {
    const url = new URL(`${CAMERA_PATH.replace(/^\//, '')}?${query}`, `${BASE_URL.replace(/\/$/, '')}/`).toString();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(response && response.status()).toBe(200);

    const root = page.locator('#camera-live-root');
    await expect(root).toHaveAttribute('data-stream-preference', 'hls');
    await expect(root).toHaveAttribute('data-stream-endpoint', '');
    await expect(root).toHaveAttribute('data-stream-hls-endpoint', /format=hls/);
    await expect(page.locator('[data-live-stream]')).toHaveCount(0);
    await expect(page.locator('[data-live-hls]')).toHaveCount(1);
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
    await expect(page.locator('[data-live-mode-badge]')).toHaveText('Livestream HLS');
  });
}
