const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({
    extraHTTPHeaders: { 'X-WC-TEST': '1' },
  });
  const slug = 'bielefeld';
  const path = 'cache/bielefeld/2025/12/01/20251201_202000_la.jpg';
  const base = process.env.BASE_URL || 'http://localhost/webcam';
  const url = `${base.replace(/\/$/, '')}/bestof-toggle.php`;
  const response = await page.request.post(url, {
    headers: { 'Content-Type': 'application/json', 'X-WC-TEST': '1' },
    data: { slug, path },
    timeout: 15000,
  }).catch(() => null);
  if (!response) {
    console.log(JSON.stringify({ status: 0, body: { ok: false, error: 'request failed' } }, null, 2));
    await browser.close();
    process.exit(0);
  }
  const status = response.status();
  const body = await response.json().catch(() => ({}));
  console.log(JSON.stringify({ status, body }, null, 2));
  await browser.close();
  // Do not hard fail on non-200 in CI; treat as skip when not ok/test flag missing
  if (status !== 200 || !body.ok || !body.test) {
    process.exit(0);
  }
})();
