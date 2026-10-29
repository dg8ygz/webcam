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
  let server, browser;
  try {
    for (const dir of ['public', 'src', 'sessions', 'servers', 'keys', 'runtime', 'status'])
      fs.mkdirSync(path.join(stage, dir), { mode: 0o700 });
    for (const file of ['source.js', 'index.php', 'style.css', 'gpio.js']) fs.copyFileSync(path.join(repo, 'admin/public', file), path.join(stage, 'public', file));
    for (const file of ['source-panel.php', 'climate.php', 'climate-panel.php', 'gpio.php', 'gpio-panel.php']) fs.copyFileSync(path.join(repo, 'admin/src', file), path.join(stage, 'src', file));
    const config = path.join(stage, 'climate.json');
    // Model the already-installed first version. The UI must omit retired
    // settings immediately and clean them on save without changing other values.
    const initial = JSON.parse(fs.readFileSync(path.join(repo, 'config/climate.example.json')));
    fs.writeFileSync(config, JSON.stringify({ ...initial, max_temperature_c: 35, max_heat_seconds: 900 }));
    const gpioConfig=path.join(stage,'gpio.json');
    const gpioActive=path.join(stage,'active.json');
    const original={dht:4,heater:5,fan:27,camera_power:null};
    fs.writeFileSync(gpioConfig,JSON.stringify(original));
    fs.writeFileSync(gpioActive,JSON.stringify(original));
    fs.writeFileSync(path.join(stage,'model'),'Raspberry Pi 4 Model B Rev 1.5\0');
    fs.writeFileSync(path.join(stage,'compatible'),'raspberrypi,4-model-b\0brcm,bcm2711\0');
    const env = {
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

    const panel=page.locator('#gpio');
    assert.match(await panel.innerText(),/Raspberry Pi 4 Model B Rev 1.5/);
    assert.match(await panel.innerText(),/40-Pin-Header/);
    assert.equal(await panel.locator('select').count(),5);
    assert.equal(await panel.locator('[name=gpio_dht]').inputValue(),'4');
    assert.equal(await panel.locator('[name=gpio_camera_power]').inputValue(),'');
    assert.equal(await panel.locator('[name=gpio_focus]').inputValue(),'');
    await panel.locator('[name=gpio_focus]').selectOption('13');
    assert(await panel.locator('[name=gpio_camera_power] option[value="13"]').evaluate(e=>e.disabled&&e.hidden));
    assert(await panel.locator('[name=gpio_fan] option[value="4"]').evaluate(e=>e.disabled&&e.hidden));
    await panel.locator('[name=gpio_dht]').selectOption('17');
    assert(await panel.locator('[name=gpio_fan] option[value="17"]').evaluate(e=>e.disabled&&e.hidden));
    assert(await panel.locator('[name=gpio_fan] option[value="4"]').evaluate(e=>!e.disabled&&!e.hidden));
    await panel.getByRole('button',{name:'GPIO-Belegung speichern'}).click();
    await page.waitForLoadState();
    assert.equal(JSON.parse(fs.readFileSync(gpioConfig)).dht,17);
    assert.equal(JSON.parse(fs.readFileSync(gpioConfig)).focus,13);
    assert.deepEqual(JSON.parse(fs.readFileSync(gpioActive)),original);
    assert.match(await panel.innerText(),/Noch nicht aktiv/);
    assert.match(await panel.innerText(),/Aktuell: GPIO 4/);
    const before=fs.readFileSync(gpioConfig,'utf8');
    const csrf=await panel.locator('[name=csrf]').inputValue();
    const revision=await panel.locator('[name=gpio_revision]').inputValue();
    const form={action:'gpio_save',csrf,gpio_revision:revision,
      gpio_dht:'17',gpio_heater:'5',gpio_fan:'17',gpio_camera_power:''};
    const response=await page.request.post(url,{form});
    assert.match(await response.text(),/bereits in Benutzung/);
    assert.equal(fs.readFileSync(gpioConfig,'utf8'),before);
    await page.request.post(url,{form:{...form,csrf:'invalid',gpio_fan:'27'}});
    assert.equal(fs.readFileSync(gpioConfig,'utf8'),before);
    await page.request.post(url,{form:{...form,gpio_revision:'0'.repeat(64),gpio_fan:'27'}});
    assert.equal(fs.readFileSync(gpioConfig,'utf8'),before);
    for (const width of [390,1280]) {
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    assert.deepEqual(JSON.parse(fs.readFileSync(gpioActive)),original);
    // Change ONLY the fixture identity: real web/backend must restrict header.
    fs.writeFileSync(path.join(stage,'compatible'),'raspberrypi,model-b-rev2\0brcm,bcm2835\0');
    fs.writeFileSync(path.join(stage,'model'),'Raspberry Pi Model B Rev 2\0');
    await page.goto(url);
    assert.match(await panel.innerText(),/26-Pin-Header/);
    assert.equal(await panel.locator('option[value="5"]').count(),0);
    assert.equal(await panel.locator('option[value="26"]').count(),0);
    assert.match(await panel.innerText(),/GPIO 5 nicht auf diesem Header/);
    const csrf26=await panel.locator('[name=csrf]').inputValue();
    const rev26=await panel.locator('[name=gpio_revision]').inputValue();
    const denied26=await page.request.post(url,{form:{...form,csrf:csrf26,gpio_revision:rev26,gpio_fan:'27'}});
    assert.match(await denied26.text(),/auf diesem Header nicht verfügbar/);
    assert.equal(fs.readFileSync(gpioConfig,'utf8'),before);
    for(const board of ['5-model-b','4-compute-module','unknown-future-board']) {
      fs.writeFileSync(path.join(stage,'compatible'),'raspberrypi,'+board+'\0');
      await page.goto(url);
      assert.equal(await panel.locator('select').count(),0);
      assert.match(await panel.innerText(),/GPIO-Auswahl gesperrt/);
    }
    console.log('OK: five GPIO dropdowns, exclusion, save/pending, backend duplicates, CSRF, CAS, mobile; no GPIO');
  } finally {
    if(browser) await browser.close();
    if(server && server.exitCode===null){server.kill();await once(server,'exit');}
    fs.rmSync(stage,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
