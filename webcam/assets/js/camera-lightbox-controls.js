(() => {
  'use strict';

  /**
   * Kapselt ausschließlich die Bedienung des bereits vorhandenen Lightbox-DOM.
   *
   * Der Controller kennt keine Timeline, keine API und keine Best-of-Seiten.
   * Seine Rückrufe bedeuten nur „ein Bild weiter“, „einen Tag weiter“ oder
   * „schließen“. Dadurch bleibt die umfangreiche Maus-/Tastaturbelegung von der
   * fachlichen Navigation getrennt und kann unabhängig verändert werden.
   */
  const create = ({
    lightbox,
    image,
    closeButton,
    previousButton,
    nextButton,
    zoomButton,
    helpButton,
    helpPanel,
    onClose,
    onShift,
    onShiftDay,
    onJumpDayBoundary,
    onZoomChanged,
    baseScale = 1,
    defaultZoom = 2,
    maximumZoom = 8,
    zoomStep = 0.25,
  } = {}) => {
    const callbacks = [onClose, onShift, onShiftDay, onJumpDayBoundary, onZoomChanged];
    if (callbacks.some((callback) => typeof callback !== 'function')) {
      throw new Error('Lightbox-Bedienung: notwendige Rückrufe fehlen.');
    }

    let zoom = defaultZoom;
    let zoomed = true;
    let drag = { active: false, x: 0, y: 0, originX: 0, originY: 0 };

    const stage = lightbox?.querySelector('.lightbox__stage') || null;
    const clampY = (value) => {
      const limit = stage ? (stage.clientHeight * (zoom - 1)) / 2 : 450;
      return Math.max(-limit, Math.min(limit, value));
    };
    const clampX = (value) => {
      const limit = stage ? (stage.clientWidth * (zoom - 1)) / 2 : 800;
      return Math.max(-limit, Math.min(limit, value));
    };

    /** Wendet den aktuellen Zoom und die Verschiebung in einem CSS-Schritt an. */
    const applyTransform = () => {
      if (!image) return;
      const x = Number.parseFloat(image.dataset.dx || '0');
      const y = Number.parseFloat(image.dataset.dy || '0');
      const scale = zoomed ? zoom : baseScale;
      image.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px)) scale(${scale})`;
      image.style.cursor = zoomed ? 'grab' : 'zoom-in';
    };

    const notifyZoomChanged = () => {
      applyTransform();
      onZoomChanged();
    };

    const resetPosition = () => {
      if (!image) return;
      image.dataset.dx = '0';
      image.dataset.dy = '0';
    };

    const resetForImage = () => {
      zoomed = true;
      resetPosition();
      applyTransform();
    };

    const pan = (deltaX, deltaY) => {
      if (!zoomed || !image) return;
      image.dataset.dx = String(clampX(Number.parseFloat(image.dataset.dx || '0') + deltaX));
      image.dataset.dy = String(clampY(Number.parseFloat(image.dataset.dy || '0') + deltaY));
      applyTransform();
    };

    const setHelpOpen = (open) => {
      if (!helpPanel) return;
      const shouldOpen = Boolean(open);
      helpPanel.toggleAttribute('hidden', !shouldOpen);
      helpButton?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    };

    const toggleHelp = () => setHelpOpen(Boolean(helpPanel?.hasAttribute('hidden')));

    const toggleZoom = () => {
      zoomed = !zoomed;
      if (zoomed) zoom = Math.max(zoom, defaultZoom);
      resetPosition();
      notifyZoomChanged();
    };

    const zoomIn = () => {
      zoomed = true;
      zoom = Math.min(zoom + zoomStep, maximumZoom);
      resetPosition();
      notifyZoomChanged();
    };

    const zoomOut = () => {
      if (zoom - zoomStep <= baseScale) {
        zoomed = false;
        zoom = defaultZoom;
      } else {
        zoom = Math.max(baseScale, zoom - zoomStep);
      }
      resetPosition();
      notifyZoomChanged();
    };

    const resetZoom = () => {
      zoomed = false;
      zoom = defaultZoom;
      resetPosition();
      notifyZoomChanged();
    };

    /**
     * Leitet einen gut erkennbaren Dateinamen aus dem gerade angezeigten Bild ab.
     * Query-Parameter und die ursprüngliche Dateiendung gehören nicht in den
     * Namen des neu erzeugten JPEG-Ausschnitts.
     */
    const cropDownloadFilename = () => {
      let basename = 'webcam-bild';
      try {
        const sourceUrl = new URL(image?.currentSrc || image?.src || '', window.location.href);
        const sourceName = decodeURIComponent(sourceUrl.pathname.split('/').pop() || '');
        if (sourceUrl.protocol === 'http:' || sourceUrl.protocol === 'https:') {
          basename = sourceName.replace(/\.[^.]+$/, '') || basename;
        }
      } catch (_error) {
        // Bei einer ungewöhnlichen Bild-URL bleibt der neutrale Ersatzname bestehen.
      }

      const safeBasename = basename
        .replace(/[^a-z0-9._-]+/gi, '-')
        .replace(/^-+|-+$/g, '') || 'webcam-bild';
      return `${safeBasename}_ausschnitt.jpg`;
    };

    /**
     * Berechnet den auf dem Bildschirm sichtbaren Teil in Originalbild-Pixeln.
     *
     * Das Bild wird vom Browser zunächst proportional in die Bühne eingepasst
     * und danach per CSS vergrößert und verschoben. Für den Download rechnen wir
     * diese drei Schritte rückwärts. Freie dunkle Ränder der Lightbox werden
     * nicht mitgespeichert; der Download enthält ausschließlich Bildinformation.
     */
    const visibleSourceRectangle = () => {
      if (!stage || !image || image.naturalWidth < 1 || image.naturalHeight < 1) return null;

      const stageWidth = stage.clientWidth;
      const stageHeight = stage.clientHeight;
      if (stageWidth < 1 || stageHeight < 1) return null;

      const fitScale = Math.min(
        1,
        stageWidth / image.naturalWidth,
        stageHeight / image.naturalHeight
      );
      const activeScale = zoomed ? zoom : baseScale;
      const renderedScale = fitScale * activeScale;
      const renderedWidth = image.naturalWidth * renderedScale;
      const renderedHeight = image.naturalHeight * renderedScale;
      const offsetX = Number.parseFloat(image.dataset.dx || '0');
      const offsetY = Number.parseFloat(image.dataset.dy || '0');
      const imageLeft = (stageWidth - renderedWidth) / 2 + offsetX;
      const imageTop = (stageHeight - renderedHeight) / 2 + offsetY;

      const visibleLeft = Math.max(0, imageLeft);
      const visibleTop = Math.max(0, imageTop);
      const visibleRight = Math.min(stageWidth, imageLeft + renderedWidth);
      const visibleBottom = Math.min(stageHeight, imageTop + renderedHeight);
      if (visibleRight <= visibleLeft || visibleBottom <= visibleTop) return null;

      return {
        x: Math.max(0, (visibleLeft - imageLeft) / renderedScale),
        y: Math.max(0, (visibleTop - imageTop) / renderedScale),
        width: Math.min(image.naturalWidth, (visibleRight - visibleLeft) / renderedScale),
        height: Math.min(image.naturalHeight, (visibleBottom - visibleTop) / renderedScale),
      };
    };

    /**
     * Rendert den sichtbaren Bildausschnitt lokal in ein Canvas und startet den
     * Browser-Download. Es werden keine Bilddaten an einen zusätzlichen Server
     * geschickt; Auflösung und Qualität stammen direkt aus dem geladenen JPEG.
     */
    const downloadVisibleCrop = async () => {
      if (!image?.complete || image.naturalWidth < 1 || image.naturalHeight < 1) return false;

      const source = visibleSourceRectangle();
      if (!source) return false;

      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(source.width));
      canvas.height = Math.max(1, Math.round(source.height));
      const context = canvas.getContext('2d');
      if (!context) return false;

      context.drawImage(
        image,
        source.x,
        source.y,
        source.width,
        source.height,
        0,
        0,
        canvas.width,
        canvas.height
      );

      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.95));
      if (!(blob instanceof Blob)) return false;

      const objectUrl = URL.createObjectURL(blob);
      const downloadLink = document.createElement('a');
      downloadLink.href = objectUrl;
      downloadLink.download = cropDownloadFilename();
      downloadLink.hidden = true;
      document.body.append(downloadLink);
      downloadLink.click();
      downloadLink.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      return true;
    };

    const handleWheel = (event) => {
      event.preventDefault();
      const wantsPan = zoomed && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
      if (wantsPan && image) {
        image.dataset.dy = String(clampY(Number.parseFloat(image.dataset.dy || '0') - event.deltaY * 0.2));
        applyTransform();
        return;
      }
      if (event.deltaY < 0) zoomIn();
      else zoomOut();
    };

    const handleKeydown = (event) => {
      if (!lightbox?.classList.contains('is-open')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        if (helpPanel && !helpPanel.hasAttribute('hidden')) setHelpOpen(false);
        else onClose();
        return;
      }
      if (event.key.toLowerCase() === 'h' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        if (!event.repeat) toggleHelp();
        return;
      }
      if (event.key.toLowerCase() === 'd' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        const target = event.target;
        const isTextInput = target instanceof HTMLElement
          && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
        if (isTextInput) return;

        event.preventDefault();
        if (!event.repeat) {
          void downloadVisibleCrop().catch((error) => {
            console.error('Der sichtbare Bildausschnitt konnte nicht gespeichert werden.', error);
          });
        }
        return;
      }
      if (event.key === 'PageUp' || event.key === 'PageDown') {
        event.preventDefault();
        const direction = event.key === 'PageUp' ? 'prev' : 'next';
        if (event.shiftKey) onShiftDay(direction);
        else onShift(direction === 'prev' ? -1 : 1);
        return;
      }
      if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        onJumpDayBoundary(event.key === 'Home' ? 'first' : 'last');
        return;
      }
      const pans = {
        ArrowLeft: [40, 0],
        ArrowRight: [-40, 0],
        ArrowUp: [0, 40],
        ArrowDown: [0, -40],
      };
      if (pans[event.key]) {
        event.preventDefault();
        pan(...pans[event.key]);
        return;
      }
      if (event.key === '+') {
        event.preventDefault();
        zoomIn();
      } else if (event.key === '-') {
        event.preventDefault();
        zoomOut();
      } else if (event.key === '0') {
        event.preventDefault();
        resetZoom();
      }
    };

    const init = () => {
      // Die Lightbox bleibt bei partieller Navigation im Dokument. Der Guard
      // verhindert doppelte Listener nach jedem Archivsprung.
      if (!lightbox || lightbox.dataset.controlsInitialized === '1') return;
      lightbox.dataset.controlsInitialized = '1';
      closeButton?.addEventListener('click', onClose);
      lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) onClose();
      });
      previousButton?.addEventListener('click', () => onShift(-1));
      nextButton?.addEventListener('click', () => onShift(1));
      helpButton?.addEventListener('click', toggleHelp);
      image?.addEventListener('dblclick', toggleZoom);
      zoomButton?.addEventListener('click', toggleZoom);

      if (stage && image) {
        image.addEventListener('mousedown', (event) => {
          if (!zoomed) return;
          drag = {
            active: true,
            originX: event.clientX,
            originY: event.clientY,
            x: Number.parseFloat(image.dataset.dx || '0'),
            y: Number.parseFloat(image.dataset.dy || '0'),
          };
          image.style.cursor = 'grabbing';
        });
        window.addEventListener('mouseup', () => {
          if (!drag.active) return;
          drag.active = false;
          image.style.cursor = 'grab';
        });
        window.addEventListener('mousemove', (event) => {
          if (!drag.active || !zoomed) return;
          image.dataset.dx = String(clampX(drag.x + event.clientX - drag.originX));
          image.dataset.dy = String(clampY(drag.y + event.clientY - drag.originY));
          applyTransform();
        });
        stage.addEventListener('wheel', handleWheel, { passive: false });
      }
      window.addEventListener('keydown', handleKeydown);
    };

    return Object.freeze({
      init,
      resetForImage,
      setHelpOpen,
      downloadVisibleCrop,
      getScaleLabel: () => `${(zoomed ? zoom : baseScale).toFixed(2).replace(/\.00$/, '')}x`,
    });
  };

  window.WebcamCameraLightboxControls = Object.freeze({ create });
})();
