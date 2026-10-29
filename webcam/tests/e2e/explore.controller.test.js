const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const fixture = JSON.parse(fs.readFileSync(path.join(process.env.WEBCAM_REPOSITORY_ROOT, 'fixture.json')));
const clock = new Date(fixture.to * 1000).toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
const date = new Date(fixture.to * 1000).toLocaleDateString('en-CA', { timeZone: 'Europe/Berlin' });

test.beforeEach(async ({ context }) => {
  await context.route('**/*', route => new URL(route.request().url()).origin === process.env.BASE_URL ? route.continue() : route.abort());
});

test('explore compares archived cameras, displays gaps and rotates snapshots', async ({ page }, testInfo) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  await page.goto('/');
  await page.getByRole('link', { name: 'Mehrkamera', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Mehrkamera', exact: true })).toBeVisible();
  const navigation = page.locator('.overview-hero');
  for (const name of ['Webcam-Übersicht', 'Globales Best-Of', 'Status', 'Mehrkamera', 'Wetter']) {
    await expect(navigation.getByRole('link', { name, exact: true })).toBeVisible();
  }
  await navigation.getByRole('link', { name: 'Webcam-Übersicht', exact: true }).click();
  await expect(page.locator('[data-camera-grid]')).toBeVisible();
  await page.getByRole('link', { name: 'Mehrkamera', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Vorhersage & Radar' })).toHaveCount(0);
  await expect(page.locator('#multi-grid figure')).toHaveCount(2);
  await expect.poll(() => page.locator('#multi-grid img').first().evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  await page.locator('#multi-date').fill(date);
  const trigger = page.getByRole('button', { name: 'Uhrzeit', exact: true });
  const openClock = async () => { if (testInfo.project.use.hasTouch) await trigger.tap(); else await trigger.click(); };
  await openClock();
  const panel = page.getByRole('listbox', { name: /Uhrzeit/ });
  await expect(panel.getByRole('option')).toHaveCount(144);
  if (!testInfo.project.use.hasTouch) await page.mouse.move(0, 0);
  await expect(panel).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(panel).toBeHidden();
  await expect(trigger).toBeFocused();
  await openClock();
  const selectedClock = clock.slice(0, 4) + '0';
  const option = panel.getByRole('option', { name: selectedClock, exact: true });
  await option.scrollIntoViewIfNeeded();
  await page.screenshot({ path: testInfo.outputPath('multicamera-clock.png') });
  if (testInfo.project.use.hasTouch) await option.tap(); else await option.click();
  await expect(panel).toBeHidden();
  await expect(trigger).toHaveText(`${selectedClock} ▾`);
  await expect(page.locator('#multi-clock')).toHaveValue(selectedClock);
  await page.locator('#multi-load').click();
  await expect(page.locator('#multi-status')).toContainText('Vergleich für');
  await expect(page.locator('#multi-grid figcaption').first()).toContainText('Abweichung');
  await page.locator('#multi-date').fill('2001-01-01'); await page.locator('#multi-load').click();
  await expect(page.locator('#multi-grid .missing')).toHaveCount(2);
  await page.locator('#camera-mode').selectOption('show');
  await expect(trigger).toBeDisabled();
  await expect(page.locator('#multi-grid img')).toHaveCount(1);
  await page.locator('#show-play').click(); await expect(page.locator('#show-play')).toHaveText('Diashow pausieren');
  await page.locator('#show-play').click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
});

test('random slideshow uses all public archives and stops on pause or mode change', async ({ page, request }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const response = await request.get('/api/public.php/presentation/random?camera=testcam');
  expect(response.status()).toBe(200);
  expect(response.headers()['cache-control']).toBe('no-store');
  const sample = await response.json();
  expect(['bielefeld', 'stromberg']).toContain(sample.camera.slug);
  expect(sample.camera.frame.timestamp).toBeLessThanOrEqual(Math.floor(Date.now() / 1000));
  let randomCalls = 0;
  page.on('request', req => { if (new URL(req.url()).pathname.endsWith('/presentation/random')) randomCalls++; });
  await page.goto('/explore.php?camera=testcam');
  await page.locator('#camera-mode').selectOption('show');
  await page.locator('#show-source').selectOption('random');
  await expect(page.locator('#multi-status')).toContainText('Zufallsdiashow');
  await expect(page.locator('#camera-selects')).toBeHidden();
  await expect(page.locator('#multi-grid img')).toHaveCount(1);
  await expect.poll(() => page.locator('#multi-grid img').evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  await expect(page.locator('#multi-grid figcaption')).toContainText('Zufällige Archivaufnahme');
  await expect(page.locator('#multi-grid figcaption a')).not.toHaveAttribute('href', /testcam/);
  const first = randomCalls;
  await page.locator('#show-next').click();
  await expect.poll(() => randomCalls).toBeGreaterThan(first);
  await expect(page.locator('#multi-status')).toContainText('Zufallsdiashow');
  await page.locator('#show-delay').selectOption('5');
  await page.locator('#show-play').click();
  const before = randomCalls;
  await expect.poll(() => randomCalls, { timeout: 8000 }).toBeGreaterThan(before);
  await page.locator('#show-play').click();
  await expect(page.locator('#show-play')).toHaveText('Diashow starten');
  const paused = randomCalls;
  await page.waitForTimeout(5300);
  expect(randomCalls).toBe(paused);
  await page.locator('#show-source').selectOption('current');
  await expect(page.locator('#camera-selects')).toBeVisible();
  await expect(page.locator('#show-next')).toBeHidden();
  await expect(page.locator('#multi-status')).toContainText('Aktuelle Aufnahmen');
  expect(errors).toEqual([]);
});

test('camera dayvideo selects periods and clock while cancelling old builds', async ({ page }, testInfo) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const requests = [];
  let limited = false;
  await page.route('**/dayvideo?*', route => {
    const query = new URL(route.request().url()).searchParams;
    const period = query.get('period'); requests.push(Object.fromEntries(query));
    if (limited) return route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ detail: 'Rate limit exceeded', retry_after: 120 }) });
    if (period === 'month') return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ retry_after: 1 }) });
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ video_url: `cache/${period}.mp4`, download_url: `cache/${period}.mp4`, cached: true }) });
  });
  await page.route('**/cache/*.mp4*', route => route.abort());
  await page.goto('/camera/bielefeld');
  await page.locator('.tl-menu__toggle[aria-label="Optionen"]').click();
  await page.locator('[data-dayvideo-open]').click();
  const choose = async (label, value) => {
    const before = requests.length;
    const trigger = page.getByRole('button', { name: label, exact: true });
    if (testInfo.project.use.hasTouch) await trigger.tap(); else await trigger.click();
    const option = page.locator(`.dayvideo-picker__panel:not([hidden]) [data-dayvideo-picker-option="${value}"]`);
    await option.scrollIntoViewIfNeeded();
    if (testInfo.project.use.hasTouch) await option.tap(); else await option.click();
    await expect(page.locator('[data-dayvideo-status]')).toContainText('Mit „Video laden“ starten');
    expect(requests.length).toBe(before);
    await page.locator('[data-dayvideo-reload]').click();
    await expect.poll(() => requests.length).toBeGreaterThan(before);
    expect(requests.at(-1).refresh).toBeUndefined();
  };
  const period = page.getByRole('button', { name: 'Zeitraum', exact: true });
  const video = page.locator('[data-dayvideo-video]');
  await expect(period).toBeVisible();
  await expect(page.locator('[data-dayvideo-clock-label]')).toBeHidden();
  await expect(page.locator('.tl-menu.is-dayvideo-dismissed .tl-menu__dropdown')).toBeHidden();
  await period.click();
  await expect(page.getByRole('listbox', { name: 'Zeitraum', exact: true }).getByRole('option')).toHaveText(['Ganzer Tag', 'Sonnenaufgang', 'Abend', 'Nacht', 'Monatsfilm', 'Jahresfilm']);
  await page.mouse.move(1, 1);
  await expect(page.getByRole('listbox', { name: 'Zeitraum', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(period).toBeFocused();
  await expect(video).toHaveAttribute('src', /day\.mp4/);
  for (const value of ['sunrise', 'sunset', 'night', 'year']) {
    await choose('Zeitraum', value);
    await expect(video).toHaveAttribute('src', new RegExp(`${value}\\.mp4`));
    expect(requests.at(-1).period).toBe(value);
    expect(requests.at(-1).fps).toBe('5');
    expect(requests.at(-1).max_width).toBe('1200');
    expect(requests.at(-1).date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  }
  const clockTrigger = page.getByRole('button', { name: 'Tägliche Uhrzeit', exact: true });
  await expect(clockTrigger).toBeVisible();
  await clockTrigger.click();
  await expect(page.getByRole('listbox', { name: 'Tägliche Uhrzeit', exact: true }).getByRole('option')).toHaveCount(144);
  await page.screenshot({ path: testInfo.outputPath('dayvideo-clock-menu.png') });
  await page.keyboard.press('Escape');
  await choose('Tägliche Uhrzeit', '13:20');
  await expect.poll(() => requests.at(-1).clock).toBe('13:20');
  await expect(page.locator('[data-dayvideo-selection]')).toContainText('13:20 Uhr');
  await choose('FPS', '7');
  await expect.poll(() => requests.at(-1).fps).toBe('7');
  await choose('Auflösung', '1600');
  await expect.poll(() => requests.at(-1).max_width).toBe('1600');
  await page.locator('[data-dayvideo-inline]').screenshot({ path: testInfo.outputPath('dayvideo-periods.png') });
  await choose('Zeitraum', 'month');
  await expect.poll(() => requests.at(-1).period).toBe('month');
  await choose('Zeitraum', 'sunrise');
  await expect(video).toHaveAttribute('src', /sunrise\.mp4/);
  await expect(page.locator('[data-dayvideo-clock-label]')).toBeHidden();
  const count = requests.length;
  await page.waitForTimeout(1300);
  expect(requests.length).toBe(count);
  await expect(page.locator('[data-dayvideo-status]')).toContainText('Video aus Cache');
  limited = true;
  await page.locator('[data-dayvideo-refresh]').click();
  await expect(page.locator('[data-dayvideo-status]')).toContainText('Kontingent');
  await expect(page.locator('[data-dayvideo-status]')).toContainText('2 Minute(n)');
  expect(requests.at(-1).refresh).toBe('1');
  await expect(page.locator('[data-dayvideo-status]')).not.toContainText('nicht erzeugt');
  limited = false;
  await choose('Zeitraum', 'month');
  await expect.poll(() => requests.at(-1).period).toBe('month');
  await page.locator('[data-dayvideo-close]').click();
  const closedCount = requests.length;
  await page.waitForTimeout(1300);
  expect(requests.length).toBe(closedCount);
  await expect(page.locator('[data-dayvideo-inline]')).toBeHidden();
  expect(errors).toEqual([]);
});

test('global weather keeps forecast charts without radar and handles failed refresh', async ({ page }, testInfo) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  let failed = false;
  const radarRequests = [];
  await page.route('**/forecast', route => route.fulfill({ status: failed ? 503 : 200, contentType: 'application/json', body: JSON.stringify(failed ? { detail: 'Daten derzeit nicht verfügbar.' } : {
    station: { name: 'DWD Fixture', distance_km: 4.2 }, fetched_at: fixture.to, stale: true,
    data: { issued_at: fixture.to - 3600, hours: Array.from({ length: 48 }, (_, i) => ({ timestamp: fixture.to + i * 3600, temperature: i === 0 ? 12.5 : i === 1 ? -2 : i === 2 ? null : 10 + 5 * Math.sin(i / 6), rain: i === 0 ? null : i % 6 === 0 ? 2.5 : 0, wind: 18 + i % 7, gust: 30 + i % 11, cloud: i % 5 * 20 })) }
  }) }));
  await page.goto('/');
  page.on('request', request => {
    if (/radar|leaflet|tile\.openstreetmap/.test(request.url())) radarRequests.push(request.url());
  });
  await page.getByRole('link', { name: 'Wetter', exact: true }).click();
  await page.locator('#weather-forecast > summary').click();
  await expect(page.locator('#weather-camera option[value=testcam]')).toHaveCount(0);
  await page.locator('#weather-load').click();
  await expect(page.locator('#forecast-status')).toContainText('DWD Fixture');
  await expect(page.locator('#forecast-status')).toContainText('Zwischengespeicherte');
  await expect(page.locator('.forecast-chart svg')).toHaveCount(4);
  const temperature = page.locator('[data-metric="temperature"]');
  const rain = page.locator('[data-metric="rain"]');
  await expect(temperature.locator('.forecast-chart-value')).toContainText('12,5 °C');
  await expect(rain.locator('.forecast-chart-value')).toContainText('keine Daten');
  await expect(rain.locator('.forecast-bar')).toHaveCount(47);
  expect((await temperature.locator('.forecast-series').getAttribute('d')).match(/M/g)).toHaveLength(2);
  await expect(page.locator('#forecast-hour')).toHaveCount(0);
  await temperature.locator('svg').focus();
  await page.keyboard.press('ArrowRight');
  await expect(temperature.locator('.forecast-chart-value')).toContainText('-2 °C');
  await expect(rain.locator('.forecast-chart-value')).toContainText('0 mm');
  await page.keyboard.press('ArrowRight');
  await expect(temperature.locator('.forecast-chart-value')).toContainText('keine Daten');
  await page.locator('#forecast-charts').screenshot({ path: testInfo.outputPath('forecast-charts.png') });
  await expect(page.locator('#weather-forecast > summary')).toHaveText('Vorhersage');
  await expect(page.locator('[id^="radar-"]')).toHaveCount(0);
  expect(radarRequests).toEqual([]);
  failed = true; await page.locator('#weather-load').click();
  await expect(page.locator('#forecast-status')).toContainText('Daten derzeit nicht verfügbar');
  await expect(page.locator('.forecast-chart')).toHaveCount(0);
  await expect(page.locator('#forecast-time')).toBeEmpty();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
});

test('explore keeps test cameras unlisted but supports direct links and rejects invalid ranges', async ({ page, request }) => {
  await page.goto('/camera/bielefeld');
  await expect(page.locator('a[href*="explore.php"]')).toHaveCount(0);
  await page.goto('/weather?camera=testcam');
  await expect(page.locator('#weather-forecast')).toHaveCount(0);
  await page.goto('/explore.php#weather');
  await expect(page).toHaveURL(/\/weather#weather-forecast$/);
  await expect(page.locator('#weather-load')).toBeVisible();
  await page.goto('/explore.php');
  await expect(page.locator('[data-camera-select] option[value=testcam]')).toHaveCount(0);
  await expect(page.getByText('Zeitraffer & Filme', { exact: true })).toHaveCount(0);
  await expect(page.locator('#film-period')).toHaveCount(0);
  await page.goto('/explore.php?camera=testcam');
  await expect(page.locator('[data-camera-select]').first()).toHaveValue('testcam');
  for (const query of [
    'cameras=bielefeld,stromberg,testcam,x,y&live=1',
    'cameras=bielefeld&date=2024-02-31',
    'cameras=bielefeld&date=2024-03-31&clock=02:30',
    'cameras=bielefeld&date[]=2024-01-01',
  ]) {
    const response = await request.get(`/api/public.php/presentation/multiview?${query}`);
    expect(response.status()).toBe(400);
  }
});
