const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  // Standalone scripts execute immediately and call process.exit.
  testIgnore: ['**/bestof-toggle.test.js', '**/bestof-toggle-archive.test.js'],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
});
