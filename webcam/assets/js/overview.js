(() => {
  'use strict';

  // Schlanker Seitencontroller: Er liest nur die serverseitigen Daten, verbindet
  // die vier UI-Module und registriert die übergreifenden Ereignisse.
  const readConfig = () => {
    const node = document.querySelector('[data-overview-config]');
    if (!node) return {};
    try {
      const parsed = JSON.parse(node.textContent || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
      console.error('overview config invalid', error);
      return {};
    }
  };

  const grid = document.querySelector('[data-camera-grid]');
  if (!grid) return;

  const config = readConfig();
  const cards = Array.from(grid.querySelectorAll('[data-camera-card]'));
  const layout = document.querySelector('[data-layout-toggle]');
  const favoriteToggle = document.querySelector('[data-toggle-favs]');
  const recentSection = document.querySelector('[data-overview-recent]');
  const recentList = document.querySelector('[data-recent-list]');
  const recentClear = document.querySelector('[data-recent-clear]');

  const recentModule = window.WebcamOverviewRecent;
  const cardsModule = window.WebcamOverviewCards;
  const mapModule = window.WebcamOverviewMap;
  const lightboxModule = window.WebcamOverviewLightbox;
  if (!recentModule || !cardsModule || !mapModule || !lightboxModule) {
    console.error('overview modules incomplete');
    return;
  }

  let favorites = recentModule.loadFavorites();
  let recentViews = recentModule.load();
  const renderRecent = () => recentModule.render({
    section: recentSection,
    list: recentList,
    cards,
    entries: recentViews,
    timezone: typeof config.timezone === 'string' ? config.timezone : 'UTC',
  });
  const cardController = cardsModule.create({
    grid,
    cards,
    favoriteToggle,
    getFavorites: () => favorites,
    apiUrl: typeof config.apiUrl === 'string' ? config.apiUrl : '',
  });
  // Der Controller bindet Zoom/Navigationsereignisse selbst. open() bleibt für
  // eine spätere explizite Kartenaktion verfügbar; normale Bildlinks navigieren.
  lightboxModule.create();

  grid.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action="fav-toggle"]');
    if (!button) return;
    const card = button.closest('[data-camera-card]');
    const slug = card?.dataset.slug || '';
    if (!slug) return;
    const previousScrollY = window.scrollY;
    favorites = favorites.includes(slug)
      ? favorites.filter((item) => item !== slug)
      : [...new Set([...favorites, slug])];
    recentModule.saveFavorites(favorites);
    cardController.syncFavorites();
    cardController.filter();
    button.blur();
    window.scrollTo(0, previousScrollY);
  });

  favoriteToggle?.addEventListener('change', cardController.filter);
  recentClear?.addEventListener('click', () => {
    recentViews = [];
    recentModule.save(recentViews);
    renderRecent();
  });
  layout?.addEventListener('change', () => {
    grid.dataset.layout = layout.value === 'list' ? 'list' : 'grid';
  });

  mapModule.init(config);
  cardController.syncFavorites();
  cardController.filter();
  renderRecent();
  void cardController.refresh();
  window.setInterval(cardController.refresh, 60000);
})();
