(() => {
  'use strict';

  const root = document.querySelector('[data-global-bestof]');
  if (!root) return;

  const grid = root.querySelector('[data-global-bestof-grid]');
  const moreButton = root.querySelector('[data-global-bestof-more]');
  const status = root.querySelector('[data-global-bestof-status]');
  if (!grid || !moreButton) return;

  let page = Number.parseInt(root.dataset.page || '1', 10) || 1;
  let totalPages = Number.parseInt(root.dataset.totalPages || '1', 10) || 1;
  const perPage = Number.parseInt(root.dataset.perPage || '48', 10) || 48;
  const appPrefix = root.dataset.appPrefix || './';

  const appPath = (value) => {
    const path = String(value || '').trim();
    if (!path) return '#';
    if (/^(?:[a-z]+:)?\/\//i.test(path) || path.startsWith('/')) return path;
    return `${appPrefix}${path.replace(/^\.\//, '')}`;
  };

  const formatTimestamp = (value) => {
    const seconds = Number.parseInt(String(value || ''), 10);
    if (!Number.isFinite(seconds) || seconds <= 0) return 'Zeit unbekannt';
    return new Intl.DateTimeFormat('de-DE', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(seconds * 1000));
  };

  const cardFor = (item) => {
    const link = document.createElement('a');
    link.className = 'bestof-card global-bestof-card';
    link.href = appPath(item.link);
    link.dataset.cameraSlug = item.camera_slug || '';
    if (item.slot_ts) link.dataset.slotTs = String(item.slot_ts);

    const image = document.createElement('img');
    image.src = appPath(item.thumb_url || item.url);
    image.dataset.fullSrc = appPath(item.url || item.thumb_url);
    image.alt = `Best-Of-Aufnahme von ${item.camera_title || item.camera_slug || ''}`;
    image.loading = 'lazy';
    image.decoding = 'async';
    link.appendChild(image);

    const caption = document.createElement('span');
    caption.className = 'global-bestof-card__caption';
    const title = document.createElement('strong');
    title.textContent = item.camera_title || item.camera_slug || '';
    const timestamp = document.createElement('small');
    timestamp.textContent = formatTimestamp(item.slot_ts);
    caption.append(title, timestamp);
    link.appendChild(caption);
    return link;
  };

  const loadMore = async () => {
    if (page >= totalPages) return;
    moreButton.disabled = true;
    if (status) status.textContent = 'Weitere Bilder werden geladen…';
    try {
      const endpoint = new URL(root.dataset.endpoint || appPath('api/public.php/bestof'), document.baseURI);
      endpoint.searchParams.set('page', String(page + 1));
      endpoint.searchParams.set('per_page', String(perPage));
      if (root.dataset.selectedYear) endpoint.searchParams.set('year', root.dataset.selectedYear);
      if (root.dataset.selectedCamera) endpoint.searchParams.set('camera', root.dataset.selectedCamera);
      const response = await fetch(endpoint, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      const items = Array.isArray(payload.items) ? payload.items : [];
      items.forEach((item) => grid.appendChild(cardFor(item)));
      page = Number.parseInt(payload.page || String(page + 1), 10) || page + 1;
      totalPages = Number.parseInt(payload.total_pages || String(totalPages), 10) || totalPages;
      root.dataset.page = String(page);
      root.dataset.totalPages = String(totalPages);
      moreButton.hidden = page >= totalPages;
      if (status) status.textContent = items.length ? `${items.length} weitere Bilder geladen.` : 'Keine weiteren Bilder.';
    } catch (error) {
      if (status) status.textContent = 'Weitere Bilder konnten nicht geladen werden.';
    } finally {
      moreButton.disabled = false;
    }
  };

  moreButton.addEventListener('click', loadMore);
})();
