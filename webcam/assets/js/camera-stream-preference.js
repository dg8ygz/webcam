(() => {
  'use strict';

  /**
   * Verwaltet ausschließlich die kurzlebige Auswahl „Snapshot/Livestream“.
   *
   * Die Auswahl gilt pro Kamera und nur für die aktuelle Browsersitzung. Das
   * Modul startet selbst keinen Stream; der eigentliche Stream-Controller wird
   * über setDisplayMode zurückgerufen. Technische Fallbacks dürfen die bewusst
   * gespeicherte Benutzerauswahl nicht überschreiben.
   */
  const create = ({ getLiveRoot, getConfig, resolveNavMode, updateLiveModeBadge } = {}) => {
    const dependencies = [getLiveRoot, getConfig, resolveNavMode, updateLiveModeBadge];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new Error('Stream-Präferenz: notwendige Seitenadapter fehlen.');
    }

    const normalize = (value) => {
      const mode = String(value || '').toLowerCase();
      return mode === 'stream' || mode === 'snapshot' ? mode : null;
    };

    const storageKey = () => {
      const slug = getLiveRoot()?.dataset?.cameraSlug || '';
      return slug ? `wc_live_display_${slug}` : 'wc_live_display';
    };

    const read = () => {
      try {
        return normalize(window.sessionStorage.getItem(storageKey()));
      } catch {
        return null;
      }
    };

    const write = (mode) => {
      try {
        const normalized = normalize(mode);
        if (!normalized) window.sessionStorage.removeItem(storageKey());
        else window.sessionStorage.setItem(storageKey(), normalized);
      } catch {
        // SessionStorage kann in restriktiven Browsermodi gesperrt sein. Die
        // laufende Ansicht funktioniert trotzdem ohne persistierte Auswahl.
      }
    };

    const current = () => {
      const container = getLiveRoot()?.querySelector('.live-image');
      return normalize(container?.dataset?.liveDisplay) || 'snapshot';
    };

    const updateMenu = (mode) => {
      const root = getLiveRoot();
      if (!root) return;
      root.querySelectorAll('a[data-live-display], button[data-live-display]').forEach((item) => {
        const active = item.dataset.liveDisplay === mode;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    };

    /** Bindet das Menü genau einmal je gerendertem Live-Root. */
    const init = (setDisplayMode) => {
      if (typeof setDisplayMode !== 'function') {
        throw new TypeError('Stream-Präferenz benötigt setDisplayMode().');
      }
      const root = getLiveRoot();
      if (!root || root.dataset.liveDisplayInit === '1') return;
      root.dataset.liveDisplayInit = '1';
      const container = root.querySelector('.live-image');
      if (!container) return;
      const { enabled, preference } = getConfig();
      if (!enabled) return;
      const initialMode = preference === 'hls'
        ? 'stream'
        : (read() || container.dataset.liveDisplay || 'snapshot');
      // The image container carries the same state attribute. Binding it would
      // restart the stream whenever native video controls receive a click.
      root.querySelectorAll('a[data-live-display], button[data-live-display]').forEach((item) => {
        item.addEventListener('click', (event) => {
          event.preventDefault();
          void setDisplayMode(item.dataset.liveDisplay || 'snapshot', {
            persist: true,
            userInitiated: true,
          });
        });
      });
      void setDisplayMode(initialMode);
    };

    const restore = (displayMode, fallbackActive, setDisplayMode) => {
      const storedMode = read();
      if (fallbackActive || !storedMode || storedMode === displayMode) return false;
      void setDisplayMode(storedMode);
      return true;
    };

    const applyVisibleMode = (mode) => {
      updateMenu(mode);
      updateLiveModeBadge(resolveNavMode());
    };

    return Object.freeze({ read, write, current, updateMenu, init, restore, applyVisibleMode });
  };

  window.WebcamCameraStreamPreference = Object.freeze({ create });
})();
