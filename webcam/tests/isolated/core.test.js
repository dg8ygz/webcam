const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.WEBCAM_REPOSITORY_ROOT, 'fixture.json')));

test.beforeEach(async ({ context }) => {
  // Maps are not under test; external traffic is never needed for acceptance.
  await context.route('**/*', (route) => {
    const url = new URL(route.request().url());
    return url.origin === process.env.BASE_URL ? route.continue() : route.abort();
  });
});

test('camera diagnostic log authenticates, redacts and only acknowledges a stored log', async ({ request }) => {
  const root = process.env.WEBCAM_REPOSITORY_ROOT;
  expect(fs.existsSync(path.join(root, '.isolated-e2e'))).toBe(true);
  const log = path.join(root, 'var/log/upload.log');
  const send = (key, text) => request.post('/include/log.php', {
    headers: { 'X-Upload-Key': key },
    multipart: { wc: 'bielefeld', serial: '1791655200',
      log: { name: 'capture-error.txt', mimeType: 'text/plain', buffer: Buffer.from(text) } },
  });
  expect((await send('wrong-key', 'UNAUTHORIZED_DIAGNOSTIC')).status()).toBe(403);
  expect(fs.readFileSync(log, 'utf8')).not.toContain('UNAUTHORIZED_DIAGNOSTIC');
  const response = await send('fixture-key', 'backend_exit=1\nFailed eosremoterelease\nfixture-key\nrtsp://user:secret@example.invalid/live\npassword=hidden');
  expect(response.status()).toBe(200);
  expect(await response.text()).toBe('ok\n');
  const stored = fs.readFileSync(log, 'utf8');
  expect(stored).toContain('serial=1791655200');
  expect(stored).toContain('Failed eosremoterelease');
  for (const secret of ['fixture-key', 'user:secret', 'password=hidden']) expect(stored).not.toContain(secret);
  expect((await send('fixture-key', 'x'.repeat(262145))).status()).toBe(413);
  // Deliberately fail the log write in this disposable private server only.
  fs.renameSync(log, log + '.before');
  fs.mkdirSync(log);
  try {
    const failed = await send('fixture-key', 'must-not-ack');
    expect(failed.status()).toBe(500);
    expect(await failed.text()).not.toBe('ok\n');
  } finally {
    fs.rmdirSync(log);
    fs.renameSync(log + '.before', log);
  }
});

test('overview lists public cameras and keeps test camera hidden', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/');
  await expect(page.locator('[data-camera-card]')).toHaveCount(2);
  await expect(page.locator('[data-camera-card][data-slug="testcam"]')).toHaveCount(0);
  await expect(page.locator('[data-camera-card] img').first()).toBeVisible();
  await expect.poll(() => page.locator('[data-camera-card] img').first().evaluate((img) => img.complete && img.naturalWidth > 0)).toBe(true);
  expect(errors).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('camera renders real image, EXIF and direct test-camera access', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/camera/testcam');
  await expect(page.locator('#camera-live-root')).toBeVisible();
  await expect(page.locator('[data-open-exif]').first()).toBeAttached();
  expect(await page.locator('#camera-live-root').getAttribute('data-camera-slug')).toBe('testcam');
  expect(errors).toEqual([]);
});

test('camera menu switches from MJPEG to HLS and back to MJPEG', async ({ page }) => {
  // Only mutate the disposable fixture, never a live camera configuration.
  const configureStream = mode => execFileSync('php', ['-r', `
    $root = getenv('WEBCAM_REPOSITORY_ROOT');
    if (!$root || !is_file($root.'/.isolated-e2e') || getenv('WEBCAM_CONFIG_DB') !== $root.'/fixture.sqlite') exit(1);
    require $root.'/config/camera_store.php';
    $document = webcam_camera_store_load();
    foreach ($document['cameras'] as &$camera) {
      if ($camera['slug'] === 'bielefeld') $camera['stream']['mode'] = $argv[1];
    }
    unset($camera);
    webcam_camera_store_save($document);
  `, mode], { timeout: 10000 });
  configureStream('rtsp');
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    HTMLMediaElement.prototype.play = () => Promise.resolve();
  });
  // Real page/templates/controller, synthetic media: no camera or ffmpeg.
  await page.route('**/assets/vendor/hls/hls.light.min.js*', route => route.fulfill({
    contentType: 'application/javascript', body: `
      window.Hls = class {
        static Events = { ERROR: 'error', MANIFEST_PARSED: 'manifest' };
        static isSupported() { return true; }
        on() {}
        attachMedia(video) { this.video = video; }
        loadSource() { this.video.dispatchEvent(new Event('loadedmetadata')); }
        destroy() {}
      };
    `,
  }));
  await page.route('**/stream/bielefeld*', route => {
    if (new URL(route.request().url()).searchParams.has('probe')) {
      return route.fulfill({ contentType: 'application/json', body: '{"available":true,"ok":true}' });
    }
    return route.fulfill({ contentType: 'image/gif', body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64') });
  });
  try {
    await page.goto('/camera/bielefeld?switch-test=1');
    await expect(page.locator('.live-image')).toHaveAttribute('data-stream-renderer', 'mjpeg');
    await expect(page.locator('[data-live-stream]')).toHaveAttribute('src', /stream\/bielefeld/);
    await page.getByRole('button', { name: 'Optionen', exact: true }).click();
    await page.locator('[data-stream-video-link]').click();
    await expect(page).toHaveURL(/stream=hls/);
    await expect(page.locator('.live-image')).toHaveAttribute('data-stream-renderer', 'hls');
    await expect(page.locator('[data-live-stream]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Optionen', exact: true }).click();
    await page.getByRole('link', { name: 'Livestream', exact: true }).click();
    await expect(page.locator('.live-image')).toHaveAttribute('data-stream-renderer', 'mjpeg');
    expect(new URL(page.url()).searchParams.has('stream')).toBe(false);
    await expect(page.locator('[data-live-stream]')).toHaveAttribute('src', /stream\/bielefeld/);
    expect(errors).toEqual([]);
  } finally {
    await page.goto('about:blank');
    configureStream('none');
  }
});

