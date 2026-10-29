// Local PHP rendering with private RAM-status fixtures only. No sensor, GPIO,
// daemon, credentials or real camera endpoint is accessed by this UI test.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { chromium } = require('playwright');

(async () => {
  const repo = path.resolve(__dirname, '..');
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'camera-climate-status-ui-'));
  const port = 26000 + process.pid % 10000;
  const now = Math.floor(Date.now() / 1000);
  const defaults = { updated_epoch: now, mode: 'simulate', state: 'heating',
    message: 'Taupunktschutz aktiv', heater: 'on', fan_percent: '100',
    fan_target_percent: '30', dew_point_c: '17.4', dew_gap_c: '2.6', outputs: 'none' };
  const publish = (changes = {}) => fs.writeFileSync(path.join(stage, 'climate.status'),
    Object.entries({ ...defaults, updated_epoch: Math.floor(Date.now() / 1000), ...changes })
      .map(([key, value]) => `${key}=${value}\n`).join(''));
  let server, browser;
  try {
    fs.writeFileSync(path.join(stage, 'current.status'),
      `updated_epoch=${now}\nsensor_state=ok\ntemperature_c=20.0\nhumidity_percent=85.0\nfan_rpm=2400\nheater_state=unknown\nheater_message=Heizungsstatus ist nicht angeschlossen.\nfan_state=unknown\nfan_message=Luefterstatus ist nicht angeschlossen.\n`);
    fs.writeFileSync(path.join(stage, 'history.tsv'), `${now - 180}\t18.0\t70.0\n${now - 150}\t19.0\t75.0\n${now}\t20.0\t85.0\n`);
    // Independent regulator history: startup boost, running PWM, capture pause,
    // then an outage and a mode change. Never derive actuator history from DHT.
    const historyFile = path.join(stage, 'climate-history.tsv');
    fs.writeFileSync(historyFile, [
      [180, 'simulate', 'heating', 'none', 17.4, 2.6, 100, 1],
      [178, 'simulate', 'heating', 'none', 17.4, 2.6, 85, 1],
      [148, 'simulate', 'heating', 'none', 17.5, 2.5, 86, 1],
      [130, 'simulate', 'capture_pause', 'none', 17.5, 2.5, 0, 0],
      [128, 'simulate', 'heating', 'none', 17.6, 2.4, 100, 1],
      [126, 'simulate', 'heating', 'none', 17.6, 2.4, 86, 1],
      [60, 'simulate', 'idle', 'none', 16.0, 6.5, 0, 0],
      [30, 'auto', 'idle', 'gpio', 16.1, 6.6, 0, 0],
      [0, 'auto', 'idle', 'gpio', 16.0, 6.7, 0, 0],
    ].map(([ago, ...rest]) => [now - ago, ...rest].join('\t') + '\n').join(''));
    publish();
    server = spawn('php', ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', path.join(repo, 'status/public'), path.join(__dirname, 'fixtures/status-router.php')], {
      stdio: 'ignore', env: { ...process.env, CAMERA_RPI_STATUS_ROOT: stage },
    });
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ timezoneId: 'UTC' });
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('console', message => { if (message.type() === 'error') failures.push(message.text()); });
    const url = `http://127.0.0.1:${port}/index.php`;
    await page.goto(url);
    assert.equal(await page.locator('h1').innerText(), 'Lokaler Blick', 'no invented camera ID before first capture');
    assert.equal(await page.locator('.page-head .eyebrow').textContent(), 'Camera-RPi V'+fs.readFileSync(path.resolve(__dirname,'../VERSION'),'utf8').trim());
    fs.writeFileSync(path.join(stage, 'latest.status'), 'camera_slug=steinhagen\n');
    await page.reload();
    assert.equal(await page.locator('h1').innerText(), 'Lokaler Blick auf Steinhagen');
    assert.equal(await page.title(), 'Lokaler Blick auf Steinhagen · Camera-RPi');
    assert.equal(fs.readFileSync(path.join(stage, 'latest.status'), 'utf8'), 'camera_slug=steinhagen\n', 'display does not rewrite the stored identifier');
    fs.writeFileSync(path.join(stage, 'latest.status'), 'camera_slug=<img src=x onerror=alert(1)>\n');
    await page.reload();
    assert.equal(await page.locator('h1 img').count(), 0, 'camera ID is text, never HTML');
    assert.equal(await page.locator('h1').innerText(), 'Lokaler Blick auf <img src=x onerror=alert(1)>');
    fs.writeFileSync(path.join(stage, 'latest.status'), 'camera_slug=steinhagen\n');
    await page.reload();
    const cssVersion = createHash('sha256').update(fs.readFileSync(path.join(repo, 'status/public/style.css'))).digest('hex').slice(0, 12);
    assert.equal(await page.locator('link[rel="stylesheet"]').getAttribute('href'), `/camera-status/style.css?v=${cssVersion}`);
    const overview = page.locator('.climate-overview');
    const cells = overview.locator('tbody td');
    assert.equal(await page.locator('.cards,.card,.heater-card,.fan-card').count(), 0, 'three redundant cards removed');
    assert.deepEqual(await page.locator('.overlay-values strong').allTextContents(), ['20,0 °C', '85,0 % rF']);
    assert.doesNotMatch(await page.locator('body').innerText(), /nicht angeschlossen|2400|Dreht/);
    assert.deepEqual(await overview.locator('th').allTextContents(), ['Taupunkt', 'Abstand', 'Lüfter-PWM', 'Heizung']);
    assert.deepEqual(await cells.allTextContents(), ['17,4 °C', '2,6 °C', '100,0 %', 'An']);
    assert.match(await overview.innerText(), /Betriebsart: Simulation/);
    assert.doesNotMatch(await overview.innerText(), /U\/min|2400|Sollwerte, keine physische/);
    // During the two-second start boost the current 100% must be displayed,
    // not the eventual 30% target. Keep the table and insert the new graph.
    const graph = page.locator('svg.chart:not(.climate-chart)');
    assert.equal(await page.locator('main > .chart-card:not(.climate-history):not(.climate-overview) h2').innerText(), 'Temperatur & Luftfeuchte im Kamera-Innengehäuse');
    const climateGraph = page.locator('.climate-history');
    const colors = [];
    for (const metric of ['dew', 'gap', 'pwm', 'heater']) {
      const masked = ['pwm', 'heater'].includes(metric);
      assert.equal(await climateGraph.locator(`.${metric}-line`).count(), masked ? 4 : 3, 'outages, mode changes and masked capture break paths');
      assert.equal(await climateGraph.locator(`.${metric}-line.mode-simulate`).count(), masked ? 3 : 2);
      const label = climateGraph.locator(`.legend .${metric}`);
      const color = await label.evaluate(el => getComputedStyle(el).color);
      assert.equal(await label.evaluate(el => getComputedStyle(el, '::before').backgroundColor), color, 'legend marker matches label');
      assert.equal(await climateGraph.locator(`.${metric}-line`).first().evaluate(el => getComputedStyle(el).stroke), color, 'curve matches label');
      colors.push(color);
    }
    assert.equal(new Set(colors).size, 4, 'four distinct label/marker/curve colors');
    assert.match(await climateGraph.locator('.heater-line').first().getAttribute('d'), /H[0-9.]+ V/);
    assert.match(await climateGraph.locator('.pwm-line').first().getAttribute('d'), /H[0-9.]+ V/);
    assert.match(await climateGraph.locator('.dew-line.mode-simulate').first().evaluate(el => getComputedStyle(el).strokeDasharray), /6/);
    for (const width of [390, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'page overflow');
      assert(await overview.locator('.table-scroll').evaluate(el => el.scrollWidth <= el.clientWidth), 'all four columns must fit at 390px');
      const graphBox = await graph.boundingBox(), climateBox = await climateGraph.boundingBox(), tableBox = await overview.boundingBox();
      assert(climateBox.y > graphBox.y + graphBox.height, 'climate graph must follow temperature graph');
      assert(tableBox.y > climateBox.y + climateBox.height, 'existing table must follow the new graph');
      // Use rendered SVG coordinates, including letterboxing on mobile. Equal
      // X at top/bottom must select the same actual time without hitting a line.
      const sensorTip = graph.locator('..').locator('.chart-tooltip');
      const climateSvg = climateGraph.locator('svg');
      const climateTip = climateGraph.locator('.chart-tooltip');
      const move = async (svg, x, y, type = 'mouse') => {
        await svg.scrollIntoViewIfNeeded();
        const point = await svg.evaluate((el, xy) => {
          const point = el.createSVGPoint(); point.x = xy[0]; point.y = xy[1];
          const screen = point.matrixTransform(el.getScreenCTM());
          return {x: screen.x, y: screen.y};
        }, [x, y]);
        if (type === 'mouse') await page.mouse.move(point.x, point.y);
        else await svg.dispatchEvent('pointerdown', {pointerType: 'touch', clientX: point.x, clientY: point.y});
      };
      let hoverRequests = 0;
      const onRequest = () => ++hoverRequests;
      page.on('request', onRequest);
      assert.match(await graph.locator('.temperature-line').getAttribute('points'), /166\.0,/, 'sensor uses real elapsed time, not sample index');
      await move(graph, 166, 48);
      assert.match(await sensorTip.innerText(), /Temperatur: 19,0 °C/);
      assert.match(await sensorTip.innerText(), /Luftfeuchte: 75,0 %/);
      const selectedTime = await sensorTip.locator('strong').innerText();
      const berlinTime = new Intl.DateTimeFormat('de-DE', {timeZone: 'Europe/Berlin', dateStyle: 'short', timeStyle: 'medium'}).format(new Date((now - 150) * 1000));
      assert.equal(selectedTime, berlinTime, 'tooltip is Berlin time even in UTC browser');
      await move(graph, 166, 236);
      assert.equal(await sensorTip.locator('strong').innerText(), selectedTime);
      await move(climateSvg, 70, 280);
      assert.match(await climateTip.innerText(), /PWM: 100,0 %/);
      assert.match(await climateTip.innerText(), /Heizung: An/);
      assert.match(await climateTip.innerText(), /Simulation/);
      await move(climateSvg, 450, 100, 'touch');
      assert.match(await climateTip.innerText(), /Messlücke/);
      assert.match(await climateTip.innerText(), /PWM: 0,0 %/);
      await climateSvg.focus();
      await page.keyboard.press('Home');
      await page.keyboard.press('ArrowRight');
      assert.match(await climateTip.innerText(), /PWM: 85,0 %/);
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('ArrowRight');
      assert.match(await climateTip.innerText(), /PWM: Ausgeblendet/);
      assert.match(await climateTip.innerText(), /Heizung: Ausgeblendet/);
      await page.keyboard.press('ArrowRight');
      assert.match(await climateTip.innerText(), /PWM: Ausgeblendet/);
      assert.match(await climateTip.innerText(), /Heizung: An/);
      await page.keyboard.press('End');
      assert.match(await climateTip.innerText(), /Heizung: Aus/);
      await page.keyboard.press('Escape');
      assert(await climateTip.isHidden());
      await graph.dispatchEvent('pointerleave');
      assert(await sensorTip.isHidden());
      assert.equal(hoverRequests, 0, 'hover/touch/keyboard never fetch');
      page.off('request', onRequest);
      if (process.env.CAMERA_RPI_UI_ARTIFACT_DIR) {
        fs.mkdirSync(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, { recursive: true });
        await overview.screenshot({ path: path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, `climate-status-${width}.png`) });
        await climateGraph.screenshot({ path: path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, `climate-history-${width}.png`) });
        await page.screenshot({ path: path.join(process.env.CAMERA_RPI_UI_ARTIFACT_DIR, `climate-page-${width}.png`), fullPage: true });
      }
    }
    publish({ fault: 'capture_timeout', state: 'capture_pause', measurement_source: 'current' });
    await page.setViewportSize({ width: 390, height: 900 });
    await page.reload();
    assert.match(await page.locator('.page-head [role=alert]').innerText(), /capture_timeout/);
    assert.match(await overview.innerText(), /aktuellen gültigen Messpaar/);
    assert.deepEqual((await cells.allTextContents()).slice(0, 2), ['17,4 °C', '2,6 °C']);
    assert.equal(await page.locator('.climate-fault a').getAttribute('href'), '/camera-admin/#climate');
    assert.equal(await page.locator('.climate-fault form, .climate-fault button').count(), 0);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'fault notice fits mobile');
    publish({ fault: 'capture_timeout', updated_epoch: now - 60 });
    await page.reload();
    assert.match(await page.locator('.climate-fault').innerText(), /Letzter bekannter Fehler/);
    publish({ fault: '<script>alert(1)</script>' });
    await page.reload();
    assert.equal(await page.locator('.climate-fault script').count(), 0);
    publish({ heater: 'off', fan_percent: '0', dew_point_c: '-12.8', dew_gap_c: '2.8', mode: 'auto' });
    await page.reload();
    assert.equal(await page.locator('.climate-fault').count(), 0);
    assert.deepEqual(await cells.allTextContents(), ['-12,8 °C', '2,8 °C', '0,0 %', 'Aus']);
    assert.match(await overview.innerText(), /Betriebsart: Automatik/);
    publish({ dew_point_c: '', dew_gap_c: '', fan_percent: '75' });
    await page.reload();
    assert.deepEqual(await cells.allTextContents(), ['–', '–', '75,0 %', 'An']);
    publish({ updated_epoch: now - 60 });
    await page.reload();
    assert.deepEqual(await cells.allTextContents(), ['–', '–', '–', 'Unbekannt']);
    assert.match(await overview.innerText(), /Kein frischer Reglerstatus/);
    assert.equal(await climateGraph.locator('.heater-line').count(), 4, 'stale current status does not erase history');
    publish({ updated_epoch: now + 60 });
    await page.reload();
    assert.deepEqual(await cells.allTextContents(), ['–', '–', '–', 'Unbekannt']);
    publish({ dew_point_c: 'NaN', dew_gap_c: '-1', fan_percent: '101', heater: '<script>', message: '<script>alert(1)</script>' });
    await page.reload();
    assert.deepEqual(await cells.allTextContents(), ['–', '–', '–', 'Unbekannt']);
    assert.equal(await overview.locator('script').count(), 0);
    fs.unlinkSync(path.join(stage, 'climate.status'));
    await page.reload();
    assert.deepEqual(await cells.allTextContents(), ['–', '–', '–', 'Unbekannt']);
    assert.equal(await overview.locator('form,button,input').count(), 0, 'public overview stays read-only');
    fs.writeFileSync(historyFile, `${now}\tauto\tidle\tgpio\t-\t-\t75\t1\n`);
    await page.reload();
    await climateGraph.locator('svg').focus();
    assert.match(await climateGraph.locator('.chart-tooltip').innerText(), /Taupunkt: Unbekannt/);
    assert.match(await climateGraph.locator('.chart-tooltip').innerText(), /PWM: 75,0 %/);
    fs.writeFileSync(historyFile, `${now}\tauto\tstopped\tgpio\t10\t5\t75\t1\n`);
    await page.reload();
    await climateGraph.locator('svg').focus();
    assert.equal((await climateGraph.locator('.chart-tooltip').innerText()).match(/Unbekannt/g).length, 4, 'stopped rows do not invent actuator states');
    fs.writeFileSync(historyFile, `${now}\t<script>\theating\tgpio\t10\t5\t80\t1\n`);
    await page.reload();
    assert.equal(await climateGraph.locator('svg,script').count(), 0, 'invalid history cannot inject markup');
    fs.unlinkSync(historyFile);
    await page.reload();
    assert.match(await climateGraph.innerText(), /ersten Aufzeichnung/);
    assert.equal(await graph.count(), 1, 'missing regulator history preserves temperature history');
    assert.equal(fs.existsSync(historyFile), false, 'page never creates history');
    assert.deepEqual(failures, [], 'no script, asset or CSP errors');
    console.log('OK: Climate/status 390/1280px; vertical nearest-time hover, real time axis, Berlin timezone, gaps, simulation, touch/keyboard, CSP, no hover requests; retained table/read-only');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { server.kill(); await once(server, 'exit'); }
    fs.rmSync(stage, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
