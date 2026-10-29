const { test, expect } = require('@playwright/test');
const path = require('node:path');

test('playback uses camera civil days including DST and midnight across browser timezones', async ({ page }) => {
  await page.setContent('<main></main>');
  await page.addScriptTag({ path: path.resolve(__dirname, '../../assets/js/camera-playback.js') });
  const result = await page.evaluate(() => {
    const range = window.WebcamCameraPlayback.calendarDayRange;
    return [range(2026, 10, 9, 'Europe/Berlin'), range(2026, 3, 29, 'Europe/Berlin'),
      range(2026, 10, 25, 'Europe/Berlin'), range(2026, 10, 8, 'Europe/Berlin'),
      range(2026, 10, 9, 'UTC')];
  });
  expect(result[0]).toEqual([1791496800, 1791583199]);
  expect(result[1][1] - result[1][0] + 1).toBe(23 * 3600);
  expect(result[2][1] - result[2][0] + 1).toBe(25 * 3600);
  expect(result[3][1] + 1).toBe(result[0][0]);
  expect(result[4][0]).toBe(1791504000);
});

test('playback after midnight requests the current civil day and whole previous day', async ({ page }) => {
  await page.goto('/fixture-health.txt');
  await page.clock.setFixedTime(new Date('2026-10-08T22:38:00Z'));
  await page.setContent(`<main id="root" data-camera-slug="demo" data-timezone="Europe/Berlin"
    data-selected-year="2026" data-selected-month="10" data-selected-day="9">
    <div data-timelapse><button data-tl-play></button><button data-tl-prev></button>
    <button data-tl-next></button><button data-tl-day-prev>Vortag</button><button data-tl-day-next>Folgetag</button>
    <select data-tl-speed><option>1</option></select><img data-tl-img><span data-tl-meta></span></div></main>`);
  await page.addScriptTag({ path: path.resolve(__dirname, '../../assets/js/camera-playback.js') });
  await page.evaluate(() => {
    window.requestedRanges = [];
    window.fetch = async (url) => {
      const q = new URL(url, location.href).searchParams;
      const from = +q.get('from'), to = +q.get('to');
      window.requestedRanges.push([from, to]);
      return {ok: true, json: async () => ({from, to, frames: []})};
    };
    window.WebcamCameraDayvideo = {create: () => ({initInline() {}})};
    const identity = x => x;
    window.WebcamCameraPlayback.create({getLiveRoot: () => document.querySelector('#root'),
      parseIntSafe: x => parseInt(x, 10), formatDateInTimezone: identity, buildAppUrl: identity,
      toRelativeUrl: identity, toAbsoluteUrl: identity, resolveAppUrl: identity,
      normalizeAssetUrl: identity, isLiveboxOpen: () => false, getEventTargetElement: e => e.target,
    }).initTimelapse();
  });
  await expect.poll(() => page.evaluate(() => window.requestedRanges.length)).toBe(1);
  expect(await page.evaluate(() => window.requestedRanges[0])).toEqual([1791496800, 1791499080]);
  await page.getByRole('button', {name: 'Vortag', exact: true}).click();
  await expect.poll(() => page.evaluate(() => window.requestedRanges.length)).toBe(2);
  expect(await page.evaluate(() => window.requestedRanges[1])).toEqual([1791410400, 1791496799]);
  await page.getByRole('button', {name: 'Folgetag', exact: true}).click();
  await expect.poll(() => page.evaluate(() => window.requestedRanges.length)).toBe(3);
  expect(await page.evaluate(() => window.requestedRanges[2])).toEqual([1791496800, 1791499080]);
});
