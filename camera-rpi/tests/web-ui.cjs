// Run after tests/run-tests with CAMERA_RPI_UI_ARTIFACT_DIR set.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
  const root = process.env.CAMERA_RPI_UI_ARTIFACT_DIR;
  assert(root, 'CAMERA_RPI_UI_ARTIFACT_DIR required');
  const browser = await chromium.launch({ headless: true });
  try {
    // Shell fixtures test JPEG transport, not browser decoding. Render a real
    // synthetic JPEG here; no hardware image or personal data in screenshots.
    const jpeg = execFileSync('php', ['-r', '$i=imagecreatetruecolor(640,360); imagefill($i,0,0,imagecolorallocate($i,50,90,100)); imagejpeg($i);']).toString('base64');
    const page = await browser.newPage();
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      for (const kind of ['admin', 'status']) {
        let html = fs.readFileSync(path.join(root, `${kind}.html`), 'utf8');
        html = html.replace(/<link[^>]+>/g, '');
        if (kind === 'status') html = html.replace(/src="\?asset=latest-image[^\"]*"/, `src="data:image/jpeg;base64,${jpeg}"`);
        await page.setContent(html);
        await page.addStyleTag({ content: fs.readFileSync(path.join(root, `${kind}.css`), 'utf8') });
        if (kind === 'admin') {
          assert.equal(await page.locator('h1 small').innerText(), fs.readFileSync(path.resolve(__dirname,'../VERSION'),'utf8').trim());
          assert.equal(await page.locator('.server-form [name=server_id]').inputValue(), 'web-test');
          assert.equal(await page.locator('[name=server_base_url]').inputValue(), 'https://server.invalid/webcam');
          assert.equal(await page.locator('[name=capture_backend]').inputValue(), 'v4l2');
          assert.equal(await page.locator('[name=key]').inputValue(), '');
          assert.equal(await page.locator('[name=key]').evaluate(el => el.required), false);
          const button = page.getByRole('button', { name: 'Jetzt aufnehmen', exact: true });
          assert.equal(await button.count(), 1);
          assert.equal(await button.isEnabled(), true);
          assert.equal(await button.locator('..').locator('[name=server_id]').inputValue(), 'web-test');
          assert.equal(await button.locator('..').locator('[name=action]').inputValue(), 'capture');
          const logs = page.getByRole('button', { name: 'Letzte 10 Log-Einträge', exact: true });
          assert.equal(await logs.count(), 1);
          assert.equal(await logs.locator('..').locator('[name=server_id]').inputValue(), 'web-test');
          assert.equal(await logs.locator('..').locator('[name=action]').inputValue(), 'logs');
          await page.locator('[name=upload_mode]').selectOption('chunked');
          assert.equal(await page.locator('[name=upload_mode]').inputValue(), 'chunked');
        } else {
          assert.equal(await page.locator('.page-head .eyebrow').textContent(), 'Camera-RPi V'+fs.readFileSync(path.resolve(__dirname,'../VERSION'),'utf8').trim());
          assert.equal(await page.locator('form').count(), 0);
          assert(await page.getByRole('heading', { name: 'Kamera & Klima' }).isVisible());
          await page.locator('.hero img').evaluate(img => img.decode());
        }
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${kind}: overflow at ${width}`);
        await page.screenshot({ path: path.join(root, `${kind}-${width}.png`), fullPage: true });
      }
    }
    await page.setContent(fs.readFileSync(path.join(root, 'admin-new.html'), 'utf8'));
    assert.equal(await page.locator('.server-form [name=server_id]').inputValue(), '');
    assert.equal(await page.locator('[name=key]').evaluate(el => el.required), true);
    console.log('OK: Pi-Admin/Infoseite 390/1280 px, zielbezogener Button und Upload-Auswahl');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
