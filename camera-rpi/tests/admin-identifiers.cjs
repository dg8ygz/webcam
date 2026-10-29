// Real PHP forms and Chromium native validation, with an isolated sudo/bridge
// replacement. No installed configuration, camera, sensor or service is touched.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');

(async () => {
  const repo = path.resolve(__dirname, '..');
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-admin-identifiers-'));
  let server, browser;
  try {
    for (const dir of ['public', 'src', 'sessions']) fs.mkdirSync(path.join(stage, dir), { mode: 0o700 });
    for (const f of ['source.js', 'index.php', 'style.css']) fs.copyFileSync(path.join(repo, 'admin/public', f), path.join(stage, 'public', f));
    for (const f of ['source-panel.php', 'climate.php', 'climate-panel.php']) fs.copyFileSync(path.join(repo, 'admin/src', f), path.join(stage, 'src', f));
    const bootstrap = fs.readFileSync(path.join(repo, 'admin/src/bootstrap.php'), 'utf8')
      .replace("'/usr/local/sbin/camera-rpi-admin'", JSON.stringify(path.join(repo, 'tests/fixtures/fake-admin-bridge')))
      .replace("'/run/camera-rpi-admin/sessions'", JSON.stringify(path.join(stage, 'sessions')))
      .replace("['/usr/bin/sudo', '-n', CAMERA_RPI_ADMIN_BRIDGE]", "['/bin/bash', CAMERA_RPI_ADMIN_BRIDGE]");
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), bootstrap);
    const port = 24000 + process.pid % 10000;
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(stage, 'public'), path.join(repo, 'tests/fixtures/php-router.php')], { stdio: 'ignore' });
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const url = `http://127.0.0.1:${port}/index.php?new=1`;
    await page.goto(url);
    for (const [name, max] of [['server_id', 48], ['camera_slug', 64]]) {
      const input = page.locator(`form:has(input[name=action][value=save]) [name=${name}]`);
      assert.equal(await input.getAttribute('maxlength'), String(max));
      // Double internal hyphens and single digits remain valid; no renaming.
      for (const value of ['a', '0', 'steinhagen-prod', 'cam--2', 'a'.repeat(max)]) {
        await input.fill(value);
        assert.equal(await input.evaluate(el => el.checkValidity()), true, `${name}: ${value}`);
      }
      for (const value of ['', 'Steinhagen', 'cam_prod', 'cam.prod', '-cam', 'cam-', 'cam prod', 'cam–prod', 'http://192.0.2.34/webcam2']) {
        await input.fill(value);
        assert.equal(await input.evaluate(el => el.checkValidity()), false, `${name}: ${value}`);
      }
    }
    const form = page.locator('form:has(input[name=action][value=save])');
    await form.locator('[name=server_id]').fill('Steinhagen');
    await form.locator('[name=camera_slug]').fill('steinhagen');
    await form.locator('[name=server_base_url]').fill('http://192.0.2.34/webcam2');
    await form.locator('[name=key]').fill('fixture-key-1234');
    let posts = 0;
    page.on('request', r => { if (r.method() === 'POST') posts++; });
    await form.locator('button[type=submit]').click();
    assert.equal(posts, 0, 'invalid native form must not submit');
    const csrf = await form.locator('[name=csrf]').inputValue();
    const base = { csrf, action: 'save', server_id: 'steinhagen-prod', camera_slug: 'steinhagen', server_base_url: 'http://192.0.2.34/webcam2', key: 'fixture-key-1234', upload_mode: 'chunked', capture_backend: 'v4l2', gphoto_capture_mode: 'standard', video_device: '/dev/video0', video_width: '1920', video_height: '1080', video_warmup_frames: '15' };
    // Bypass HTML entirely: PHP must reject bad IDs for every target action.
    for (const action of ['save', 'set_enabled', 'validate', 'capture', 'restart', 'logs']) {
      const res = await page.request.post(url, { form: { ...base, action, server_id: 'Steinhagen' } });
      const html = await res.text();
      assert.match(html, /Interner Name \(Server-ID\): 1 bis 48 Zeichen/);
      assert(!html.includes(base.key), 'key must not appear in error response');
    }
    for (const [field, value, expected] of [['server_id', 'a'.repeat(49), 'Server-ID'], ['camera_slug', 'Steinhagen', 'Webcam-Kennung'], ['camera_slug', 'a'.repeat(65), 'Webcam-Kennung']]) {
      const res = await page.request.post(url, { form: { ...base, [field]: value } });
      assert((await res.text()).includes(expected), `PHP rejects ${field}`);
    }
    const res = await page.request.post(url, { form: base });
    assert.match(await res.text(), /Server steinhagen-prod wurde gespeichert/);
    await page.goto(url.replace('?new=1', ''));
    assert.equal(await page.locator('.server-form [name=server_id]').getAttribute('readonly'), '');
    assert.equal(await page.locator('.server-form [name=server_id]').inputValue(), 'web-test');
    console.log('OK: admin identifier patterns, blocked submit, direct POST validation, field messages, existing IDs unchanged');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await once(server, 'exit'); }
    fs.rmSync(stage, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
