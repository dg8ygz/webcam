(() => {
  'use strict';

  /**
   * Leseführung
   * -----------
   * Dieses Modul setzt ein bereits berechnetes Timeline-Modell in sichtbare
   * Bedienelemente um. Es lädt selbst keine Daten und berechnet auch keine
   * zeitlichen Nachbarn. Dafür ist ausschließlich `camera-timeline-data.js`
   * zuständig.
   *
   * Die Datei ist in fünf Funktionsblöcke gegliedert:
   *
   * 1. Navigationsgruppen und Uhrzeitauswahl als DOM aufbauen.
   * 2. Seitliche Hotspots passend zum aktiven Navigationsmodus erzeugen.
   * 3. Das fertige Präsentationsmodell in den aktuellen Kamera-Root übernehmen.
   * 4. Lade- und Timeoutzustand des Hauptbildes verwalten.
   * 5. Eine kleine Controller-Schnittstelle für `camera.js` bereitstellen.
   *
   * Alle sichtbaren Texte werden mit `textContent` geschrieben. Das Modul
   * behält außerdem keine dauerhafte Referenz auf den Kamera-Root, weil dieser
   * bei partieller Archivnavigation ausgetauscht werden kann.
   */

  const IMAGE_LOADING_TIMEOUT_MS = 20000;

  const create = ({
    getLiveRoot,
    buildEntryUrl,
    initTimelinePickers,
    resolveNavMode,
    updateLiveModeBadge,
    applyEntryOverlay,
    updateLiveLabels,
    prefetchAdjacentSnapshots,
  } = {}) => {
    const requiredFunctions = [
      getLiveRoot,
      buildEntryUrl,
      initTimelinePickers,
      resolveNavMode,
      updateLiveModeBadge,
      applyEntryOverlay,
      updateLiveLabels,
      prefetchAdjacentSnapshots,
    ];
    if (!requiredFunctions.every((dependency) => typeof dependency === 'function')) {
      throw new TypeError('CameraTimelineView: notwendige Seitenadapter fehlen.');
    }

    // Der Timeout gehört zum gerade laufenden Bildwechsel. Ein erfolgreicher
    // Abschluss löscht ihn; ein neuer Start ersetzt den vorherigen Lauf.
    let loadingFailTimer = null;

    // -----------------------------------------------------------------------
    // 1. Navigationsgruppen und Uhrzeitauswahl
    // -----------------------------------------------------------------------

    const appendDirectionButton = ({ group, entry, direction, label, cameraSlug, navMode }) => {
      if (!entry) {
        const placeholder = document.createElement('span');
        placeholder.className = 'timeline-controls__button is-disabled';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.textContent = direction === 'prev' ? '‹' : '›';
        group.appendChild(placeholder);
        return;
      }

      const link = document.createElement('a');
      link.className = 'timeline-controls__button';
      link.href = buildEntryUrl(entry, cameraSlug, navMode);
      link.setAttribute('aria-label', `${direction === 'prev' ? 'Vorherige ' : 'Nächste '}${label}`);
      link.dataset.prefetchLink = 'snapshot';
      if (entry.timestamp) {
        link.dataset.targetKey = String(entry.timestamp);
      }
      link.textContent = direction === 'prev' ? '‹' : '›';
      group.appendChild(link);
    };

    /**
     * Baut die mittlere Beschriftung einer Gruppe. Nur die Uhrzeit erhält eine
     * aufklappbare Liste; Jahr, Monat, Tag und Stunde behalten ihre serverseitig
     * vorbereiteten beziehungsweise einfachen Beschriftungen.
     */
    const appendGroupLabel = ({ group, state, label, isTime, isDay, timePicker, cameraSlug, navMode }) => {
      if (isTime && Array.isArray(timePicker) && timePicker.length > 0) {
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'timeline-time-button';
        trigger.dataset.timePickerTrigger = '';
        trigger.setAttribute('aria-haspopup', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        const strong = document.createElement('strong');
        strong.textContent = state.selectedLabel || 'Zeit';
        trigger.appendChild(strong);
        group.appendChild(trigger);

        const panel = document.createElement('div');
        panel.className = 'timeline-time-picker';
        panel.dataset.timePickerPanel = '';
        panel.setAttribute('aria-label', 'Zeitauswahl');
        const list = document.createElement('ul');

        timePicker.forEach((option) => {
          const item = document.createElement('li');
          const link = document.createElement('a');
          const classes = ['timeline-time-picker__option'];
          if (option.kind) classes.push(`timeline-time-picker__option--${option.kind}`);
          if (option.entry && option.entry.timestamp === state.selectedTs) {
            classes.push('is-selected');
          }
          link.className = classes.join(' ');
          link.href = buildEntryUrl(option.entry, cameraSlug, navMode) || '#';
          link.dataset.timePickerOption = '';
          link.textContent = option.label || '–';
          item.appendChild(link);
          list.appendChild(item);
        });

        panel.appendChild(list);
        group.appendChild(panel);
        return;
      }

      const labelElement = document.createElement(isDay ? 'button' : 'div');
      labelElement.className = isDay
        ? 'timeline-controls__label timeline-controls__label--day'
        : 'timeline-controls__label';
      const strong = document.createElement('strong');
      strong.textContent = label;
      labelElement.appendChild(strong);
      if (isDay) {
        labelElement.type = 'button';
        labelElement.disabled = true;
      }
      group.appendChild(labelElement);
    };

    const appendNavigationGroup = ({
      groupsWrap,
      state,
      cameraSlug,
      label,
      previous,
      next,
      groupType,
      isTime = false,
      isDay = false,
      timePicker = null,
      navMode,
    }) => {
      const group = document.createElement('div');
      group.className = [
        'timeline-controls__group',
        isTime ? 'timeline-controls__group--time' : '',
        groupType ? `timeline-controls__group--${groupType}` : '',
      ].filter(Boolean).join(' ');

      appendDirectionButton({
        group,
        entry: previous,
        direction: 'prev',
        label,
        cameraSlug,
        navMode,
      });
      appendGroupLabel({
        group,
        state,
        label,
        isTime,
        isDay,
        timePicker,
        cameraSlug,
        navMode,
      });
      appendDirectionButton({
        group,
        entry: next,
        direction: 'next',
        label,
        cameraSlug,
        navMode,
      });
      groupsWrap.appendChild(group);
    };

    const renderControls = (state, cameraSlug) => {
      const container = getLiveRoot()?.querySelector('[data-timeline-container]');
      if (!container) return;

      // Menü und Kameraname werden serverseitig gerendert und bleiben beim
      // API-Rebuild erhalten. Alle zeitbezogenen Gruppen entstehen neu.
      const preservedMenu = container.querySelector('.timeline-controls__group--menus');
      const preservedCameraName = container.querySelector('.timeline-controls__group--camera-name');
      preservedMenu?.remove();
      preservedCameraName?.remove();
      container.replaceChildren();

      const groupsWrap = document.createElement('div');
      groupsWrap.className = 'timeline-controls__groups';
      if (preservedMenu) groupsWrap.appendChild(preservedMenu);
      if (preservedCameraName) groupsWrap.appendChild(preservedCameraName);

      const groupDefinitions = [
        [state.labels.year, state.yearPrev, state.yearNext, 'year', false, false, null, 'year'],
        [state.labels.month, state.monthPrev, state.monthNext, 'month', false, false, null, 'month'],
        [state.labels.day, state.dayPrev, state.dayNext, 'day', false, true, null, 'day'],
        [state.labels.hour, state.hourPrev, state.hourNext, 'hour', false, false, null, 'hour'],
        [state.labels.time, state.prev, state.next, 'time', true, false, state.timePicker, 'time'],
      ];
      groupDefinitions.forEach(([
        label,
        previous,
        next,
        groupType,
        isTime,
        isDay,
        timePicker,
        navMode,
      ]) => appendNavigationGroup({
        groupsWrap,
        state,
        cameraSlug,
        label,
        previous,
        next,
        groupType,
        isTime,
        isDay,
        timePicker,
        navMode,
      }));

      // The testcam's video return must keep the current-photo route even
      // when an API response rebuilds the server-rendered controls.
      const liveRoot = getLiveRoot();
      const latestUrl = liveRoot?.dataset.streamPreference === 'usb'
        ? (liveRoot.dataset.liveLink || state.latestUrl)
        : state.latestUrl;
      if (latestUrl) {
        const latestGroup = document.createElement('div');
        latestGroup.className = 'timeline-controls__group timeline-controls__group--latest';
        const latestLink = document.createElement('a');
        latestLink.className = 'timeline-controls__latest';
        latestLink.href = latestUrl;
        latestLink.textContent = 'Aktuell';
        latestGroup.appendChild(latestLink);
        groupsWrap.appendChild(latestGroup);
      }

      container.appendChild(groupsWrap);
      container.dataset.apiControls = '1';
      // Die neuen Picker-Elemente benötigen ihre bestehenden Bedienlistener.
      initTimelinePickers();
    };

    // -----------------------------------------------------------------------
    // 2. Seitliche Hotspots
    // -----------------------------------------------------------------------

    const appendHotspot = ({ wrap, entry, side, label, navMode, cameraSlug }) => {
      if (!entry) return;
      const hotspot = document.createElement('div');
      hotspot.className = `timeline-hotspot timeline-hotspot--${side}`;
      hotspot.dataset.timelineHotspot = side;
      const url = buildEntryUrl(entry, cameraSlug, navMode);
      if (url) hotspot.dataset.url = url;
      if (entry.timestamp) hotspot.dataset.targetKey = String(entry.timestamp);
      hotspot.setAttribute('role', 'button');
      hotspot.setAttribute('tabindex', '0');
      hotspot.setAttribute('aria-label', label);

      const icon = document.createElement('span');
      icon.className = 'timeline-hotspot__icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = side === 'left' ? '‹' : '›';
      hotspot.appendChild(icon);
      wrap.appendChild(hotspot);
    };

    const renderHotspots = (state, cameraSlug) => {
      const liveRoot = getLiveRoot();
      const wrap = liveRoot?.querySelector('[data-timeline-hotspots]');
      if (!liveRoot || !wrap) return;
      wrap.replaceChildren();

      const modeMap = {
        year: [state.yearPrev, state.yearNext, 'Vorheriges Jahr anzeigen', 'Nächstes Jahr anzeigen'],
        month: [state.monthPrev, state.monthNext, 'Vorherigen Monat anzeigen', 'Nächsten Monat anzeigen'],
        day: [state.dayPrev, state.dayNext, 'Vorherigen Tag anzeigen', 'Nächsten Tag anzeigen'],
        hour: [state.hourPrev, state.hourNext, 'Vorherige Stunde anzeigen', 'Nächste Stunde anzeigen'],
        time: [state.prev, state.next, 'Vorheriges Bild anzeigen', 'Nächstes Bild anzeigen'],
        bestof: [state.prev, state.next, 'Vorheriges Bild anzeigen', 'Nächstes Bild anzeigen'],
      };
      const navMode = resolveNavMode();
      const [previous, next, previousLabel, nextLabel] = modeMap[navMode] || modeMap.time;
      appendHotspot({ wrap, entry: previous, side: 'left', label: previousLabel, navMode, cameraSlug });
      appendHotspot({ wrap, entry: next, side: 'right', label: nextLabel, navMode, cameraSlug });
      updateLiveModeBadge(navMode);

      // Alte beziehungsweise serverseitig aufgebaute Ansichten besitzen unter
      // Umständen keine API-Nachbarn, aber weiterhin direkte Fallback-URLs.
      if (wrap.children.length === 0) {
        const previousUrl = liveRoot.dataset.prevUrl || '';
        const nextUrl = liveRoot.dataset.nextUrl || '';
        appendHotspot({
          wrap,
          entry: previousUrl ? { url: previousUrl } : null,
          side: 'left',
          label: 'Vorheriges Bild anzeigen',
          navMode,
          cameraSlug,
        });
        appendHotspot({
          wrap,
          entry: nextUrl ? { url: nextUrl } : null,
          side: 'right',
          label: 'Nächstes Bild anzeigen',
          navMode,
          cameraSlug,
        });
      }
    };

    // -----------------------------------------------------------------------
    // 3. Fertiges Präsentationsmodell anwenden
    // -----------------------------------------------------------------------

    const renderEmptyState = (container) => {
      if (!container) return;
      const group = document.createElement('div');
      group.className = 'timeline-controls__group';
      const label = document.createElement('div');
      label.className = 'timeline-controls__label';
      label.textContent = 'Keine Timeline-Daten';
      group.appendChild(label);
      container.replaceChildren(group);
    };

    const applyPresentation = ({ data, context, selection, state }) => {
      const liveRoot = getLiveRoot();
      if (!liveRoot) return;
      const container = liveRoot.querySelector('[data-timeline-container]');
      if (!selection || !state) {
        renderEmptyState(container);
        return;
      }

      // Serverseitige Jahres-/Monats-/Tages-/Stundenpicker bleiben erhalten.
      // Erst wenn keine vorhanden sind oder bereits API-Gruppen laufen, baut
      // dieses Modul die Navigation vollständig aus dem API-Modell neu.
      const hasServerPickers = container?.querySelector(
        '[data-year-picker-panel],[data-month-picker-panel],[data-day-picker-panel],[data-hour-picker-panel]',
      );
      const allowRenderControls = container
        ? (container.dataset.apiControls === '1' || !hasServerPickers)
        : false;
      if (allowRenderControls) {
        renderControls(state, context.cameraSlug);
      }

      renderHotspots(state, context.cameraSlug);
      const hasBestofParam = context.urlParams?.has('bestof');
      const allowOverlayUpdate = !hasBestofParam
        || (selection.selected
          && context.currentTs
          && selection.selected.timestamp === context.currentTs);
      if (allowOverlayUpdate) {
        applyEntryOverlay(selection.selected || null);
      }
      if (Array.isArray(data.labels)) {
        updateLiveLabels(data.labels);
      }
      prefetchAdjacentSnapshots();
    };

    // -----------------------------------------------------------------------
    // 4. Hauptbild laden und Timeout sichtbar abschließen
    // -----------------------------------------------------------------------

    const finishImageLoading = (detail = {}) => {
      if (loadingFailTimer !== null) {
        window.clearTimeout(loadingFailTimer);
        loadingFailTimer = null;
      }
      getLiveRoot()?.classList.remove('is-loading');
      window.cameraImageReady = true;
      window.dispatchEvent(new CustomEvent('camera:image-ready', { detail }));
    };

    const startImageLoading = () => {
      window.cameraImageReady = false;
      getLiveRoot()?.classList.add('is-loading');
      if (loadingFailTimer !== null) {
        window.clearTimeout(loadingFailTimer);
      }
      loadingFailTimer = window.setTimeout(() => {
        loadingFailTimer = null;
        finishImageLoading({ timeout: true });
      }, IMAGE_LOADING_TIMEOUT_MS);
    };

    // -----------------------------------------------------------------------
    // 5. Öffentliche, bewusst kleine Controller-Schnittstelle
    // -----------------------------------------------------------------------

    return Object.freeze({
      applyPresentation,
      startImageLoading,
      finishImageLoading,
    });
  };

  window.WebcamCameraTimelineView = Object.freeze({ create });
})();
