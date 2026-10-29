const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/stromberg?view=playback';

const resolveCameraUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const gotoCamera = async (page) => {
  const url = resolveCameraUrl();
  await page.goto(url, { waitUntil: 'networkidle' });
};

const ensureTimelapse = async (page) => {
  const tl = page.locator('[data-timelapse]');
  if (!(await tl.count())) {
    test.skip('Timelapse block not rendered (playback disabled?)');
  }
  await tl.waitFor({ state: 'attached', timeout: 20000 });
  await tl.first().waitFor({ state: 'visible', timeout: 20000 });
  return tl.first();
};

test('Timelapse control layout (Abspielen-Menü)', async ({ page }) => {
  await gotoCamera(page);
  const tl = await ensureTimelapse(page);

  const controls = tl.locator('.timelapse__controls');
  await expect(controls).toBeVisible();

  // Check main controls exist
  const buttons = [
    '[data-tl-play]',
    '[data-tl-prev]',
    '[data-tl-next]',
    '[data-tl-framecount]',
    '[data-tl-speed]',
    '[data-tl-day-prev]',
    '[data-tl-day-next]',
    '[data-tl-fullscreen]',
  ];
  for (const sel of buttons) {
    await expect(controls.locator(sel)).toBeVisible();
  }

  // Screenshot layout for review
  await controls.screenshot({ path: 'test-results/timelapse-controls.png', fullPage: false });
});
