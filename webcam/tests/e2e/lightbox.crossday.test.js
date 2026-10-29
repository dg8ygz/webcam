const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/stromberg';

const resolveCameraUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const extractSlug = (cameraUrl) => {
  try {
    const url = new URL(cameraUrl);
    const querySlug = url.searchParams.get('cam');
    if (querySlug) return querySlug;
    const match = url.pathname.match(/\/camera\/([^/]+)/);
    if (match) return decodeURIComponent(match[1]);
    const fallback = url.pathname.split('/').filter(Boolean).pop();
    return fallback || null;
  } catch {
    return null;
  }
};

const parseDateParams = (cameraUrl) => {
  try {
    const url = new URL(cameraUrl);
    const year = parseInt(url.searchParams.get('year') || '', 10);
    const month = parseInt(url.searchParams.get('month') || '', 10);
    const day = parseInt(url.searchParams.get('day') || '', 10);
    return {
      year: Number.isFinite(year) ? year : null,
      month: Number.isFinite(month) ? month : null,
      day: Number.isFinite(day) ? day : null,
    };
  } catch {
    return { year: null, month: null, day: null };
  }
};

const buildApiUrl = (cameraUrl, slug, params = {}) => {
  const url = new URL(cameraUrl);
  let basePath = url.pathname.replace(/\/+$/, '');
  if (basePath.endsWith('/camera.php')) {
    basePath = basePath.slice(0, -'/camera.php'.length);
  } else if (/\/camera\/[^/]+$/.test(basePath)) {
    basePath = basePath.replace(/\/camera\/[^/]+$/, '');
  } else if (basePath.endsWith('/camera')) {
    basePath = basePath.slice(0, -'/camera'.length);
  } else if (slug && basePath.endsWith(`/${slug}`)) {
    basePath = basePath.slice(0, -(slug.length + 1));
  }
  if (basePath === '/') basePath = '';
  const apiPath = `${basePath}/api/public.php/cameras/${encodeURIComponent(slug)}/timeline`;
  const apiUrl = new URL(apiPath.startsWith('/') ? apiPath : `/${apiPath}`, url.origin);
  apiUrl.searchParams.set('limit', '2000');
  if (params.year) apiUrl.searchParams.set('year', String(params.year));
  if (params.month) apiUrl.searchParams.set('month', String(params.month));
  if (params.day) apiUrl.searchParams.set('day', String(params.day));
  return apiUrl.toString();
};

const entryToDay = (entry) => {
  if (!entry || typeof entry !== 'object') return null;
  const year = parseInt(entry.year, 10);
  const month = parseInt(entry.month, 10);
  const day = parseInt(entry.day, 10);
  if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
    return null;
  }
  return { year, month, day };
};

const entryToTime = (entry) => {
  if (!entry || typeof entry !== 'object') return null;
  if (typeof entry.time === 'string' && entry.time.trim() !== '') {
    return entry.time.trim();
  }
  return null;
};

const waitForStatus = async (page) => {
  const handle = await page.waitForFunction(() => {
    const el = document.querySelector('[data-livebox-status]');
    if (!el) return null;
    const text = el.textContent || '';
    const match = text.match(/(\d+)\s*\/\s*(\d+)/);
    if (!match) return null;
    return { index: parseInt(match[1], 10), total: parseInt(match[2], 10) };
  }, { timeout: 15000 });
  return handle ? handle.jsonValue() : null;
};

test.describe('Lightbox cross-day navigation', () => {
  test('loads adjacent day with Shift+PageUp/PageDown', async ({ page, request }) => {
    const cameraUrl = resolveCameraUrl();
    const slug = extractSlug(cameraUrl);
    test.skip(!slug, 'Camera slug not detected');

    const dateParams = parseDateParams(cameraUrl);
    const timelineUrl = buildApiUrl(cameraUrl, slug, dateParams);
    const resp = await request.get(timelineUrl);
    test.skip(!resp.ok(), `Timeline API not reachable (${resp.status()})`);
    const data = await resp.json();
    const entries = Array.isArray(data.entries) ? data.entries : [];
    test.skip(entries.length === 0, 'No timeline entries available');

    let direction = null;
    let edgeEntry = null;
    if (data.day_next_entry) {
      direction = 'next';
      edgeEntry = data.day_next_entry;
    } else if (data.day_prev_entry) {
      direction = 'prev';
      edgeEntry = data.day_prev_entry;
    }
    test.skip(!direction || !edgeEntry, 'No adjacent day found');

    const edgeDay = entryToDay(edgeEntry);
    test.skip(!edgeDay, 'Adjacent day missing date parts');

    const adjacentUrl = buildApiUrl(cameraUrl, slug, edgeDay);
    const adjResp = await request.get(adjacentUrl);
    test.skip(!adjResp.ok(), `Adjacent timeline not reachable (${adjResp.status()})`);
    const adjData = await adjResp.json();
    const adjEntries = Array.isArray(adjData.entries) ? adjData.entries : [];
    test.skip(adjEntries.length < 2, 'Adjacent day has too few entries');

    const boundaryEntry = direction === 'next' ? entries[entries.length - 1] : entries[0];
    const boundaryDay = entryToDay(boundaryEntry);
    const boundaryTime = entryToTime(boundaryEntry);
    test.skip(!boundaryDay || !boundaryTime, 'Boundary entry missing date/time');

    const targetUrl = new URL(cameraUrl);
    targetUrl.searchParams.delete('view');
    targetUrl.searchParams.set('year', String(boundaryDay.year));
    targetUrl.searchParams.set('month', String(boundaryDay.month));
    targetUrl.searchParams.set('day', String(boundaryDay.day));
    targetUrl.searchParams.set('time', boundaryTime);

    await page.goto(targetUrl.toString(), { waitUntil: 'networkidle' });

    const liveImg = page.locator('[data-live-image]');
    test.skip((await liveImg.count()) === 0, 'Live image not available');
    await liveImg.first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForFunction(() => {
      const img = document.querySelector('[data-live-image]');
      return img && img.getAttribute('src');
    }, { timeout: 20000 });

    await page.locator('.live-image').click();
    const lightbox = page.locator('[data-livebox]');
    await expect(lightbox).toBeVisible({ timeout: 5000 });

    const initialStatus = await waitForStatus(page);
    test.skip(!initialStatus || !initialStatus.total, 'Lightbox status not available');
    const initialImgSrc = await page.locator('[data-livebox-img]').getAttribute('src');

    // Der Tagessprung lädt den Nachbartag direkt und sucht dort das Bild mit
    // der ähnlichsten Uhrzeit. Die seitlichen Schaltflächen bleiben bewusst
    // für die schrittweise Bildnavigation zuständig.
    await page.keyboard.press(direction === 'next' ? 'Shift+PageDown' : 'Shift+PageUp');

    await page.waitForFunction((initialTotal) => {
      const el = document.querySelector('[data-livebox-status]');
      if (!el) return false;
      const text = el.textContent || '';
      const match = text.match(/(\d+)\s*\/\s*(\d+)/);
      if (!match) return false;
      const total = parseInt(match[2], 10);
      return total > initialTotal;
    }, initialStatus.total, { timeout: 20000 });

    const imgSrc = await page.locator('[data-livebox-img]').getAttribute('src');
    expect(imgSrc).toBeTruthy();
    expect(imgSrc).not.toBe(initialImgSrc);
  });
});
