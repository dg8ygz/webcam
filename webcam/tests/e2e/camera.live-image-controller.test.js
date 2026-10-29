const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const CONTROLLER_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/camera-live-image.js');
const PREVIEW_IMAGE = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="2" height="2"/%3E';
const FULL_IMAGE = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/%3E';

const openControllerPage = async (page, liveContent) => {
  await page.setContent(`
    <main id="camera-live-root" data-camera-slug="bielefeld">
      <nav class="timeline-controls">Navigation</nav>
      ${liveContent}
    </main>
  `);
  await page.addScriptTag({ path: CONTROLLER_SCRIPT });
  await page.evaluate(() => {
    window.liveImageCalls = { start: 0, finish: [], prefetch: 0, lightbox: 0 };
    window.liveImageController = window.WebcamCameraLiveImage.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      restoreLiveDisplayPreference: () => false,
      startImageLoading: () => { window.liveImageCalls.start += 1; },
      finishImageLoading: (detail) => { window.liveImageCalls.finish.push(detail); },
      prefetchAdjacentSnapshots: () => { window.liveImageCalls.prefetch += 1; },
      openLivebox: () => { window.liveImageCalls.lightbox += 1; },
      buildCameraPath: (slug) => `/camera/${slug}`,
    });
  });
};

test('loads the placeholder, swaps to the full image and filters lightbox clicks', async ({ page }) => {
  await openControllerPage(page, `
    <section class="live-image is-placeholder" data-live-display="snapshot">
      <img
        data-live-image
        data-capture-timestamp="123456"
        data-full-src='${FULL_IMAGE}'
        src='${PREVIEW_IMAGE}'
        alt="Testbild"
      >
      <button type="button" data-timeline-hotspot>Vorheriges Bild</button>
    </section>
  `);

  await page.evaluate(() => window.liveImageController.initLoad());
  await expect(page.locator('.live-image')).toHaveClass(/is-loaded/);
  await expect(page.locator('[data-live-image]')).toHaveAttribute('data-state', 'full');

  await page.locator('[data-live-image]').click();
  await page.locator('[data-timeline-hotspot]').click();
  const calls = await page.evaluate(() => window.liveImageCalls);
  expect(calls.start).toBe(1);
  expect(calls.prefetch).toBe(1);
  expect(calls.lightbox).toBe(1);
  expect(calls.finish).toHaveLength(1);
  expect(calls.finish[0].timestamp).toBe('123456');
});

test('marks an HLS stream ready without starting snapshot loading', async ({ page }) => {
  await openControllerPage(page, `
    <section class="live-image is-placeholder" data-live-display="stream">
      <video data-live-hls></video>
    </section>
  `);

  await page.evaluate(() => window.liveImageController.initLoad());
  await expect(page.locator('.live-image')).toHaveClass(/is-loaded/);
  await expect(page.locator('.live-image')).not.toHaveClass(/is-placeholder/);
  const calls = await page.evaluate(() => window.liveImageCalls);
  expect(calls.start).toBe(0);
  expect(calls.prefetch).toBe(0);
  expect(calls.finish).toEqual([{ skipped: true, stream: true }]);
});
