const { defineConfig } = require('@playwright/test');

if (!process.env.BASE_URL?.startsWith('http://127.0.0.1:') || !process.env.WEBCAM_E2E_REPORT) {
  throw new Error('Use npm run test:e2e:isolated; no live-server fallback');
}
module.exports = defineConfig({
  testDir: '..',
  testMatch: ['isolated/core.test.js', 'e2e/*.controller.test.js', 'e2e/camera.stream-controller.test.js', 'e2e/camera.partial-navigation-controller.test.js', 'e2e/camera.weather-model.test.js', 'e2e/camera.meta-modal.test.js'],
  workers: 1, retries: 0, forbidOnly: true,
  timeout: 30000, globalTimeout: 300000, expect: { timeout: 7000 },
  outputDir: process.env.WEBCAM_E2E_REPORT + '/results',
  reporter: [['list'], ['json', { outputFile: process.env.WEBCAM_E2E_REPORT + '/results.json' }]],
  use: { baseURL: process.env.BASE_URL, serviceWorkers: 'block', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
    { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
