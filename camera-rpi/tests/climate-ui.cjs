// Real PHP + real root-bridge code in explicit TEST_MODE, temporary config only.
// No sudo, systemd, network target, sensor device or physical GPIO is accessed.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');

(async () => {
  const repo = path.resolve(__dirname, '..');
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-climate-ui-'));
  const port = 24000 + process.pid % 10000;
  let server, browser, daemon;
  try {
    for (const dir of ['public', 'src', 'sessions', 'servers', 'keys', 'runtime', 'status'])
      fs.mkdirSync(path.join(stage, dir), { mode: 0o700 });
    for (const file of ['source.js', 'index.php', 'style.css']) fs.copyFileSync(path.join(repo, 'admin/public', file), path.join(stage, 'public', file));
    for (const file of ['source-panel.php', 'climate.php', 'climate-panel.php']) fs.copyFileSync(path.join(repo, 'admin/src', file), path.join(stage, 'src', file));
    const config = path.join(stage, 'climate.json');
    // Model the already-installed first version. The UI must omit retired
    // settings immediately and clean them on save without changing other values.
    const initial = JSON.parse(fs.readFileSync(path.join(repo, 'config/climate.example.json')));
    fs.writeFileSync(config, JSON.stringify({ ...initial, max_temperature_c: 35, max_heat_seconds: 900 }));
    const env = {
      PATH: '/usr/bin:/bin', CAMERA_RPI_ADMIN_TEST_MODE: '1',
      CAMERA_RPI_CONFIG_DIR: path.join(stage, 'servers'), CAMERA_RPI_KEY_DIR: path.join(stage, 'keys'),
      CAMERA_RPI_CLIMATE_CONFIG: config, CAMERA_RPI_CLIMATE_RUNTIME: path.join(stage, 'runtime'),
      CAMERA_RPI_CLIMATE_HELPER: path.join(repo, 'scripts/camera-rpi-climate.php'),
      CAMERA_RPI_STATUS_ROOT: path.join(stage, 'status'), CAMERA_RPI_OPERATION_LOCK: path.join(stage, 'operation.lock'),
      CAMERA_RPI_STATUS_GROUP: '',
      CAMERA_RPI_PWM_BIN: '/usr/bin/true', // Existence check only; UI tests never start a regulator.
    };
    const phpEnv = '[' + Object.entries(env).map(([k, v]) => `${JSON.stringify(k)} => ${JSON.stringify(v)}`).join(', ') + ']';
    const bootstrap = fs.readFileSync(path.join(repo, 'admin/src/bootstrap.php'), 'utf8')
      .replace("'/usr/local/sbin/camera-rpi-admin'", JSON.stringify(path.join(repo, 'scripts/admin/camera-rpi-admin')))
      .replace("'/run/camera-rpi-admin/sessions'", JSON.stringify(path.join(stage, 'sessions')))
      .replace("['/usr/bin/sudo', '-n', CAMERA_RPI_ADMIN_BRIDGE]", "['/bin/bash', CAMERA_RPI_ADMIN_BRIDGE]")
      .replace("['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']", phpEnv);
    fs.writeFileSync(path.join(stage, 'src/bootstrap.php'), bootstrap);
    server = spawn('php', ['-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', path.join(stage, 'public'), path.join(repo, 'tests/fixtures/php-router.php')], { stdio: 'ignore' });
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const url = `http://127.0.0.1:${port}/index.php`;
    await page.goto(url);
    const panel = page.locator('#climate');
    assert.equal(await panel.locator('[name=climate_mode]').inputValue(), 'off');
    assert.equal(await panel.locator('[name=dew_margin_c]').inputValue(), '5');
    assert.equal(await panel.locator('[name=max_temperature_c], [name=max_heat_seconds]').count(), 0);
    assert.equal(await panel.locator('[name=confirm_hardware]').count(), 0);
    assert.equal(await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).count(), 0);
    assert.equal(await panel.locator('input[value=climate_reset]').count(), 0);
    assert.doesNotMatch(await panel.innerText(), /Nur mit frischen gültigen Messwerten; danach erneuter Pufferaufbau/);
    assert.doesNotMatch(await panel.innerText(), /Sicherheits-Temperaturgrenze|Maximale durchgehende Heizzeit/);
    await panel.locator('summary').click();
    assert.match(await panel.innerText(), /2 Sekunden 100 %/);
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await panel.locator('[name=climate_mode]').selectOption('simulate');
      await panel.locator('[name=dew_margin_c]').fill('4.5');
      await panel.getByRole('button', { name: 'Klima-Einstellungen speichern' }).click();
      await page.waitForLoadState();
      assert.match(await page.locator('[role=status]').innerText(), /Klima-Einstellungen gespeichert/);
      assert.equal(JSON.parse(fs.readFileSync(config)).dew_margin_c, 4.5);
      assert(!('max_temperature_c' in JSON.parse(fs.readFileSync(config))));
      assert(!('max_heat_seconds' in JSON.parse(fs.readFileSync(config))));
      assert.equal(await panel.locator('[name=dew_margin_c]').inputValue(), '4.5');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow');
      if (process.env.CAMERA_RPI_UI_ARTIFACT_DIR) {
        fs.mkdirSync(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, { recursive: true });
        await panel.screenshot({ path: path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, `climate-${width}.png`) });
      }
    }
    // Invalid CSRF cannot acknowledge a fault or change the config.
    const before = fs.readFileSync(config, 'utf8');
    await page.request.post(url, { form: { action: 'climate_reset', csrf: 'bad' } });
    assert(!fs.existsSync(path.join(stage, 'runtime/reset.request')));
    assert.equal(fs.readFileSync(config, 'utf8'), before);
    // A latched fault stays visible even during a permitted capture pause.
    // A fresh status exposes the existing CSRF-protected reset action; stale
    // snapshots are marked as historical and never pretend a live controller.
    const climateStatus = path.join(stage, 'status/climate.status');
    const faultStatus = age => fs.writeFileSync(climateStatus,
      `updated_epoch=${Math.floor(Date.now() / 1000) - age}\nmode=auto\nstate=capture_pause\nfault=capture_timeout\nmessage=Aufnahmepause\nheater=off\nfan_percent=0\n`);
    faultStatus(60);
    await page.reload();
    assert.match(await panel.getByRole('alert').innerText(), /Letzter bekannter Fehler/);
    assert.equal(await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).count(), 0);
    faultStatus(0);
    await page.setViewportSize({ width: 390, height: 900 });
    await page.reload();
    assert.match(await panel.getByRole('alert').innerText(), /capture_timeout/);
    assert.match(await panel.getByRole('alert').innerText(), /Neue Fotos/);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'fault panel fits mobile');
    await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).click();
    await page.waitForLoadState();
    assert.match(await page.locator('[role=status]').innerText(), /Klimadienst laeuft nicht/);
    assert(!fs.existsSync(path.join(stage, 'runtime/reset.request')));
    // Exercise real request/reply through PHP, bridge and a simulated daemon.
    fs.writeFileSync(path.join(stage, 'runtime/fault'), 'capture_timeout');
    daemon = spawn('php', [path.join(repo, 'scripts/camera-rpi-climate.php'), 'run'], { env, stdio: 'ignore' });
    await new Promise(resolve => setTimeout(resolve, 400));
    await page.reload();
    await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).click();
    await page.waitForLoadState();
    assert.match(await page.locator('[role=status]').innerText(), /frische gueltige Messwerte/);
    fs.writeFileSync(path.join(stage, 'status/current.status'),
      `sensor_state=ok\nupdated_epoch=${Math.floor(Date.now()/1000)}\ntemperature_c=20\nhumidity_percent=50\n`);
    await new Promise(resolve => setTimeout(resolve, 2200));
    await page.reload();
    assert.match(await panel.innerText(), /9.3 °C/);
    assert.match(await panel.innerText(), /aktuellen gültigen Messpaar/);
    await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).click();
    await page.waitForLoadState();
    assert.match(await page.locator('[role=status]').innerText(), /Regler hat die Quittierung bestätigt/);
    assert.equal(await panel.getByRole('button', { name: 'Klima-Störung quittieren' }).count(), 0);
    daemon.kill(); await once(daemon, 'exit'); daemon = null;
    assert.equal(fs.readFileSync(config, 'utf8'), before);
    fs.unlinkSync(climateStatus);
    // A stale edit revision cannot overwrite a newer settings document.
    await page.goto(url);
    const conf = JSON.parse(before);
    conf.dew_margin_c = 6;
    fs.writeFileSync(config, JSON.stringify(conf));
    await panel.getByRole('button', { name: 'Klima-Einstellungen speichern' }).click();
    await page.waitForLoadState();
    assert.match(await page.locator('[role=status]').innerText(), /inzwischen geaendert/);
    assert.equal(JSON.parse(fs.readFileSync(config)).dew_margin_c, 6);
    await panel.locator('[name=climate_mode]').selectOption('auto');
    await panel.getByRole('button', { name: 'Klima-Einstellungen speichern' }).click();
    await page.waitForLoadState();
    assert.match(await page.locator('[role=status]').innerText(), /Klima-Einstellungen gespeichert/);
    assert.equal(JSON.parse(fs.readFileSync(config)).mode, 'auto');
    assert.deepEqual(fs.readdirSync(path.join(stage, 'keys')), []);
    console.log('OK: Climate UI 390/1280px, save/prefill/backup/CAS/CSRF, retired fields absent, auto without checkbox; no GPIO');
  } finally {
    if (daemon && daemon.exitCode === null) { daemon.kill(); await once(daemon, 'exit'); }
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await once(server, 'exit'); }
    fs.rmSync(stage, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
