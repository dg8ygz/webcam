const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';

const assetUrl = () => new URL(
  'assets/js/camera-stream.js',
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

const preferenceAssetUrl = () => new URL(
  'assets/js/camera-stream-preference.js',
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

const hlsAssetUrl = () => new URL(
  'assets/js/camera-stream-hls.js',
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

const timelineAssetUrl = () => new URL(
  'assets/js/camera-timeline.js',
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

/**
 * Baut nur die DOM-Elemente auf, die der Stream-Controller wirklich benötigt.
 * Die Streamantworten werden von Playwright abgefangen; dadurch belegen diese
 * Tests weder einen echten MJPEG-Slot noch starten sie einen HLS-Runner.
 */
const prepareController = async (page, { preferHls = false, navMode = 'time', realHls = false } = {}) => {
  const origin = new URL(BASE_URL).origin;
  const fixtureUrl = `${origin}/__webcam_stream_controller_fixture__`;
  await page.route(fixtureUrl, (route) => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    body: `
      <main
        id="camera-live-root"
        data-camera-slug="stream-test"
        data-stream-enabled="1"
        data-stream-endpoint="${origin}/mock-stream"
        data-stream-hls-endpoint="${origin}/mock-hls"
        data-stream-preference="${preferHls ? 'hls' : ''}"
      >
        <button type="button" data-live-display="stream">Livestream</button>
        <button type="button" data-live-display="snapshot">Snapshot</button>
        <div class="live-image" data-live-display="snapshot">
          <img data-live-image alt="Snapshot">
          <img data-live-stream data-stream-src="${origin}/mock-stream" alt="MJPEG">
          <video data-live-hls muted></video>
          <span data-stream-status hidden></span>
          <span data-live-mode-badge data-mode="${navMode}"></span>
        </div>
      </main>
    `,
  }));
  await page.goto(fixtureUrl);
  if (realHls) {
    await page.addScriptTag({ url: new URL('assets/vendor/hls/hls.light.min.js', `${BASE_URL.replace(/\/$/, '')}/`).toString() });
  }
  await page.addScriptTag({ url: timelineAssetUrl() });
  // Der Koordinator wird im Browser genauso wie in camera.php erst nach
  // seinen beiden kleinen Teilmodulen geladen.
  await page.addScriptTag({ url: preferenceAssetUrl() });
  await page.addScriptTag({ url: hlsAssetUrl() });
  await page.addScriptTag({ url: assetUrl() });
  await page.evaluate(({ currentNavMode }) => {
    window.__snapshotLoads = 0;
    window.__timelineController = window.WebcamTimeline.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      navRoot: document,
      prefersReducedMotion: () => true,
      toRelativeUrl: (url) => `${url.pathname}${url.search}${url.hash}`,
      getLiveDisplayMode: () => document.querySelector('.live-image')?.dataset.liveDisplay || 'snapshot',
    });
    window.__streamController = window.WebcamCameraStream.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      resolveNavMode: () => currentNavMode,
      updateLiveModeBadge: (mode) => window.__timelineController.updateLiveModeBadge(mode),
      startImageLoading: () => {},
      finishImageLoading: () => {},
      initLiveImageLoad: () => { window.__snapshotLoads += 1; },
    });
    window.__streamController.initLiveDisplay();
  }, { currentNavMode: navMode });
};

