const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1/webcam';
const CAMERA_PATH = process.env.CAMERA_PATH || '/camera/bielefeld';

const archiveUrl = () => {
  const base = BASE_URL.endsWith('/') ? BASE_URL : `${BASE_URL}/`;
  const path = CAMERA_PATH.startsWith('/') ? CAMERA_PATH.slice(1) : CAMERA_PATH;
  const url = new URL(path, base);
  url.searchParams.set('year', '2026');
  url.searchParams.set('month', '05');
  url.searchParams.set('day', '31');
  url.searchParams.set('time', '180000');
  return url.toString();
};

test.describe('Mobile timeline layout', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('keeps menu, camera title and page inside the viewport', async ({ page }) => {
    const response = await page.goto(archiveUrl(), { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);

    // Menü und Kameraname müssen Geschwister sein. War die schließende DIV
    // nach den Menüs nicht vorhanden, enthielt der starre Menüblock auch den
    // Kameranamen und alle Zeitgruppen und wuchs auf rund 458 px.
    const structure = await page.evaluate(() => {
      const menus = document.querySelector('.timeline-controls__group--menus');
      const camera = document.querySelector('.timeline-controls__group--camera-name');
      const groups = document.querySelector('.timeline-controls__groups');
      return {
        menuParentIsGroups: menus?.parentElement === groups,
        cameraParentIsGroups: camera?.parentElement === groups,
        viewportWidth: document.documentElement.clientWidth,
        documentWidth: document.documentElement.scrollWidth,
        timelineWidth: groups?.getBoundingClientRect().width ?? 0,
        timelineScrollWidth: groups?.scrollWidth ?? 0,
      };
    });

    expect(structure.menuParentIsGroups).toBe(true);
    expect(structure.cameraParentIsGroups).toBe(true);
    expect(structure.documentWidth).toBeLessThanOrEqual(structure.viewportWidth);
    expect(structure.timelineScrollWidth).toBeLessThanOrEqual(Math.ceil(structure.timelineWidth));
  });

  test('keeps the wider weather dialog inside a phone viewport', async ({ page }) => {
    await page.goto(archiveUrl(), { waitUntil: 'networkidle' });
    const weatherTrigger = page.locator('[data-open-weather]').first();
    await expect(weatherTrigger).toHaveCount(1);

    // Der Link liegt in einem geschlossenen Dropdown. Ein DOM-Klick prüft
    // gezielt die Dialogdarstellung, ohne erst das Menüverhalten mitzutesten.
    await weatherTrigger.evaluate((node) => node.click());
    const dialog = page.locator('[data-meta-modal] .meta-modal__dialog');
    await expect(dialog).toHaveClass(/meta-modal__dialog--weather/);
    await expect(dialog.locator('.weather-chart__title', { hasText: 'Temperatur' })).toBeVisible({ timeout: 10000 });

    const dimensions = await dialog.evaluate((node) => ({
      right: node.getBoundingClientRect().right,
      left: node.getBoundingClientRect().left,
      viewportWidth: document.documentElement.clientWidth,
      documentWidth: document.documentElement.scrollWidth,
      chartLeft: node.querySelector('.weather-chart')?.getBoundingClientRect().left ?? 0,
      chartRight: node.querySelector('.weather-chart')?.getBoundingClientRect().right ?? 0,
    }));
    expect(dimensions.left).toBeGreaterThanOrEqual(0);
    expect(dimensions.right).toBeLessThanOrEqual(dimensions.viewportWidth);
    expect(dimensions.chartLeft).toBeGreaterThanOrEqual(dimensions.left);
    expect(dimensions.chartRight).toBeLessThanOrEqual(dimensions.right);
    expect(dimensions.documentWidth).toBeLessThanOrEqual(dimensions.viewportWidth);
  });
});

