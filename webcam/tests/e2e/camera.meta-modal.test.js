const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const META_MODAL_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/camera-meta-modal.js');

/**
 * Prüft die EXIF-Aufbereitung mit einem kleinen, vollständig lokalen DOM.
 * Dadurch hängt die Darstellung weder von einem aktuellen Kamerabild noch
 * von vorhandenen Sidecars auf dem Testsystem ab.
 */
test('renders EXIF sidecar lines as a safe key/value table', async ({ page }) => {
  await page.setContent(`
    <main id="camera-live-root"></main>
    <button type="button" data-open-exif>EXIF-Daten</button>
    <div class="meta-modal" data-meta-modal hidden>
      <div class="meta-modal__dialog" role="dialog">
        <h3 data-meta-title></h3>
        <button type="button" data-meta-close>Schließen</button>
        <div data-meta-content></div>
      </div>
    </div>
  `);
  await page.locator('#camera-live-root').evaluate((root) => {
    root.dataset.exifText = [
      'Model | Canon EOS 2000D',
      'Exposure Time | 1/125 sec.',
      'ISO: 100',
      '<script>alert(1)</script>',
    ].join('\n');
  });
  await page.addScriptTag({ path: META_MODAL_SCRIPT });
  await page.evaluate(() => {
    const noOperation = () => {};
    const controller = window.WebcamCameraMetaModal.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      closeTransientTimelineUi: noOperation,
      initTimelinePickers: noOperation,
      initLiveLabels: noOperation,
      getWeatherController: () => ({ loadContent: async () => null }),
      getSurroundingsController: () => ({ isAvailable: () => false }),
      destroySurroundings: noOperation,
      getImageCompareController: () => ({ readStoredSnapshot: () => null }),
    });
    controller.init();
  });

  await page.locator('[data-open-exif]').click();
  const modal = page.locator('[data-meta-modal]');
  await expect(modal).toBeVisible();
  await expect(modal.locator('.meta-modal__exif-table tr')).toHaveCount(4);
  await expect(modal.locator('th').first()).toHaveText('Model');
  await expect(modal.locator('td').first()).toHaveText('Canon EOS 2000D');
  await expect(modal.locator('th').nth(2)).toHaveText('ISO');
  await expect(modal.locator('td').nth(2)).toHaveText('100');
  await expect(modal.locator('script')).toHaveCount(0);
  await expect(modal.locator('.meta-modal__exif-note')).toContainText('<script>alert(1)</script>');
});
