(() => {
  'use strict';

  /**
   * Leseführung
   * -----------
   * Dieses Modul steuert die frei positionierbaren Beschriftungen über dem
   * Kamerabild. Es erledigt dabei fünf klar getrennte Aufgaben:
   *
   * 1. Eingabedaten aus dem HTML oder der Timeline-API einlesen.
   * 2. Unvollständige oder gefährliche Werte auf ein kleines erlaubtes Format
   *    reduzieren (Text, Position, Farbe und optionaler Link).
   * 3. Die Beschriftungen ausschließlich mit DOM-Methoden aufbauen. Fremder
   *    Text wird dadurch nie als HTML interpretiert.
   * 4. Die persönliche Ein-/Auswahl im Browser speichern und alle sichtbaren
   *    Umschalter synchron halten.
   * 5. Zusätzliche HTML-Beschriftungen unterdrücken, wenn ein altes Bild seine
   *    Beschriftung bereits fest eingebrannt enthält.
   *
   * `camera.js` liefert nur noch die drei kleinen Abhängigkeiten und ruft die
   * öffentliche Controller-API auf. Der Zustand bleibt bewusst hier im Modul.
   */

  const LABEL_TOGGLE_STORAGE_KEY = 'wc_labels_enabled';
  const LEGACY_EMBEDDED_OVERLAY_CUTOFF_YEAR = 2026;
  const LABEL_COLOR_PRESETS = new Set([
    'blue',
    'teal',
    'green',
    'yellow',
    'orange',
    'red',
    'purple',
    'gray',
  ]);
  const CUSTOM_COLOR_PATTERN = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
  const LABEL_TEXT_TONE_VALUES = new Set(['auto', 'light', 'dark']);
  const LABEL_COLOR_HEX = {
    blue: '#2f6cff',
    teal: '#21b6c8',
    green: '#2faa63',
    yellow: '#d6b652',
    orange: '#dd7a3a',
    red: '#d45a5a',
    purple: '#8b5bd6',
    gray: '#6a7486',
  };
  const LABEL_BUBBLE_PATHS = {
    0: 'M32 12H288Q306 12 306 30V82Q306 100 288 100H96L64 118L76 100H32Q14 100 14 82V30Q14 12 32 12Z',
    1: 'M32 12H288Q306 12 306 30V82Q306 100 288 100H224L248 118L236 100H32Q14 100 14 82V30Q14 12 32 12Z',
    4: 'M32 12H288Q306 12 306 30V82Q306 100 288 100H176L160 118L144 100H32Q14 100 14 82V30Q14 12 32 12Z',
  };

  const create = ({ getLiveRoot, parseIntSafe, resolveAppUrl }) => {
    if (typeof getLiveRoot !== 'function'
      || typeof parseIntSafe !== 'function'
      || typeof resolveAppUrl !== 'function') {
      throw new TypeError('CameraLabels benötigt getLiveRoot, parseIntSafe und resolveAppUrl.');
    }

    // Der Controller überlebt partielle Timeline-Navigation. Darum wird das
    // aktuelle Root-Element nie dauerhaft gespeichert, sondern bei jedem
    // Rendern neu über getLiveRoot() aufgelöst.
    let labelsEnabled = true;
    let labelsSuppressed = false;
    let liveLabelsRaw = [];
    let globalListenersAttached = false;
    let labelsRenderHandle = null;
    const labelBubbleCache = new Map();

    const getLiveLabelsContainer = () => getLiveRoot()?.querySelector('[data-live-labels]') || null;

    // Die Sprechblasen sind kleine lokale SVG-Hintergründe. Farbe und Pfad
    // stammen nur aus den zuvor geprüften Whitelists; Benutzereingaben können
    // deshalb weder Markup noch zusätzliche CSS-Anweisungen einschleusen.
    const buildLabelBubbleDataUrl = (type, color) => {
      const normalized = typeof color === 'string' ? color.trim() : '';
      if (!normalized) return '';
      const bubbleType = LABEL_BUBBLE_PATHS[type] ? type : 0;
      const cacheKey = `${bubbleType}:${normalized.toLowerCase()}`;
      const cached = labelBubbleCache.get(cacheKey);
      if (cached) return cached;

      const path = LABEL_BUBBLE_PATHS[bubbleType];
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 120" role="img" aria-hidden="true"><path d="${path}" fill="${normalized}" fill-opacity="0.82" stroke="#ffffff" stroke-opacity="0.22" stroke-width="2"/></svg>`;
      const encoded = encodeURIComponent(svg).replace(/'/g, '%27').replace(/"/g, '%22');
      const url = `url("data:image/svg+xml,${encoded}")`;
      labelBubbleCache.set(cacheKey, url);
      return url;
    };

    // Falls ein Browser den SVG-Hintergrund nicht verwenden kann, dient RGBA
    // als schlichter Farbhintergrund. Auch hier werden nur geprüfte Hexwerte
    // akzeptiert.
    const hexToRgba = (hex, fallbackAlpha) => {
      if (typeof hex !== 'string') return '';
      let value = hex.trim();
      if (!value.startsWith('#')) return '';
      value = value.slice(1);
      if (value.length === 3) {
        value = value.split('').map((character) => character + character).join('');
      }
      if (value.length !== 6 && value.length !== 8) return '';

      const red = Number.parseInt(value.slice(0, 2), 16);
      const green = Number.parseInt(value.slice(2, 4), 16);
      const blue = Number.parseInt(value.slice(4, 6), 16);
      if (![red, green, blue].every(Number.isFinite)) return '';

      let alpha = Number.isFinite(fallbackAlpha) ? fallbackAlpha : 0.82;
      if (value.length === 8) {
        const parsedAlpha = Number.parseInt(value.slice(6, 8), 16);
        if (Number.isFinite(parsedAlpha)) {
          alpha = Math.round((parsedAlpha / 255) * 1000) / 1000;
        }
      }
      return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
    };

    // Historische Bilder können Text und Logo bereits in ihren Pixeln tragen.
    // Ein zweites HTML-Overlay wäre dann doppelt. Neuere DB-Einträge melden das
    // direkt; für Altbestände bleiben Jahr und Archivpfad als Übergangsregeln.
    const entryUsesEmbeddedOverlay = (entry) => {
      const liveRoot = getLiveRoot();
      if (!entry || typeof entry !== 'object') {
        return liveRoot?.dataset.labelsSuppressed === '1';
      }
      if (entry.logo_detected === true || entry.logo_detected === 1 || entry.logo_detected === '1') {
        return true;
      }
      const year = parseIntSafe(entry.year);
      if (year && year < LEGACY_EMBEDDED_OVERLAY_CUTOFF_YEAR) {
        return true;
      }
      const relativePath = typeof entry.relative_path === 'string'
        ? entry.relative_path.replace(/\\/g, '/')
        : '';
      const slug = liveRoot?.dataset.cameraSlug || '';
      return Boolean(relativePath && slug && relativePath.includes(`archive/${slug}/`));
    };

    const readEmbeddedLabels = () => {
      const script = getLiveRoot()?.querySelector('[data-live-labels-json]');
      if (!script) return [];
      try {
        const data = JSON.parse(script.textContent || '[]');
        return Array.isArray(data) ? data : [];
      } catch {
        // Fehlerhafte eingebettete Daten dürfen nur die Labels deaktivieren,
        // nicht die gesamte Kameraansicht unbenutzbar machen.
        return [];
      }
    };

    // Nur HTTP(S), Fragment-, Query- und dokumentrelative Links sind erlaubt.
    // Andere absolute Schemata (z. B. javascript:) werden wie ein lokaler Pfad
    // behandelt und können dadurch nicht als ausführbarer Link wirken.
    const resolveLabelHref = (href) => {
      const raw = String(href || '').trim();
      if (!raw) return '';
      if (raw.startsWith('#') || raw.startsWith('?')) return raw;
      if (/^(https?:)?\/\//i.test(raw)) return raw;
      return resolveAppUrl(raw);
    };

    // Diese Grenze nimmt sowohl ältere Feldnamen als auch aktuelle API-Werte
    // entgegen und liefert nur das kleine Format, das der Renderer versteht.
    const normalizeLabel = (label) => {
      if (!label || typeof label !== 'object') return null;
      const text = String(label.text || label.txt || '').trim();
      const x = Number.parseFloat(label.x);
      const y = Number.parseFloat(label.y);
      if (!Number.isFinite(x) || !Number.isFinite(y) || !text) return null;

      const type = Number.isFinite(label.type) ? label.type : parseIntSafe(label.type) || 0;
      const resolution = Number.isFinite(label.resolution)
        ? label.resolution
        : parseIntSafe(label.resolution) || 0;
      const rawColor = typeof label.color === 'string' ? label.color.trim().toLowerCase() : '';
      const color = LABEL_COLOR_PRESETS.has(rawColor) ? rawColor : '';
      const customColor = !color && CUSTOM_COLOR_PATTERN.test(rawColor) ? rawColor : '';
      const rawTextTone = typeof label.text_tone === 'string'
        ? label.text_tone.trim().toLowerCase()
        : (typeof label.textTone === 'string' ? label.textTone.trim().toLowerCase() : '');

      return {
        id: Number.isFinite(label.id) ? label.id : parseIntSafe(label.id),
        x,
        y,
        text,
        href: resolveLabelHref(label.href || ''),
        type,
        resolution,
        color,
        customColor,
        textTone: LABEL_TEXT_TONE_VALUES.has(rawTextTone) ? rawTextTone : 'auto',
      };
    };

    // Fremde Texte werden über textContent gesetzt. Das ist die wichtigste
    // Sicherheitsgrenze dieses Renderers: Labeltexte können kein HTML erzeugen.
    const buildLabelElement = (label) => {
      const element = document.createElement(label.href ? 'a' : 'span');
      const bubbleType = label.type === 4 ? 4 : (label.type === 1 || label.type === 3 ? 1 : 0);
      element.className = `live-label live-label--type-${bubbleType}${label.type === 4 ? ' live-label--center' : ''}`;

      if (label.color) {
        element.classList.add(`live-label--color-${label.color}`);
        const presetHex = LABEL_COLOR_HEX[label.color];
        const bubbleUrl = presetHex ? buildLabelBubbleDataUrl(bubbleType, presetHex) : '';
        if (bubbleUrl) {
          element.style.setProperty('--label-bubble', bubbleUrl);
          element.style.setProperty('--label-bubble-filter', 'none');
        } else {
          const rgba = hexToRgba(presetHex, 0.82);
          if (rgba) element.style.setProperty('--label-bubble-color', rgba);
        }
      } else if (label.customColor) {
        const bubbleUrl = buildLabelBubbleDataUrl(bubbleType, label.customColor);
        if (bubbleUrl) {
          element.style.setProperty('--label-bubble', bubbleUrl);
        } else {
          const rgba = hexToRgba(label.customColor, 0.82);
          if (rgba) element.style.setProperty('--label-bubble-color', rgba);
        }
        element.style.setProperty('--label-bubble-filter', 'none');
      }

      if (label.textTone !== 'auto') {
        element.classList.add(`live-label--tone-${label.textTone}`);
      }
      if (label.id !== null && label.id !== undefined) {
        element.dataset.labelId = String(label.id);
      }
      element.textContent = label.text;

      if (label.href) {
        element.setAttribute('href', label.href);
        if (/^(https?:)?\/\//i.test(label.href)) {
          element.setAttribute('target', '_blank');
          element.setAttribute('rel', 'noopener');
        }
      }

      if (label.type === 1 || label.type === 3) {
        element.style.right = `calc(100% - ${label.x}%)`;
      } else {
        element.style.left = `${label.x}%`;
      }
      if (label.type === 2 || label.type === 3) {
        element.style.top = `${label.y}%`;
      } else {
        element.style.bottom = `calc(100% - ${label.y}%)`;
      }
      return element;
    };

    const canToggleLabels = () => {
      if (labelsSuppressed || !Array.isArray(liveLabelsRaw) || liveLabelsRaw.length === 0) {
        return false;
      }
      return liveLabelsRaw.some((label) => normalizeLabel(label) !== null);
    };

    const updateLabelToggleUi = () => {
      const toggleText = labelsEnabled ? 'Beschriftung ausblenden' : 'Beschriftung anzeigen';
      document.querySelectorAll('[data-label-toggle]').forEach((element) => {
        element.hidden = !canToggleLabels();
        element.textContent = toggleText;
        element.setAttribute('aria-pressed', labelsEnabled && !labelsSuppressed ? 'true' : 'false');
      });
    };

    const render = () => {
      const container = getLiveLabelsContainer();
      if (!container) return;
      if (labelsSuppressed || !labelsEnabled) {
        container.replaceChildren();
        container.hidden = true;
        container.setAttribute('aria-hidden', 'true');
        return;
      }

      const liveRoot = getLiveRoot();
      const width = container.clientWidth || liveRoot?.querySelector('.live-image')?.clientWidth || 0;
      const labels = liveLabelsRaw
        .map(normalizeLabel)
        .filter((label) => label && (!label.resolution || !width || width >= label.resolution));

      container.replaceChildren(...labels.map(buildLabelElement));
      container.hidden = labels.length === 0;
      container.setAttribute('aria-hidden', labels.length === 0 ? 'true' : 'false');
    };

    // Mehrere Resize-Ereignisse werden zu genau einem Renderlauf pro Browser-
    // Frame zusammengefasst. Das verhindert unnötige Layoutberechnungen.
    const scheduleRender = () => {
      if (labelsRenderHandle !== null) return;
      labelsRenderHandle = window.requestAnimationFrame(() => {
        labelsRenderHandle = null;
        render();
      });
    };

    const setEnabled = (enabled) => {
      labelsEnabled = Boolean(enabled);
      try {
        localStorage.setItem(LABEL_TOGGLE_STORAGE_KEY, labelsEnabled ? '1' : '0');
      } catch {
        // Private Browsermodi können Storage blockieren. Die Auswahl gilt dann
        // trotzdem bis zum nächsten vollständigen Seitenaufruf.
      }
      getLiveRoot()?.classList.toggle('labels-hidden', labelsSuppressed || !labelsEnabled);
      updateLabelToggleUi();
      render();
    };

    // Delegation am Dokument überlebt den Austausch des Kamera-Roots. Der alte
    // Code registrierte zusätzlich pro Schalter einen zweiten Listener; hier
    // gibt es bewusst nur noch diesen einen, eindeutig verantwortlichen Pfad.
    const handleToggleClick = (event) => {
      if (event.defaultPrevented || (typeof event.button === 'number' && event.button !== 0)) return;
      const target = event.target instanceof Element ? event.target : null;
      const toggle = target?.closest('[data-label-toggle]');
      if (!toggle) return;
      event.preventDefault();
      event.stopPropagation();
      setEnabled(!labelsEnabled);
    };

    const attachGlobalListeners = () => {
      if (globalListenersAttached) return;
      document.addEventListener('click', handleToggleClick, true);
      window.addEventListener('resize', scheduleRender);
      globalListenersAttached = true;
    };

    const applyEmbeddedOverlayState = (entry = null) => {
      labelsSuppressed = entryUsesEmbeddedOverlay(entry);
      const liveRoot = getLiveRoot();
      if (!liveRoot) return;

      liveRoot.dataset.labelsSuppressed = labelsSuppressed ? '1' : '0';
      liveRoot.classList.toggle('labels-hidden', labelsSuppressed || !labelsEnabled);
      const overlay = liveRoot.querySelector('[data-live-overlay]');
      if (overlay) overlay.hidden = labelsSuppressed;
      const logo = liveRoot.querySelector('.live-logo');
      if (logo) logo.hidden = labelsSuppressed;
      updateLabelToggleUi();
      render();
    };

    const update = (labels) => {
      if (!Array.isArray(labels)) return;
      liveLabelsRaw = labels;
      updateLabelToggleUi();
      render();
    };

    const init = () => {
      const liveRoot = getLiveRoot();
      if (!liveRoot) return;

      labelsSuppressed = liveRoot.dataset.labelsSuppressed === '1';
      try {
        const stored = localStorage.getItem(LABEL_TOGGLE_STORAGE_KEY);
        if (stored === '0') labelsEnabled = false;
        if (stored === '1') labelsEnabled = true;
      } catch {
        labelsEnabled = true;
      }

      liveLabelsRaw = readEmbeddedLabels();
      liveRoot.classList.toggle('labels-hidden', labelsSuppressed || !labelsEnabled);
      attachGlobalListeners();
      applyEmbeddedOverlayState();
    };

    return Object.freeze({
      init,
      update,
      applyEmbeddedOverlayState,
    });
  };

  window.WebcamCameraLabels = Object.freeze({ create });
})();
