const { test, expect } = require('@playwright/test');
const path = require('path');

const REPOSITORY_ROOT = process.env.WEBCAM_REPO_ROOT || path.resolve(__dirname, '../..');

/**
 * Prüft den Ausschnitt-Download bewusst ohne Kamera-API und Archiv-Mount.
 * Dadurch zeigt ein Fehler hier direkt auf die Lightbox-Bedienung und wird
 * nicht durch einen langsamen Netzwerkzugriff auf historische Bilder verdeckt.
 */
test('D downloads the visible crop and zoom stops at 8x', async ({ page }) => {
  await page.setContent(`
    <style>
      .lightbox__stage { position: relative; width: 400px; height: 300px; overflow: hidden; }
      .lightbox__img {
        position: absolute;
        top: 50%;
        left: 50%;
        max-width: 100%;
        max-height: 100%;
      }
    </style>
    <div class="lightbox is-open" data-livebox>
      <button data-livebox-help></button>
      <button data-livebox-zoom></button>
      <aside data-livebox-help-panel hidden></aside>
      <div class="lightbox__stage">
        <img class="lightbox__img" data-livebox-img alt="Testbild">
      </div>
    </div>
  `);
  await page.addScriptTag({
    path: path.join(REPOSITORY_ROOT, 'assets/js/camera-lightbox-controls.js'),
  });

  await page.evaluate(async () => {
    // Ein lokal erzeugtes Testbild hält auch die Canvas-/Downloadprüfung frei
    // von CORS, externen Bilddateien und zufälligen Archiv-Laufzeiten.
    const source = document.createElement('canvas');
    source.width = 1600;
    source.height = 900;
    const sourceContext = source.getContext('2d');
    sourceContext.fillStyle = '#1d4ed8';
    sourceContext.fillRect(0, 0, source.width, source.height);
    sourceContext.fillStyle = '#f8fafc';
    sourceContext.fillRect(400, 200, 800, 500);

    const image = document.querySelector('[data-livebox-img]');
    image.src = source.toDataURL('image/png');
    await image.decode();

    window.__lightboxControls = window.WebcamCameraLightboxControls.create({
      lightbox: document.querySelector('[data-livebox]'),
      image,
      zoomButton: document.querySelector('[data-livebox-zoom]'),
      helpButton: document.querySelector('[data-livebox-help]'),
      helpPanel: document.querySelector('[data-livebox-help-panel]'),
      onClose: () => {},
      onShift: () => {},
      onShiftDay: () => {},
      onJumpDayBoundary: () => {},
      onZoomChanged: () => {},
    });
    window.__lightboxControls.init();
    window.__lightboxControls.resetForImage();
  });

  // Mehr Tastendrücke als nötig prüfen, dass die neue Obergrenze zuverlässig
  // greift und der Zoom auch durch wiederholte Eingaben nicht über 8x steigt.
  for (let step = 0; step < 30; step += 1) {
    await page.keyboard.press('+');
  }
  expect(await page.evaluate(() => window.__lightboxControls.getScaleLabel())).toBe('8x');

  await page.keyboard.press('ArrowRight');
  const transform = await page.locator('[data-livebox-img]').evaluate((element) => element.style.transform);
  expect(transform).toContain('scale(8)');
  expect(transform).toContain('- 40px');

  await page.evaluate(() => {
    // Die vom Controller erzeugte Canvas-Größe belegt, dass nicht das komplette
    // 1600×900-Testbild, sondern nur der sichtbare 8x-Ausschnitt exportiert wird.
    const originalToBlob = HTMLCanvasElement.prototype.toBlob;
    HTMLCanvasElement.prototype.toBlob = function rememberDownloadSize(...args) {
      window.__downloadCanvasSize = [this.width, this.height];
      return originalToBlob.apply(this, args);
    };
  });
  const downloadPromise = page.waitForEvent('download');
  await page.keyboard.press('d');
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe('webcam-bild_ausschnitt.jpg');
  expect(await download.failure()).toBeNull();
  expect(await page.evaluate(() => window.__downloadCanvasSize)).toEqual([200, 150]);
});
