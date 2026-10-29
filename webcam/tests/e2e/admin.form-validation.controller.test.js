const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const VALIDATION_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-form-validation.js');

test.describe('Ausgelagerte Admin-Formularvalidierung', () => {
  test('prüft Felder, öffnet den Fehler-Tab und synchronisiert RTSP-Eingaben', async ({ page }) => {
    await page.setContent(`
      <main id="validation-root">
        <form class="camera-form">
          <div data-tabs>
            <button type="button" data-tab-target="basic" class="is-active">Basis</button>
            <button type="button" data-tab-target="storage">Speicher</button>
            <section data-tab-panel="basic" class="is-active">
              <div class="form-field">
                <label>Slug</label>
                <input name="slug" required data-validate="slug" value="bielefeld">
              </div>
              <div class="form-field">
                <label>Titel</label>
                <input name="title" required data-validate="text" value="Bielefeld">
              </div>
              <select name="transport">
                <option value="http-get" selected>HTTP</option>
                <option value="rtsp">RTSP</option>
              </select>
              <div data-rtsp-stream-setting>
                <input name="rtsp_transport" data-rtsp-stream-field value="tcp">
              </div>
            </section>
            <section data-tab-panel="storage">
              <div class="form-field">
                <label>Basispfad</label>
                <input name="base_path" data-validate="path" value="storage/bielefeld">
              </div>
            </section>
          </div>
        </form>
      </main>`);
    await page.addScriptTag({ path: VALIDATION_SCRIPT });

    await page.evaluate(() => {
      const root = document.getElementById('validation-root');
      const form = root.querySelector('.camera-form');
      root.querySelectorAll('[data-tab-target]').forEach((button) => {
        button.addEventListener('click', () => {
          root.querySelectorAll('[data-tab-target]').forEach((candidate) => {
            candidate.classList.toggle('is-active', candidate === button);
          });
          root.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.tabPanel === button.dataset.tabTarget);
          });
        });
      });

      const controller = window.WebcamAdminFormValidation.create({ root });
      controller.bind();
      controller.bind();
      controller.init(form);
      controller.init(form);
      window.__validationController = controller;
    });

    // HTTP-Capture darf die nur für RTSP sinnvollen Werte weder zeigen noch
    // in einem späteren FormData-Payload mitsenden.
    await expect(page.locator('[data-rtsp-stream-setting]')).toBeHidden();
    await expect(page.locator('[data-rtsp-stream-field]')).toBeDisabled();
    await page.locator('select[name="transport"]').selectOption('rtsp');
    await expect(page.locator('[data-rtsp-stream-setting]')).toBeVisible();
    await expect(page.locator('[data-rtsp-stream-field]')).toBeEnabled();

    const slug = page.locator('input[name="slug"]');
    await slug.fill('Bad Slug');
    await expect(slug.locator('xpath=..')).toHaveClass(/has-error/);
    await expect(slug.locator('xpath=..').locator('.form-error')).toContainText(
      'Nur Kleinbuchstaben, Zahlen und Bindestriche',
    );
    await expect(slug.locator('xpath=..').locator('.form-error')).toHaveCount(1);
    await slug.fill('bielefeld-nord');
    await expect(slug.locator('xpath=..')).not.toHaveClass(/has-error/);

    // Ein Fehler in einem unsichtbaren Abschnitt muss genau diesen Tab öffnen
    // und das erste dort fehlerhafte Feld fokussieren.
    const storagePath = page.locator('input[name="base_path"]');
    await storagePath.fill('../secret');
    const sectionValid = await page.evaluate(() => window.__validationController.validateSection(
      document.querySelector('[data-tab-panel="storage"]'),
    ));
    expect(sectionValid).toBe(false);
    await expect(page.locator('[data-tab-target="storage"]')).toHaveClass(/is-active/);
    await expect(storagePath).toBeFocused();
    await expect(storagePath.locator('xpath=..').locator('.form-error')).toContainText("keine '..'-Segmente");

    await storagePath.fill('storage/bielefeld');
    await page.locator('input[name="title"]').fill('Bielefeld Nord');
    const formValid = await page.evaluate(() => window.__validationController.validateForm(
      document.querySelector('.camera-form'),
    ));
    expect(formValid).toBe(true);
    await expect(page.locator('.form-field.has-error')).toHaveCount(0);

    await page.locator('select[name="transport"]').selectOption('http-get');
    await expect(page.locator('[data-rtsp-stream-setting]')).toBeHidden();
    await expect(page.locator('[data-rtsp-stream-field]')).toBeDisabled();
  });
});
