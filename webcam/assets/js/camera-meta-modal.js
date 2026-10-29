(() => {
  'use strict';

  /**
   * Gemeinsamer Metadaten-Dialog der Kameraansicht.
   *
   * EXIF-Text, Wetterdiagramme, Umgebungskarte und Bildvergleich benutzen
   * bewusst dasselbe Modal. Dieses Modul koordiniert deren Lebenszyklus:
   *
   * 1. Dialoginhalt und passenden Darstellungsmodus setzen.
   * 2. Fokus in den Dialog verschieben und die Seite gegen Scrollen sperren.
   * 3. Asynchrone Wetter-/Kartenantworten nach einem Schließen verwerfen.
   * 4. Beim Schließen Karte, Timeline-Picker und Beschriftungen aufräumen.
   * 5. Menüaktionen dokumentweit delegieren, damit sie auch nach einem
   *    partiellen Austausch des Kamera-Inhalts weiterhin funktionieren.
   *
   * Die fachlichen Komponenten bleiben getrennt: camera-weather.js liefert
   * Wetterinhalt, camera-surroundings.js die Karte und
   * camera-image-compare.js die Vergleichsoberfläche. Der Controller kennt
   * weder Serverpfade noch Timeline- oder Storage-Details.
   */
  const create = ({
    getLiveRoot,
    closeTransientTimelineUi,
    initTimelinePickers,
    initLiveLabels,
    getWeatherController,
    getSurroundingsController,
    destroySurroundings,
    getImageCompareController,
  } = {}) => {
    const requiredFunctions = [
      getLiveRoot,
      closeTransientTimelineUi,
      initTimelinePickers,
      initLiveLabels,
      getWeatherController,
      getSurroundingsController,
      destroySurroundings,
      getImageCompareController,
    ];
    if (requiredFunctions.some((candidate) => typeof candidate !== 'function')) {
      throw new Error('Metadaten-Modal: notwendige Seitenadapter fehlen.');
    }

    // Das Modal liegt außerhalb des partiell ausgetauschten #camera-live-root.
    // Seine DOM-Referenzen bleiben daher während der gesamten Dokumentlaufzeit
    // gültig. Kameraabhängige Daten werden dagegen immer über getLiveRoot()
    // aus dem aktuell sichtbaren Inhalt gelesen.
    const modal = document.querySelector('[data-meta-modal]');
    const dialog = modal?.querySelector('.meta-modal__dialog') || null;
    const titleTarget = modal?.querySelector('[data-meta-title]') || null;
    const contentTarget = modal?.querySelector('[data-meta-content]') || null;

    let initialized = false;
    let requestToken = 0;

    /** Erzeugt einen sicheren Klartextblock; fremder Text wird nie als HTML interpretiert. */
    const createTextBlock = (text) => {
      const pre = document.createElement('pre');
      pre.className = 'meta-modal__text';
      pre.textContent = text || 'Keine Daten vorhanden.';
      return pre;
    };

    /**
     * Bereitet die zeilenorientierten EXIF-Sidecars als lesbare Tabelle auf.
     *
     * Aktuelle Sidecars verwenden „Name | Wert“. Einige Altbestände enthalten
     * stattdessen „Name: Wert“. Unbekannte Zeilen bleiben als vollständige
     * Notiz erhalten; nichts wird als HTML interpretiert. Damit verbessert die
     * Darstellung nur die Lesbarkeit und verändert keine gespeicherten Daten.
     */
    const createExifTable = (text) => {
      const lines = String(text || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
      if (!lines.length) {
        return createTextBlock('Keine Daten vorhanden.');
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'meta-modal__exif';
      const table = document.createElement('table');
      table.className = 'meta-modal__exif-table';
      const body = document.createElement('tbody');

      lines.forEach((line) => {
        // Der senkrechte Strich ist eindeutig. Beim Doppelpunkt verlangt die
        // Regel Leerraum danach, damit Uhrzeiten oder URLs nicht zerlegt werden.
        const pair = line.match(/^([^|]+?)\s*\|\s*(.+)$/)
          || line.match(/^([^:]{1,80}?):\s+(.+)$/);
        const row = document.createElement('tr');
        if (!pair) {
          row.className = 'meta-modal__exif-note';
          const note = document.createElement('td');
          note.colSpan = 2;
          note.textContent = line;
          row.appendChild(note);
        } else {
          const label = document.createElement('th');
          label.scope = 'row';
          label.textContent = pair[1].trim();
          const value = document.createElement('td');
          value.textContent = pair[2].trim();
          row.append(label, value);
        }
        body.appendChild(row);
      });

      table.appendChild(body);
      wrapper.appendChild(table);
      return wrapper;
    };

    // ---------------------------------------------------------------------
    // Grundlegender Dialog-Lebenszyklus
    // ---------------------------------------------------------------------

    const moveFocusIntoModal = () => {
      const target = modal?.querySelector('[data-meta-close]') || dialog;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target === dialog && !target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
      }
      target.focus({ preventScroll: true });
    };

    const restoreFocusAfterModal = () => {
      const target = document.querySelector('[data-timeline-container]') || getLiveRoot();
      if (!(target instanceof HTMLElement)) {
        if (document.activeElement instanceof HTMLElement) {
          document.activeElement.blur();
        }
        return;
      }
      const hadTabindex = target.hasAttribute('tabindex');
      if (!hadTabindex) {
        target.setAttribute('tabindex', '-1');
      }
      target.focus({ preventScroll: true });
      if (!hadTabindex) {
        requestAnimationFrame(() => {
          if (target.getAttribute('tabindex') === '-1') {
            target.removeAttribute('tabindex');
          }
        });
      }
    };

    const setPageLock = (locked) => {
      document.documentElement.classList.toggle('meta-modal-open', locked);
      document.body.classList.toggle('meta-modal-open', locked);
    };

    /**
     * Zeigt einen bereits als DOM-Knoten aufgebauten Inhalt.
     * `mode` schaltet nur die bekannten Breiten-/Layoutklassen um.
     */
    const render = (title, contentNode, mode = 'text') => {
      if (!modal || !titleTarget || !contentTarget) {
        return;
      }
      closeTransientTimelineUi();
      titleTarget.textContent = title;
      contentTarget.classList.toggle('meta-modal__content--weather', mode === 'weather');
      contentTarget.classList.toggle('meta-modal__content--map', mode === 'map');
      contentTarget.classList.toggle('meta-modal__content--compare', mode === 'compare');
      contentTarget.classList.toggle('meta-modal__content--exif', mode === 'exif');
      if (dialog) {
        dialog.classList.toggle('meta-modal__dialog--weather', mode === 'weather');
        dialog.classList.toggle('meta-modal__dialog--map', mode === 'map');
        dialog.classList.toggle('meta-modal__dialog--compare', mode === 'compare');
        dialog.classList.toggle('meta-modal__dialog--exif', mode === 'exif');
      }
      contentTarget.replaceChildren(contentNode);
      modal.hidden = false;
      modal.classList.add('is-open');
      setPageLock(true);
      moveFocusIntoModal();
    };

    const close = () => {
      if (!modal) {
        return;
      }
      // Jede noch laufende Wetter-/Kartenantwort gehört ab jetzt zu einem
      // veralteten Dialogzustand und darf den nächsten Inhalt nicht ersetzen.
      requestToken += 1;
      destroySurroundings();
      modal.classList.remove('is-open');
      modal.hidden = true;
      setPageLock(false);
      closeTransientTimelineUi();
      initTimelinePickers();
      initLiveLabels();
      restoreFocusAfterModal();
    };

    const openText = (title, text) => {
      requestToken += 1;
      destroySurroundings();
      render(title, createTextBlock(text || 'Keine Daten vorhanden.'));
    };

    // ---------------------------------------------------------------------
    // Bildvergleich
    // ---------------------------------------------------------------------

    const updateImageCompareMenuState = () => {
      const stored = getImageCompareController().readStoredSnapshot();
      document.querySelectorAll('[data-clear-compare-image]').forEach((link) => {
        link.hidden = !stored;
      });
    };

    const rememberCurrentCompareSnapshot = ({ showConfirmation = true } = {}) => {
      requestToken += 1;
      destroySurroundings();
      const compare = getImageCompareController();
      const currentSnapshot = compare.buildCurrentSnapshot();
      if (!currentSnapshot) {
        render(
          'Bildvergleich',
          compare.buildNotice('Auf dieser Ansicht ist kein Snapshot verfügbar, den du als Vergleichsbild merken könntest.'),
          'compare'
        );
        return false;
      }
      if (!compare.storeSnapshot(currentSnapshot)) {
        render(
          'Bildvergleich',
          compare.buildNotice('Das Vergleichsbild konnte in dieser Sitzung nicht gespeichert werden.'),
          'compare'
        );
        return false;
      }
      updateImageCompareMenuState();
      if (showConfirmation) {
        const openButton = compare.createActionButton('Bildvergleich öffnen');
        openButton.addEventListener('click', openImageCompare);
        render(
          'Bildvergleich',
          compare.buildNotice(
            `Vergleichsbild vom ${currentSnapshot.capturedAt} wurde gemerkt. Wähle jetzt einen anderen Zeitpunkt und öffne danach den Bildvergleich.`,
            [openButton]
          ),
          'compare'
        );
      }
      return true;
    };

    const openImageCompare = () => {
      requestToken += 1;
      destroySurroundings();
      const compare = getImageCompareController();
      const currentSnapshot = compare.buildCurrentSnapshot();
      const storedSnapshot = compare.readStoredSnapshot();
      if (!currentSnapshot) {
        const actions = [];
        if (storedSnapshot) {
          const clearButton = compare.createActionButton('Vergleichsbild löschen');
          clearButton.addEventListener('click', () => {
            compare.clearStoredSnapshot();
            updateImageCompareMenuState();
            openImageCompare();
          });
          actions.push(clearButton);
        }
        render(
          'Bildvergleich',
          compare.buildNotice('Auf dieser Ansicht ist kein Snapshot für den Bildvergleich verfügbar.', actions),
          'compare'
        );
        return;
      }
      if (!storedSnapshot) {
        const rememberButton = compare.createActionButton('Aktuelles Bild als Vergleich merken');
        rememberButton.addEventListener('click', rememberCurrentCompareSnapshot);
        render(
          'Bildvergleich',
          compare.buildNotice('Es ist noch kein Vergleichsbild gemerkt. Öffne zuerst einen Snapshot, den du als Referenz behalten willst.', [rememberButton]),
          'compare'
        );
        return;
      }
      if (storedSnapshot.slug !== currentSnapshot.slug) {
        const rememberButton = compare.createActionButton('Aktuelles Bild neu merken');
        rememberButton.addEventListener('click', rememberCurrentCompareSnapshot);
        const clearButton = compare.createActionButton('Vergleichsbild löschen');
        clearButton.addEventListener('click', () => {
          compare.clearStoredSnapshot();
          updateImageCompareMenuState();
          openImageCompare();
        });
        render(
          'Bildvergleich',
          compare.buildNotice(
            `Das gemerkte Vergleichsbild gehört zur Kamera ${storedSnapshot.slug}. Für ${currentSnapshot.slug} solltest du zuerst ein neues Vergleichsbild merken.`,
            [rememberButton, clearButton]
          ),
          'compare'
        );
        return;
      }
      const content = compare.buildContent(storedSnapshot, currentSnapshot, {
        onRememberCurrent: rememberCurrentCompareSnapshot,
        onClearStored: () => {
          compare.clearStoredSnapshot();
          updateImageCompareMenuState();
          openImageCompare();
        },
      });
      render('Bildvergleich', content, 'compare');
    };

    // ---------------------------------------------------------------------
    // Asynchrone Wetter- und Karteninhalte
    // ---------------------------------------------------------------------

    const openWeather = async () => {
      const token = ++requestToken;
      destroySurroundings();
      render('Wetterdaten', createTextBlock('Lade Wetterdaten …'), 'weather');
      let content = null;
      try {
        content = await getWeatherController().loadContent();
      } catch {
        // Auch der Fehlerfallback gehört zum ursprünglichen Öffnen. Wurde der
        // Dialog inzwischen geschlossen oder anderweitig geöffnet, bleibt der
        // neuere Zustand unangetastet.
        if (token === requestToken) {
          render(
            'Wetterdaten',
            createTextBlock(getLiveRoot()?.dataset.weatherText || 'Wetterdaten konnten nicht geladen werden.')
          );
        }
        return;
      }
      if (token !== requestToken) {
        return;
      }
      if (!content) {
        render(
          'Wetterdaten',
          createTextBlock(getLiveRoot()?.dataset.weatherText || 'Keine Wetterdaten vorhanden.')
        );
        return;
      }
      render('Wetterdaten', content, 'weather');
    };

    const openSurroundings = async () => {
      const token = ++requestToken;
      destroySurroundings();
      let controller = null;
      try {
        controller = getSurroundingsController();
      } catch {
        render('Umgebungskarte', createTextBlock('Kartenansicht ist auf dieser Seite derzeit nicht verfügbar.'));
        return;
      }
      if (!controller.isAvailable()) {
        render('Umgebungskarte', createTextBlock('Kartenansicht ist auf dieser Seite derzeit nicht verfügbar.'));
        return;
      }
      const overviewContext = controller.getContext();
      render(
        'Umgebungskarte',
        createTextBlock(
          overviewContext?.label
            ? `Karte zum gewählten Zeitpunkt ${overviewContext.label} wird geladen …`
            : 'Karte wird geladen …'
        ),
        'map'
      );
      try {
        const prepared = await controller.load(overviewContext);
        if (token !== requestToken) {
          return;
        }
        if (!prepared) {
          render('Umgebungskarte', createTextBlock('Keine Kameras mit gültigen Standortdaten gefunden.'));
          return;
        }
        render('Umgebungskarte', prepared.container, 'map');
        if (token !== requestToken) {
          return;
        }
        if (!controller.mount(prepared)) {
          render('Umgebungskarte', createTextBlock('Karte konnte nicht initialisiert werden.'));
        }
      } catch {
        if (token === requestToken) {
          render('Umgebungskarte', createTextBlock('Karte konnte nicht geladen werden.'));
        }
      }
    };

    // ---------------------------------------------------------------------
    // Einmalige Ereignisbindung
    // ---------------------------------------------------------------------

    const handleDocumentClick = (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }
      const compareMenuToggle = target.closest('[data-compare-menu-toggle]');
      if (compareMenuToggle) {
        event.preventDefault();
        const compareMenu = compareMenuToggle.closest('[data-compare-menu]');
        const compareMenuItems = compareMenu?.querySelector('[data-compare-menu-items]');
        const isOpen = compareMenu?.classList.toggle('is-open') ?? false;
        compareMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (compareMenuItems) {
          compareMenuItems.hidden = !isOpen;
        }
        return;
      }
      if (target.closest('[data-open-exif]')) {
        event.preventDefault();
        requestToken += 1;
        destroySurroundings();
        render(
          'EXIF-Daten',
          createExifTable(getLiveRoot()?.dataset.exifText || ''),
          'exif'
        );
        return;
      }
      if (target.closest('[data-open-weather]')) {
        event.preventDefault();
        openWeather();
        return;
      }
      if (target.closest('[data-open-surroundings-map]')) {
        event.preventDefault();
        const token = requestToken + 1;
        openSurroundings().catch(() => {
          if (token === requestToken) {
            render('Umgebungskarte', createTextBlock('Karte konnte nicht geladen werden.'));
          }
        });
        return;
      }
      if (target.closest('[data-remember-compare-image]')) {
        event.preventDefault();
        rememberCurrentCompareSnapshot();
        return;
      }
      if (target.closest('[data-open-image-compare]')) {
        event.preventDefault();
        openImageCompare();
        return;
      }
      if (target.closest('[data-clear-compare-image]')) {
        event.preventDefault();
        requestToken += 1;
        destroySurroundings();
        const compare = getImageCompareController();
        compare.clearStoredSnapshot();
        updateImageCompareMenuState();
        render(
          'Bildvergleich',
          compare.buildNotice('Das gemerkte Vergleichsbild wurde gelöscht.'),
          'compare'
        );
      }
    };

    const init = () => {
      if (initialized || !modal || !titleTarget || !contentTarget) {
        return;
      }
      initialized = true;
      modal.dataset.initialized = '1';
      // Ein delegierter Handler reicht für Backdrop und Schließen-Schaltfläche
      // aus. So wird close() pro Klick genau einmal ausgeführt.
      modal.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target === modal || target?.hasAttribute('data-meta-close')) {
          close();
        }
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          close();
        }
      });
      document.addEventListener('click', handleDocumentClick);
      window.addEventListener('camera:image-ready', updateImageCompareMenuState);
      updateImageCompareMenuState();
    };

    return Object.freeze({
      init,
      close,
    });
  };

  window.WebcamCameraMetaModal = Object.freeze({ create });
})();
