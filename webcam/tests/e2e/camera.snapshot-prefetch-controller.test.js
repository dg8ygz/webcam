const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';

const assetUrl = () => new URL(
  'assets/js/camera-snapshot-prefetch.js',
  `${BASE_URL.replace(/\/$/, '')}/`,
).toString();

/**
 * Prüft den kurzlebigen Browsercache ohne echte Kamera- oder Archivdateien.
 * HTML und Bild werden vollständig abgefangen; der Test verändert deshalb
 * weder Kamera-DB noch Laufzeitzustand der Anwendung.
 */
test('deduplicates prefetch and consumes only the expected snapshot document', async ({ page }) => {
  const origin = new URL(BASE_URL).origin;
  const fixtureUrl = `${origin}/__webcam_prefetch_fixture__`;
  const targetUrl = `${origin}/__webcam_prefetch_target__`;
  const imageUrl = `${origin}/__webcam_prefetch_image__.jpg`;
  let documentRequests = 0;
  let imageRequests = 0;

  await page.route(fixtureUrl, (route) => route.fulfill({
    status: 200,
    contentType: 'text/html; charset=utf-8',
    body: `
      <main id="camera-live-root">
        <div class="live-image" data-current-snapshot="100">
          <img data-live-image src="${origin}/initial.jpg" alt="">
        </div>
        <div data-timeline-hotspot data-url="${targetUrl}" data-target-key="200"></div>
      </main>
    `,
  }));
  await page.route(targetUrl, (route) => {
    documentRequests += 1;
    return route.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      body: `
        <main id="camera-live-root">
          <div class="live-image" data-current-snapshot="200">
            <img data-live-image src="${imageUrl}" alt="">
          </div>
        </main>
      `,
    });
  });
  await page.route(imageUrl, (route) => {
    imageRequests += 1;
    return route.fulfill({
      status: 200,
      contentType: 'image/jpeg',
      body: Buffer.from([0xff, 0xd8, 0xff, 0xd9]),
    });
  });

  await page.goto(fixtureUrl);
  await page.addScriptTag({ url: assetUrl() });
  await page.evaluate(() => {
    window.__snapshotPrefetch = window.WebcamCameraSnapshotPrefetch.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      isPrefetchEnabled: () => true,
    });
    // Zwei unmittelbare Aufrufe dürfen nur einen Netzwerkrequest starten.
    window.__snapshotPrefetch.prefetchAdjacentSnapshots();
    window.__snapshotPrefetch.prefetchAdjacentSnapshots();
  });

  await expect.poll(() => documentRequests).toBe(1);
  await expect.poll(() => imageRequests).toBe(1);

  const firstConsumption = await page.evaluate((url) => {
    const target = new URL(url);
    const first = window.__snapshotPrefetch.consumePrefetchedDocument(target, '200');
    const second = window.__snapshotPrefetch.consumePrefetchedDocument(target, '200');
    return {
      firstSnapshot: first?.querySelector('[data-current-snapshot]')?.getAttribute('data-current-snapshot'),
      secondWasEmpty: second === null,
    };
  }, targetUrl);
  expect(firstConsumption).toEqual({ firstSnapshot: '200', secondWasEmpty: true });

  // Nach dem Verbrauch darf erneut geladen werden. Ein falscher erwarteter
  // Schlüssel darf das Dokument nicht aus dem Cache entfernen.
  await page.evaluate(() => window.__snapshotPrefetch.prefetchAdjacentSnapshots());
  await expect.poll(() => documentRequests).toBe(2);
  await page.waitForTimeout(100);
  const keyGuard = await page.evaluate((url) => {
    const target = new URL(url);
    const wrong = window.__snapshotPrefetch.consumePrefetchedDocument(target, '999');
    const correct = window.__snapshotPrefetch.consumePrefetchedDocument(target, '200');
    return wrong === null && correct instanceof Document;
  }, targetUrl);
  expect(keyGuard).toBe(true);

  const hydrated = await page.evaluate((source) => {
    const root = document.getElementById('camera-live-root');
    root.innerHTML = `<div class="live-image"><img data-live-image src="${source}" alt=""></div>`;
    window.__snapshotPrefetch.hydrateLiveImageFromCache();
    const image = root.querySelector('[data-live-image]');
    return {
      source: image.getAttribute('src'),
      originalSource: image.dataset.originalSrc,
    };
  }, imageUrl);
  expect(hydrated.source).toMatch(/^blob:/);
  expect(hydrated.originalSource).toBe(imageUrl);
});
