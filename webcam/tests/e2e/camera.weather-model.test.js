const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const WEATHER_MODEL_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/camera-weather-model.js');

test('compacts local sensor captions when selecting a timeline image', async ({ page }) => {
  await page.setContent('<main><div data-live-overlay></div></main>');
  await page.addScriptTag({ path: path.join(REPOSITORY_ROOT, 'assets/js/camera-lightbox-entry.js') });
  await page.evaluate(() => {
    const model = window.WebcamCameraLightboxEntry.create({ liveRoot: document.querySelector('main'),
      parseIntSafe: value => Number.parseInt(value, 10), normalizeAssetUrl: value => value,
      applyEmbeddedOverlayState: () => {}, formatDateInTimezone: () => '',
    });
    model.applyEntryOverlay({ datetime_label: '11.09.2026 22:50',
      weather_text: 'Temp: 23.6 C\nHumidity: 59.6 %\nSource: local DHT22\nMeasured at: 2026-09-11T20:49:56+00:00' });
  });
  await expect(page.locator('.live-overlay__item').first()).toHaveText('11.09.2026 22:50 Uhr Temp: 23,6 °C · Hum: 59,6 %');
  await expect(page.locator('main')).not.toContainText('Source:');
  await expect(page.locator('main')).not.toContainText('Measured at:');
});

/**
 * Das Wettermodell arbeitet ohne DOM und ohne Netzwerk. Der Test bildet die
 * historisch vorkommenden Textformate nach und stellt sicher, dass spätere
 * Änderungen an Diagramm oder Modal nicht versehentlich die Datenübersetzung
 * verändern.
 */
test('normalizes legacy weather text into sorted metric series', async ({ page }) => {
  await page.setContent('<main></main>');
  await page.addScriptTag({ path: WEATHER_MODEL_SCRIPT });

  const result = await page.evaluate(() => {
    const model = window.WebcamCameraWeatherModel.create({
      resolvePreviewUrl: (entry) => entry.preview_url || entry.url || null,
    });
    return model.buildDayPayload({
      entries: [
        {
          timestamp: 200,
          time: 123000,
          time_label: '12:30 Uhr',
          preview_url: 'second.jpg',
          weather_text: 'Temperatur: 20,5 °C Luftdruck: 1012,4 hPa Windrichtung: NE',
        },
        {
          timestamp: 100,
          time: 90000,
          url: 'first.jpg',
          weather: 'T: -1.5 C P: 998 mbar Wind: 12 km/h, WSW',
        },
        {
          timestamp: 300,
          time: 130000,
          weather_text: 'Text ohne bekannte Messwerte',
        },
      ],
    });
  });

  expect(result.sampleCount).toBe(2);
  expect(result.tempSeries.map((sample) => sample.value)).toEqual([-1.5, 20.5]);
  expect(result.pressureSeries.map((sample) => sample.value)).toEqual([998, 1012.4]);
  expect(result.windSamples.map((sample) => sample.windDir)).toEqual(['WSW', 'NO']);
  expect(result.windSeries.map((sample) => sample.value)).toEqual([247.5, 45]);
  expect(result.latestTimeLabel).toBe('12:30 Uhr');
  expect(result.latestTempSample.previewUrl).toBe('second.jpg');
});

test('exposes stable German labels for charts', async ({ page }) => {
  await page.setContent('<main></main>');
  await page.addScriptTag({ path: WEATHER_MODEL_SCRIPT });

  const labels = await page.evaluate(() => ({
    number: window.WebcamCameraWeatherModel.formatNumber(12.34),
    time: window.WebcamCameraWeatherModel.formatTime((9 * 3600) + (5 * 60)),
    missing: window.WebcamCameraWeatherModel.formatNumber(null),
  }));

  expect(labels).toEqual({ number: '12,3', time: '09:05 Uhr', missing: '—' });
});

test('renders local DHT22 temperature and humidity including humidity-only samples', async ({ page }, testInfo) => {
  await page.setContent('<main></main>');
  await page.addScriptTag({ path: WEATHER_MODEL_SCRIPT });
  await page.addScriptTag({ path: path.join(REPOSITORY_ROOT, 'assets/js/camera-weather.js') });
  const result = await page.evaluate(async () => {
    const entries = [
      { timestamp: 100, time: 225000, weather_text: 'Temp: 23.6 C\nHumidity: 59.6 %\nSource: local DHT22' },
      { timestamp: 200, time: 225100, weather_text: 'Hum: 0 %' },
      { timestamp: 300, time: 225200, weather_text: 'Humidity: 100 %' },
      { timestamp: 400, time: 225300, weather_text: 'Humidity: 101 %' },
      { timestamp: 500, time: 225400, weather_text: 'Humidity: -1 %' },
    ];
    const adapters = {
      resolvePreviewUrl: () => null,
      resolveContext: () => ({ cameraSlug: 'test', year: 2026, month: 9, day: 11, isCurrentDay: true }),
      loadTimelineDay: async () => ({ entries: [entries[0]] }),
    };
    const data = window.WebcamCameraWeatherModel.create(adapters).buildDayPayload({ entries });
    const content = await window.WebcamCameraWeather.create(adapters).loadContent();
    document.querySelector('main').append(content);
    const onlyHumidity = await window.WebcamCameraWeather.create({ ...adapters,
      loadTimelineDay: async () => ({ entries: [entries[1]] }),
    }).loadContent();
    return { values: data.humiditySeries.map(s => s.value), onlyHumidity: onlyHumidity.textContent };
  });
  expect(result.values).toEqual([59.6, 0, 100]);
  expect(result.onlyHumidity).toContain('Luftfeuchte');
  expect(result.onlyHumidity).toContain('0,0 %');
  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(page.locator('.weather-chart__title').filter({ hasText: 'Temperatur' })).toBeVisible();
    await expect(page.locator('.weather-chart__title').filter({ hasText: 'Luftfeuchte' })).toBeVisible();
    await expect(page.locator('.weather-chart__current').filter({ hasText: '59,6 %' })).toBeVisible();
    expect(await page.locator('svg').evaluateAll(nodes => nodes.every(n => !/NaN|Infinity/.test(n.outerHTML)))).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath(`local-weather-${width}.png`), fullPage: true });
  }
});
