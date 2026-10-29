(() => {
  'use strict';

  // -------------------------------------------------------------------------
  // Bildvergleich der Kameraansicht
  // -------------------------------------------------------------------------
  //
  // Dieses Modul besitzt den in sich geschlossenen Teil des Bildvergleichs:
  //
  // 1. Einen gemerkten Snapshot sicher in der aktuellen Browser-Sitzung halten.
  // 2. Den momentan sichtbaren Snapshot aus der Kameraansicht beschreiben.
  // 3. Die Vergleichsoberfläche mit Trennkante, Zoom und Verschieben bauen.
  //
  // camera.js bleibt der Koordinator der ganzen Seite. Dort liegen weiterhin
  // das gemeinsame Modal, dokumentrelative URL-Auflösung und Menüereignisse.
  // Diese Trennung ist wichtig: EXIF, Wetter, Umgebungskarte und Bildvergleich
  // benutzen dasselbe Modal und dürfen nicht vier konkurrierende Dialoge öffnen.
  //
  // Das Modul erzeugt DOM-Knoten ausschließlich mit createElement/textContent.
  // Snapshot-Texte aus Storage oder data-Attributen werden nie als HTML
  // interpretiert. Dadurch bleibt auch ein manipuliertes sessionStorage-Objekt
  // ohne Auswirkung auf die HTML-Struktur.

  const STORAGE_KEY = 'webcam:image-compare:v1';

  /**
   * Erstellt eine Instanz für das aktuelle Dokument.
   *
   * Die kleinen Funktionsabhängigkeiten werden von camera.js übergeben. So
   * kennt dieses Modul weder Deployment-Prefix noch Pretty-Route und kann auch
   * nach partieller Navigation immer den aktuellen #camera-live-root abfragen.
   */
  const create = ({
    getLiveRoot,
    parseIntSafe,
    formatDateTime,
    resolveSnapshotImageUrl,
  } = {}) => {
    if (typeof getLiveRoot !== 'function'
      || typeof parseIntSafe !== 'function'
      || typeof formatDateTime !== 'function'
      || typeof resolveSnapshotImageUrl !== 'function') {
      throw new Error('Bildvergleich: notwendige Seitenadapter fehlen.');
    }

    // -----------------------------------------------------------------------
    // Snapshot-Grenze und Sitzungs-Speicher
    // -----------------------------------------------------------------------

    /**
     * Reduziert unbekannte Storage-Daten auf die wenigen erlaubten Felder.
     * Ungültige Kamera- oder Bildangaben werden fail-closed verworfen.
     */
    const sanitizeSnapshot = (raw) => {
      if (!raw || typeof raw !== 'object') {
        return null;
      }
      const slug = typeof raw.slug === 'string' ? raw.slug.trim() : '';
      const imageUrl = resolveSnapshotImageUrl(
        raw.imageUrl || raw.url || '',
        raw.baseUrl || window.location.href
      );
      if (!slug || !imageUrl) {
        return null;
      }
      const timestamp = parseIntSafe(raw.timestamp);
      const capturedAt = typeof raw.capturedAt === 'string' ? raw.capturedAt : '';
      return {
        slug,
        imageUrl,
        timestamp: Number.isFinite(timestamp) ? timestamp : null,
        capturedAt: capturedAt
          || (Number.isFinite(timestamp)
            ? formatDateTime(new Date(timestamp * 1000).toISOString())
            : '—'),
        rememberedAt: typeof raw.rememberedAt === 'string' ? raw.rememberedAt : '',
      };
    };

    /** Liest das eine Vergleichsbild der aktuellen Browser-Sitzung. */
    const readStoredSnapshot = () => {
      if (typeof window === 'undefined' || !window.sessionStorage) {
        return null;
      }
      try {
        return sanitizeSnapshot(JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || 'null'));
      } catch {
        // Defektes oder gesperrtes sessionStorage darf die Kameraansicht nicht
        // abbrechen. Der Nutzer sieht dann einfach den Zustand „nicht gemerkt“.
        return null;
      }
    };

    /**
     * Speichert ausschließlich einen zuvor normalisierten Snapshot.
     * false signalisiert dem Modal sichtbar, dass der Browser nicht schreiben
     * konnte (z.B. Datenschutzmodus oder ausgeschöpftes Storage).
     */
    const storeSnapshot = (snapshot) => {
      const sanitized = sanitizeSnapshot(snapshot);
      if (!sanitized || typeof window === 'undefined' || !window.sessionStorage) {
        return false;
      }
      try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
          ...sanitized,
          rememberedAt: sanitized.rememberedAt || new Date().toISOString(),
        }));
        return true;
      } catch {
        return false;
      }
    };

    /** Entfernt nur den eigenen Storage-Schlüssel, niemals fremden Zustand. */
    const clearStoredSnapshot = () => {
      if (typeof window === 'undefined' || !window.sessionStorage) {
        return;
      }
      try {
        window.sessionStorage.removeItem(STORAGE_KEY);
      } catch {
        // Ein nicht beschreibbarer Browser-Speicher ist kein Seitenfehler.
      }
    };

    /**
     * Beschreibt den aktuell sichtbaren Snapshot.
     *
     * data-full-src gewinnt, weil der Vergleich nicht den kleinen Placeholder
     * vergrößern soll. Die Zeit kommt aus der serverseitig gewählten Aufnahme;
     * nur wenn sie dort fehlt, dient historyRefTs als letzter Fallback.
     */
    const buildCurrentSnapshot = () => {
      const root = getLiveRoot();
      if (!root) {
        return null;
      }
      const imageEl = root.querySelector('[data-live-image]');
      if (!imageEl) {
        return null;
      }
      const slug = typeof root.dataset.cameraSlug === 'string'
        ? root.dataset.cameraSlug.trim()
        : '';
      const imageUrl = resolveSnapshotImageUrl(
        imageEl.getAttribute('data-full-src')
        || imageEl.currentSrc
        || imageEl.getAttribute('src')
        || '',
        window.location.href
      );
      if (!slug || !imageUrl) {
        return null;
      }
      const timestamp = parseIntSafe(root.dataset.selectedTs)
        || parseIntSafe(root.querySelector('[data-current-snapshot]')?.getAttribute('data-current-snapshot'))
        || parseIntSafe(root.dataset.historyRefTs)
        || null;
      return sanitizeSnapshot({
        slug,
        imageUrl,
        timestamp,
        capturedAt: Number.isFinite(timestamp)
          ? formatDateTime(new Date(timestamp * 1000).toISOString())
          : '—',
      });
    };

    // -----------------------------------------------------------------------
    // Kleine DOM-Bausteine
    // -----------------------------------------------------------------------

    const createActionButton = (label, dataset = {}, className = 'button ghost') => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = className;
      button.textContent = label;
      Object.entries(dataset).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
          button.dataset[key] = String(value);
        }
      });
      return button;
    };

    /** Baut leere, Fehler- und Bestätigungszustände des Vergleichsmodals. */
    const buildNotice = (message, actions = []) => {
      const container = document.createElement('div');
      container.className = 'image-compare';

      const intro = document.createElement('p');
      intro.className = 'image-compare__intro';
      intro.textContent = message;
      container.appendChild(intro);

      if (actions.length) {
        const row = document.createElement('div');
        row.className = 'image-compare__actions';
        actions.forEach((action) => row.appendChild(action));
        container.appendChild(row);
      }

      return container;
    };

    const buildMetaCard = (label, snapshot) => {
      const card = document.createElement('div');
      card.className = 'image-compare__meta-card';

      const kicker = document.createElement('div');
      kicker.className = 'image-compare__meta-label';
      kicker.textContent = label;

      const time = document.createElement('strong');
      time.className = 'image-compare__meta-time';
      time.textContent = snapshot?.capturedAt || '—';

      const slug = document.createElement('span');
      slug.className = 'image-compare__meta-slug';
      slug.textContent = snapshot?.slug ? `Kamera: ${snapshot.slug}` : '—';

      card.append(kicker, time, slug);
      return card;
    };

    // -----------------------------------------------------------------------
    // Interaktive Split-/Zoom-Ansicht
    // -----------------------------------------------------------------------

    /**
     * Baut den vollständigen Vergleich als eigenständigen DOM-Unterbaum.
     *
     * Beide Bilder verwenden immer denselben Transform. Dadurch bleiben linke
     * und rechte Aufnahme auch bei Zoom und Verschieben pixelgenau ausgerichtet.
     * Callbacks verändern den äußeren Sitzungs-/Modalzustand; dieses Modul
     * entscheidet nicht selbst, welches Modal danach geöffnet wird.
     */
    const buildContent = (
      referenceSnapshot,
      currentSnapshot,
      { onRememberCurrent, onClearStored } = {}
    ) => {
      const container = document.createElement('div');
      container.className = 'image-compare';

      const intro = document.createElement('p');
      intro.className = 'image-compare__intro';
      const isSameSnapshot = (
        (referenceSnapshot?.timestamp
          && currentSnapshot?.timestamp
          && referenceSnapshot.timestamp === currentSnapshot.timestamp)
        || (referenceSnapshot?.imageUrl
          && currentSnapshot?.imageUrl
          && referenceSnapshot.imageUrl === currentSnapshot.imageUrl)
      );
      intro.textContent = isSameSnapshot
        ? 'Aktuell werden zwei identische Snapshots verglichen. Wähle einen anderen Zeitpunkt, um Unterschiede sichtbar zu machen.'
        : 'Vergleichsbild links, aktueller Snapshot rechts. Ziehe die weiße Trennlinie am Kreis; Mausrad zoomt, Ziehen im Bild verschiebt den Ausschnitt.';
      container.appendChild(intro);

      const actions = document.createElement('div');
      actions.className = 'image-compare__actions';
      const swapButton = createActionButton('Seiten tauschen');
      const rememberButton = createActionButton('Aktuelles Bild als Vergleich merken');
      const clearButton = createActionButton('Vergleichsbild löschen');
      actions.append(swapButton, rememberButton, clearButton);
      container.appendChild(actions);

      // Die Stage enthält zwei deckungsgleiche Ebenen. CSS schneidet die obere
      // Ebene an --image-compare-split ab; der Divider markiert dieselbe Stelle.
      const stage = document.createElement('div');
      stage.className = 'image-compare__stage';
      stage.style.setProperty('--image-compare-split', '50%');
      stage.tabIndex = 0;
      stage.setAttribute('aria-label', 'Bildvergleich mit Zoom und Bildausschnitt-Verschiebung');

      const basePane = document.createElement('div');
      basePane.className = 'image-compare__pane image-compare__pane--base';
      const baseImage = document.createElement('img');
      baseImage.className = 'image-compare__image';
      baseImage.alt = currentSnapshot?.capturedAt
        ? `Snapshot ${currentSnapshot.capturedAt}`
        : 'Aktueller Snapshot';

      const comparePane = document.createElement('div');
      comparePane.className = 'image-compare__pane image-compare__pane--compare';
      const compareImage = document.createElement('img');
      compareImage.className = 'image-compare__image';
      compareImage.alt = referenceSnapshot?.capturedAt
        ? `Vergleichsbild ${referenceSnapshot.capturedAt}`
        : 'Vergleichsbild';

      // Der sichtbare Trenner ist zugleich das Bedienelement. Ein Button mit
      // Slider-Rolle ist per Tab erreichbar, während CSS seine unsichtbare
      // Trefferfläche deutlich breiter als die zwei Pixel starke Linie macht.
      const divider = document.createElement('button');
      divider.type = 'button';
      divider.className = 'image-compare__divider';
      divider.setAttribute('role', 'slider');
      divider.setAttribute('aria-label', 'Trennlinie des Bildvergleichs');
      divider.setAttribute('aria-orientation', 'horizontal');
      divider.setAttribute('aria-valuemin', '0');
      divider.setAttribute('aria-valuemax', '100');
      divider.title = 'Trennlinie ziehen oder mit den Pfeiltasten verschieben';

      basePane.appendChild(baseImage);
      comparePane.appendChild(compareImage);

      // Die Zoomknöpfe liegen direkt auf dem Bild. Dadurch bleibt unter der
      // Stage kein separater Bedienbalken mehr stehen.
      const zoomControls = document.createElement('div');
      zoomControls.className = 'image-compare__zoom-controls';
      zoomControls.setAttribute('role', 'group');
      zoomControls.setAttribute('aria-label', 'Zoomsteuerung');
      const zoomOutButton = createActionButton('−', {}, 'button secondary button-small');
      const zoomResetButton = createActionButton('100%', {}, 'button secondary button-small');
      const zoomInButton = createActionButton('+', {}, 'button secondary button-small');
      const zoomLabel = document.createElement('span');
      zoomLabel.className = 'image-compare__zoom-label';
      zoomLabel.textContent = '1x';
      zoomControls.append(zoomOutButton, zoomResetButton, zoomInButton, zoomLabel);
      stage.append(basePane, comparePane, divider, zoomControls);
      container.appendChild(stage);

      const meta = document.createElement('div');
      meta.className = 'image-compare__meta';
      container.appendChild(meta);

      let leftSnapshot = referenceSnapshot;
      let rightSnapshot = currentSnapshot;
      const zoomMin = 1;
      const zoomMax = 4;
      const zoomStep = 0.25;
      let zoom = 1;
      let offsetX = 0;
      let offsetY = 0;
      let splitValue = 50;
      const drag = {
        active: false,
        pointerId: null,
        originX: 0,
        originY: 0,
        startX: 0,
        startY: 0,
      };
      const splitDrag = {
        active: false,
        pointerId: null,
      };

      const getStageSize = () => ({
        width: Math.max(1, stage.clientWidth || 0),
        height: Math.max(1, stage.clientHeight || 0),
      });

      /**
       * Ermittelt die eingepasste Bildfläche. Beide Aufnahmen können andere
       * Pixelmaße besitzen; die größere sichtbare Fläche bestimmt die zulässige
       * Verschiebung, damit der Nutzer keine komplett leere Stage ziehen kann.
       */
      const getFitMetrics = () => {
        const size = getStageSize();
        let maxWidth = size.width;
        let maxHeight = size.height;
        [baseImage, compareImage].forEach((img) => {
          const naturalWidth = img.naturalWidth || 0;
          const naturalHeight = img.naturalHeight || 0;
          if (!naturalWidth || !naturalHeight) {
            return;
          }
          const scale = Math.min(size.width / naturalWidth, size.height / naturalHeight);
          maxWidth = Math.max(maxWidth, naturalWidth * scale);
          maxHeight = Math.max(maxHeight, naturalHeight * scale);
        });
        return { width: maxWidth, height: maxHeight };
      };

      const clampOffsets = (nextX, nextY) => {
        const fit = getFitMetrics();
        const size = getStageSize();
        const maxX = Math.max(0, ((fit.width * zoom) - size.width) / 2);
        const maxY = Math.max(0, ((fit.height * zoom) - size.height) / 2);
        return {
          x: Math.max(-maxX, Math.min(maxX, nextX)),
          y: Math.max(-maxY, Math.min(maxY, nextY)),
        };
      };

      const updateZoomLabel = () => {
        zoomLabel.textContent = `${zoom.toFixed(2).replace(/\.00$/, '')}x`;
      };

      const applyTransform = () => {
        const clamped = clampOffsets(offsetX, offsetY);
        offsetX = clamped.x;
        offsetY = clamped.y;
        const transform = `translate(calc(-50% + ${offsetX}px), calc(-50% + ${offsetY}px)) scale(${zoom})`;
        baseImage.style.transform = transform;
        compareImage.style.transform = transform;
        stage.classList.toggle('is-zoomed', zoom > zoomMin + 0.001);
        stage.classList.toggle('is-dragging', drag.active);
        updateZoomLabel();
      };

      const setOffsets = (nextX, nextY) => {
        offsetX = nextX;
        offsetY = nextY;
        applyTransform();
      };

      /**
       * Beim Zoomen um den Mauszeiger wird der bisherige Fokuspunkt erhalten.
       * Ohne diese Umrechnung würde jede Stufe sichtbar zur Mitte springen.
       */
      const setZoom = (
        nextZoom,
        { focusX = 0, focusY = 0, preserveFocus = false } = {}
      ) => {
        const previousZoom = zoom;
        zoom = Math.max(zoomMin, Math.min(zoomMax, nextZoom));
        if (zoom <= zoomMin + 0.001) {
          offsetX = 0;
          offsetY = 0;
        } else if (preserveFocus && previousZoom > 0 && previousZoom !== zoom) {
          const ratio = zoom / previousZoom;
          offsetX = (offsetX * ratio) + (focusX * (1 - ratio));
          offsetY = (offsetY * ratio) + (focusY * (1 - ratio));
        }
        applyTransform();
      };

      const applySplit = (value) => {
        const numeric = Math.max(0, Math.min(100, parseIntSafe(value) ?? 50));
        splitValue = numeric;
        const percentage = `${numeric}%`;
        stage.style.setProperty('--image-compare-split', percentage);
        divider.style.left = percentage;
        divider.setAttribute('aria-valuenow', String(numeric));
        divider.setAttribute('aria-valuetext', `${numeric} Prozent Vergleichsbild sichtbar`);
      };

      /**
       * Rechnet eine Maus-/Touchposition in den Prozentwert der Trennlinie um.
       * Die Begrenzung in applySplit verhindert, dass der Griff die Bildfläche
       * bei schnellen Bewegungen oder einem Pointer-Up außerhalb verlässt.
       */
      const applySplitFromPointer = (clientX) => {
        const rect = stage.getBoundingClientRect();
        if (rect.width <= 0) {
          return;
        }
        applySplit(Math.round(((clientX - rect.left) / rect.width) * 100));
      };

      const applySnapshots = () => {
        compareImage.src = leftSnapshot.imageUrl;
        baseImage.src = rightSnapshot.imageUrl;
        compareImage.alt = leftSnapshot?.capturedAt
          ? `Snapshot ${leftSnapshot.capturedAt}`
          : 'Linkes Vergleichsbild';
        baseImage.alt = rightSnapshot?.capturedAt
          ? `Snapshot ${rightSnapshot.capturedAt}`
          : 'Rechtes Vergleichsbild';
        meta.replaceChildren(
          buildMetaCard('Linke Seite', leftSnapshot),
          buildMetaCard('Rechte Seite', rightSnapshot)
        );
        applyTransform();
      };

      // Der Griff besitzt seine eigene Pointer-Capture. stopPropagation ist
      // wichtig, weil dieselbe Stage bei aktivem Zoom ansonsten das Bild statt
      // der Trennkante verschieben würde.
      divider.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        splitDrag.active = true;
        splitDrag.pointerId = event.pointerId;
        divider.classList.add('is-dragging');
        if (typeof divider.setPointerCapture === 'function') {
          divider.setPointerCapture(event.pointerId);
        }
        applySplitFromPointer(event.clientX);
      });
      divider.addEventListener('pointermove', (event) => {
        if (!splitDrag.active || event.pointerId !== splitDrag.pointerId) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        applySplitFromPointer(event.clientX);
      });
      const stopSplitDrag = (event) => {
        if (!splitDrag.active || event.pointerId !== splitDrag.pointerId) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (event.type === 'pointerup') {
          applySplitFromPointer(event.clientX);
        }
        if (typeof divider.releasePointerCapture === 'function') {
          try {
            divider.releasePointerCapture(event.pointerId);
          } catch {
            // Beim Schließen des Modals kann die Capture bereits entfallen sein.
          }
        }
        splitDrag.active = false;
        splitDrag.pointerId = null;
        divider.classList.remove('is-dragging');
      };
      divider.addEventListener('pointerup', stopSplitDrag);
      divider.addEventListener('pointercancel', stopSplitDrag);
      divider.addEventListener('click', (event) => {
        // Unterdrückt die synthetische Button-Aktion nach einem Pointer-Drag.
        event.preventDefault();
        event.stopPropagation();
      });
      divider.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 5 : 1;
        let nextValue = null;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
          nextValue = splitValue - step;
        } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
          nextValue = splitValue + step;
        } else if (event.key === 'Home') {
          nextValue = 0;
        } else if (event.key === 'End') {
          nextValue = 100;
        }
        if (nextValue === null) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        applySplit(nextValue);
      });

      // Zoomknöpfe verändern nur den lokalen Vergleichszustand. Ihr Pointer-
      // Down darf bei vergrößertem Bild ebenfalls keinen Stage-Drag beginnen.
      zoomControls.addEventListener('pointerdown', (event) => event.stopPropagation());
      zoomOutButton.addEventListener('click', () => setZoom(zoom - zoomStep));
      zoomInButton.addEventListener('click', () => setZoom(zoom + zoomStep));
      zoomResetButton.addEventListener('click', () => {
        offsetX = 0;
        offsetY = 0;
        setZoom(zoomMin);
      });
      swapButton.addEventListener('click', () => {
        const previousLeft = leftSnapshot;
        leftSnapshot = rightSnapshot;
        rightSnapshot = previousLeft;
        applySnapshots();
        applySplit(splitValue);
      });
      rememberButton.addEventListener('click', () => {
        if (typeof onRememberCurrent === 'function') {
          onRememberCurrent();
        }
      });
      clearButton.addEventListener('click', () => {
        if (typeof onClearStored === 'function') {
          onClearStored();
        }
      });

      // Mausrad zoomt um den Zeiger. Shift+Mausrad verschiebt bei aktivem
      // Zoom horizontal, damit auch Touchpad-Nutzer breite Motive prüfen können.
      stage.addEventListener('wheel', (event) => {
        event.preventDefault();
        const rect = stage.getBoundingClientRect();
        const focusX = event.clientX - rect.left - (rect.width / 2);
        const focusY = event.clientY - rect.top - (rect.height / 2);
        if (zoom > zoomMin + 0.001 && event.shiftKey) {
          setOffsets(offsetX - (event.deltaY * 0.35), offsetY);
          return;
        }
        const nextZoom = event.deltaY < 0 ? zoom + zoomStep : zoom - zoomStep;
        setZoom(nextZoom, { focusX, focusY, preserveFocus: true });
      }, { passive: false });

      stage.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || zoom <= zoomMin + 0.001) {
          return;
        }
        event.preventDefault();
        drag.active = true;
        drag.pointerId = event.pointerId;
        drag.originX = event.clientX;
        drag.originY = event.clientY;
        drag.startX = offsetX;
        drag.startY = offsetY;
        if (typeof stage.setPointerCapture === 'function') {
          stage.setPointerCapture(event.pointerId);
        }
        applyTransform();
      });

      stage.addEventListener('pointermove', (event) => {
        if (!drag.active) {
          return;
        }
        event.preventDefault();
        setOffsets(
          drag.startX + (event.clientX - drag.originX),
          drag.startY + (event.clientY - drag.originY)
        );
      });

      const stopDrag = (event = null) => {
        if (!drag.active) {
          return;
        }
        if (event
          && typeof stage.releasePointerCapture === 'function'
          && drag.pointerId !== null) {
          try {
            stage.releasePointerCapture(drag.pointerId);
          } catch {
            // Pointer-Capture kann beim Schließen des Modals bereits weg sein.
          }
        }
        drag.active = false;
        drag.pointerId = null;
        applyTransform();
      };
      stage.addEventListener('pointerup', stopDrag);
      stage.addEventListener('pointercancel', stopDrag);

      stage.addEventListener('dblclick', (event) => {
        event.preventDefault();
        if (zoom > zoomMin + 0.001) {
          offsetX = 0;
          offsetY = 0;
          setZoom(zoomMin);
          return;
        }
        const rect = stage.getBoundingClientRect();
        setZoom(2, {
          focusX: event.clientX - rect.left - (rect.width / 2),
          focusY: event.clientY - rect.top - (rect.height / 2),
          preserveFocus: true,
        });
      });

      // Dieselben Kernfunktionen sind vollständig per Tastatur erreichbar.
      stage.addEventListener('keydown', (event) => {
        if (event.key === '+' || event.key === '=') {
          event.preventDefault();
          setZoom(zoom + zoomStep);
        } else if (event.key === '-') {
          event.preventDefault();
          setZoom(zoom - zoomStep);
        } else if (event.key === '0') {
          event.preventDefault();
          offsetX = 0;
          offsetY = 0;
          setZoom(zoomMin);
        } else if (zoom > zoomMin + 0.001 && event.key === 'ArrowLeft') {
          event.preventDefault();
          setOffsets(offsetX + 36, offsetY);
        } else if (zoom > zoomMin + 0.001 && event.key === 'ArrowRight') {
          event.preventDefault();
          setOffsets(offsetX - 36, offsetY);
        } else if (zoom > zoomMin + 0.001 && event.key === 'ArrowUp') {
          event.preventDefault();
          setOffsets(offsetX, offsetY + 36);
        } else if (zoom > zoomMin + 0.001 && event.key === 'ArrowDown') {
          event.preventDefault();
          setOffsets(offsetX, offsetY - 36);
        }
      });

      // Erst nach dem Laden sind naturalWidth/-Height bekannt; danach werden
      // eventuell zu große Offsets nochmals gegen die echte Bildfläche begrenzt.
      [baseImage, compareImage].forEach((img) => {
        img.addEventListener('load', applyTransform);
      });

      applySnapshots();
      applySplit(splitValue);
      applyTransform();

      return container;
    };

    return Object.freeze({
      buildCurrentSnapshot,
      readStoredSnapshot,
      storeSnapshot,
      clearStoredSnapshot,
      createActionButton,
      buildNotice,
      buildContent,
    });
  };

  window.WebcamImageCompare = Object.freeze({ create });
})();