test.describe('Camera stream controller', () => {
  test('shows livestream instead of the previous month mode while stream is active', async ({ page }) => {
    await page.route('**/mock-stream?probe=1', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ available: true }),
    }));
    await page.route('**/mock-stream', (route) => route.fulfill({
      status: 200,
      contentType: 'image/gif',
      body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64'),
    }));
    await prepareController(page, { navMode: 'month' });

    await expect(page.locator('[data-live-mode-badge]')).toHaveText('Monat');
    await page.locator('[data-live-display="stream"]').click();
    await expect(page.locator('[data-live-mode-badge]')).toHaveText('Livestream');
    await expect(page.locator('[data-live-mode-badge]')).toHaveAttribute('data-mode', 'time');

    await page.locator('[data-live-display="snapshot"]').click();
    await expect(page.locator('[data-live-mode-badge]')).toHaveText('Monat');
    await expect(page.locator('[data-live-mode-badge]')).toHaveAttribute('data-mode', 'month');
  });

  test('stops an active stream before snapshot navigation and persists snapshot mode', async ({ page }) => {
    await page.route('**/mock-stream?probe=1', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ available: true }),
    }));
    await page.route('**/mock-stream', (route) => route.fulfill({
      status: 200,
      contentType: 'image/gif',
      body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64'),
    }));
    await prepareController(page);

    await page.locator('[data-live-display="stream"]').click();
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
    await expect(page.locator('[data-live-stream]')).toHaveAttribute('src', /mock-stream/);

    await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());

    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
    await expect(page.locator('[data-live-stream]')).not.toHaveAttribute('src', /.+/);
    expect(await page.evaluate(() => sessionStorage.getItem('wc_live_display_stream-test'))).toBe('snapshot');
    expect(await page.evaluate(() => window.__snapshotLoads)).toBeGreaterThan(0);
  });

  test('treats an administrative 403 as controlled snapshot stop without HLS fallback', async ({ page }) => {
    let hlsRequests = 0;
    await page.route('**/mock-stream?probe=1', (route) => route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Verbindung getrennt' }),
    }));
    await page.route('**/mock-hls*', (route) => {
      hlsRequests += 1;
      return route.fulfill({ status: 500, body: '' });
    });
    await prepareController(page);

    await page.locator('[data-live-display="stream"]').click();
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
    await expect(page.locator('[data-stream-status]')).toHaveText('Verbindung getrennt');
    expect(hlsRequests).toBe(0);
    expect(await page.evaluate(() => window.__snapshotLoads)).toBeGreaterThan(0);
  });

  test('keeps a 503 client limit visible and schedules retry without HLS fallback', async ({ page }) => {
    let hlsRequests = 0;
    await page.route('**/mock-stream?probe=1', (route) => route.fulfill({
      status: 503,
      headers: { 'Retry-After': '60' },
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Alle Streamplätze sind belegt.' }),
    }));
    await page.route('**/mock-hls*', (route) => {
      hlsRequests += 1;
      return route.fulfill({ status: 500, body: '' });
    });
    await prepareController(page);

    await page.locator('[data-live-display="stream"]').click();
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
    await expect(page.locator('[data-stream-status]')).toContainText('Neuer Versuch in 60s');
    expect(hlsRequests).toBe(0);
  });

  test('prefers hls.js when Chromium also reports native HLS support', async ({ page }) => {
    await page.addInitScript(() => {
      HTMLMediaElement.prototype.play = () => Promise.resolve();
      window.__hlsInstances = 0;
      window.__hlsDestroys = 0;
      window.__hlsSources = [];
      const nativeCanPlayType = HTMLMediaElement.prototype.canPlayType;
      HTMLMediaElement.prototype.canPlayType = function canPlayType(type) {
        if (type === 'application/vnd.apple.mpegurl') {
          return 'maybe';
        }
        return nativeCanPlayType.call(this, type);
      };

      class FakeHls {
        static Events = {
          ERROR: 'error',
          MANIFEST_PARSED: 'manifest-parsed',
        };

        static isSupported() {
          return true;
        }

        constructor() {
          window.__hlsInstances += 1;
          this.listeners = new Map();
          this.video = null;
        }

        on(event, listener) {
          this.listeners.set(event, listener);
        }

        attachMedia(video) {
          this.video = video;
        }

        loadSource(source) {
          window.__hlsSources.push(source);
          this.listeners.get(FakeHls.Events.MANIFEST_PARSED)?.();
          this.video?.dispatchEvent(new Event('loadedmetadata'));
        }

        destroy() {
          window.__hlsDestroys += 1;
        }
      }

      window.Hls = FakeHls;
    });
    await page.route('**/mock-hls?probe=1', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    }));
    await prepareController(page, { preferHls: true });

    await expect(page.locator('.live-image')).toHaveAttribute('data-stream-renderer', 'hls');
    await expect.poll(() => page.evaluate(() => window.__hlsSources.length)).toBe(1);
    expect(await page.evaluate(() => window.__hlsInstances)).toBe(1);
    expect(await page.locator('[data-live-hls]').getAttribute('src')).toBeNull();

    // Bielefeld-Nord nutzt in der Praxis HLS. Auch dieser Decoder muss beim
    // Sprung in den Navigationsbaum vollständig freigegeben werden.
    await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
    expect(await page.evaluate(() => window.__hlsDestroys)).toBeGreaterThan(0);
    expect(await page.evaluate(() => sessionStorage.getItem('wc_live_display_stream-test'))).toBe('snapshot');
  });
});

