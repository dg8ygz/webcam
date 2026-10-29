const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({
    extraHTTPHeaders: { 'X-WC-TEST': '1' },
  });
  const slug = 'bielefeld';
  // Beispiel: Archivpfad (LA-Variante) 2024-11-30 19:50
  const path = 'archive/bielefeld/2024/11/30/1950_la.jpg';
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
  let body = null;
  try {
    body = await response.json();
  } catch (e) {
    // ignore
  }
  console.log(JSON.stringify({ status, body }, null, 2));
  await browser.close();
  if (status !== 200 || !body || !body.ok || !body.test) {
    process.exit(0);
  }
})();
