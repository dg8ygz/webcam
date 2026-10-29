const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const buildUrl = () => {
  if (/^https?:\/\//i.test(CAMERA_PATH)) {
    return CAMERA_PATH;
  }
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  return new URL(path, base).toString();
};

const snapshotRelevantStorage = async (page) => page.evaluate(() => ({
  local: {
    labels: window.localStorage.getItem('wc_labels_enabled'),
  },
  session: {
    compare: window.sessionStorage.getItem('webcam:image-compare:v1'),
  },
}));

test.describe('Camera menu side effects', () => {
  test('metadata modal stays usable after partial snapshot navigation', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    // Das Modal liegt bewusst außerhalb des austauschbaren Kamera-Roots. Die
    // Referenz erlaubt die Gegenprobe, dass der Klick tatsächlich nur den
    // Snapshot-Inhalt ersetzt und der einmal gebundene Controller weiterlebt.
    await page.locator('[data-meta-modal]').evaluate((modal) => {
      window.__wcMetaModalBeforeNavigation = modal;
    });

    const previousSnapshot = page.locator('[aria-label="Vorherige Zeit"]').first();
    await expect(previousSnapshot).toBeVisible({ timeout: 15000 });
    const oldSnapshot = await page.locator('#camera-live-root').getAttribute('data-selected-ts');
    await previousSnapshot.click();
    await expect.poll(async () => (
      page.locator('#camera-live-root').getAttribute('data-selected-ts')
    ), { timeout: 15000 }).not.toBe(oldSnapshot);

    const sameModalNode = await page.evaluate(() => (
      window.__wcMetaModalBeforeNavigation === document.querySelector('[data-meta-modal]')
    ));
    expect(sameModalNode).toBe(true);

    const infoToggle = page.locator('.tl-menu__toggle', { hasText: 'Infos' }).first();
    await infoToggle.click();
    await page.locator('[data-open-exif]').first().click();
    await expect(page.locator('[data-meta-modal]')).toBeVisible({ timeout: 5000 });
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-meta-modal]')).toBeHidden({ timeout: 5000 });
  });

  test('modal items stay transient and dayvideo only hides the overlay temporarily', async ({ page }) => {
    await page.goto(buildUrl(), { waitUntil: 'networkidle' });

    const beforeStorage = await snapshotRelevantStorage(page);

    const openAndCloseModal = async (triggerSelector, modalSelector) => {
      const trigger = page.locator(triggerSelector).first();
      if (!(await trigger.count())) {
        test.skip(`${triggerSelector} not present`);
      }
      await expect(trigger).toBeVisible({ timeout: 10000 });
      await trigger.click({ force: true });

      const modal = page.locator(modalSelector);
      await expect(modal).toBeVisible({ timeout: 5000 });
      await page.keyboard.press('Escape');
      await expect(modal).toBeHidden({ timeout: 5000 });

      const afterStorage = await snapshotRelevantStorage(page);
      expect(afterStorage).toEqual(beforeStorage);
    };

    const infoToggle = page.locator('.tl-menu__toggle', { hasText: 'Infos' }).first();
    await expect(infoToggle).toBeVisible({ timeout: 15000 });
    await infoToggle.click();

    await openAndCloseModal('[data-open-exif]', '[data-meta-modal]');
    await infoToggle.click();
    await openAndCloseModal('[data-open-weather]', '[data-meta-modal]');
    await infoToggle.click();
    await openAndCloseModal('[data-open-surroundings-map]', '[data-meta-modal]');
    await infoToggle.click();
    await openAndCloseModal('[data-open-status]', '[data-status-modal]');

    const optionsToggle = page.locator('.tl-menu__toggle', { hasText: 'Optionen' }).first();
    await expect(optionsToggle).toBeVisible({ timeout: 10000 });
    await optionsToggle.click({ force: true });

    const dayVideoLink = page.locator('[data-dayvideo-open]').first();
    if (!(await dayVideoLink.count())) {
      test.skip('Dayvideo link not present');
    }
    await expect(dayVideoLink).toBeVisible({ timeout: 5000 });
    const beforeDayvideoState = await page.evaluate(() => {
      const readState = (selector) => {
        const el = document.querySelector(selector);
        return {
          exists: !!el,
          hidden: el ? el.hidden : null,
        };
      };

      return {
        labels: readState('.live-labels'),
        overlay: readState('.live-overlay'),
        logo: readState('.live-logo'),
        hotspot: readState('[data-bestof-hotspot]'),
      };
    });
    await dayVideoLink.click({ force: true });

    const inline = page.locator('[data-dayvideo-inline]');
    await expect(inline).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.live-image')).toHaveClass(/is-dayvideo/);
    await expect(page.locator('.live-labels')).toBeHidden({ timeout: 5000 });
    await expect(page.locator('.live-overlay')).toBeHidden({ timeout: 5000 });
    await expect(page.locator('.live-logo')).toBeHidden({ timeout: 5000 });
    await expect(page.locator('[data-bestof-hotspot]')).toBeHidden({ timeout: 5000 });

    const closeDayvideo = inline.locator('[data-dayvideo-close]');
    await expect(closeDayvideo).toBeVisible({ timeout: 5000 });
    await closeDayvideo.click({ force: true });

    await expect(inline).toBeHidden({ timeout: 5000 });
    await expect(page.locator('.live-image')).not.toHaveClass(/is-dayvideo/);
    const afterDayvideoState = await page.evaluate(() => {
      const readState = (selector) => {
        const el = document.querySelector(selector);
        return {
          exists: !!el,
          hidden: el ? el.hidden : null,
        };
      };

      return {
        labels: readState('.live-labels'),
        overlay: readState('.live-overlay'),
        logo: readState('.live-logo'),
        hotspot: readState('[data-bestof-hotspot]'),
      };
    });
    expect(afterDayvideoState).toEqual(beforeDayvideoState);

    const finalStorage = await snapshotRelevantStorage(page);
    expect(finalStorage).toEqual(beforeStorage);
  });
});
