// Browser-only status fixtures: no live endpoint, sensor, GPIO or driver action.
// Advance the real 60s timer instead of adding a faster production test switch.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');
(async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-refresh-ui-'));
  const repo = path.resolve(__dirname, '..'), now = Math.floor(Date.now()/1000);
  const port = 26000 + process.pid % 10000;
  let server, browser;
  try {
    fs.writeFileSync(path.join(root, 'current.status'), `updated_epoch=${now}\nsensor_state=ok\ntemperature_c=20.0\nhumidity_percent=80.0\n`);
    fs.writeFileSync(path.join(root, 'history.tsv'), `${now-30}\t19\t80\n${now}\t20\t80\n`);
    const latest = stamp => fs.writeFileSync(path.join(root, 'latest.status'), `captured_epoch=${stamp}\ncamera_slug=test\n`);
    latest(now-20);
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(repo, 'status/public'), path.join(__dirname, 'fixtures/status-router.php')],
      {stdio:'ignore', env:{...process.env, CAMERA_RPI_STATUS_ROOT:root}});
    browser = await chromium.launch({headless:true});
    const page = await browser.newPage();
    const jpeg = await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width=40; canvas.height=30;
      canvas.getContext('2d').fillRect(0,0,40,30); return canvas.toDataURL('image/jpeg').split(',')[1];
    });
    fs.writeFileSync(path.join(root, 'latest.jpg'), Buffer.from(jpeg,'base64'));
    await page.clock.install();
    let navigations=0, images=0, fetches=0;
    const errors=[];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.isNavigationRequest()) navigations++;
      if (request.url().includes('asset=latest-image')) images++;
      if (request.resourceType()==='fetch') fetches++;
    });
    const url = `http://127.0.0.1:${port}/index.php`;
    await page.goto(url);
    await page.locator('.hero img').evaluate(img => img.decode());
    await page.evaluate(() => { window.originalImage=document.querySelector('.hero img'); window.originalDocument=document; });
    const waitFor = async predicate => {
      for(let i=0;i<100;i++) { if(await predicate()) return; await new Promise(resolve=>setTimeout(resolve,30)); }
      throw new Error('Timed out waiting for background update');
    };
    const advance = async () => { await page.clock.runFor(60000); };
    fs.writeFileSync(path.join(root, 'current.status'), `updated_epoch=${now}\nsensor_state=ok\ntemperature_c=21.0\nhumidity_percent=80.0\n`);
    fs.writeFileSync(path.join(root, 'climate.status'), `updated_epoch=${now}\nfault=capture_timeout\nstate=capture_pause\n`);
    await advance();
    await waitFor(async()=> (await page.locator('.overlay-values').innerText()).includes('21,0'));
    assert.equal(navigations,1); assert.equal(images,1); assert.equal(fetches,1);
    assert.match(await page.locator('.climate-fault').innerText(), /capture_timeout/);
    assert(await page.evaluate(()=>originalImage===document.querySelector('.hero img') && originalDocument===document), 'same image and document retained');

    // Hold a new JPEG download open: the old image and its timestamp stay put.
    latest(now-10);
    fs.unlinkSync(path.join(root, 'climate.status'));
    let release, requested=false;
    await page.route(`**/*asset=latest-image*v=${now-10}`, async route => {
      requested=true; await new Promise(resolve=>{release=resolve;}); await route.continue();
    });
    const oldTimestamp=await page.locator('.overlay > div strong').first().innerText();
    await advance(); await waitFor(()=>requested);
    assert(await page.evaluate(()=>originalImage===document.querySelector('.hero img') && originalImage.naturalWidth>0));
    assert.equal(await page.locator('.overlay > div strong').first().innerText(),oldTimestamp);
    release();
    await waitFor(()=>page.evaluate(()=>originalImage!==document.querySelector('.hero img')));
    assert(await page.locator('.hero img').evaluate(img=>img.complete && img.naturalWidth>0));
    assert.notEqual(await page.locator('.overlay > div strong').first().innerText(),oldTimestamp);
    assert.equal(await page.locator('.climate-fault').count(), 0, 'resolved fault disappears on background refresh');
    await page.evaluate(()=>window.originalImage=document.querySelector('.hero img'));

    // A broken replacement does not remove the previously decoded image.
    latest(now-5);
    await page.route(`**/*asset=latest-image*v=${now-5}`, route=>route.abort());
    await advance();
    await waitFor(async()=> (await page.locator('.page-head .refresh').innerText()).includes('nicht ladbar'));
    assert(await page.evaluate(()=>originalImage===document.querySelector('.hero img')));

    // Privacy removal is not treated as a failed download: remove stale preview.
    fs.unlinkSync(path.join(root,'latest.jpg'));
    await advance();
    await waitFor(async()=>await page.locator('.hero img').count()===0);
    assert.equal(await page.locator('.image-empty').count(),1);
    await page.locator('.chart-interactive svg').focus();
    assert(await page.locator('.chart-tooltip').isVisible(), 'hover handlers rebound after update');
    await page.route(url, route=>route.fulfill({status:503,body:'unavailable'}));
    await advance();
    await waitFor(async()=> (await page.locator('.page-head .refresh').innerText()).includes('fehlgeschlagen'));
    assert.equal(navigations,1,'no meta-refresh/navigation even after several minutes');
    assert.deepEqual(errors,[]);
    console.log('OK: 60s background refresh; no navigation, stable image node, decoded swap, failure retention, privacy removal, hover rebound');
  } finally {
    if(browser)await browser.close();
    if(server && server.exitCode===null){server.kill();await once(server,'exit');}
    fs.rmSync(root,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