test.describe('Weather dialog desktop width', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('uses the dedicated wide presentation for weather charts', async ({ page }) => {
    await page.goto(archiveUrl(), { waitUntil: 'networkidle' });
    await expect(page.locator('script[src*="assets/js/camera-weather.js"]')).toHaveCount(1);
    const weatherTrigger = page.locator('[data-open-weather]').first();
    await weatherTrigger.evaluate((node) => node.click());

    const dialog = page.locator('[data-meta-modal] .meta-modal__dialog');
    await expect(dialog).toHaveClass(/meta-modal__dialog--weather/);
    const width = await dialog.evaluate((node) => node.getBoundingClientRect().width);

    // Der allgemeine Metadaten-Dialog ist 600 px breit. Wetter erhält bewusst
    // mehr Platz für mehrere Diagramme und deren Vorschaukarten.
    expect(width).toBeGreaterThan(600);
    expect(width).toBeLessThanOrEqual(960);

    // Temperatur und Luftdruck verwenden denselben Diagramm-Renderer. Beide
    // müssen Uhrzeit und Messwert in separaten Zeilen ausgeben, damit der
    // aktuelle Wert nicht über die Skalenwerte ragt.
    for (const title of ['Temperatur', 'Luftdruck']) {
      const chart = page.locator('.weather-chart').filter({
        has: page.locator('.weather-chart__title', { hasText: title }),
      }).first();
      await expect(chart).toBeVisible({ timeout: 10000 });
      await expect(chart.locator('.weather-chart__current-time')).toHaveCount(1);
      await expect(chart.locator('.weather-chart__current-value')).toHaveCount(1);
      await expect(chart.locator('.weather-chart__current-time')).not.toHaveText('');
      await expect(chart.locator('.weather-chart__current-value')).not.toHaveText('');

      // Der Kopf ist der feste aktuelle Tageswert. Beim Erkunden älterer
      // Punkte ändern sich nur Tooltip/Vorschau, nicht diese Orientierung.
      const currentBeforeHover = await chart.locator('.weather-chart__current').innerText();
      const firstPoint = chart.locator('[data-weather-point]').first();
      if (await firstPoint.count()) {
        await firstPoint.hover();
        expect(await chart.locator('.weather-chart__current').innerText()).toBe(currentBeforeHover);
      }

      const layout = await chart.evaluate((node) => {
        const current = node.querySelector('.weather-chart__current')?.getBoundingClientRect();
        const svg = node.querySelector('.weather-chart__svg')?.getBoundingClientRect();
        return {
          currentBottom: current?.bottom ?? 0,
          svgTop: svg?.top ?? 0,
        };
      });
      expect(layout.currentBottom).toBeLessThanOrEqual(layout.svgTop);
    }

    // Die gesamte Diagrammfläche dient als Zeitachse. Hier bewegen wir die
    // Maus bewusst oberhalb der Kurvenpunkte und prüfen, dass trotzdem jeweils
    // der horizontal nächste Messpunkt samt Tagesbild ausgewählt wird.
    const temperatureChart = page.locator('.weather-chart').filter({
      has: page.locator('.weather-chart__title', { hasText: 'Temperatur' }),
    }).first();
    const temperaturePoints = temperatureChart.locator('[data-weather-point]');
    expect(await temperaturePoints.count()).toBeGreaterThan(1);
    const hoverTargets = await temperatureChart.evaluate((node) => {
      const svg = node.querySelector('.weather-chart__svg');
      const points = Array.from(node.querySelectorAll('[data-weather-point]'));
      if (!svg || points.length < 2) return null;
      const rect = svg.getBoundingClientRect();
      const viewBox = svg.viewBox.baseVal;
      const pointXs = points.map((point) => Number.parseFloat(point.getAttribute('cx') || '0'));
      const toClientX = (viewBoxX) => rect.left + (((viewBoxX - viewBox.x) / viewBox.width) * rect.width);
      return {
        // Jeweils ein Viertel des Abstands zum Nachbarpunkt: klar näher am
        // Randpunkt, aber nicht auf dessen kleinem Kreis.
        firstX: toClientX(pointXs[0] + ((pointXs[1] - pointXs[0]) * 0.25)),
        lastX: toClientX(pointXs.at(-1) - ((pointXs.at(-1) - pointXs.at(-2)) * 0.25)),
        y: rect.top + 2,
      };
    });
    expect(hoverTargets).not.toBeNull();

    await page.mouse.move(hoverTargets.firstX, hoverTargets.y);
    await expect(temperaturePoints.first()).toHaveClass(/is-active/);
    const previewImage = temperatureChart.locator('.weather-chart__preview img');
    await expect(previewImage).toHaveAttribute('src', /\S+/);
    const firstPreviewUrl = await previewImage.getAttribute('src');

    await page.mouse.move(hoverTargets.lastX, hoverTargets.y);
    await expect(temperaturePoints.last()).toHaveClass(/is-active/);
    await expect.poll(() => previewImage.getAttribute('src')).not.toBe(firstPreviewUrl);

    const previewClearance = await page.locator('.weather-chart', {
      has: page.locator('.weather-chart__title', { hasText: 'Temperatur' }),
    }).first().evaluate((node) => {
      const preview = node.querySelector('.weather-chart__preview--left')?.getBoundingClientRect();
      const scaleLabel = node.querySelector('.weather-chart__svg text')?.getBoundingClientRect();
      if (!preview || !scaleLabel) {
        return null;
      }
      return preview.left - scaleLabel.right;
    });
    if (previewClearance !== null) {
      expect(previewClearance).toBeGreaterThanOrEqual(4);
    }
  });
});
