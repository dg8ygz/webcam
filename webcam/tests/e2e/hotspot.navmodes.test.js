const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = (path) => {
  if (/^https?:\/\//i.test(path)) return path;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const rel = path.startsWith('/') ? path.slice(1) : path;
  return new URL(rel, base).toString();
};

const resolveSlug = () => {
  try {
    const url = new URL(buildUrl(CAMERA_PATH));
    const paramSlug = url.searchParams.get('cam');
    if (paramSlug) return paramSlug;
    const pathParts = url.pathname.split('/').filter(Boolean);
    if (pathParts[0] === 'camera' && pathParts[1]) return pathParts[1];
  } catch (e) {
    // ignore
  }
  return 'bielefeld';
};

const buildApiUrl = (slug, query) => {
  const basePath = `/api/public.php/cameras/${slug}/timeline`;
  const qs = query ? `?${query}` : '';
  return buildUrl(basePath + qs);
};

const pad2 = (value) => String(value).padStart(2, '0');
const pad6 = (value) => String(value).padStart(6, '0');

const buildQuery = (params) => {
  const search = new URLSearchParams();
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    search.set(key, String(value));
  });
  return search.toString();
};

const fetchTimeline = async (page, slug, params) => {
  const query = buildQuery(params || {});
  const resp = await page.request.get(buildApiUrl(slug, query));
  if (!resp.ok()) return { ok: false, status: resp.status(), data: null };
  const data = await resp.json().catch(() => null);
  return { ok: true, status: resp.status(), data };
};

const normalizeTime = (value) => {
  if (value === null || value === undefined) return null;
  const raw = String(value).replace(':', '');
  return pad6(raw);
};

const pickCandidateSeed = async (page, slug) => {
  const { ok, data } = await fetchTimeline(page, slug, { limit: 800 });
  if (!ok || !data || !Array.isArray(data.entries) || !data.entries.length) return null;
  const entry = data.entries[Math.floor(data.entries.length / 2)] || data.entries[0];
  if (!entry || !entry.year || !entry.month || !entry.day) return null;
  return entry;
};

const flattenDays = (daysMap, year) => {
  const yearKey = String(year);
  const months = (daysMap && daysMap[yearKey]) ? Object.keys(daysMap[yearKey]) : [];
  const days = [];
  months.forEach((monthKey) => {
    const month = parseInt(monthKey, 10);
    const list = Array.isArray(daysMap[yearKey][monthKey]) ? daysMap[yearKey][monthKey] : [];
    list.forEach((dayValue) => {
      const day = parseInt(dayValue, 10);
      if (Number.isNaN(month) || Number.isNaN(day)) return;
      days.push({ year: parseInt(yearKey, 10), month, day });
    });
  });
  days.sort((a, b) => (a.month - b.month) || (a.day - b.day));
  return days;
};

const pickDayWithNeighbors = async (page, slug) => {
  const seed = await pickCandidateSeed(page, slug);
  if (!seed) return null;

  const seedQuery = {
    year: seed.year,
    month: pad2(seed.month),
    day: pad2(seed.day),
    time: normalizeTime(seed.time),
    limit: 2000,
  };
  const seedResp = await fetchTimeline(page, slug, seedQuery);
  if (!seedResp.ok || !seedResp.data) return null;

  const daysList = flattenDays(seedResp.data.days, seed.year);
  if (!daysList.length) return null;

  const mid = Math.floor(daysList.length / 2);
  const order = [];
  for (let offset = 0; offset < daysList.length; offset += 1) {
    const idx = (offset % 2 === 0) ? mid + (offset / 2) : mid - Math.ceil(offset / 2);
    if (idx >= 0 && idx < daysList.length) order.push(idx);
  }

  for (const idx of order) {
    const day = daysList[idx];
    const resp = await fetchTimeline(page, slug, {
      year: day.year,
      month: pad2(day.month),
      day: pad2(day.day),
      limit: 2000,
    });
    if (!resp.ok || !resp.data) continue;
    const entries = Array.isArray(resp.data.entries) ? resp.data.entries : [];
    if (!entries.length) continue;
    const entry = entries[Math.floor(entries.length / 2)] || entries[0];
    if (!entry || !entry.year || !entry.month || !entry.day) continue;
    if (resp.data.day_prev_entry || resp.data.day_next_entry) {
      return { entry, timeline: resp.data };
    }
  }
  return null;
};

