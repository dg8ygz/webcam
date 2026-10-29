// Real PHP form + root bridge in isolated TEST_MODE; no live config or sudo.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {once} = require('node:events');
const {chromium} = require('playwright');
(async () => {
  const repo = path.resolve(__dirname, '..'), stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-privacy-ui-'));
  let server, browser;
  try {
    for (const dir of ['public', 'src', 'sessions', 'servers', 'keys', 'runtime', 'status', 'privacy', 'devices']) fs.mkdirSync(path.join(stage,dir), {mode: 0o700});
    for (const file of ['index.php','style.css','source.js']) fs.copyFileSync(path.join(repo,'admin/public',file),path.join(stage,'public',file));
    for (const file of ['climate.php','climate-panel.php','privacy.php','privacy-panel.php','source-panel.php']) fs.copyFileSync(path.join(repo,'admin/src',file),path.join(stage,'src',file));
    const env = {PATH:'/usr/bin:/bin', CAMERA_RPI_ADMIN_TEST_MODE:'1',
      CAMERA_RPI_SOURCE_CONFIG:path.join(stage,'source.conf'),
      CAMERA_RPI_SOURCE_DEVICE_DIR:path.join(stage,'devices'),
      CAMERA_RPI_CONFIG_DIR:path.join(stage,'servers'), CAMERA_RPI_KEY_DIR:path.join(stage,'keys'),
      CAMERA_RPI_CLIMATE_CONFIG:path.join(stage,'no-climate.json'), CAMERA_RPI_CLIMATE_RUNTIME:path.join(stage,'runtime'),
      CAMERA_RPI_CLIMATE_HELPER:path.join(repo,'scripts/camera-rpi-climate.php'),
      CAMERA_RPI_PRIVACY_HELPER:path.join(repo,'scripts/camera-rpi-privacy.php'),
      CAMERA_RPI_PRIVACY_CONFIG:path.join(stage,'privacy.json'), CAMERA_RPI_PRIVACY_RUNTIME:path.join(stage,'privacy'),
      CAMERA_RPI_STATUS_ROOT:path.join(stage,'status'), CAMERA_RPI_OPERATION_LOCK:path.join(stage,'operation.lock')};
    fs.symlinkSync('/dev/null',path.join(stage,'devices/usb-Fixture_Camera-video-index0'));
    fs.writeFileSync(path.join(stage,'devices/not-a-device'),'ignored');
    const phpEnv = '['+Object.entries(env).map(([k,v])=>`${JSON.stringify(k)}=>${JSON.stringify(v)}`).join(',')+']';
    const bootstrap=fs.readFileSync(path.join(repo,'admin/src/bootstrap.php'),'utf8')
      .replace("'/usr/local/sbin/camera-rpi-admin'",JSON.stringify(path.join(repo,'scripts/admin/camera-rpi-admin')))
      .replace("'/run/camera-rpi-admin/sessions'",JSON.stringify(path.join(stage,'sessions')))
      .replace("['/usr/bin/sudo', '-n', CAMERA_RPI_ADMIN_BRIDGE]","['/bin/bash', CAMERA_RPI_ADMIN_BRIDGE]")
      .replace("['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']",phpEnv);
    fs.writeFileSync(path.join(stage,'src/bootstrap.php'),bootstrap);
    fs.writeFileSync(path.join(stage,'servers/dev.conf'),'server_id=dev\nserver_base_url=https://example.invalid/webcam\ncamera_slug=test\nkey_file='+path.join(stage,'keys/dev.key')+'\nmanage_root_mount=false\n');
    fs.chmodSync(path.join(stage,'servers/dev.conf'),0o600);
    fs.writeFileSync(path.join(stage,'keys/dev.key'),'fixture-key\n',{mode:0o600});
    const port=28000+process.pid%10000;
    server=spawn('php',['-d','opcache.enable=0','-S',`127.0.0.1:${port}`,'-t',path.join(stage,'public'),path.join(repo,'tests/fixtures/php-router.php')],{stdio:'ignore'});
    browser=await chromium.launch({args:['--no-sandbox']}); const page=await browser.newPage();
    await page.goto(`http://127.0.0.1:${port}/index.php`);
    assert.equal(await page.locator('[name=gphoto_capture_mode]').inputValue(),'eos-legacy');
    assert.equal(await page.locator('[name=gphoto_capture_mode] option[value="eos-legacy"]').textContent(),'Standard – bewährte EOS-Sequenz');
    assert.equal(await page.locator('[name=gphoto_capture_mode] option[value="standard"]').textContent(),'Alternativ – direktes gphoto2 Capture & Download');
    const panel=page.locator('#capture-privacy');
    assert.match(await panel.innerText(),/bitte zuerst den Kamerastandort/);
    await panel.locator('[name=privacy_mode]').selectOption('solar');
    await panel.locator('[name=privacy_source_server]').selectOption('dev');
    await panel.getByRole('button',{name:'Aufnahmeoptionen speichern'}).click();
    await page.waitForLoadState();
    assert(!fs.existsSync(env.CAMERA_RPI_PRIVACY_CONFIG),'missing position must not activate solar');
    const identity=spawnSync('php',[env.CAMERA_RPI_PRIVACY_HELPER,'identity','dev'],{env,encoding:'utf8'}).stdout;
    fs.writeFileSync(path.join(stage,'privacy/position-dev.json'),JSON.stringify({identity,received:Math.floor(Date.now()/1000),position:{latitude:52,longitude:8.5,timezone:'Europe/Berlin'}}));
    for (const width of [390,1366]) {
      await page.setViewportSize({width,height:950}); await page.reload();
      await panel.locator('[name=privacy_mode]').selectOption('solar');
      await panel.locator('[name=privacy_source_server]').selectOption('dev');
      await panel.locator('[name=privacy_sunrise_offset]').fill('+30');
      await panel.locator('[name=privacy_sunset_offset]').fill('-45');
      await panel.locator('[name=privacy_dark_enabled]').check();
      await panel.locator('[name=privacy_minimum_percent]').fill('12.5');
      await panel.getByRole('button',{name:'Aufnahmeoptionen speichern'}).click(); await page.waitForLoadState();
      assert.match(await page.locator('body').innerText(),/Aufnahmeoptionen gespeichert/);
      const config=JSON.parse(fs.readFileSync(env.CAMERA_RPI_PRIVACY_CONFIG));
      assert.equal(config.config.sunrise_offset,30); assert.equal(config.config.sunset_offset,-45);
      assert.equal(config.config.minimum_percent,12.5); assert.equal(config.position.position.latitude,52);
      assert.match(await panel.innerText(),/Freigabezeiten \(Europe\/Berlin\)/);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
      // The checkbox stays left of its clickable label on desktop and mobile.
      const checkbox=panel.locator('[name=privacy_dark_enabled]');
      const box=await checkbox.boundingBox();
      const text=await checkbox.locator('..').locator('span').boundingBox();
      assert(box.x+box.width<=text.x && box.y<text.y+text.height && box.y+box.height>text.y,
        'brightness checkbox immediately before text, not in a separate row');
      if (process.env.CAMERA_RPI_UI_ARTIFACT_DIR) {
        fs.mkdirSync(process.env.CAMERA_RPI_UI_ARTIFACT_DIR,{recursive:true});
        await panel.screenshot({path:path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR,`privacy-${width}.png`)});
      }
    }
    const before=fs.readFileSync(env.CAMERA_RPI_PRIVACY_CONFIG,'utf8');
    await page.request.post(`http://127.0.0.1:${port}/index.php`,{form:{action:'privacy_save',csrf:'bad'}});
    assert.equal(fs.readFileSync(env.CAMERA_RPI_PRIVACY_CONFIG,'utf8'),before,'CSRF blocks write');
    const source=fs.readFileSync(path.join(repo,'config/camera-source.example.conf'),'utf8');
    fs.writeFileSync(env.CAMERA_RPI_SOURCE_CONFIG,source,{mode:0o600});
    await page.reload();
    assert.equal(await page.locator('[name=capture_backend]').inputValue(),'rtsp',await page.locator('body').innerText());
    assert(await page.locator('[name=capture_backend]').isEnabled());
    assert.equal(await page.locator('[name=rtsp_url]').inputValue(),'rtsp://192.0.2.110:554/11');
    await page.getByRole('button',{name:'Sicher speichern',exact:true}).click();
    await page.waitForLoadState();
    assert.equal(fs.readFileSync(env.CAMERA_RPI_SOURCE_CONFIG,'utf8'),source);
    assert.match(fs.readFileSync(path.join(stage,'servers/dev.conf'),'utf8'),/^capture_backend=gphoto2$/m);
    assert.equal(await page.locator('[name=capture_backend]').inputValue(),'rtsp');
    const targetBefore=fs.readFileSync(path.join(stage,'servers/dev.conf'),'utf8');
    for (const width of [390,1366]) {
      await page.setViewportSize({width,height:950});
      const select=page.locator('[name=capture_backend]');
      for (const mode of ['v4l2','gphoto2','rtsp']) {
        await select.selectOption(mode);
        for (const other of ['v4l2','gphoto2','rtsp']) assert.equal(await page.locator(`[data-source-fields="${other}"]`).isVisible(),other===mode);
        if (mode==='gphoto2') await page.locator('[name=gphoto_capture_mode]').selectOption('eos-legacy');
        if (mode==='v4l2') {
          await page.locator('[data-source-devices]').selectOption('/dev/v4l/by-id/usb-Fixture_Camera-video-index0');
          assert.equal(await page.locator('[name=video_device]').inputValue(),'/dev/v4l/by-id/usb-Fixture_Camera-video-index0');
          assert.equal(await page.locator('[data-source-devices] option').count(),2);
          await page.locator('[name=video_device]').fill('/dev/video7');
          assert.equal(await page.locator('[data-source-devices]').inputValue(),'');
        }
        await page.getByRole('button',{name:'Aufnahmequelle speichern',exact:true}).click(); await page.waitForLoadState();
        assert.match(await page.locator('body').innerText(),/Aufnahmequelle für alle Ziele gespeichert/);
        assert.equal(await select.inputValue(),mode);
        assert.match(fs.readFileSync(env.CAMERA_RPI_SOURCE_CONFIG,'utf8'),new RegExp('^source='+mode+'$','m'));
        assert.equal(fs.readFileSync(path.join(stage,'servers/dev.conf'),'utf8'),targetBefore);
      }
      assert.equal(await page.locator('[name=video_device]').inputValue(),'/dev/video7');
      assert.equal(await page.locator('[name=gphoto_capture_mode]').inputValue(),'eos-legacy');
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    const sourceBefore=fs.readFileSync(env.CAMERA_RPI_SOURCE_CONFIG,'utf8');
    await page.locator('[name=rtsp_url]').fill('file:///etc/passwd');
    await page.getByRole('button',{name:'Aufnahmequelle speichern',exact:true}).click(); await page.waitForLoadState();
    assert.equal(fs.readFileSync(env.CAMERA_RPI_SOURCE_CONFIG,'utf8'),sourceBefore);
    await page.request.post(`http://127.0.0.1:${port}/index.php`,{form:{action:'source_save',csrf:'bad'}});
    assert.equal(fs.readFileSync(env.CAMERA_RPI_SOURCE_CONFIG,'utf8'),sourceBefore);
    console.log('OK: RTSP/USB/gphoto source switching, conditional fields, custom path, retained alternatives, validation and CSRF on desktop/mobile');
    console.log('OK: privacy admin desktop/mobile, missing-site rejection, signed offsets, imported position, threshold, timezone, CSRF; no hardware');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode===null) { server.kill('SIGTERM'); await once(server,'exit'); }
    fs.rmSync(stage,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
