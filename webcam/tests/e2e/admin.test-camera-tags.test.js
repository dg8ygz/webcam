const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const root = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';

test('Testkamera-Attribut wird bei Neuanlage und Bearbeitung gesetzt und wieder entfernt', async ({ page }) => {
  // Echte Tag-Builder aus dem Einstieg isolieren, ohne Live-Admin-API anzusprechen.
  const source = fs.readFileSync(path.join(root, 'assets/js/admin.js'), 'utf8');
  const start = source.indexOf('const INTERNAL_CAMERA_TAG_PREFIX');
  const end = source.indexOf('const getCookie', start);
  expect(start).toBeGreaterThan(-1);
  expect(end).toBeGreaterThan(start);
  await page.setContent('<form><input name="tags" value="stadt, __test_camera"><input type="checkbox" name="test_camera"><input type="checkbox" name="native_display" checked><input type="checkbox" name="night_denoise" checked></form>');
  await page.addScriptTag({ content: `
    const state = { isCreating: false, selectedSlug: 'labor-nord', config: { cameras: {
      'labor-nord': { tags: ['__test_camera', '__native_display', '__night_denoise', '__future'] }
    } } };
    const cleanString = value => String(value ?? '').trim();
    const parseArray = value => value.split(',');
    ${source.slice(start, end)}
    window.tags = () => buildCameraTagsFromForm(new FormData(document.querySelector('form')), 'labor-nord');
    window.createCamera = () => { state.isCreating = true; };
  ` });
  expect(await page.evaluate(() => window.tags())).toEqual(['stadt', '__future', '__native_display', '__night_denoise']);
  await page.locator('[name="test_camera"]').check();
  expect(await page.evaluate(() => window.tags())).toEqual(['stadt', '__future', '__native_display', '__night_denoise', '__test_camera']);
  await page.evaluate(() => window.createCamera());
  expect(await page.evaluate(() => window.tags())).toEqual(['stadt', '__native_display', '__night_denoise', '__test_camera']);
  await page.locator('[name="test_camera"]').uncheck();
  expect(await page.evaluate(() => window.tags())).not.toContain('__test_camera');
});
