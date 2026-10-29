// Isolated real PHP/Playwright test. No installed bridge, sudo or hardware.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');

(async () => {
  const repo = path.resolve(__dirname, '..');
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-focus-ui-'));
  const port = 24000 + process.pid % 10000;
  let server, browser;
  try {
    for (const dir of ['public', 'src', 'sessions']) fs.mkdirSync(path.join(stage, dir), { mode: 0o700 });
    for (const file of ['source.js', 'index.php', 'style.css']) fs.copyFileSync(path.join(repo, 'admin/public', file), path.join(stage, 'public', file));
    for (const file of ['source-panel.php', 'climate.php', 'climate-panel.php']) fs.copyFileSync(path.join(repo, 'admin/src', file), path.join(stage, 'src', file));
    const bridge = path.join(repo, 'tests/fixtures/fake-admin-bridge');
    fs.chmodSync(bridge, 0o755);
    const log = path.join(stage, 'focus.log');
    const bootstrap = fs.readFileSync(path.join(repo, 'admin/src/bootstrap.php'), 'utf8')
      .replace("'/usr/local/sbin/camera-rpi-admin'", JSON.stringify(bridge))
      .replace("'/run/camera-rpi-admin/sessions'", JSON.stringify(path.join(stage, 'sessions')))
      .replace("['/usr/bin/sudo', '-n', CAMERA_RPI_ADMIN_BRIDGE]", "['/bin/bash', CAMERA_RPI_ADMIN_BRIDGE]")
      .replace("['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']", `['PATH' => '/usr/bin:/bin', 'FAKE_ADMIN_BACKEND' => 'gphoto2', 'FAKE_ADMIN_FOCUS_LOG' => ${JSON.stringify(log)}]`);
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), bootstrap);
    server = spawn('php', ['-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', path.join(stage, 'public'), path.join(repo, 'tests/fixtures/php-router.php')], { stdio: 'ignore' });
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const url = `http://127.0.0.1:${port}/index.php`;
    await page.goto(url);
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      for (const mode of ['--', '-', 'auto', '+', '++']) {
        const button = page.getByRole('button', { name: `Fokus ${mode}`, exact: true });
        assert(await button.isEnabled());
        await button.click();
        await page.waitForLoadState();
        assert.match(await page.locator('[role=status]').innerText(), /Kamerafokus.*vorgemerkt/);
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow');
      if (process.env.CAMERA_RPI_UI_ARTIFACT_DIR) {
        fs.mkdirSync(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, { recursive: true });
        await page.screenshot({ path: path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, `focus-${width}.png`), fullPage: true });
      }
    }
    assert.equal(fs.readFileSync(log, 'utf8').trim().split('\n').length, 10);
    await page.request.post(url, { form: { action: 'focus', server_id: 'web-test', focus_mode: 'auto', csrf: 'wrong' } });
    assert.equal(fs.readFileSync(log, 'utf8').trim().split('\n').length, 10, 'CSRF bypass');
    // Multiple upload targets still expose exactly one physical-camera panel.
    // Selecting another server card or forging server_id cannot reroute focus.
    const multi = bootstrap.replace("'FAKE_ADMIN_BACKEND' => 'gphoto2'", "'FAKE_ADMIN_BACKEND' => 'gphoto2', 'FAKE_ADMIN_MULTI' => '1', 'FAKE_ADMIN_POOL_SOURCE' => 'web-test'");
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), multi);
    await page.goto(url + '?edit=other-target');
    assert.equal(await page.locator('.camera-focus-panel').count(), 1);
    assert.equal(await page.locator('.focus-controls').count(), 1);
    assert.equal(await page.locator('article .focus-controls').count(), 0);
    assert.equal(await page.locator('.focus-controls [name=server_id]').count(), 0);
    assert.equal(await page.locator('[name=focus_mode]').count(), 5);
    const csrf = await page.locator('.focus-controls [name=csrf]').inputValue();
    await page.request.post(url, { form: { action: 'focus', server_id: 'other-target', focus_mode: '+', csrf } });
    assert.equal(fs.readFileSync(log, 'utf8').trim().split('\n').at(-1), 'web-test:+');
    // Missing/ambiguous pool source must not guess a camera-owning target.
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), multi.replace("'FAKE_ADMIN_POOL_SOURCE' => 'web-test'", "'FAKE_ADMIN_POOL_SOURCE' => '-'"));
    await page.reload();
    for (const button of await page.locator('[name=focus_mode]').all()) assert(await button.isDisabled());
    await page.request.post(url, { form: { action: 'focus', focus_mode: 'auto', csrf } });
    assert.equal(fs.readFileSync(log, 'utf8').trim().split('\n').length, 11);
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), bootstrap.replace("'FAKE_ADMIN_BACKEND' => 'gphoto2'", "'FAKE_ADMIN_BACKEND' => 'v4l2'"));
    await page.reload();
    assert.equal(await page.locator('.camera-focus-panel').count(), 0, 'V4L2 has no focus panel');
    assert.equal(await page.locator('[name=focus_mode]').count(), 0, 'V4L2 has no focus buttons');
    await page.request.post(url, { form: { action: 'focus', focus_mode: 'auto', csrf } });
    assert.equal(fs.readFileSync(log, 'utf8').trim().split('\n').length, 11, 'V4L2 backend guard');
    console.log('OK: one camera-wide focus panel, pool source routing, forged target ignored, ambiguity/V4L2/CSRF blocked, 390/1280 px; fixture only');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await once(server, 'exit'); }
    fs.rmSync(stage, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
