const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const LABEL_EDITOR_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-label-editor.js');

test.describe('Ausgelagerter Admin-Label-Editor', () => {
  test('maskiert Zeilen, validiert den Payload und bindet DOM-Aktionen nur einmal', async ({ page }) => {
    await page.setContent('<main id="label-root"></main>');
    await page.addScriptTag({ path: LABEL_EDITOR_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('label-root');
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
      const controller = window.WebcamAdminLabelEditor.create({
        root,
        escapeHtml,
        toNumber,
      });
      const rows = controller.renderRows([{
        id: 42,
        x: 12.5,
        y: 34,
        text: '<img src=x onerror="window.__labelXss=1">Infopunkt',
        href: './camera/bielefeld?x=" autofocus onfocus="window.__labelXss=2',
        color: '#AABBCC',
        text_tone: 'dark',
        type: 3,
        resolution: 1600,
        valid_from: '2026-01-01 12:00',
        valid_to: '2026-12-31 23:59',
      }]);
      root.innerHTML = `
        <form class="camera-form">
          <section data-labels-editor>
            <button type="button" data-label-add>+ Label hinzufügen</button>
            <table><tbody data-labels-body>${rows}</tbody></table>
          </section>
        </form>`;
      controller.init();
      controller.init();
      window.__labelController = controller;
    });

    // Sämtliche administrativen Werte müssen im HTML nur als Feldwerte, nie
    // als ausführbare Elemente oder Attribute ankommen.
    await expect(page.locator('#label-root img, #label-root [onerror], #label-root [onfocus]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__labelXss)).toBeUndefined();
    await expect(page.locator('[data-label-field="text"]')).toHaveValue(
      '<img src=x onerror="window.__labelXss=1">Infopunkt',
    );
    await expect(page.locator('[data-label-field="color"]')).toHaveValue('custom');
    await expect(page.locator('[data-label-field="color_custom"]')).toHaveValue('#aabbcc');
    await expect(page.locator('[data-label-field="color_custom"]')).toBeVisible();

    const payload = await page.evaluate(() => window.__labelController.collect(
      document.querySelector('.camera-form'),
    ));
    expect(payload).toEqual([{
      id: 42,
      x: 12.5,
      y: 34,
      text: '<img src=x onerror="window.__labelXss=1">Infopunkt',
      href: './camera/bielefeld?x=" autofocus onfocus="window.__labelXss=2',
      color: '#aabbcc',
      text_tone: 'dark',
      type: 3,
      resolution: 1600,
      valid_from: '2026-01-01 12:00',
      valid_to: '2026-12-31 23:59',
    }]);

    // Zweifaches init() darf den Hinzufügen-Listener nicht doppelt binden.
    await page.locator('[data-label-add]').click();
    await expect(page.locator('[data-label-row]')).toHaveCount(2);
    await page.locator('[data-label-row]').nth(1).locator('[data-label-remove]').click();
    await expect(page.locator('[data-label-row]')).toHaveCount(1);

    // Beim Wechsel von einer eigenen Farbe auf ein Preset wird der nun
    // bedeutungslose Freitextwert sichtbar entfernt.
    await page.locator('[data-label-field="color"]').selectOption('blue');
    await expect(page.locator('[data-label-field="color_custom"]')).toBeHidden();
    await expect(page.locator('[data-label-field="color_custom"]')).toHaveValue('');

    const unsafeLinkError = await page.evaluate(() => {
      const form = document.querySelector('.camera-form');
      form.querySelector('[data-label-field="href"]').value = 'javascript:alert(1)';
      try {
        window.__labelController.collect(form);
        return '';
      } catch (error) {
        return error.message;
      }
    });
    expect(unsafeLinkError).toContain('javascript:');

    const invalidColorError = await page.evaluate(() => {
      const form = document.querySelector('.camera-form');
      form.querySelector('[data-label-field="href"]').value = 'https://example.invalid/info';
      form.querySelector('[data-label-field="color"]').value = 'custom';
      form.querySelector('[data-label-field="color_custom"]').value = 'red; background:url(x)';
      try {
        window.__labelController.collect(form);
        return '';
      } catch (error) {
        return error.message;
      }
    });
    expect(invalidColorError).toContain('Farbcode');

    await page.locator('[data-label-remove]').click();
    await expect(page.locator('[data-label-row]')).toHaveCount(0);
    await expect(page.locator('[data-labels-empty]')).toContainText('Keine Labels angelegt');
  });
});
