(function () {
  function getCameraSlugFromUrl() {
    try {
      const url = new URL(window.location.href);
      const slug = url.searchParams.get('cam');
      if (slug) return slug;
    } catch {
      // ignore
    }
    const apiEl = document.querySelector('[data-api-camera-detail]');
    const apiUrl = apiEl ? apiEl.getAttribute('data-api-camera-detail') : '';
    if (apiUrl) {
      const match = apiUrl.match(/cameras\/([^/?#]+)/i);
      if (match) return match[1];
    }
    return '';
  }

  function getAppBasePath() {
    let path = window.location.pathname || '';
    path = path.replace(/\/+$/, '');
    if (!path || path === '/') {
      return '';
    }
    if (path.endsWith('/camera.php')) {
      path = path.slice(0, -'/camera.php'.length);
    } else if (/\/camera\/[^/]+$/.test(path)) {
      path = path.replace(/\/camera\/[^/]+$/, '');
    } else if (path.endsWith('/camera')) {
      path = path.slice(0, -'/camera'.length);
    } else {
      const slug = getCameraSlugFromUrl();
      if (slug && path.endsWith(`/${slug}`)) {
        path = path.slice(0, -(slug.length + 1));
      }
    }
    return path === '/' ? '' : path;
  }

  function buildAppPath(pathLike) {
    const cleaned = String(pathLike || '').replace(/^\/+/, '');
    const base = getAppBasePath();
    if (!cleaned) {
      return base || '/';
    }
    if (!base) {
      return `/${cleaned}`;
    }
    return `${base}/${cleaned}`;
  }

  function normalizeAssetUrl(value) {
    if (!value) return '';
    const str = String(value);
    if (/^(https?:)?\/\//i.test(str)) return str;
    if (str.startsWith('/')) return str;
    if (/^(?:data|blob):/i.test(str)) return str;
    // Server-seitig kommen Best-Of/Top-Like-Bilder teils bewusst als
    // dokumentrelative `../cache/...` oder `../storage/...`-Pfade. Diese
    // dürfen hier nicht noch einmal an den App-Base-Path gehängt werden,
    // sonst wird aus `/webcam/../cache/...` effektiv `/cache/...` und damit
    // ein 404 im Subdir-Deploy.
    if (/^\.\.?\//.test(str)) return str;
    return buildAppPath(str);
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function parseJson(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || 'null');
    } catch (e) {
      return null;
    }
  }

  function renderExif() {
    const container = document.querySelector('[data-exif-container]');
    if (!container) return;
    const lines = parseJson('exif-data');
    const placeholder = container.querySelector('[data-exif-placeholder]');
    if (!Array.isArray(lines) || lines.length === 0) {
      if (placeholder) placeholder.textContent = 'Keine EXIF-Daten vorhanden.';
      return;
    }
    const pre = document.createElement('pre');
    pre.textContent = lines.join('\n');
    if (placeholder) {
      placeholder.replaceWith(pre);
    } else {
      container.appendChild(pre);
    }
  }

  function figureFromItem(item) {
    const hasDatePrefix = typeof item.filename === 'string' && /^\d{8}_\d{6}/.test(item.filename);
    // Datumslose Best-Of-Items (legacy bielefeld-nord/steinhagen) besser ausblenden, weil Links/Datum fehlen.
    if (!hasDatePrefix) {
      return null;
    }
    const fig = document.createElement('figure');
    fig.className = 'bestof-card';
    const a = document.createElement('a');
    a.className = 'bestof-card__link';
    a.href = item.link || normalizeAssetUrl(item.url);
    a.target = '_self';
    a.rel = 'noopener';
    const img = document.createElement('img');
    img.src = normalizeAssetUrl(item.thumb_url || item.url);
    img.alt = 'Best of Bild ' + (item.filename || '');
    img.loading = 'lazy';
    img.decoding = 'async';
    a.appendChild(img);
    fig.appendChild(a);
    const fc = document.createElement('figcaption');
    const ts = hasDatePrefix && item.slot_ts ? item.slot_ts : null;
    const date = ts ? new Date(ts * 1000) : null;
    const dateLabel = date ? date.toLocaleString('de-DE', { timeZone: 'Europe/Berlin', year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
    const title = dateLabel ? dateLabel : (item.filename || '');
    fc.textContent = title;
    fig.appendChild(fc);
    return fig;
  }

  function figureFromLike(item) {
    const fig = document.createElement('figure');
    fig.className = 'bestof-card';
    const a = document.createElement('a');
    a.className = 'bestof-card__link';
    a.href = item.link || normalizeAssetUrl(item.url);
    a.target = '_self';
    a.rel = 'noopener';
    const img = document.createElement('img');
    img.src = normalizeAssetUrl(item.thumb_url || item.url);
    img.alt = 'Beliebtes Bild ' + (item.filename || '');
    img.loading = 'lazy';
    img.decoding = 'async';
    a.appendChild(img);
    fig.appendChild(a);
    const fc = document.createElement('figcaption');
    const hasDatePrefix = typeof item.filename === 'string' && /^\d{8}_\d{6}/.test(item.filename);
    const date = hasDatePrefix && item.slot_ts ? new Date(item.slot_ts * 1000) : null;
    const dateLabel = date ? date.toLocaleString('de-DE', { timeZone: 'Europe/Berlin', year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
    const likes = typeof item.likes === 'number' ? item.likes : 0;
    fc.textContent = dateLabel || item.filename || '';
    fc.appendChild(document.createElement('br'));
    const likesEl = document.createElement('small');
    likesEl.textContent = `${likes} ❤`;
    fc.appendChild(likesEl);
    fig.appendChild(fc);
    return fig;
  }

  let cameraDetailCache = null;
  let cameraDetailPromise = null;

  async function fetchCameraDetail() {
    const apiEl = document.querySelector('[data-api-camera-detail]');
    const apiUrl = apiEl ? apiEl.getAttribute('data-api-camera-detail') : null;
    if (!apiUrl) {
      return null;
    }
    if (cameraDetailCache) {
      return cameraDetailCache;
    }
    if (cameraDetailPromise) {
      return cameraDetailPromise;
    }
    cameraDetailPromise = fetch(apiUrl, { headers: { Accept: 'application/json', 'X-WC-TEST': '1' } })
      .then((resp) => {
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        return resp.json();
      })
      .then((json) => {
        cameraDetailCache = json;
        return json;
      })
      .catch(() => null)
      .finally(() => {
        cameraDetailPromise = null;
      });
    return cameraDetailPromise;
  }

  async function loadBestOfData() {
    const inline = parseJson('bestof-data') || parseJson('bestof-data-inline');
    if (Array.isArray(inline) && inline.length) {
      return inline;
    }
    const detail = await fetchCameraDetail();
    const bestOf = detail && Array.isArray(detail.best_of) ? detail.best_of : null;
    if (bestOf && bestOf.length) {
      return bestOf;
    }
    return bestOf;
  }

  async function loadTopLikedData() {
    const inline = parseJson('topliked-data') || parseJson('topliked-data-inline');
    if (Array.isArray(inline) && inline.length) {
      return inline;
    }
    const detail = await fetchCameraDetail();
    const topLiked = detail && Array.isArray(detail.top_liked) ? detail.top_liked : null;
    if (topLiked && topLiked.length) {
      return topLiked;
    }
    return topLiked;
  }

  function renderBestOf(data) {
    if (!Array.isArray(data)) return;
    const BATCH = 40;
    const grids = document.querySelectorAll('[data-bestof-grid]');
    grids.forEach((grid) => {
      if (grid.hasAttribute('data-bestof-endpoint')) {
        return;
      }
      grid.innerHTML = '';
      if (!data.length) {
        const p = document.createElement('p');
        p.textContent = 'Noch keine Best-of-Auswahl vorhanden.';
        grid.appendChild(p);
        return;
      }
      const sentinel = document.createElement('div');
      sentinel.className = 'bestof-sentinel';
      grid.appendChild(sentinel);

      let rendered = 0;
      const loadMore = () => {
      let count = 0;
      while (rendered < data.length && count < BATCH) {
        const item = data[rendered++];
        if (item && item.url) {
          const fig = figureFromItem(item);
          if (fig) {
            grid.insertBefore(fig, sentinel);
          }
        }
        count += 1;
      }
        if (rendered >= data.length) {
          observer.disconnect();
          sentinel.remove();
        }
      };

      const observer = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          loadMore();
        }
      }, { root: null, rootMargin: '200px', threshold: 0 });

      observer.observe(sentinel);
      loadMore();
    });
  }

  function appendBestOfItems(grid, items) {
    if (!grid || !Array.isArray(items) || !items.length) {
      return 0;
    }
    const fragment = document.createDocumentFragment();
    let added = 0;
    items.forEach((item) => {
      if (item && item.url) {
        const fig = figureFromItem(item);
        if (fig) {
          fragment.appendChild(fig);
          added += 1;
        }
      }
    });
    if (added) {
      grid.appendChild(fragment);
    }
    return added;
  }

  function initBestOfPagination(initialData) {
    const grid = document.querySelector('[data-bestof-grid][data-bestof-endpoint]');
    if (!grid) return;
    const endpoint = grid.getAttribute('data-bestof-endpoint');
    if (!endpoint) return;
    const perPage = parseInt(grid.getAttribute('data-bestof-per-page') || '200', 10) || 200;
    const selectedYear = (grid.getAttribute('data-bestof-year') || '').trim();
    let currentPage = parseInt(grid.getAttribute('data-bestof-page') || '1', 10) || 1;
    const button = document.querySelector('[data-bestof-more]');
    const placeholder = grid.querySelector('.bestof-placeholder');
    let loading = false;
    let hasMore = true;
    let totalPages = null;
    let syncing = false;

    const setButton = (visible) => {
      if (!button) return;
      button.hidden = !visible;
      button.disabled = loading;
    };

    const setPlaceholder = (text) => {
      if (!placeholder) return;
      placeholder.textContent = text;
      placeholder.hidden = false;
    };

    const fetchPage = async (page) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('per_page', String(perPage));
      url.searchParams.set('page', String(page));
      if (selectedYear) {
        url.searchParams.set('year', selectedYear);
      }
      const resp = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-WC-TEST': '1' } });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}`);
      }
      return await resp.json();
    };

    const applyResponse = (json, replace) => {
      const items = json && Array.isArray(json.items) ? json.items : [];
      if (replace) {
        grid.innerHTML = '';
      }
      appendBestOfItems(grid, items);
      if (json && Number.isFinite(json.total_pages)) {
        totalPages = json.total_pages;
      }
      if (json && Number.isFinite(json.page)) {
        currentPage = json.page;
      }
      if (totalPages !== null && Number.isFinite(totalPages)) {
        hasMore = currentPage < totalPages;
      } else {
        hasMore = items.length >= perPage;
      }
      setButton(hasMore);
      return items.length;
    };

    const syncFirstPage = async () => {
      if (syncing) return;
      syncing = true;
      try {
        const json = await fetchPage(1);
        if (placeholder) {
          placeholder.remove();
        }
        if (!json || !Array.isArray(json.items) || json.items.length === 0) {
          setPlaceholder('Noch keine Best-of-Auswahl vorhanden.');
          setButton(false);
          hasMore = false;
          return;
        }
        applyResponse(json, true);
      } catch (e) {
        // keep inline list if sync fails
      } finally {
        syncing = false;
      }
    };

    const applyInitial = (data) => {
      if (!Array.isArray(data) || !data.length) {
        return false;
      }
      if (placeholder) {
        placeholder.remove();
      }
      appendBestOfItems(grid, data);
      // Inline-Daten koennen wegen Minute-Dedupe < perPage sein – mehr nachladen ermoeglichen
      hasMore = true;
      setButton(true);
      return true;
    };

    const loadInitial = async () => {
      if (applyInitial(initialData)) {
        syncFirstPage().catch(() => {});
        return;
      }
      setPlaceholder('Best-Of wird geladen…');
      try {
        const json = await fetchPage(currentPage);
        if (placeholder) {
          placeholder.remove();
        }
        if (!json || !Array.isArray(json.items) || json.items.length === 0) {
          setPlaceholder('Noch keine Best-of-Auswahl vorhanden.');
          setButton(false);
          hasMore = false;
          return;
        }
        applyResponse(json, true);
      } catch (e) {
        setPlaceholder('Best-Of konnte nicht geladen werden.');
        setButton(false);
        hasMore = false;
      }
    };

    const loadMore = async () => {
      if (!hasMore || loading) return;
      if (totalPages === null) {
        await syncFirstPage();
        if (!hasMore || loading) {
          return;
        }
      }
      loading = true;
      setButton(true);
      if (button) {
        button.textContent = 'Lade…';
      }
      try {
        const nextPage = currentPage + 1;
        const json = await fetchPage(nextPage);
        const items = json && Array.isArray(json.items) ? json.items : [];
        if (!items.length) {
          hasMore = false;
          setButton(false);
          return;
        }
        applyResponse(json, false);
      } catch (e) {
        hasMore = false;
        setButton(false);
      } finally {
        loading = false;
        if (button) {
          button.textContent = 'Mehr laden';
          button.disabled = false;
        }
      }
    };

    if (button) {
      button.addEventListener('click', (ev) => {
        ev.preventDefault();
        loadMore();
      });
    }

    loadInitial();
  }

  function renderTopLiked(data) {
    if (!Array.isArray(data)) return;
    const grids = document.querySelectorAll('[data-topliked-grid]');
    grids.forEach((grid) => {
      grid.innerHTML = '';
      if (!data.length) {
        const p = document.createElement('p');
        p.textContent = 'Noch keine Likes vorhanden.';
        grid.appendChild(p);
        return;
      }
      data.slice(0, 20).forEach((item) => {
        if (!item || !item.url) return;
        grid.appendChild(figureFromLike(item));
      });
    });
  }

  function patchBestofTogglePath() {
    const btn = document.querySelector('[data-bestof-toggle]');
    if (!btn) return;
    const path = btn.getAttribute('data-path') || '';
    const slug = btn.getAttribute('data-camera') || '';
    if (!path.startsWith('bestof/') || !slug) return;
    const m = path.match(/bestof\/[a-z0-9-]+\/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})_(la|lm|sm|hu|full)\.jpe?g/i);
    if (!m) return;
    const rel = `cache/${slug}/${m[1]}/${m[2]}/${m[3]}/${m[1]}${m[2]}${m[3]}_${m[4]}${m[5]}${m[6]}_${m[7].toLowerCase()}.jpg`;
    btn.setAttribute('data-path', rel);
  }

  ready(function () {
    renderExif();
    if (document.getElementById('bestof-data') || document.getElementById('bestof-data-inline')) {
      Promise.all([loadBestOfData(), loadTopLikedData()]).then(([bestOf, topLiked]) => {
        initBestOfPagination(bestOf || []);
        renderBestOf(bestOf || []);
        renderTopLiked(topLiked || []);
      });
    }
    patchBestofTogglePath();
  });
})();
