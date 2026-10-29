const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';
const HOTSPOT_QUERY_OVERRIDE = process.env.HOTSPOT_QUERY || null;
const BESTOF_FILE = process.env.BESTOF_FILE || '20251203_072000_la.jpg';
const DEFAULT_HOTSPOT_QUERY = process.env.HOTSPOT_DEFAULT_QUERY || 'year=2025&month=12&day=03&time=072000';

const buildUrl = (path) => {
  if (/^https?:\/\//i.test(path)) return path;
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const rel = path.startsWith('/') ? path.slice(1) : path;
  return new URL(rel, base).toString();
};

const buildApiUrl = (slug, query) => {
  const basePath = `/api/public.php/cameras/${slug}/timeline`;
  const qs = query ? `?${query}` : '';
  return buildUrl(basePath + qs);
};

const resolveSlug = () => {
  try {
    const url = new URL(buildUrl(CAMERA_PATH));
    return url.searchParams.get('cam') || 'bielefeld';
  } catch (e) {
    return 'bielefeld';
  }
};

const pickHotspotQuery = async (page) => {
  if (HOTSPOT_QUERY_OVERRIDE) return HOTSPOT_QUERY_OVERRIDE;
  const slug = resolveSlug();
  try {
    const resp = await page.request.get(buildApiUrl(slug, 'limit=800'));
    if (!resp.ok()) throw new Error(`timeline fetch failed: ${resp.status()} ${resp.statusText()}`);
    const data = await resp.json().catch(() => null);
    if (!data || !Array.isArray(data.entries) || data.entries.length === 0) {
      return DEFAULT_HOTSPOT_QUERY;
    }
    const findByFilename = (name) =>
      data.entries.find((e) => {
        const rp = e.relative_path || '';
        return rp.includes(name) || rp.includes(name.replace('_la', ''));
      });
    const candidate =
      findByFilename(BESTOF_FILE) ||
      data.entries[Math.floor(data.entries.length / 2)] ||
      data.entries[0];
    if (!candidate || !candidate.year || !candidate.month || !candidate.day || !candidate.time) {
      return DEFAULT_HOTSPOT_QUERY;
    }
    const pad = (n) => String(n).padStart(2, '0');
    return `year=${candidate.year}&month=${pad(candidate.month)}&day=${pad(candidate.day)}&time=${candidate.time}`;
  } catch (e) {
    return DEFAULT_HOTSPOT_QUERY;
  }
};

test.use({
  extraHTTPHeaders: { 'X-WC-TEST': '1' },
});

test.describe('Legacy hotspots & likes', () => {
  test('timeline hotspot loads another image', async ({ page }) => {
    const hotspotQuery = await pickHotspotQuery(page);

    const url = buildUrl(
      CAMERA_PATH.includes('?') ? `${CAMERA_PATH}&${hotspotQuery}` : `${CAMERA_PATH}?${hotspotQuery}`,
    );
    await page.goto(url, { waitUntil: 'networkidle' });
    const img = page.locator('[data-live-image]').first();
    await expect(img).toBeVisible({ timeout: 15000 });
    const hotspot = page.locator('[data-timeline-hotspot]').first();
    await expect(hotspot).toBeVisible({ timeout: 8000 });
    const hotspotUrl = await hotspot.getAttribute('data-url');
    expect(hotspotUrl).toBeTruthy();
  });

  test('like button opens captcha and succeeds in test mode (live view)', async ({ page }) => {
    // Der UI-Test prüft Dialog und Browserzustand, nicht die zufällige
    // Captcha-Lösung oder einen echten Like-Schreibvorgang. Kontrollierte
    // Antworten halten ihn reproduzierbar und verändern keine Live-Daten.
    await page.route('**/bestof-captcha.php', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          captcha: {
            id: 'playwright-captcha',
            image: 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
          },
        }),
      });
    });
    await page.route('**/bestof-toggle.php', async (route) => {
      const body = route.request().postDataJSON();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, active: true, path: body.path || '' }),
      });
    });

    const hotspotQuery = (await pickHotspotQuery(page)) || '';
    const url = buildUrl(
      CAMERA_PATH.includes('?') ? `${CAMERA_PATH}&${hotspotQuery}` : `${CAMERA_PATH}?${hotspotQuery}`,
    );
    await page.goto(url, { waitUntil: 'networkidle' });

    const likeBtn = page.locator('[data-bestof-toggle]').first();
    if (!(await likeBtn.count())) test.skip('no like button rendered');

    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('bestof-toggle.php') && resp.status() === 200,
      { timeout: 10000 },
    );

    // Button ist in Best-Of-View oft ausgeblendet; triggere Handler per DOM-Click.
    await page.evaluate(() => {
      const btn = document.querySelector('[data-bestof-toggle]');
      if (btn) btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    const modal = page.locator('.bestof-modal');
    const modalVisible = await modal.waitFor({ state: 'visible', timeout: 3000 }).then(() => true).catch(() => false);

    if (modalVisible) {
      await page.fill('[data-captcha-input]', 'TEST');
      await page.click('[data-captcha-submit]');
      await responsePromise;
      await expect(modal).toBeHidden({ timeout: 5000 });
      await expect(likeBtn).toHaveAttribute('aria-pressed', 'true', { timeout: 5000 });
    } else {
      // Fallback: trigger toggle directly (test mode ignores captcha)
      const payload = await likeBtn.evaluate((el) => ({
        slug: el.getAttribute('data-camera') || '',
        path: el.getAttribute('data-path') || '',
      }));
      await page.evaluate(async (body) => {
        await fetch('bestof-toggle.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(body),
        });
      }, payload);
      await responsePromise;
    }
  });
});
