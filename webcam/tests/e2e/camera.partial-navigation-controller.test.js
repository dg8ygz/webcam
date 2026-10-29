const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const CONTROLLER_SCRIPT = path.join(
  REPOSITORY_ROOT,
  'assets/js/camera-partial-navigation.js',
);

test('timeline rebuild preserves the current-photo return from USB video', async ({ page }) => {
  await page.setContent(`<main id="camera-live-root" data-stream-preference="usb"
    data-live-link="../camera/test"><div data-timeline-container></div></main>`);
  await page.addScriptTag({ path: path.join(REPOSITORY_ROOT, 'assets/js/camera-timeline-view.js') });
  const hrefs = await page.evaluate(() => {
    const noop = () => {};
    const root = document.getElementById('camera-live-root');
    const controller = window.WebcamCameraTimelineView.create({
      getLiveRoot: () => root, buildEntryUrl: () => '#', initTimelinePickers: noop,
      resolveNavMode: () => 'time', updateLiveModeBadge: noop, applyEntryOverlay: noop,
      updateLiveLabels: noop, prefetchAdjacentSnapshots: noop,
    });
    const presentation = {data: {}, context: {cameraSlug: 'test'}, selection: {},
      state: {labels: {}, latestUrl: '?year=2026&month=9&day=30&time=1200'}};
    controller.applyPresentation(presentation);
    const videoReturn = root.querySelector('.timeline-controls__latest').getAttribute('href');
    delete root.dataset.streamPreference;
    controller.applyPresentation(presentation);
    return [videoReturn, root.querySelector('.timeline-controls__latest').getAttribute('href')];
  });
  expect(hrefs).toEqual(['../camera/test', '?year=2026&month=9&day=30&time=1200']);
});

const installBasePage = async (page) => {
  await page.route('http://camera.test/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `
        <title>Start</title>
        <main id="camera-live-root" data-camera-slug="bielefeld" data-stage="start">
          <div data-timeline-container></div>
          <nav class="timeline-controls">
            <a href="?time=first" data-target-key="first">Erstes Ziel</a>
          </nav>
        </main>
      `,
    });
  });
  await page.goto('http://camera.test/camera/bielefeld');
  await page.addScriptTag({ path: CONTROLLER_SCRIPT });
};

test('partial navigation swaps the root and rebinds links on the new content', async ({ page }) => {
  await installBasePage(page);
  await page.evaluate(() => {
    window.navigationCalls = { cancel: 0, start: 0, stopStream: 0, hydrate: 0, replaced: 0, fetches: [] };
    window.fetch = async (url, options) => {
      const target = new URL(url);
      const stage = target.searchParams.get('time') || 'unknown';
      window.navigationCalls.fetches.push({
        stage,
        requestedWith: options.headers['X-Requested-With'],
      });
      const nextStage = stage === 'first' ? 'second' : 'done';
      return {
        ok: true,
        text: async () => `
          <title>${stage}</title>
          <main id="camera-live-root" data-camera-slug="bielefeld" data-stage="${stage}">
            <div data-timeline-container></div>
            <nav class="timeline-controls">
              <a href="?time=${nextStage}" data-target-key="${nextStage}">${nextStage}</a>
            </nav>
          </main>
        `,
      };
    };
    window.partialController = window.WebcamCameraPartialNavigation.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      isTimelineFetchEnabled: () => true,
      cancelTimelineRequest: () => { window.navigationCalls.cancel += 1; },
      consumePrefetchedDocument: () => null,
      startImageLoading: () => { window.navigationCalls.start += 1; },
      beforeSnapshotNavigation: () => { window.navigationCalls.stopStream += 1; },
      hydrateLiveImageFromCache: () => { window.navigationCalls.hydrate += 1; },
      getBestOfNavTarget: async () => null,
      onContentReplaced: () => { window.navigationCalls.replaced += 1; },
    });
    window.partialController.init();
  });

  await page.click('.timeline-controls a');
  await expect(page.locator('#camera-live-root')).toHaveAttribute('data-stage', 'first');
  await expect(page).toHaveTitle('first');
  expect(new URL(page.url()).searchParams.get('time')).toBe('first');

  // Der Root selbst bleibt erhalten, sein Inhalt stammt jetzt aber aus der
  // ersten Antwort. Auch dieser neue Link muss durch denselben Controller laufen.
  await page.click('.timeline-controls a');
  await expect(page.locator('#camera-live-root')).toHaveAttribute('data-stage', 'second');
  const calls = await page.evaluate(() => window.navigationCalls);
  expect(calls).toEqual({
    cancel: 2,
    start: 2,
    stopStream: 2,
    hydrate: 2,
    replaced: 2,
    fetches: [
      { stage: 'first', requestedWith: 'XMLHttpRequest' },
      { stage: 'second', requestedWith: 'XMLHttpRequest' },
    ],
  });
});

test('a prefetched navigation invalidates an older pending request', async ({ page }) => {
  await installBasePage(page);
  await page.evaluate(() => {
    const prefetchedDocument = new DOMParser().parseFromString(`
      <title>Fast</title>
      <main id="camera-live-root" data-camera-slug="bielefeld" data-stage="fast">
        <div data-timeline-container></div>
      </main>
    `, 'text/html');
    window.fetch = async (url, options) => new Promise((resolve, reject) => {
      options.signal.addEventListener('abort', () => {
        reject(new DOMException('aborted', 'AbortError'));
      }, { once: true });
    });
    window.partialController = window.WebcamCameraPartialNavigation.create({
      getLiveRoot: () => document.getElementById('camera-live-root'),
      isTimelineFetchEnabled: () => true,
      cancelTimelineRequest: () => {},
      consumePrefetchedDocument: (url, expectedKey) => (
        url.searchParams.get('time') === 'fast' && expectedKey === 'fast'
          ? prefetchedDocument
          : null
      ),
      startImageLoading: () => {},
      beforeSnapshotNavigation: () => {},
      hydrateLiveImageFromCache: () => {},
      getBestOfNavTarget: async () => null,
      onContentReplaced: () => {},
    });
  });

  const result = await page.evaluate(async () => {
    const slow = window.partialController.navigate('?time=slow');
    const fast = window.partialController.navigate('?time=fast', true, 'fast');
    return Promise.all([slow, fast]);
  });
  expect(result).toEqual([false, true]);
  await expect(page.locator('#camera-live-root')).toHaveAttribute('data-stage', 'fast');
  await expect(page.locator('body')).not.toHaveClass(/content-loading/);
  expect(new URL(page.url()).searchParams.get('time')).toBe('fast');
});
