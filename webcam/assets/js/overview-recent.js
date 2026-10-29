(() => {
  'use strict';

  // Favoriten und zuletzt betrachtete Kameras bleiben vollständig im Browser.
  // Dieses Modul validiert Local-Storage-Daten, bevor die Übersicht sie nutzt.
  const RECENT_KEY = 'webcam:recent-cameras:v1';
  const RECENT_LIMIT = 6;

  const loadFavorites = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem('wc_favs') || '[]');
      return Array.isArray(parsed) ? parsed.filter((slug) => typeof slug === 'string') : [];
    } catch { return []; }
  };

  const saveFavorites = (list) => localStorage.setItem('wc_favs', JSON.stringify(list));

  const normalize = (raw) => {
    if (!Array.isArray(raw)) return [];
    const seen = new Set();
    const items = [];
    raw.forEach((entry) => {
      if (!entry || typeof entry !== 'object') return;
      const slug = typeof entry.slug === 'string' ? entry.slug.trim() : '';
      const viewedAt = Number.parseInt(String(entry.viewedAt || ''), 10);
      if (!slug || !Number.isFinite(viewedAt) || viewedAt <= 0) return;
      const href = typeof entry.href === 'string' ? entry.href.trim() : '';
      const timestamp = Number.parseInt(String(entry.timestamp || ''), 10) || null;
      const fingerprint = href || `${slug}:${timestamp || viewedAt}`;
      if (seen.has(fingerprint)) return;
      seen.add(fingerprint);
      items.push({
        slug,
        viewedAt,
        href,
        capturedAt: typeof entry.capturedAt === 'string' ? entry.capturedAt.trim() : '',
        timestamp,
      });
    });
    items.sort((left, right) => right.viewedAt - left.viewedAt);
    return items.slice(0, RECENT_LIMIT);
  };

  const load = () => {
    try { return normalize(JSON.parse(localStorage.getItem(RECENT_KEY) || '[]')); }
    catch { return []; }
  };
  const save = (list) => {
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(normalize(list))); }
    catch { /* Private Mode oder volles Storage darf die Seite nicht stören. */ }
  };

  const formatViewedAt = (unixTs, timezone) => {
    if (!Number.isFinite(unixTs) || unixTs <= 0) return 'Zuletzt angesehen';
    const date = new Date(unixTs * 1000);
    if (Number.isNaN(date.getTime())) return 'Zuletzt angesehen';
    const now = new Date();
    const day = (value) => {
      try {
        return new Intl.DateTimeFormat('de-DE', {
          timeZone: timezone || undefined, year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(value);
      } catch { return value.toDateString(); }
    };
    const time = (value) => {
      try {
        return new Intl.DateTimeFormat('de-DE', {
          timeZone: timezone || undefined, hour: '2-digit', minute: '2-digit',
        }).format(value);
      } catch { return value.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); }
    };
    const dateKey = day(date);
    if (dateKey === day(now)) return `Heute · ${time(date)}`;
    if (dateKey === day(new Date(now.getTime() - 86400000))) return `Gestern · ${time(date)}`;
    return `${dateKey} · ${time(date)}`;
  };

  /** Baut den Bereich neu auf; textContent schützt dabei alle Kameratexte. */
  const render = ({ section, list, cards, entries, timezone }) => {
    if (!section || !list) return;
    const bySlug = new Map(cards.map((card) => [card.dataset.slug || '', card]));
    const items = entries.map((entry) => ({ entry, card: bySlug.get(entry.slug) }))
      .filter(({ card }) => card).slice(0, RECENT_LIMIT);
    list.innerHTML = '';
    if (!items.length) {
      section.hidden = true;
      return;
    }
    items.forEach(({ entry, card }) => {
      const sourceLink = card.querySelector('.camera-image');
      const sourceImage = card.querySelector('.camera-image__img');
      const titleText = card.querySelector('h2')?.textContent?.trim() || entry.slug;
      const link = document.createElement('a');
      link.className = 'overview-recent__item';
      link.href = entry.href || sourceLink?.getAttribute('href') || '#';
      // Archivbilder verschwinden regulär aus dem schnellen Cache und wandern
      // ins Langzeitarchiv. Eine früher im Browser gespeicherte Bild-URL kann
      // deshalb heute 404 liefern. Das erzeugt nicht nur ein defektes Bild,
      // sondern kann am vorgeschalteten Webserver auch Fail2Ban auslösen.
      //
      // Die Verlaufskachel zeigt daher immer das aktuelle, vom Server bereits
      // geprüfte Vorschaubild der Kamera. Zielseite und Aufnahmezeit des
      // zuletzt besuchten Archiveintrags bleiben davon unberührt.
      if (sourceImage) {
        const image = document.createElement('img');
        image.className = 'overview-recent__thumb';
        image.src = sourceImage.currentSrc || sourceImage.getAttribute('src') || '';
        image.alt = `Letztes Bild von ${titleText}`;
        image.loading = 'lazy';
        link.appendChild(image);
      }
      const body = document.createElement('span');
      body.className = 'overview-recent__body';
      const title = document.createElement('strong');
      title.textContent = titleText;
      const meta = document.createElement('span');
      meta.textContent = card.querySelector('.meta-line')?.textContent?.trim() || entry.slug;
      const viewed = document.createElement('small');
      viewed.textContent = entry.capturedAt ? `Aufnahme · ${entry.capturedAt}` : formatViewedAt(entry.viewedAt, timezone);
      body.append(title, meta, viewed);
      link.appendChild(body);
      list.appendChild(link);
    });
    section.hidden = false;
  };

  window.WebcamOverviewRecent = Object.freeze({
    limit: RECENT_LIMIT,
    loadFavorites,
    saveFavorites,
    load,
    save,
    normalize,
    render,
  });
})();
