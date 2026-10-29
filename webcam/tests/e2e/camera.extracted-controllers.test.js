const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const STATUS_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/camera-status.js');
const LIKE_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/camera-bestof-like.js');

test('status controller rebinds the modal after a partial DOM replacement', async ({ page }) => {
  await page.setContent(`
    <main id="camera-live-root" data-api-camera-detail="api/status">
      <a href="#" data-open-status>Status</a>
    </main>
    <div data-status-modal hidden>
      <button data-status-close>Schließen</button>
      <div data-status-content></div>
    </div>
    <div data-status-content hidden><p>Fallback</p></div>
  `);
  await page.evaluate(() => {
    window.statusFetches = 0;
    window.fetch = async () => {
      window.statusFetches += 1;
      return {
        ok: true,
        json: async () => ({
          camera: { interval_seconds: 600 },
          status: { last_capture_at: '2026-08-30T10:20:00+02:00', consecutive_failures: 0 },
        }),
      };
    };
  });
  await page.addScriptTag({ path: STATUS_SCRIPT });
  await page.evaluate(() => {
    window.statusController = window.WebcamCameraStatus.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
    });
    window.statusController.init();
  });

  await page.click('[data-open-status]');
  await expect(page.locator('[data-status-modal]')).toBeVisible();
  await expect(page.locator('[data-status-modal]')).toContainText('Intervall');
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-status-modal]')).toBeHidden();

  // Die partielle Navigation ersetzt den gesamten Inhalt des Live-Roots. Der
  // Controller muss danach den neuen Menülink an den bleibenden Dialog binden.
  await page.evaluate(() => {
    document.getElementById('camera-live-root').innerHTML = `
      <a href="#" data-open-status>Status neu</a>
    `;
    window.statusController.init();
  });
  await page.click('[data-open-status]');
  await expect(page.locator('[data-status-modal]')).toBeVisible();
  await expect(page.locator('[data-status-modal]')).toContainText('Letzte Auslösung');
  expect(await page.evaluate(() => window.statusFetches)).toBe(1);
});

test('best-of controller normalizes legacy paths and applies the confirmed state', async ({ page }) => {
  await page.route('http://camera.test/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `
        <main id="camera-live-root" data-camera-slug="bielefeld">
          <div data-bestof-hotspot>
            <button
              data-bestof-toggle
              data-camera="bielefeld"
              data-path="bestof/bielefeld/20260830_102000_hu.jpg"
              data-active="0"
              aria-pressed="false"
            ><span class="sr-only">Bild liken</span></button>
          </div>
        </main>
      `,
    });
  });
  // Ein regulärer HTTP-Origin ist erforderlich, weil Browser den Cookie-
  // Zugriff in `about:blank` aus Sicherheitsgründen sperren.
  await page.goto('http://camera.test/camera/bielefeld');
  await page.evaluate(() => {
    window.likeRequest = null;
    window.likeMode = null;
    window.likeState = null;
    window.WebcamCameraCaptcha = {
      create: () => ({
        request: async () => ({ captchaId: 'captcha-1', captchaCode: 'ABCD' }),
        setLoading: () => {},
        setMessage: () => {},
        hide: () => {},
      }),
    };
    window.fetch = async (url, options) => {
      window.likeRequest = { url, options, body: JSON.parse(options.body) };
      return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
          ok: true,
          active: true,
          path: 'cache/bielefeld/2026/08/30/20260830_102000_hu.jpg',
        }),
      };
    };
  });
  await page.addScriptTag({ path: LIKE_SCRIPT });
  await page.evaluate(() => {
    const controller = window.WebcamCameraBestOfLike.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      buildAppPath: (value) => value,
      updateLiveModeBadge: (mode) => { window.likeMode = mode; },
      ensureBestOfState: async (slug, filename) => { window.likeState = { slug, filename }; },
    });
    controller.init();
    document.querySelector('[data-bestof-toggle]').click();
  });

  await expect.poll(() => page.locator('[data-bestof-toggle]').getAttribute('aria-pressed')).toBe('true');
  const result = await page.evaluate(() => ({
    request: window.likeRequest,
    mode: window.likeMode,
    state: window.likeState,
  }));
  expect(result.request.url).toBe('bestof-toggle.php');
  expect(result.request.body.path).toBe('cache/bielefeld/2026/08/30/20260830_102000_hu.jpg');
  expect(result.request.body.captcha_id).toBe('captcha-1');
  expect(result.mode).toBe('bestof');
  expect(result.state).toEqual({ slug: 'bielefeld', filename: '20260830_102000_hu.jpg' });
});
