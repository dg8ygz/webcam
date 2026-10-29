// Controller contract without server, camera, credentials or live DB.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    let calls = 0, media = 0, code = 503, ready = false, prepared = false;
    const probes = [];
    await page.route('http://usb-stream.invalid/**', route => {
      calls++;
      if (code === 200) {
        if (route.request().url().includes('probe=1')) {
          probes.push(route.request().url());
          return route.fulfill({json: {ok: true, ready: ready || route.request().url().includes('resume=1'), duration: 300, message: 'In Kürze wird ein Bild gemacht. Danach automatisch 5 Minuten.'},
            headers: {'Access-Control-Allow-Origin': '*'}});
        }
        assert.ok(route.request().url().includes('prepare=1'), 'controller must prepare before HLS');
        assert.equal(route.request().method(), 'POST');
        if (prepared) media++;
        return route.fulfill({status: prepared ? 200 : 202,
          json: {ok: true, ready: prepared, message: 'Stream wird gestartet …'},
          headers: {'Access-Control-Allow-Origin': '*'}});
      }
      return route.fulfill({status: code, body: 'not ready', headers: {'Access-Control-Allow-Origin': '*'}});
    });
    await page.setContent('<div data-usb-player><video data-duration="300" data-source="http://usb-stream.invalid/stream?format=hls"></video><button id="start" data-usb-start>Start</button><button id="stop" data-usb-stop disabled>Stop</button><p id="state" data-usb-state></p></div>');
    await page.evaluate(() => {
      // Controlled media/timer boundary, not a claim about actual HLS decoding.
      window.Hls = class {
        constructor() { window.players = (window.players || 0) + 1; }
        static isSupported() { return true; }
        static Events = {ERROR: 'error'};
        on(_event, callback) { window.hlsError = callback; }
        loadSource() {} attachMedia() {} destroy() {}
      };
      const original = window.setTimeout;
      window.deadlines = [];
      window.preparationDeadlines = [];
      window.setTimeout = (callback, ms, ...args) => {
        if (ms === 300000) window.deadlines.push(callback);
        if (ms === 60000) window.preparationDeadlines.push(callback);
        return original(callback, ms, ...args);
      };
    });
    await page.addScriptTag({path: path.resolve(__dirname, '../assets/js/usb-live.js')});
    assert.equal(calls, 0, 'page load must not start USB');
    await page.click('#start');
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('nicht verfügbar'));
    await page.click('#stop');
    const stopped = calls;
    await page.waitForTimeout(5500);
    assert.equal(calls, stopped, 'Stop must cancel retry and demand');
    assert.equal(await page.locator('#start').isDisabled(), false);
    code = 403;
    await page.click('#start');
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('gesperrt'));
    assert.equal(await page.locator('#stop').isDisabled(), true);
    const denied = calls;
    await page.waitForTimeout(3500);
    assert.equal(calls, denied, '403 must not reconnect');
    code = 200;
    await page.click('#start');
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('In Kürze'));
    assert.equal(media, 0, 'photo wait must never start camera demand');
    assert.equal(await page.evaluate(() => window.deadlines.length), 0, 'waiting does not consume playback time');
    ready = true;
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('wird gestartet'));
    assert.equal(media, 0);
    assert.equal(await page.evaluate(() => window.players || 0), 0, '202 must not create HLS or request media');
    prepared = true;
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('verbunden') || document.querySelector('#state').textContent.includes('Wiedergabe'));
    assert.equal(media, 1, 'automatically connects after received photo');
    await page.evaluate(() => document.querySelector('video').dispatchEvent(new Event('playing')));
    assert.equal(await page.evaluate(() => window.deadlines.length), 1);
    await page.evaluate(() => document.querySelector('video').dispatchEvent(new Event('playing')));
    assert.equal(await page.evaluate(() => window.deadlines.length), 1, 'reconnect must not extend the five minutes');
    assert.equal(probes.some(url => url.includes('resume=1')), false, 'new Start cannot request continuation');
    ready = false;
    // Await the actual reconnect request, not a status label that an older
    // pending video.play() rejection can also update during retry.
    const resumed = page.waitForResponse(response => response.url().includes('probe=1&resume=1'));
    await page.evaluate(() => window.hlsError('error', {fatal: true}));
    await resumed;
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('verbunden') || document.querySelector('#state').textContent.includes('Wiedergabe'));
    assert.equal(probes.at(-1).includes('resume=1'), true, 'running viewer reconnects across new-start guard');
    assert.equal(await page.evaluate(() => window.deadlines.length), 1, 'resume does not extend timer');
    ready = true;
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    assert.equal(await page.locator('#stop').isDisabled(), false, 'tab switch must not stop stream');
    assert.equal(await page.evaluate(() => window.deadlines.length), 1, 'tab switch keeps original timer');
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', {configurable: true, get: () => false});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    assert.equal(await page.locator('#stop').isDisabled(), false, 'return within deadline keeps stream');
    await page.evaluate(() => {
      // Simulate a suspended timer: returning after the deadline still stops.
      const realNow = Date.now;
      Date.now = () => realNow() + 301000;
      document.dispatchEvent(new Event('visibilitychange'));
      Date.now = realNow;
    });
    assert.match(await page.locator('#state').textContent(), /5 Minuten beendet/);
    assert.equal(await page.locator('#stop').isDisabled(), true);
    await page.click('#start');
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('verbunden') || document.querySelector('#state').textContent.includes('Wiedergabe'));
    await page.evaluate(() => {
      document.querySelector('video').dispatchEvent(new Event('playing'));
      window.deadlines[1]();
    });
    assert.match(await page.locator('#state').textContent(), /5 Minuten beendet/);
    await page.click('#start');
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    assert.equal(await page.locator('#stop').isDisabled(), true, 'leaving page still stops');
    await page.click('#start');
    await page.evaluate(() => {
      const link = document.createElement('a');
      link.href = '#snapshot'; link.dataset.usbSnapshotLink = ''; link.id = 'snapshot-return';
      document.querySelector('[data-usb-player]').appendChild(link);
      // Cancel the default only after the player's bubbling handler, so this
      // checks pre-navigation release while retaining the old document.
      document.addEventListener('click', event => event.preventDefault(), {once: true});
      link.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true, ctrlKey: true}));
    });
    assert.equal(await page.locator('#stop').isDisabled(), false, 'opening another tab keeps playback');
    await page.evaluate(() => {
      document.addEventListener('click', event => event.preventDefault(), {once: true});
      document.querySelector('#snapshot-return').click();
    });
    assert.equal(await page.locator('#stop').isDisabled(), true, 'snapshot click stops before pagehide');
    assert.equal(await page.locator('#state').textContent(), 'Livebild wird geladen …');
    const afterReturn = calls;
    await page.waitForTimeout(5500);
    assert.equal(calls, afterReturn, 'pending probes and retries cannot resume after return');
    prepared = false;
    await page.click('#start');
    await page.waitForFunction(() => document.querySelector('#state').textContent.includes('wird gestartet'));
    await page.evaluate(() => window.preparationDeadlines.at(-1)());
    assert.match(await page.locator('#state').textContent(), /60 Sekunden/);
    assert.equal(await page.locator('#stop').isDisabled(), true, 'startup timeout stops demand retries');
    console.log('OK: idle/start/503/stop/403/photo-wait/202-preparation/60s-timeout/automatic-start/resume-across-start-guard/5-minute-stop/tab-change/expired-return/pagehide; no live camera');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