// Synthetic transport: no camera/FFmpeg process, but real browser promises,
// events, controls and navigation. Error payloads must never leak URLs.
const prepareHlsFailure = async (page, { blocked = false, status = 0, fatal = true } = {}) => {
  await page.addInitScript(({ blocked, status, fatal }) => {
    HTMLMediaElement.prototype.play = () => blocked
      ? Promise.reject(new DOMException('policy', 'NotAllowedError'))
      : Promise.resolve();
    window.__hlsStarts = 0;
    window.__hlsDestroys = 0;
    class FakeHls {
      static Events = { ERROR: 'error', MANIFEST_PARSED: 'manifest' };
      static isSupported() { return true; }
      constructor() { this.listeners = new Map(); window.__hls = this; }
      on(event, callback) { this.listeners.set(event, callback); }
      attachMedia(video) { this.video = video; }
      loadSource() {
        window.__hlsStarts++;
        if (status) this.fail(status, fatal);
        else this.video.dispatchEvent(new Event('loadedmetadata'));
      }
      fail(code, fatal = true) {
        this.listeners.get('error')?.('error', { fatal, type: 'networkError', response: { code }, url: 'https://secret.invalid/private' });
      }
      expire(code = 410) {
        this.listeners.get('error')?.('error', {
          fatal: false, type: 'networkError', details: 'fragLoadError',
          frag: { sn: 450 }, response: { code },
        });
      }
      destroy() { window.__hlsDestroys++; }
    }
    window.Hls = FakeHls;
  }, { blocked, status, fatal });
  await page.route('**/mock-hls?probe=1', route => route.fulfill({
    status: 200, contentType: 'application/json', body: '{"ok":true}',
  }));
  await prepareController(page, { preferHls: true });
};

test('blocked autoplay keeps video controls usable and does not restart on video click', async ({ page }) => {
  await prepareHlsFailure(page, { blocked: true });
  await expect(page.locator('[data-stream-status]')).toContainText('Automatischer Videostart blockiert');
  expect(await page.locator('video').evaluate(v => v.controls)).toBe(true);
  await page.locator('video').dispatchEvent('click');
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
  await page.locator('video').dispatchEvent('playing');
  await expect(page.locator('[data-stream-status]')).toBeHidden();
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
});

test('failed video segments report HTTP status and release the decoder', async ({ page }) => {
  await prepareHlsFailure(page, { status: 502 });
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toContainText('Netzwerkfehler (HTTP 502)');
  await expect(page.locator('[data-stream-status]')).not.toContainText('secret.invalid');
  expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
});

test('403 during playback stops without reconnect or alternate transport', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.evaluate(() => window.__hls.fail(403, false));
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toContainText('HTTP 403');
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
  expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
});

for (const status of [410, 404]) {
  test(`expired HLS segment (${status}) reloads a fresh source once and bounds retries`, async ({ page }) => {
    await prepareHlsFailure(page);
    await page.evaluate(code => {
      window.__previousHls = window.__hls;
      window.__hls.expire(code);
    }, status);
    await expect.poll(() => page.evaluate(() => window.__hlsStarts)).toBe(2);
    expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
    await page.locator('video').dispatchEvent('playing');
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
    await expect(page.locator('[data-stream-status]')).toBeHidden();
    // A late failure from the destroyed transport must not affect its successor.
    await page.evaluate(() => window.__previousHls.expire());
    expect(await page.evaluate(() => window.__hlsStarts)).toBe(2);
    await page.evaluate(() => window.__hls.expire());
    await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
    await expect(page.locator('[data-stream-status]')).toContainText('Wiederanlauf fehlgeschlagen');
    expect(await page.evaluate(() => window.__hlsStarts)).toBe(2);
    expect(await page.evaluate(() => window.__hlsDestroys)).toBe(2);
  });
}

