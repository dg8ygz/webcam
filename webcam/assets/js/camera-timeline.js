(() => {
  'use strict';

  /**
   * Bedienkonfiguration der fünf Timeline-Auswahllisten.
   *
   * Die alte Implementierung enthielt für Uhrzeit, Jahr, Monat, Tag und Stunde
   * fast denselben Öffnen-/Schließen-Code fünfmal. Diese Tabelle beschreibt
   * nur noch die Unterschiede; der gemeinsame Lebenszyklus steht darunter an
   * genau einer Stelle.
   */
  const PICKER_CONFIGS = Object.freeze([
    Object.freeze({
      type: 'time',
      groupSelector: '.timeline-controls__group--time',
      triggerSelector: '[data-time-picker-trigger]',
      panelSelector: '[data-time-picker-panel]',
      optionSelector: '[data-time-picker-option]',
      readyKey: 'timePickerReady',
      openClasses: Object.freeze(['is-time-picker-open']),
      blocksHotspots: false,
      stopsClickPropagation: false,
    }),
    Object.freeze({
      type: 'year',
      groupSelector: '.timeline-controls__group--year',
      triggerSelector: '[data-year-picker-trigger],[data-time-picker-trigger]',
      panelSelector: '[data-year-picker-panel]',
      optionSelector: '[data-year-picker-option]',
      readyKey: 'yearPickerReady',
      openClasses: Object.freeze(['is-year-picker-open', 'is-time-picker-open']),
      blocksHotspots: false,
      stopsClickPropagation: false,
    }),
    Object.freeze({
      type: 'month',
      groupSelector: '.timeline-controls__group--month',
      triggerSelector: '[data-month-picker-trigger],[data-time-picker-trigger]',
      panelSelector: '[data-month-picker-panel]',
      optionSelector: '[data-month-picker-option]',
      readyKey: 'monthPickerReady',
      openClasses: Object.freeze(['is-month-picker-open', 'is-time-picker-open']),
      blocksHotspots: true,
      stopsClickPropagation: true,
    }),
    Object.freeze({
      type: 'day',
      groupSelector: '.timeline-controls__group--day',
      triggerSelector: '[data-day-picker-trigger],[data-time-picker-trigger]',
      panelSelector: '[data-day-picker-panel]',
      optionSelector: '[data-day-picker-option]',
      readyKey: 'dayPickerReady',
      openClasses: Object.freeze(['is-day-picker-open', 'is-time-picker-open']),
      blocksHotspots: true,
      stopsClickPropagation: true,
    }),
    Object.freeze({
      type: 'hour',
      groupSelector: '.timeline-controls__group--hour',
      triggerSelector: '[data-hour-picker-trigger],[data-time-picker-trigger]',
      panelSelector: '[data-hour-picker-panel]',
      optionSelector: '[data-hour-picker-option]',
      readyKey: 'hourPickerReady',
      openClasses: Object.freeze(['is-hour-picker-open', 'is-time-picker-open']),
      blocksHotspots: true,
      stopsClickPropagation: true,
    }),
  ]);

  const NAV_MODE_LABELS = Object.freeze({
    bestof: 'Best-Of',
    year: 'Jahr',
    month: 'Monat',
    day: 'Tag',
    hour: 'Stunde',
    time: 'Live',
  });

  /**
   * Erzeugt den Timeline-Controller für genau ein Browserdokument.
   *
   * Die wenigen Seitendienste werden ausdrücklich hereingereicht. Das Modul
   * kennt dadurch weder das Deployment-Präfix noch Details der Streamlogik und
   * kann nach partieller Navigation immer den aktuellen Kamera-Root abfragen.
   */
  const create = ({
    getLiveRoot,
    navRoot = document,
    prefersReducedMotion,
    toRelativeUrl,
    getLiveDisplayMode,
  } = {}) => {
    if (typeof getLiveRoot !== 'function'
      || !(navRoot instanceof Document || navRoot instanceof Element)
      || typeof prefersReducedMotion !== 'function'
      || typeof toRelativeUrl !== 'function'
      || typeof getLiveDisplayMode !== 'function') {
      throw new Error('Timeline: notwendige Seitenadapter fehlen.');
    }

    // Es darf dokumentweit immer nur eine Auswahlliste offen sein. Der Zustand
    // wird bei einem partiellen DOM-Tausch zuerst geschlossen, damit keine
    // Listener oder Referenzen auf entfernte Elemente zurückbleiben.
    let activePicker = null;

    const normalizeNavMode = (value) => {
      const normalized = String(value || '').toLowerCase();
      return Object.prototype.hasOwnProperty.call(NAV_MODE_LABELS, normalized)
        ? normalized
        : null;
    };

    const resolveNavMode = (params = null) => {
      const search = params instanceof URLSearchParams
        ? params
        : new URLSearchParams(window.location.search);
      if (search.has('bestof')) {
        return 'bestof';
      }
      return normalizeNavMode(search.get('nav')) || 'time';
    };

    /**
     * Ergänzt den Navigationsmodus an einen internen Link. Die Umwandlung in
     * eine dokumentrelative URL übernimmt weiterhin der zentrale URL-Helfer.
     */
    const withNavMode = (href, navMode) => {
      if (!href || href === '#') {
        return href;
      }
      const mode = normalizeNavMode(navMode);
      if (!mode || mode === 'time') {
        return href;
      }
      try {
        const url = new URL(href, window.location.href);
        url.searchParams.set('nav', mode);
        return toRelativeUrl(url);
      } catch (error) {
        return href;
      }
    };

    /**
     * Zeigt an, welche Bedeutung Vor/Zurück und die seitlichen Hotspots gerade
     * besitzen. Im Live-Modus wird zusätzlich zwischen Snapshot, MJPEG und HLS
     * unterschieden, ohne Streamzustand in diesem Modul zu duplizieren.
     */
    const updateLiveModeBadge = (navMode) => {
      const liveRoot = getLiveRoot();
      if (!liveRoot) {
        return;
      }
      const badge = liveRoot.querySelector('[data-live-mode-badge]');
      if (!badge) {
        return;
      }
      const mode = normalizeNavMode(navMode) || 'time';
      const streamActive = getLiveDisplayMode() === 'stream';
      let label = NAV_MODE_LABELS[mode] || NAV_MODE_LABELS.time;

      // Der sichtbare Inhalt hat Vorrang vor dem zuletzt gewählten Abstand der
      // Archivnavigation. Wer aus Monat/Tag/Jahr in den Stream wechselt, sieht
      // kein Archivbild mehr; „Monat“ wäre deshalb als Zustandsanzeige falsch.
      // Beim Rückweg zum Snapshot wird der unveränderte URL-Modus erneut
      // aufgelöst und die frühere Navigation (zum Beispiel Monat) erscheint
      // wieder.
      if (streamActive) {
        const renderer = liveRoot.querySelector('.live-image')?.dataset?.streamRenderer || '';
        const explicitHls = liveRoot.dataset.streamPreference === 'hls';
        label = renderer === 'hls' || explicitHls ? 'Livestream HLS' : 'Livestream';
      }
      badge.textContent = label;
      badge.dataset.mode = streamActive ? 'time' : mode;
      badge.hidden = false;
    };

    // Offene Monat-/Tag-/Stundenlisten liegen über den großen unsichtbaren
    // Bild-Hotspots. Während der Auswahl werden diese Trefferflächen deshalb
    // gezielt abgeschaltet und anschließend exakt wiederhergestellt.
    const disableHotspotPointers = () => {
      navRoot.querySelectorAll('[data-timeline-hotspot]').forEach((element) => {
        if (!(element instanceof HTMLElement)) {
          return;
        }
        element.dataset.oldPointerEvents = element.style.pointerEvents || '';
        element.style.pointerEvents = 'none';
      });
    };

    const restoreHotspotPointers = () => {
      navRoot.querySelectorAll('[data-timeline-hotspot]').forEach((element) => {
        if (!(element instanceof HTMLElement)
          || element.dataset.oldPointerEvents === undefined) {
          return;
        }
        element.style.pointerEvents = element.dataset.oldPointerEvents;
        delete element.dataset.oldPointerEvents;
      });
    };

    const handleEscape = (event) => {
      if (event.key === 'Escape') {
        closeActivePicker();
      }
    };

    /** Schließt die aktuell offene Liste und räumt alle Inline-Stile auf. */
    const closeActivePicker = () => {
      if (!activePicker) {
        return;
      }
      const { config, group, trigger, panel } = activePicker;
      trigger.setAttribute('aria-expanded', 'false');
      config.openClasses.forEach((className) => group.classList.remove(className));
      panel.classList.remove('is-visible');
      panel.style.display = 'none';
      panel.style.zIndex = '';
      panel.style.pointerEvents = '';
      trigger.style.zIndex = '';
      trigger.style.pointerEvents = '';
      activePicker = null;
      restoreHotspotPointers();
    };

    // Der Controller lebt genau so lange wie das Browserdokument. Der Escape-
    // Listener bleibt deshalb stabil registriert und ist ohne activePicker ein
    // günstiger No-op. Ein späteres initPickers() kann so keinen Listener mehr
    // entfernen, während ein durch fremden DOM-Zustand sichtbares Panel offen
    // bleibt.
    document.addEventListener('keydown', handleEscape);

    const openPicker = (config, group, trigger, panel) => {
      if (activePicker?.panel === panel) {
        closeActivePicker();
        return;
      }
      closeActivePicker();
      activePicker = { config, group, trigger, panel };
      trigger.setAttribute('aria-expanded', 'true');
      config.openClasses.forEach((className) => group.classList.add(className));
      panel.classList.add('is-visible');
      panel.style.display = 'block';
      panel.style.zIndex = '400';
      panel.style.pointerEvents = 'auto';
      trigger.style.zIndex = '401';
      trigger.style.pointerEvents = 'auto';
      if (config.blocksHotspots) {
        disableHotspotPointers();
      }

      // Der aktive Eintrag soll auch in langen Jahres-/Tageslisten sofort im
      // sichtbaren Ausschnitt stehen. Reduzierte Bewegung wird respektiert.
      const selected = panel.querySelector('.timeline-time-picker__option.is-selected');
      if (selected instanceof HTMLElement) {
        selected.scrollIntoView({
          block: 'center',
          behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
      }
    };

    const bindPickerGroup = (config, group) => {
      const trigger = group.querySelector(config.triggerSelector);
      const panel = group.querySelector(config.panelSelector);
      if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
        return;
      }
      if (trigger.dataset[config.readyKey] === '1') {
        return;
      }
      trigger.dataset[config.readyKey] = '1';
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        if (config.stopsClickPropagation) {
          event.stopPropagation();
        }
        openPicker(config, group, trigger, panel);
      });
      panel.querySelectorAll(config.optionSelector).forEach((option) => {
        option.addEventListener('click', (event) => {
          if (config.stopsClickPropagation) {
            event.stopPropagation();
          }
          closeActivePicker();
        });
      });
    };

    /** Bindet neue, beispielsweise nach Ajax-Navigation ersetzte Picker. */
    const initPickers = () => {
      closeActivePicker();
      PICKER_CONFIGS.forEach((config) => {
        navRoot.querySelectorAll(config.groupSelector).forEach((group) => {
          if (group instanceof HTMLElement) {
            bindPickerGroup(config, group);
          }
        });
      });
    };

    const closeTimelineMenus = () => {
      if (document.activeElement instanceof HTMLElement
        && document.activeElement.closest('.tl-menu')) {
        document.activeElement.blur();
      }
      navRoot.querySelectorAll('.tl-menu .tl-menu__toggle, .tl-menu .tl-menu__dropdown a')
        .forEach((element) => {
          if (element instanceof HTMLElement) {
            element.blur();
          }
        });
    };

    /**
     * Noträumung vor Modalwechseln und DOM-Tausch. Sie behandelt auch ältere
     * servergerenderte Picker, die noch nicht durch initPickers gebunden waren.
     */
    const resetPickerUi = () => {
      closeActivePicker();
      navRoot.querySelectorAll('.timeline-controls__group').forEach((group) => {
        PICKER_CONFIGS.forEach((config) => {
          config.openClasses.forEach((className) => group.classList.remove(className));
        });
      });
      navRoot.querySelectorAll(
        '[data-time-picker-trigger],[data-year-picker-trigger],'
        + '[data-month-picker-trigger],[data-day-picker-trigger],[data-hour-picker-trigger]'
      ).forEach((trigger) => {
        if (!(trigger instanceof HTMLElement)) {
          return;
        }
        trigger.setAttribute('aria-expanded', 'false');
        trigger.style.zIndex = '';
        trigger.style.pointerEvents = '';
      });
      navRoot.querySelectorAll(
        '[data-time-picker-panel],[data-year-picker-panel],'
        + '[data-month-picker-panel],[data-day-picker-panel],[data-hour-picker-panel]'
      ).forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        panel.classList.remove('is-visible');
        panel.style.display = 'none';
        panel.style.zIndex = '';
        panel.style.pointerEvents = '';
      });
      restoreHotspotPointers();
    };

    const closeTransientUi = () => {
      closeTimelineMenus();
      resetPickerUi();
    };

    return Object.freeze({
      normalizeNavMode,
      resolveNavMode,
      withNavMode,
      updateLiveModeBadge,
      initPickers,
      closeTransientUi,
    });
  };

  window.WebcamTimeline = Object.freeze({ create });
})();
