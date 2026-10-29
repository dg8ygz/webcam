const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';

const assetUrl = (filename) => new URL(
  `assets/js/${filename}`,
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

/**
 * Prüft den Datencontroller ohne vollständige Kameraansicht. Der API-Request
 * wird vollständig abgefangen; der Test liest daher weder Kamera-DB noch
 * Archivdateien und verändert keinen Laufzeitzustand.
 */
test('builds navigation state and shares one cached day payload', async ({ page }) => {
  const origin = new URL(BASE_URL).origin;
  const fixtureUrl = `${origin}/__webcam_timeline_data_fixture__`;
  let requestCount = 0;

  await page.route(`${fixtureUrl}*`, (route) => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    body: `
      <main
        id="camera-live-root"
        data-camera-slug="test-camera"
        data-timeline-endpoint="${origin}/mock-timeline"
        data-selected-year="2026"
        data-selected-month="5"
        data-selected-day="31"
        data-selected-ts="1800"
        data-timezone="Europe/Berlin"
      >
        <div data-timeline-container></div>
      </main>
    `,
  }));
  await page.route(`${origin}/mock-timeline*`, (route) => {
    requestCount += 1;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        entries: [
          { timestamp: 1700, year: 2026, month: 5, day: 31, time: 170000, time_label: '17:00' },
          { timestamp: 1800, year: 2026, month: 5, day: 31, time: 180000, time_label: '18:00' },
          { timestamp: 1900, year: 2026, month: 5, day: 31, time: 190000, time_label: '19:00' },
        ],
        years: [2025, 2026],
        day_prev_entry: { timestamp: 1600, year: 2026, month: 5, day: 30, time: 235000 },
        day_next_entry: { timestamp: 2000, year: 2026, month: 6, day: 1, time: 1000 },
        months: { 2025: [12], 2026: [5, 6] },
        days: { 2025: { 12: [31] }, 2026: { 5: [30, 31], 6: [1] } },
        latest: { timestamp: 1900, year: 2026, month: 5, day: 31, time: 190000 },
      }),
    });
  });

  await page.goto(`${fixtureUrl}?view=history&year=2026&month=5&day=31&time=180000`);
  await page.addScriptTag({ url: assetUrl('camera-timeline-model.js') });
  await page.addScriptTag({ url: assetUrl('camera-timeline-data.js') });
  await page.evaluate(() => {
    const asInt = (value) => {
      const parsed = parseInt(value, 10);
      return Number.isFinite(parsed) ? parsed : null;
    };
    window.__timelinePresentations = [];
    window.__timelineController = window.WebcamTimelineData.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      isFetchEnabled: () => true,
      parseIntSafe: asInt,
      getAppBasePath: () => '/webcam',
      resolveAppUrl: (value) => new URL(value, window.location.origin).toString(),
      formatDateInTimezone: () => '2026-05-31',
      getCurrentSnapshotKey: () => '1800',
      normalizeNavMode: (value) => ['year', 'month', 'day', 'hour', 'time', 'bestof'].includes(value)
        ? value
        : null,
      onPresentation: (presentation) => window.__timelinePresentations.push(presentation),
    });
    return window.__timelineController.renderFromApi();
  });

  await expect.poll(() => page.evaluate(() => window.__timelinePresentations.length)).toBe(1);
  const result = await page.evaluate(async () => {
    const latest = window.__timelinePresentations.at(-1);
    const cached = await window.__timelineController.loadDay('test-camera', {
      year: 2026,
      month: 5,
      day: 31,
      currentTs: 1800,
    });
    return {
      selected: latest.state.selectedTs,
      previous: latest.state.prev?.timestamp,
      next: latest.state.next?.timestamp,
      monthNext: latest.state.monthNext,
      dayPreviousHref: window.__timelineController.buildEntryUrl(latest.state.dayPrev, 'test-camera', 'day'),
      dayNextHref: window.__timelineController.buildEntryUrl(latest.state.dayNext, 'test-camera', 'day'),
      label: latest.state.labels.time,
      href: window.__timelineController.buildEntryUrl(latest.state.next, 'test-camera', 'hour'),
      cachedEntries: cached.entries.length,
    };
  });

  expect(result).toEqual({
    selected: 1800,
    previous: 1700,
    next: 1900,
    monthNext: { year: 2026, month: 6, day: 1, time: 180000 },
    dayPreviousHref: '/webcam/camera/test-camera?view=history&year=2026&month=05&day=30&time=180000&nav=day',
    dayNextHref: '/webcam/camera/test-camera?view=history&year=2026&month=06&day=01&time=180000&nav=day',
    label: '18:00',
    href: '/webcam/camera/test-camera?view=history&ts=1900&year=2026&month=05&day=31&time=190000&nav=hour',
    cachedEntries: 3,
  });
  expect(requestCount).toBe(1);
});
