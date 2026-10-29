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

const waitForTimelapseReady = async (page) => {
  const tl = page.locator('[data-timelapse]');
  if (await tl.count() === 0) {
    return false;
  }
  await tl.first().waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(
    () => {
      const el = document.querySelector('[data-timelapse]');
      return el && !el.hidden && getComputedStyle(el).display !== 'none';
    },
    { timeout: 20000 }
  );
  await page.waitForFunction(
    () => {
      const img = document.querySelector('[data-tl-img]');
      return img && img.getAttribute('src');
    },
    { timeout: 20000 }
  );
  return true;
};

test.describe('Timelapse smoke', () => {
  test('scrubber + framecount + retry', async ({ page }) => {
    await gotoCamera(page);
    const ready = await waitForTimelapseReady(page);
    test.skip(!ready, 'Timelapse not available on this page');

    const img = page.locator('[data-tl-img]');
    const scrubber = page.locator('[data-tl-scrubber]');
    const frameBtn = page.locator('[data-tl-framecount]');

    // Scrubber move
    const max = parseInt(await scrubber.getAttribute('max') || '0', 10);
    if (max > 1) {
      await scrubber.fill(String(Math.floor(max / 2)));
      await page.waitForTimeout(200);
    }
    const afterScrub = await img.getAttribute('src');

    // Framecount toggle (120 -> 60)
    await frameBtn.click();
    await page.waitForTimeout(500);
    const afterFrameCount = await img.getAttribute('src');

    expect(afterScrub).not.toBeNull();
    expect(afterFrameCount).not.toBeNull();
    expect(frameBtn).toHaveText(/Frames/);
  });

  test('keyboard space/arrow', async ({ page }) => {
    await gotoCamera(page);
    const ready = await waitForTimelapseReady(page);
    test.skip(!ready, 'Timelapse not available on this page');

    const play = page.locator('[data-tl-play]');
    const img = page.locator('[data-tl-img]');
    const speed = page.locator('[data-tl-speed]');

    const src1 = await img.getAttribute('src');
    await play.focus();
    await page.keyboard.press('Space');
    await page.waitForTimeout(800);
    await page.keyboard.press('Space');
    const src2 = await img.getAttribute('src');

    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(150);
    const src3 = await img.getAttribute('src');

    // Speed change via dropdown as fallback
    await speed.selectOption('2');
    const speedVal = await speed.inputValue();

    expect(src1).not.toBeNull();
    expect(src2).not.toBeNull();
    expect(src3).not.toBeNull();
    expect(speedVal).toBe('2');
  });
});
