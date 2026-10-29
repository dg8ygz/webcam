const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const root = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';

for (const listed of [false, true]) {
  test(`Eigene Umgebungskarte laedt bekannte Kamera, gelistet=${listed}`, async ({ page }) => {
    const source = fs.readFileSync(path.join(root, 'assets/js/camera.js'), 'utf8');
    const start = source.indexOf('const fetchCameraList =');
    const end = source.indexOf('const getCameraSlugFromUrl', start);
    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);
    await page.addScriptTag({ content: `
      let cameraListCache = null, cameraListPromise = null;
      const getCameraSlugFromUrl = () => 'labor-nord';
      const buildAppUrl = path => new URL(path, 'https://example.invalid/webcam/');
      const toRelativeUrl = url => url.pathname + url.search;
      const calls = [];
      const fetch = async url => {
        calls.push(url);
        return { ok: true, json: async () => url.includes('/cameras/')
          ? { camera: { slug: 'labor-nord' } }
          : { cameras: ${listed ? "[{ slug: 'labor-nord' }]" : '[]'} } };
      };
      ${source.slice(start, end)}
      window.load = fetchCameraList;
      window.calls = calls;
    ` });
    expect(await page.evaluate(() => window.load())).toEqual([{ slug: 'labor-nord' }]);
    expect(await page.evaluate(() => window.load())).toEqual([{ slug: 'labor-nord' }]);
    expect(await page.evaluate(() => window.calls)).toEqual(listed
      ? ['/webcam/api/public.php/cameras?per_page=200']
      : ['/webcam/api/public.php/cameras?per_page=200', '/webcam/api/public.php/cameras/labor-nord']);
  });
}
