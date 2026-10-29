// Real PHP + real root-bridge code in explicit TEST_MODE, temporary config only.
// No sudo, systemd, network target, sensor device or physical GPIO is accessed.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');

(async () => {
  const repo = path.resolve(__dirname, '..');
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-climate-ui-'));
  const port = 24000 + process.pid % 10000;
  let server, browser, power;
  try {
    for (const dir of ['public', 'src', 'sessions', 'servers', 'keys', 'runtime', 'status'])
      fs.mkdirSync(path.join(stage, dir), { mode: 0o700 });
    for (const file of ['source.js', 'index.php', 'style.css', 'gpio.js', 'power.js', 'power-status.php']) fs.copyFileSync(path.join(repo, 'admin/public', file), path.join(stage, 'public', file));
    for (const file of ['source-panel.php', 'climate.php', 'climate-panel.php', 'gpio.php', 'gpio-panel.php', 'power.php', 'power-panel.php']) fs.copyFileSync(path.join(repo, 'admin/src', file), path.join(stage, 'src', file));
    const config = path.join(stage, 'climate.json');
    // Model the already-installed first version. The UI must omit retired
    // settings immediately and clean them on save without changing other values.
    const initial = JSON.parse(fs.readFileSync(path.join(repo, 'config/climate.example.json')));
    fs.writeFileSync(config, JSON.stringify({ ...initial, max_temperature_c: 35, max_heat_seconds: 900 }));
    const gpioConfig=path.join(stage,'gpio.json');
    const gpioActive=path.join(stage,'active.json');
    const original={dht:4,heater:5,fan:27,camera_power:6};
    fs.writeFileSync(gpioConfig,JSON.stringify(original));
    fs.writeFileSync(gpioActive,JSON.stringify(original));
    fs.writeFileSync(path.join(stage,'model'),'Raspberry Pi 4 Model B Rev 1.5\0');
    fs.writeFileSync(path.join(stage,'compatible'),'raspberrypi,4-model-b\0brcm,bcm2711\0');
    const usb = path.join(stage, 'lsusb');
    const usbText = path.join(stage, 'usb.txt');
    fs.writeFileSync(usb, '#!/bin/sh\ncat ' + usbText + '\n', {mode: 0o700});
    fs.writeFileSync(usbText, 'Bus 001 Device 004: ID 04a9:32e1 Canon, Inc. Canon Digital Camera\n');
    const socket = path.join(stage, 'power.sock');
    const binary = path.join(stage, 'pwm-test');
    const nativeLog = path.join(stage, 'native.log');
    execFileSync('cc', ['-O2','-Wall','-Wextra','-Werror','-DCLIMATE_TEST','-o',binary,path.join(repo,'scripts/native/camera-rpi-pwm.c')]);
    const log = fs.openSync(nativeLog, 'w');
    power = spawn(binary, ['--test-power','6',socket], {stdio:['ignore','ignore',log]});
    fs.closeSync(log);
    for(let i=0; i<50&&!fs.existsSync(socket); i++) await new Promise(r=>setTimeout(r,50));
    assert(fs.existsSync(socket));
    const env = {
      CAMERA_RPI_POWER_HELPER:path.join(repo,'scripts/camera-rpi-power.php'),
      CAMERA_RPI_POWER_CONFIG:path.join(stage,'power.json'), CAMERA_RPI_POWER_SOCKET:socket, CAMERA_RPI_LSUSB_BIN:usb,
      CAMERA_RPI_GPIO_DT_ROOT:stage,
      CAMERA_RPI_GPIO_CONFIG:gpioConfig, CAMERA_RPI_GPIO_ACTIVE:gpioActive,
      CAMERA_RPI_GPIO_HELPER:path.join(repo,'scripts/camera-rpi-gpio.php'),
      PATH: '/usr/bin:/bin', CAMERA_RPI_ADMIN_TEST_MODE: '1',
      CAMERA_RPI_CONFIG_DIR: path.join(stage, 'servers'), CAMERA_RPI_KEY_DIR: path.join(stage, 'keys'),
      CAMERA_RPI_CLIMATE_CONFIG: config, CAMERA_RPI_CLIMATE_RUNTIME: path.join(stage, 'runtime'),
      CAMERA_RPI_CLIMATE_HELPER: path.join(repo, 'scripts/camera-rpi-climate.php'),
      CAMERA_RPI_STATUS_ROOT: path.join(stage, 'status'), CAMERA_RPI_OPERATION_LOCK: path.join(stage, 'operation.lock'),
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

    const panel=page.locator('[data-camera-power]');
    const on=panel.locator('[data-power-button=on]'), off=panel.locator('[data-power-button=off]');
    const waitState=async expected=>{
      await page.waitForFunction(expected=>document.querySelector('[data-camera-power]').dataset.powerState===expected, expected, {timeout:12000});
    };
    await waitState('on');
    assert.equal(await on.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(22, 112, 59)');
    assert.equal(await on.isEnabled(),true);
    const before=fs.readFileSync(nativeLog,'utf8');
    await page.request.post(url,{form:{action:'power_off',csrf:'invalid'}});
    assert.equal(fs.readFileSync(nativeLog,'utf8'),before);
    const settings=panel.locator('[data-power-settings]');
    const csrf=await settings.locator('[name=csrf]').inputValue();
    await page.request.post(url,{form:{action:'power_save',csrf:'invalid',reset_off_seconds:'120',power_revision:'missing'}});
    assert(!fs.existsSync(path.join(stage,'power.json')));
    await settings.locator('[name=reset_off_seconds]').fill('120');
    await settings.locator('button').click(); await page.waitForLoadState();
    assert.equal(JSON.parse(fs.readFileSync(path.join(stage,'power.json'))).reset_off_seconds,120);
    assert.equal(await settings.locator('[name=reset_off_seconds]').inputValue(),'120');
    assert.equal(fs.readFileSync(nativeLog,'utf8'),before);
    const revision=await settings.locator('[name=power_revision]').inputValue();
    for(const form of [
      {reset_off_seconds:'301',power_revision:revision},
      {reset_off_seconds:'45',power_revision:'missing'},
      {reset_off_seconds:'12.5',power_revision:revision},
    ]) await page.request.post(url,{form:{action:'power_save',csrf,...form}});
    assert.equal(JSON.parse(fs.readFileSync(path.join(stage,'power.json'))).reset_off_seconds,120);
    assert.equal(fs.readFileSync(nativeLog,'utf8'),before);
    await page.goto(url);
    await off.click(); await page.waitForLoadState();
    assert.match(fs.readFileSync(nativeLog,'utf8'),/POWER 0/);
    assert.equal(await panel.getAttribute('data-power-state'),'on'); // USB still Canon.
    fs.writeFileSync(usbText,'Linux Foundation root hub\n');
    await waitState('off');
    assert.equal(await off.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(179, 38, 30)');
    await on.click(); await page.waitForLoadState();
    assert(fs.readFileSync(nativeLog,'utf8').endsWith('POWER 1\n'));
    assert.equal(await panel.getAttribute('data-power-state'),'off'); // Enumeration delay.
    fs.writeFileSync(usbText,'Bus 009 Device 231: ID 0000:1111 Canon test camera\n');
    await waitState('on');
    fs.writeFileSync(usb,'#!/bin/sh\nexit 1\n');
    await waitState('unknown');
    assert.equal(await on.getAttribute('aria-pressed'),'false');
    assert.equal(await off.getAttribute('aria-pressed'),'false');
    fs.writeFileSync(usb,'#!/bin/sh\ncat '+usbText+'\n');
    power.kill();await once(power,'exit');power=null;
    await page.waitForFunction(()=>document.querySelector('[data-power-button=on]').disabled,{timeout:12000});
    for(const width of [390,1280]) {
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    console.log('OK: real admin bridge/native test owner; ON/OFF buttons, USB colours/delay/poll/error, CSRF, offline owner, mobile. No physical GPIO.');
  } finally {
    if(browser) await browser.close();
    if(server && server.exitCode===null){server.kill();await once(server,'exit');}
    if(power && power.exitCode===null){power.kill();await once(power,'exit');}
    fs.rmSync(stage,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
