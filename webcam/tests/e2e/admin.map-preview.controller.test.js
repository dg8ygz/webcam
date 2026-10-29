const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const MAP_PREVIEW_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-map-preview.js');

test.describe('Ausgelagerte Admin-Kartenvorschau', () => {
  test('zeichnet Formularwerte neu und entfernt beim Schließen alle Listener', async ({ page }) => {
    await page.setContent(`
      <main id="map-root">
        <form class="camera-form">
          <section data-tab-panel="map" class="is-active">
            <input name="latitude" value="52.0123">
            <input name="longitude" value="8.5432">
            <input name="orientation_deg" value="45">
            <input name="field_of_view_deg" value="60">
            <input name="map_center_offset" value="0.1">
            <input name="map_center_offset_x" value="-0.2">
            <input name="orientation_visualization_km" value="5">
            <div id="camera-map-preview" style="width:560px;height:280px"></div>
          </section>
        </form>
      </main>
    `);

    // Die isolierte Prüfung verwendet den dokumentierten Canvas-Fallback.
    // Das kleine Context-Doppel zeichnet nicht, protokolliert aber Texte und
    // Anzahl der Renderläufe, sodass Eingabe- und Cleanup-Verhalten messbar sind.
    await page.evaluate(() => {
      window.L = undefined;
      window.__mapDraws = 0;
      window.__mapTexts = [];
      HTMLCanvasElement.prototype.getContext = () => ({
        clearRect: () => { window.__mapDraws += 1; },
        createLinearGradient: () => ({ addColorStop: () => {} }),
        fillRect: () => {},
        beginPath: () => {},
        moveTo: () => {},
        lineTo: () => {},
        closePath: () => {},
        fill: () => {},
        stroke: () => {},
        setLineDash: () => {},
        arc: () => {},
        fillText: (text) => { window.__mapTexts.push(String(text)); },
        fillStyle: '',
        strokeStyle: '',
        font: '',
        textAlign: '',
        lineWidth: 1,
      });
    });
    await page.addScriptTag({ path: MAP_PREVIEW_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('map-root');
      window.__mapController = window.WebcamAdminMapPreview.create({
        root,
        toNumber: (value, fallback = 0) => {
          const number = Number(value);
          return Number.isFinite(number) ? number : fallback;
        },
      });
      window.__mapController.init();
    });

    await expect(page.locator('#camera-map-preview canvas')).toHaveCount(1);
    expect(await page.evaluate(() => window.__mapTexts)).toContain('Lat 52.0123, Lon 8.5432');
    expect(await page.evaluate(() => window.__mapTexts)).toContain('Ausrichtung 45.0°, Sichtfeld 60.0°');

    const drawsBeforeInput = await page.evaluate(() => window.__mapDraws);
    await page.locator('[name="orientation_deg"]').fill('90');
    await expect.poll(() => page.evaluate(() => window.__mapDraws)).toBeGreaterThan(drawsBeforeInput);
    await expect.poll(() => page.evaluate(() => window.__mapTexts.at(-1) || '')).toContain(
      'Ausrichtung 90.0°',
    );

    // Ein im selben JavaScript-Takt vorgemerkter Zeichenlauf muss zusammen
    // mit seinen Listenern verschwinden und darf kein altes Canvas mehr nutzen.
    const drawsAtDestroy = await page.evaluate(() => {
      const input = document.querySelector('[name="orientation_deg"]');
      input.value = '100';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      window.__mapController.destroy();
      return window.__mapDraws;
    });
    await expect(page.locator('.map-preview__placeholder')).toHaveText('Karte ausgeblendet.');
    await page.waitForTimeout(50);
    expect(await page.evaluate(() => window.__mapDraws)).toBe(drawsAtDestroy);

    const drawsAfterDestroy = drawsAtDestroy;
    await page.locator('[name="orientation_deg"]').fill('135');
    await page.waitForTimeout(50);
    expect(await page.evaluate(() => window.__mapDraws)).toBe(drawsAfterDestroy);
  });
});