const buildCameraUrl = (params) => {
  const base = buildUrl(CAMERA_PATH);
  const url = new URL(base);
  Object.entries(params).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    url.searchParams.set(key, String(value));
  });
  return url.toString();
};

const paramsValue = (params, key) => {
  if (!params) return null;
  if (params instanceof URLSearchParams) return params.get(key);
  if (Object.prototype.hasOwnProperty.call(params, key)) return params[key];
  return null;
};

const expectParamsMatch = (params, expected) => {
  expect(params).not.toBeNull();
  Object.entries(expected).forEach(([key, value]) => {
    if (value === null || value === undefined) return;
    expect(paramsValue(params, key)).toBe(String(value));
  });
};

const readHotspotParams = async (locator) => locator.evaluate((el) => {
  const href = el.getAttribute('data-url') || '';
  const url = new URL(href, window.location.href);
  return Object.fromEntries(url.searchParams.entries());
});

const requireValue = (value, message) => {
  if (!value) {
    throw new Error(message);
  }
  return value;
};

test.use({
  extraHTTPHeaders: { 'X-WC-TEST': '1' },
});

test.describe('Hotspot navigation modes', () => {
  test('day mode uses day_prev_entry/day_next_entry', async ({ page }) => {
    const slug = resolveSlug();
    const picked = await pickDayWithNeighbors(page, slug);
    requireValue(picked, 'No timeline entry available with day neighbors (need multiple days of data)');
    const baseEntry = picked.entry;
    const data = picked.timeline;
    const prev = data.day_prev_entry || null;
    const next = data.day_next_entry || null;
    requireValue(prev || next, 'No day prev/next entries (need at least two days of data)');

    await page.goto(buildCameraUrl({
      year: baseEntry.year,
      month: pad2(baseEntry.month),
      day: pad2(baseEntry.day),
      time: normalizeTime(baseEntry.time),
      nav: 'day',
    }), { waitUntil: 'networkidle' });

    const badge = page.locator('[data-live-mode-badge]');
    await expect(badge).toHaveAttribute('data-mode', 'day');
    await expect(badge).toContainText('Tag');

    const left = page.locator('[data-timeline-hotspot="left"]').first();
    const right = page.locator('[data-timeline-hotspot="right"]').first();

    if (prev) {
      const params = await readHotspotParams(left);
      expectParamsMatch(params, {
        year: prev.year,
        month: pad2(prev.month),
        day: pad2(prev.day),
        time: pad6(prev.time || ''),
        nav: 'day',
      });
    }

    if (next) {
      const params = await readHotspotParams(right);
      expectParamsMatch(params, {
        year: next.year,
        month: pad2(next.month),
        day: pad2(next.day),
        time: pad6(next.time || ''),
        nav: 'day',
      });
    }
  });

  test('month mode uses adjacent months', async ({ page }) => {
    const slug = resolveSlug();
    const picked = await pickDayWithNeighbors(page, slug);
    requireValue(picked, 'No timeline entry available with day neighbors (need multiple days of data)');
    const baseEntry = picked.entry;
    const data = picked.timeline;

    const yearKey = String(baseEntry.year);
    const months = (data.months && data.months[yearKey]) ? data.months[yearKey] : [];
    const monthList = Array.from(months).map((m) => parseInt(m, 10)).filter(Boolean).sort((a, b) => a - b);
    const dayMap = (data.days && data.days[yearKey]) ? data.days[yearKey] : {};
    requireValue(monthList.length >= 2, 'Not enough months for month navigation (need at least two months of data)');

    const smaller = monthList.filter((m) => m < baseEntry.month);
    const greater = monthList.filter((m) => m > baseEntry.month);
    const prevMonth = smaller.length ? Math.max(...smaller) : null;
    const nextMonth = greater.length ? Math.min(...greater) : null;
    requireValue(prevMonth || nextMonth, 'No adjacent months available for month navigation');

    const pickDay = (month) => {
      const days = Array.isArray(dayMap[String(month)]) ? dayMap[String(month)] : [];
      const list = days.map((d) => parseInt(d, 10)).filter(Boolean).sort((a, b) => a - b);
      if (!list.length) return null;
      let best = null;
      list.forEach((d) => {
        if (d <= baseEntry.day) best = d;
      });
      return best || list[list.length - 1];
    };

    await page.goto(buildCameraUrl({
      year: baseEntry.year,
      month: pad2(baseEntry.month),
      day: pad2(baseEntry.day),
      time: normalizeTime(baseEntry.time),
      nav: 'month',
    }), { waitUntil: 'networkidle' });

    const badge = page.locator('[data-live-mode-badge]');
    await expect(badge).toHaveAttribute('data-mode', 'month');
    await expect(badge).toContainText('Monat');

    const left = page.locator('[data-timeline-hotspot="left"]').first();
    const right = page.locator('[data-timeline-hotspot="right"]').first();

    if (prevMonth) {
      const prevDay = pickDay(prevMonth);
      requireValue(prevDay, 'No day available in previous month');
      const params = await readHotspotParams(left);
      expectParamsMatch(params, {
        year: baseEntry.year,
        month: pad2(prevMonth),
        day: pad2(prevDay),
        nav: 'month',
      });
    }

    if (nextMonth) {
      const nextDay = pickDay(nextMonth);
      requireValue(nextDay, 'No day available in next month');
      const params = await readHotspotParams(right);
      expectParamsMatch(params, {
        year: baseEntry.year,
        month: pad2(nextMonth),
        day: pad2(nextDay),
        nav: 'month',
      });
    }
  });

  test('year mode uses adjacent years', async ({ page }) => {
    const slug = resolveSlug();
    const picked = await pickDayWithNeighbors(page, slug);
    requireValue(picked, 'No timeline entry available with day neighbors (need multiple days of data)');
    const baseEntry = picked.entry;
    const data = picked.timeline;

    const years = Array.isArray(data.years) ? data.years.map((y) => parseInt(y, 10)).filter(Boolean).sort((a, b) => a - b) : [];
    requireValue(years.length >= 2, 'Not enough years for year navigation (need at least two years of data)');

    const smaller = years.filter((y) => y < baseEntry.year);
    const greater = years.filter((y) => y > baseEntry.year);
    const prevYear = smaller.length ? Math.max(...smaller) : null;
    const nextYear = greater.length ? Math.min(...greater) : null;
    requireValue(prevYear || nextYear, 'No adjacent years available for year navigation');

    await page.goto(buildCameraUrl({
      year: baseEntry.year,
      month: pad2(baseEntry.month),
      day: pad2(baseEntry.day),
      time: normalizeTime(baseEntry.time),
      nav: 'year',
    }), { waitUntil: 'networkidle' });

    const badge = page.locator('[data-live-mode-badge]');
    await expect(badge).toHaveAttribute('data-mode', 'year');
    await expect(badge).toContainText('Jahr');

    const left = page.locator('[data-timeline-hotspot="left"]').first();
    const right = page.locator('[data-timeline-hotspot="right"]').first();

    if (prevYear) {
      const params = await readHotspotParams(left);
      expectParamsMatch(params, {
        year: String(prevYear),
        nav: 'year',
      });
    }

    if (nextYear) {
      const params = await readHotspotParams(right);
      expectParamsMatch(params, {
        year: String(nextYear),
        nav: 'year',
      });
    }
  });
});
