(() => {
  'use strict';

  // Kartenstatus, Sortierung und Favoritenfilter bilden eine geschlossene UI-
  // Einheit. Der Controller liest Favoriten über einen Callback, damit der
  // persistente Zustand weiterhin nur an einer Stelle liegt.
  const formatAge = (ageSeconds) => {
    if (ageSeconds === null || ageSeconds === undefined) return 'Keine Daten';
    const minutes = Math.floor(ageSeconds / 60);
    return minutes < 1 ? 'Gerade eben' : `${minutes} min`;
  };

  const freshness = (ageSeconds, intervalSeconds) => {
    if (ageSeconds === null || ageSeconds === undefined) return 'unknown';
    const interval = Math.max(60, Number(intervalSeconds) || 600);
    if (ageSeconds <= interval * 2) return 'ok';
    if (ageSeconds <= interval * 3) return 'warn';
    return 'error';
  };

  const updateFromApi = (card, camera) => {
    const interval = Number(camera.interval_seconds ?? card.dataset.interval ?? 600);
    const age = camera.age_seconds ?? null;
    const captured = camera.last_capture_at ? Math.floor(Date.parse(camera.last_capture_at) / 1000) : null;
    card.dataset.age = age !== null ? String(age) : '999999';
    card.dataset.interval = String(interval);
    if (captured) card.dataset.lastTs = String(captured);
    if (camera.mode) {
      card.dataset.mode = camera.mode;
      const mode = card.querySelector('[data-mode-pill]');
      if (mode) mode.textContent = String(camera.mode).toUpperCase();
    }
    const pill = card.querySelector('[data-age-pill]');
    if (!pill) return;
    pill.textContent = formatAge(age);
    pill.classList.remove('pill--ok', 'pill--warn', 'pill--error');
    const state = freshness(age, interval);
    if (state !== 'unknown') pill.classList.add(`pill--${state}`);
  };

  const create = ({ grid, cards, favoriteToggle, getFavorites, apiUrl }) => {
    const markImagesReady = () => cards.forEach((card) => {
      const image = card.querySelector('.camera-image__img');
      if (!image) return;
      const ready = () => image.closest('.camera-image')?.classList.add('is-loaded');
      if (image.complete) ready();
      else {
        image.addEventListener('load', ready, { once: true });
        image.addEventListener('error', ready, { once: true });
      }
    });

    const filter = () => {
      const favorites = getFavorites();
      const onlyFavorites = favoriteToggle?.checked;
      const visible = cards.filter((card) => {
        const selected = favorites.includes(card.dataset.slug || '');
        card.dataset.fav = selected ? '1' : '0';
        return !onlyFavorites || selected;
      }).sort((left, right) => (left.dataset.title || '').localeCompare(right.dataset.title || ''));
      cards.forEach((card) => { card.style.display = 'none'; });
      visible.forEach((card) => {
        grid.appendChild(card);
        card.style.display = 'grid';
      });
    };

    const syncFavorites = () => {
      const favorites = getFavorites();
      cards.forEach((card) => {
        const button = card.querySelector('[data-action="fav-toggle"]');
        if (!button) return;
        const selected = favorites.includes(card.dataset.slug || '');
        button.classList.toggle('is-fav', selected);
        button.textContent = selected ? '★' : '☆';
        card.dataset.fav = selected ? '1' : '0';
      });
    };

    const refresh = async () => {
      if (!apiUrl) return;
      try {
        const response = await fetch(apiUrl, { headers: { 'X-WC-TEST': '1' } });
        if (!response.ok) return;
        const payload = await response.json();
        const cameras = Array.isArray(payload.cameras) ? payload.cameras : [];
        const bySlug = new Map(cameras.map((camera) => [camera.slug, camera]));
        cards.forEach((card) => {
          const camera = bySlug.get(card.dataset.slug || '');
          if (camera) updateFromApi(card, camera);
        });
        filter();
      } catch (error) {
        console.warn('overview refresh failed', error);
      }
    };

    markImagesReady();
    return Object.freeze({ filter, syncFavorites, refresh });
  };

  window.WebcamOverviewCards = Object.freeze({ create });
})();
