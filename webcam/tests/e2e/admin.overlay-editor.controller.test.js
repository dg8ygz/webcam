const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const OVERLAY_EDITOR_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-overlay-editor.js');

test.describe('Ausgelagerter Admin-Overlay-Editor', () => {
  test('maskiert Formulardaten, normalisiert Payloads und nutzt Multipart für Uploads', async ({ page }) => {
    await page.setContent('<main id="overlay-root"></main>');
    await page.evaluate(() => {
      // Uploadvorschauen sollen im isolierten Vertrag keine Netzwerkbilder
      // laden. Das Testbild meldet reproduzierbare natürliche Abmessungen.
      window.Image = class TestImage {
        set src(value) {
          this.value = value;
          this.naturalWidth = 640;
          this.naturalHeight = 360;
          queueMicrotask(() => this.onload?.());
        }
      };
    });
    await page.addScriptTag({ path: OVERLAY_EDITOR_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('overlay-root');
      const state = {
        isCreating: false,
        config: {
          cameras: {
            bielefeld: {
              overlay_logo: null,
              overlay_logo_dark: { url: 'overlay.php?dark=1', width: 80, height: 40 },
            },
          },
        },
      };
      window.__overlayCalls = [];
      window.__overlayMessages = [];
      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
      const toNumber = (value, fallback = 0) => {
        const raw = String(value ?? '').trim().replace(/,/g, '.');
        if (!raw) return fallback;
        const number = Number(raw);
        return Number.isFinite(number) ? number : fallback;
      };
      const cleanString = (value) => String(value ?? '').trim();
      const api = async (endpoint, options = {}) => {
        const file = options.body instanceof FormData ? options.body.get('file') : null;
        window.__overlayCalls.push({
          endpoint,
          method: options.method,
          isFormData: options.body instanceof FormData,
          filename: file?.name || '',
        });
        return {
          relative_path: 'overlay.php?slug=bielefeld&file=uploaded.png',
          width: 320,
          height: 180,
        };
      };

      const controller = window.WebcamAdminOverlayEditor.create({
        state,
        root,
        api,
        flash: (message, level) => window.__overlayMessages.push({ message, level }),
        escapeHtml,
        appBase: './',
        toNumber,
        cleanString,
      });
      root.innerHTML = `
        <form class="camera-form">
          <input name="slug" value="bielefeld">
          ${controller.renderVariantSection({
            variant: 'light',
            label: '<img src=x onerror="window.__overlayXss=1">',
            overlay: {
              url: 'x" onerror="window.__overlayXss=2',
              width: '1" onfocus="window.__overlayXss=3',
              height: 90,
              offset_top: 10,
              offset_right: 12,
              opacity: 0.8,
            },
            slug: 'bielefeld" autofocus onfocus="window.__overlayXss=4',
            isCreating: false,
          })}
          ${controller.renderVariantSection({
            variant: 'dark',
            label: 'Dunkles Logo',
            overlay: state.config.cameras.bielefeld.overlay_logo_dark,
            slug: 'bielefeld',
            isCreating: false,
          })}
        </form>`;
      controller.init();
      controller.init();
      window.__overlayController = controller;
      window.__overlayState = state;
    });

    await expect(page.locator('#overlay-root [onerror], #overlay-root [onfocus]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__overlayXss)).toBeUndefined();
    await expect(page.locator('[data-overlay-variant]')).toHaveCount(2);
    await expect(page.locator('#overlay-file')).toHaveAttribute('accept', 'image/png,image/jpeg');

    // Eine vorhandene URL ohne positive Maße muss vor einem API-Payload
    // sichtbar scheitern. Deckkraft außerhalb 0–1 wird anschließend begrenzt.
    const invalidMessage = await page.evaluate(() => {
      const form = document.querySelector('.camera-form');
      form.querySelector('#overlay-width').value = '';
      form.querySelector('#overlay-height').value = '';
      try {
        window.__overlayController.parseFormData(new FormData(form), 'light');
        return '';
      } catch (error) {
        return error.message;
      }
    });
    expect(invalidMessage).toContain('Breite und Höhe größer 0');

    const parsed = await page.evaluate(() => {
      const form = document.querySelector('.camera-form');
      form.querySelector('#overlay-width').value = '120';
      form.querySelector('#overlay-height').value = '60';
      form.querySelector('#overlay-opacity').value = '2';
      return window.__overlayController.parseFormData(new FormData(form), 'light');
    });
    expect(parsed).toEqual({
      url: 'x" onerror="window.__overlayXss=2',
      width: 120,
      height: 60,
      offset_top: 10,
      offset_right: 12,
      opacity: 1,
    });

    await page.locator('#overlay-file').setInputFiles({
      name: 'logo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('test-png'),
    });
    await expect.poll(() => page.evaluate(() => window.__overlayCalls.length)).toBe(1);
    expect(await page.evaluate(() => window.__overlayCalls[0])).toEqual({
      endpoint: '/api/admin/cameras/bielefeld/overlay',
      method: 'POST',
      isFormData: true,
      filename: 'logo.png',
    });
    await expect(page.locator('#overlay-url')).toHaveValue(
      'overlay.php?slug=bielefeld&file=uploaded.png',
    );
    await expect(page.locator('#overlay-width')).toHaveValue('640');
    await expect(page.locator('#overlay-height')).toHaveValue('360');
    await expect.poll(() => page.evaluate(
      () => window.__overlayMessages.at(-1)?.message || '',
    )).toBe('Overlay hochgeladen');

    await page.locator('#overlay-clear').click();
    await expect(page.locator('#overlay-url')).toHaveValue('');
    expect(await page.evaluate(
      () => window.__overlayState.config.cameras.bielefeld.overlay_logo,
    )).toBeNull();
    expect(await page.evaluate(
      () => window.__overlayState.config.cameras.bielefeld.overlay_logo_dark.url,
    )).toBe('overlay.php?dark=1');
  });
});