test('history and timelapse contain the seeded captures, never skip empty fixtures', async ({ request }) => {
  const history = await request.get(`/api/public.php/cameras/bielefeld/history?mode=days&days=3&ts=${fixture.to}`);
  expect(history.ok()).toBe(true);
  const historyData = await history.json();
  expect(historyData.items).toHaveLength(3);
  const response = await request.get(`/api/public.php/cameras/bielefeld/timelapse?from=${fixture.from}&to=${fixture.to}&frame_count=12&max_width=640`);
  expect(response.ok()).toBe(true);
  const data = await response.json();
  expect(data.frames.length).toBe(fixture.frames_per_camera);
  for (const frame of data.frames) {
    const url = new URL(frame.url, process.env.BASE_URL);
    expect(url.origin).toBe(process.env.BASE_URL);
    const image = await request.get(url.href);
    expect(image.ok()).toBe(true);
    expect(image.headers()['content-type']).toContain('image/jpeg');
  }
});

test('global Best-Of includes seeded favourites but excludes test camera', async ({ page, request }) => {
  const response = await request.get('/api/public.php/bestof');
  expect(response.ok()).toBe(true);
  const data = await response.json();
  expect(data.items).toHaveLength(2);
  expect(data.items.map(item => item.camera_slug).sort()).toEqual(['bielefeld', 'stromberg']);
  expect((await request.get('/api/public.php/bestof?camera=testcam')).status()).toBe(404);
  await page.goto('/bestof');
  await expect(page.locator('[data-global-bestof-grid] .global-bestof-card')).toHaveCount(2);
});

test('private configuration and runtime paths stay HTTP-inaccessible', async ({ request }) => {
  for (const target of ['/fixture.sqlite', '/fixture.json', '/config/app.php', '/var/state/cameras/testcam.json']) {
    expect((await request.get(target)).status()).toBe(403);
  }
});

test('history UI and playback UI are available with seeded images', async ({ page }) => {
  await page.goto('/camera/bielefeld?view=history');
  await expect(page.locator('[data-history-root]')).toBeVisible();
  await page.goto('/camera/bielefeld?view=playback');
  await expect(page.locator('[data-timelapse]').first()).toBeVisible();
  await expect(page.locator('[data-tl-img]').first()).toHaveAttribute('src', /.+/);
});

test('weather is directly accessible for test camera and includes both measurements', async ({ page, request }) => {
  const response = await page.goto('/weather?camera=testcam');
  expect(response.headers()['x-robots-tag']).toContain('noindex');
  await expect(page.locator('body')).toContainText(/Temperatur/);
  await expect(page.locator('body')).toContainText(/Luftfeuchte/);
  expect((await request.get('/weather?camera=missing-fixture')).status()).toBe(404);
});

test('admin is protected and authenticated UI loads current revision', async ({ page, request }) => {
  const anonymous = await request.get('/admin/api.php/cameras');
  expect([401, 403]).toContain(anonymous.status());
  await page.setExtraHTTPHeaders({ Authorization: 'Bearer isolated-e2e-token' });
  await page.goto('/admin/');
  await expect(page.getByRole('button', { name: /Kameras \(/ })).toBeVisible();
  const revision = fs.readFileSync(path.join(process.env.WEBCAM_REPOSITORY_ROOT, 'ITERATION'), 'utf8').trim();
  await expect(page.locator('.rail-sub')).toContainText(`Iteration ${revision}`);
});