test('navigation cancels queued HLS expiry recovery', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.evaluate(() => {
    window.__hls.expire();
    window.__streamController.stopForSnapshotNavigation();
  });
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
  expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
});

test('an unavailable HLS playlist is not treated as an expired segment', async ({ page }) => {
  await prepareHlsFailure(page, { status: 404 });
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toContainText('HTTP 404');
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
});

test('real hls.js replaces an expired playlist and respects denial on the fresh request', async ({ page }) => {
  let playlists = 0;
  let expiredSegments = 0;
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/mock-hls*', route => {
    const query = new URL(route.request().url()).searchParams;
    if (query.has('probe')) return route.fulfill({
      status: 200, contentType: 'application/json', body: '{"ok":true}',
    });
    if (query.has('file')) {
      expiredSegments++;
      return route.fulfill({ status: 410, body: '' });
    }
    playlists++;
    if (playlists > 1) return route.fulfill({ status: 403, body: '' });
    return route.fulfill({
      status: 200, contentType: 'application/vnd.apple.mpegurl',
      body: '#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:450\n#EXTINF:2,\n?file=seg_00450.ts\n#EXT-X-ENDLIST\n',
    });
  });
  await prepareController(page, { preferHls: true, realHls: true });
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toContainText('HTTP 403');
  expect(playlists).toBe(2);
  expect(expiredSegments).toBe(1);
  expect(errors).toEqual([]);
});

test('native video error after metadata remains visible and stops playback', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.locator('video').dispatchEvent('error');
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toContainText('nicht geladen oder decodiert');
  expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
});

test('late video events after snapshot navigation cannot reactivate the player', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());
  await page.locator('video').dispatchEvent('loadedmetadata');
  await page.locator('video').dispatchEvent('playing');
  await page.evaluate(() => window.__hls.fail(502));
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  await expect(page.locator('[data-stream-status]')).toBeHidden();
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
});

test('snapshot navigation cancels a pending HLS probe', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  let received;
  const started = new Promise(resolve => { received = resolve; });
  await page.route('**/mock-hls?probe=1', async route => {
    received(); await gate;
    await route.fulfill({status: 200, contentType: 'application/json', body: '{"ok":true}'});
  });
  await page.locator('button[data-live-display="stream"]').click();
  await started;
  await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());
  const response = page.waitForResponse('**/mock-hls?probe=1');
  release(); await response;
  await page.waitForTimeout(100);
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'snapshot');
  expect(await page.evaluate(() => window.__hlsStarts)).toBe(1);
});

test('a superseded HLS probe cannot stop a newer successful start', async ({ page }) => {
  await prepareHlsFailure(page);
  await page.evaluate(() => window.__streamController.stopForSnapshotNavigation());
  let release, received, calls = 0;
  const gate = new Promise(resolve => { release = resolve; });
  const started = new Promise(resolve => { received = resolve; });
  await page.route('**/mock-hls?probe=1', async route => {
    if (++calls === 1) { received(); await gate; }
    await route.fulfill({status: 200, contentType: 'application/json', body: '{"ok":true}'});
  });
  await page.locator('button[data-live-display="stream"]').click();
  await started;
  await page.locator('button[data-live-display="stream"]').click();
  await expect.poll(() => page.evaluate(() => window.__hlsStarts)).toBe(2);
  const response = page.waitForResponse('**/mock-hls?probe=1');
  release(); await response; await page.waitForTimeout(100);
  await expect(page.locator('.live-image')).toHaveAttribute('data-live-display', 'stream');
  expect(await page.evaluate(() => window.__hlsDestroys)).toBe(1);
});
