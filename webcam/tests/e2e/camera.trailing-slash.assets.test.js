const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8082';
const CAMERA_PATH = (process.env.CAMERA_PATH || '/camera/bielefeld').replace(/\/+$/, '');

// Fuer Asset- und Linkaufloesung ist kein Frontend-JavaScript erforderlich;
// deaktiviert lassen, damit der Test keinen Livestream-Viewer oeffnet.
test.use({ javaScriptEnabled: false });

const cameraUrl = (trailingSlash) => {
  const base = `${BASE_URL.replace(/\/$/, '')}/`;
  const path = `${CAMERA_PATH.replace(/^\//, '')}${trailingSlash ? '/' : ''}`;
  return new URL(path, base).toString();
};

test('keeps assets valid when slash and non-slash camera routes share the page cache', async ({ page }) => {
  for (const trailingSlash of [false, true, false]) {
    const response = await page.goto(cameraUrl(trailingSlash), { waitUntil: 'domcontentloaded' });
    expect(response && response.status()).toBe(200);

    const stylesheetHref = await page.locator('link[rel="stylesheet"][href*="assets/css/app.css"]').getAttribute('href');
    expect(stylesheetHref).toBeTruthy();
    const stylesheetUrl = new URL(stylesheetHref, page.url()).toString();
    const stylesheetResponse = await page.request.get(stylesheetUrl);
    expect(stylesheetResponse.status()).toBe(200);
    expect(stylesheetResponse.headers()['content-type'] || '').toContain('text/css');

    // Wetterlogik wird vor der allgemeinen Kamerasteuerung geladen. Auch das
    // neue Teil-Asset muss bei beiden Pretty-Route-Formen dokumentrelativ
    // auflösbar bleiben.
    const weatherModelScriptSrc = await page.locator('script[src*="assets/js/camera-weather-model.js"]').getAttribute('src');
    expect(weatherModelScriptSrc).toBeTruthy();
    const weatherModelScriptUrl = new URL(weatherModelScriptSrc, page.url()).toString();
    const weatherModelScriptResponse = await page.request.get(weatherModelScriptUrl);
    expect(weatherModelScriptResponse.status()).toBe(200);
    expect(weatherModelScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const weatherScriptSrc = await page.locator('script[src*="assets/js/camera-weather.js"]').getAttribute('src');
    expect(weatherScriptSrc).toBeTruthy();
    const weatherScriptUrl = new URL(weatherScriptSrc, page.url()).toString();
    const weatherScriptResponse = await page.request.get(weatherScriptUrl);
    expect(weatherScriptResponse.status()).toBe(200);
    expect(weatherScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Auch die große Umgebungskarte ist ein eigenes Modul. Sie muss vor
    // camera.js erreichbar sein, unabhängig vom abschließenden Route-Slash.
    const surroundingsScriptSrc = await page.locator('script[src*="assets/js/camera-surroundings.js"]').getAttribute('src');
    expect(surroundingsScriptSrc).toBeTruthy();
    const surroundingsScriptUrl = new URL(surroundingsScriptSrc, page.url()).toString();
    const surroundingsScriptResponse = await page.request.get(surroundingsScriptUrl);
    expect(surroundingsScriptResponse.status()).toBe(200);
    expect(surroundingsScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Der Bildvergleich ist ebenfalls ein eigenes Modul und muss zwingend vor
    // camera.js geladen werden. Sonst würde das Optionen-Menü erst beim ersten
    // Klick mit einer fehlenden Factory abbrechen.
    const compareScriptSrc = await page.locator('script[src*="assets/js/camera-image-compare.js"]').getAttribute('src');
    expect(compareScriptSrc).toBeTruthy();
    const compareScriptUrl = new URL(compareScriptSrc, page.url()).toString();
    const compareScriptResponse = await page.request.get(compareScriptUrl);
    expect(compareScriptResponse.status()).toBe(200);
    expect(compareScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Dokument- und Bild-Prefetch sind ein eigener, kurzlebiger Controller.
    // Auch dieses Modul muss vor camera.js dokumentrelativ erreichbar sein.
    const prefetchScriptSrc = await page.locator('script[src*="assets/js/camera-snapshot-prefetch.js"]').getAttribute('src');
    expect(prefetchScriptSrc).toBeTruthy();
    const prefetchScriptUrl = new URL(prefetchScriptSrc, page.url()).toString();
    const prefetchScriptResponse = await page.request.get(prefetchScriptUrl);
    expect(prefetchScriptResponse.status()).toBe(200);
    expect(prefetchScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Beschriftungsnormalisierung und die Regeln für bereits eingebrannte
    // Overlays liegen in einem eigenen Controller. Er muss vor camera.js
    // erreichbar sein, sonst könnte die Kameraansicht keine Labels starten.
    const labelsScriptSrc = await page.locator('script[src*="assets/js/camera-labels.js"]').getAttribute('src');
    expect(labelsScriptSrc).toBeTruthy();
    const labelsScriptUrl = new URL(labelsScriptSrc, page.url()).toString();
    const labelsScriptResponse = await page.request.get(labelsScriptUrl);
    expect(labelsScriptResponse.status()).toBe(200);
    expect(labelsScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Das gemeinsame EXIF-/Wetter-/Karten-/Vergleichsmodal koordiniert die
    // Fachmodule in einem eigenen Controller. Auch dieser muss vor camera.js
    // bei beiden Pretty-Route-Tiefen dokumentrelativ erreichbar sein.
    const metaModalScriptSrc = await page.locator('script[src*="assets/js/camera-meta-modal.js"]').getAttribute('src');
    expect(metaModalScriptSrc).toBeTruthy();
    const metaModalScriptUrl = new URL(metaModalScriptSrc, page.url()).toString();
    const metaModalScriptResponse = await page.request.get(metaModalScriptUrl);
    expect(metaModalScriptResponse.status()).toBe(200);
    expect(metaModalScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Navigationsmodus und die fünf Timeline-Picker liegen ebenfalls in einem
    // eigenen Modul, das vor camera.js verfügbar sein muss.
    const timelineScriptSrc = await page.locator('script[src*="assets/js/camera-timeline.js"]').getAttribute('src');
    expect(timelineScriptSrc).toBeTruthy();
    const timelineScriptUrl = new URL(timelineScriptSrc, page.url()).toString();
    const timelineScriptResponse = await page.request.get(timelineScriptUrl);
    expect(timelineScriptResponse.status()).toBe(200);
    expect(timelineScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // API-Abruf, Tagescache und Timeline-View-Model wurden absichtlich nicht
    // in den Picker-Controller gemischt. Auch dieses zweite Timeline-Modul
    // muss bei beiden Route-Tiefen vor camera.js erreichbar sein.
    const timelineModelScriptSrc = await page.locator('script[src*="assets/js/camera-timeline-model.js"]').getAttribute('src');
    expect(timelineModelScriptSrc).toBeTruthy();
    const timelineModelScriptUrl = new URL(timelineModelScriptSrc, page.url()).toString();
    const timelineModelScriptResponse = await page.request.get(timelineModelScriptUrl);
    expect(timelineModelScriptResponse.status()).toBe(200);
    expect(timelineModelScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const timelineDataScriptSrc = await page.locator('script[src*="assets/js/camera-timeline-data.js"]').getAttribute('src');
    expect(timelineDataScriptSrc).toBeTruthy();
    const timelineDataScriptUrl = new URL(timelineDataScriptSrc, page.url()).toString();
    const timelineDataScriptResponse = await page.request.get(timelineDataScriptUrl);
    expect(timelineDataScriptResponse.status()).toBe(200);
    expect(timelineDataScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Das Timeline-Modell und seine DOM-Darstellung bleiben getrennte Module.
    // Der View-Controller muss bei beiden Route-Tiefen vor camera.js laden.
    const timelineViewScriptSrc = await page.locator('script[src*="assets/js/camera-timeline-view.js"]').getAttribute('src');
    expect(timelineViewScriptSrc).toBeTruthy();
    const timelineViewScriptUrl = new URL(timelineViewScriptSrc, page.url()).toString();
    const timelineViewScriptResponse = await page.request.get(timelineViewScriptUrl);
    expect(timelineViewScriptResponse.status()).toBe(200);
    expect(timelineViewScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Like-Captcha und Archivhistorie sind getrennte Dialogcontroller. Beide
    // müssen bei Slash- und Non-Slash-Routen vor camera.js erreichbar sein.
    const captchaScriptSrc = await page.locator('script[src*="assets/js/camera-captcha.js"]').getAttribute('src');
    expect(captchaScriptSrc).toBeTruthy();
    const captchaScriptUrl = new URL(captchaScriptSrc, page.url()).toString();
    const captchaScriptResponse = await page.request.get(captchaScriptUrl);
    expect(captchaScriptResponse.status()).toBe(200);
    expect(captchaScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Like-Ablauf und Statusdialog besitzen eigene, dokumentierte Controller.
    // Beide müssen vor camera.js unter jeder Pretty-Route erreichbar bleiben.
    const likeScriptSrc = await page.locator('script[src*="assets/js/camera-bestof-like.js"]').getAttribute('src');
    expect(likeScriptSrc).toBeTruthy();
    const likeScriptResponse = await page.request.get(new URL(likeScriptSrc, page.url()).toString());
    expect(likeScriptResponse.status()).toBe(200);
    expect(likeScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const statusScriptSrc = await page.locator('script[src*="assets/js/camera-status.js"]').getAttribute('src');
    expect(statusScriptSrc).toBeTruthy();
    const statusScriptResponse = await page.request.get(new URL(statusScriptSrc, page.url()).toString());
    expect(statusScriptResponse.status()).toBe(200);
    expect(statusScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // HTML-Austausch, History und Hotspot-Ereignisse liegen in einem eigenen
    // Navigationscontroller. Er muss vor camera.js auf beiden Routen laden.
    const partialNavigationSrc = await page.locator('script[src*="assets/js/camera-partial-navigation.js"]').getAttribute('src');
    expect(partialNavigationSrc).toBeTruthy();
    const partialNavigationResponse = await page.request.get(new URL(partialNavigationSrc, page.url()).toString());
    expect(partialNavigationResponse.status()).toBe(200);
    expect(partialNavigationResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Placeholder, Vollbildtausch und Lightbox-Klick werden ebenfalls vor dem
    // Orchestrator geladen und müssen beide Pretty-Route-Tiefen überstehen.
    const liveImageSrc = await page.locator('script[src*="assets/js/camera-live-image.js"]').getAttribute('src');
    expect(liveImageSrc).toBeTruthy();
    const liveImageResponse = await page.request.get(new URL(liveImageSrc, page.url()).toString());
    expect(liveImageResponse.status()).toBe(200);
    expect(liveImageResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const historyScriptSrc = await page.locator('script[src*="assets/js/camera-history.js"]').getAttribute('src');
    expect(historyScriptSrc).toBeTruthy();
    const historyScriptUrl = new URL(historyScriptSrc, page.url()).toString();
    const historyScriptResponse = await page.request.get(historyScriptUrl);
    expect(historyScriptResponse.status()).toBe(200);
    expect(historyScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Tagesvideo und Timelapse besitzen getrennte Controller. Beide müssen vor
    // camera.js und unter beiden Route-Tiefen erreichbar sein.
    const dayvideoScriptSrc = await page.locator('script[src*="assets/js/camera-dayvideo.js"]').getAttribute('src');
    expect(dayvideoScriptSrc).toBeTruthy();
    const dayvideoScriptUrl = new URL(dayvideoScriptSrc, page.url()).toString();
    const dayvideoScriptResponse = await page.request.get(dayvideoScriptUrl);
    expect(dayvideoScriptResponse.status()).toBe(200);
    expect(dayvideoScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const playbackScriptSrc = await page.locator('script[src*="assets/js/camera-playback.js"]').getAttribute('src');
    expect(playbackScriptSrc).toBeTruthy();
    const playbackScriptUrl = new URL(playbackScriptSrc, page.url()).toString();
    const playbackScriptResponse = await page.request.get(playbackScriptUrl);
    expect(playbackScriptResponse.status()).toBe(200);
    expect(playbackScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Archiv-Lightbox, Tageswechsel und Best-of-Navigation sind ebenfalls
    // ausgelagert. Die Factory muss geladen sein, bevor camera.js beim ersten
    // Bildklick den zugehörigen Controller erzeugt.
    const lightboxEntryScriptSrc = await page.locator('script[src*="assets/js/camera-lightbox-entry.js"]').getAttribute('src');
    expect(lightboxEntryScriptSrc).toBeTruthy();
    const lightboxEntryScriptUrl = new URL(lightboxEntryScriptSrc, page.url()).toString();
    const lightboxEntryScriptResponse = await page.request.get(lightboxEntryScriptUrl);
    expect(lightboxEntryScriptResponse.status()).toBe(200);
    expect(lightboxEntryScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const lightboxControlsScriptSrc = await page.locator('script[src*="assets/js/camera-lightbox-controls.js"]').getAttribute('src');
    expect(lightboxControlsScriptSrc).toBeTruthy();
    const lightboxControlsScriptUrl = new URL(lightboxControlsScriptSrc, page.url()).toString();
    const lightboxControlsScriptResponse = await page.request.get(lightboxControlsScriptUrl);
    expect(lightboxControlsScriptResponse.status()).toBe(200);
    expect(lightboxControlsScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const lightboxScriptSrc = await page.locator('script[src*="assets/js/camera-lightbox.js"]').getAttribute('src');
    expect(lightboxScriptSrc).toBeTruthy();
    const lightboxScriptUrl = new URL(lightboxScriptSrc, page.url()).toString();
    const lightboxScriptResponse = await page.request.get(lightboxScriptUrl);
    expect(lightboxScriptResponse.status()).toBe(200);
    expect(lightboxScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Stream-Präferenz und HLS-Renderer werden vor dem Koordinator geladen.
    // Der Test prüft nur die dokumentrelativen Assets und öffnet wegen
    // deaktiviertem JavaScript ausdrücklich keinen echten Stream-Viewer.
    const streamPreferenceScriptSrc = await page.locator('script[src*="assets/js/camera-stream-preference.js"]').getAttribute('src');
    expect(streamPreferenceScriptSrc).toBeTruthy();
    const streamPreferenceScriptUrl = new URL(streamPreferenceScriptSrc, page.url()).toString();
    const streamPreferenceScriptResponse = await page.request.get(streamPreferenceScriptUrl);
    expect(streamPreferenceScriptResponse.status()).toBe(200);
    expect(streamPreferenceScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const streamHlsScriptSrc = await page.locator('script[src*="assets/js/camera-stream-hls.js"]').getAttribute('src');
    expect(streamHlsScriptSrc).toBeTruthy();
    const streamHlsScriptUrl = new URL(streamHlsScriptSrc, page.url()).toString();
    const streamHlsScriptResponse = await page.request.get(streamHlsScriptUrl);
    expect(streamHlsScriptResponse.status()).toBe(200);
    expect(streamHlsScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Der verbleibende Koordinator verbindet MJPEG, HLS und Snapshot.
    const streamScriptSrc = await page.locator('script[src*="assets/js/camera-stream.js"]').getAttribute('src');
    expect(streamScriptSrc).toBeTruthy();
    // Die Kennung enthaelt Iteration, mtime und Dateigroesse. Dadurch kann ein
    // normaler Reload keine alte Stream-Factory mit einer neuen camera.js
    // kombinieren, auch wenn beide Dateien sehr schnell nacheinander wechseln.
    expect(streamScriptSrc).toMatch(/[?&]v=\d+-\d+-\d+(?:&|$)/);
    const streamScriptUrl = new URL(streamScriptSrc, page.url()).toString();
    const streamScriptResponse = await page.request.get(streamScriptUrl);
    expect(streamScriptResponse.status()).toBe(200);
    expect(streamScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    // Die ausgelagerte Standortkarte ist ebenfalls dokumentrelativ. Beide
    // Pretty-Route-Varianten müssen daher dasselbe JavaScript laden können.
    const mapScriptSrc = await page.locator('script[src*="assets/js/camera-map.js"]').getAttribute('src');
    expect(mapScriptSrc).toBeTruthy();
    const mapScriptUrl = new URL(mapScriptSrc, page.url()).toString();
    const mapScriptResponse = await page.request.get(mapScriptUrl);
    expect(mapScriptResponse.status()).toBe(200);
    expect(mapScriptResponse.headers()['content-type'] || '').toMatch(/javascript|text\/plain/);

    const currentSlug = await page.locator('#camera-live-root').getAttribute('data-camera-slug');
    const cameraLink = page.locator(`a[data-camera-slug]:not([data-camera-slug="${currentSlug}"])`).first();
    if (await cameraLink.count()) {
      const targetSlug = await cameraLink.getAttribute('data-camera-slug');
      const href = await cameraLink.getAttribute('href');
      const resolved = new URL(href, page.url());
      expect(resolved.pathname).toMatch(new RegExp(`/camera/${targetSlug}/?$`));
      expect(resolved.pathname).not.toContain(`/camera/${currentSlug}/${targetSlug}`);
    }
  }
});
