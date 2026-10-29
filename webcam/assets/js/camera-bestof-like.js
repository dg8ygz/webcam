(() => {
  'use strict';

  /**
   * Like-Aktion für das aktuelle Kamerabild.
   *
   * Leseführung
   * -----------
   * 1. CSRF-Cookie und Bildpfad werden für den Request vorbereitet.
   * 2. Der sichtbare Herz-Zustand wird an einer Stelle aktualisiert.
   * 3. Das Backend erhält Captcha, Kamera und Bild über einen JSON-Request.
   * 4. Der Klickablauf behandelt Wiederholung, falsches Captcha und Fehler.
   * 5. `init()` bindet den aktuellen Button nach jeder Teilnavigation neu.
   *
   * Das Modul entscheidet nicht, ob ein Like erlaubt ist. Captcha-Prüfung,
   * Rate-Limit, Dateisperre und DB-Schreibvorgang bleiben verbindlich im PHP-
   * Backend. Der Browser zeigt lediglich den bestätigten Serverzustand an.
   */

  const create = ({
    getLiveRoot,
    buildAppPath,
    updateLiveModeBadge,
    ensureBestOfState,
  } = {}) => {
    const dependencies = [getLiveRoot, buildAppPath, updateLiveModeBadge, ensureBestOfState];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new TypeError('CameraBestOfLike benötigt alle Seitenadapter.');
    }

    // -----------------------------------------------------------------------
    // 1. Requestwerte vorbereiten
    // -----------------------------------------------------------------------

    const getCookie = (name) => document.cookie
      .split(';')
      .map((cookie) => cookie.trim())
      .filter(Boolean)
      .map((cookie) => cookie.split('='))
      .reduce((values, [key, value]) => {
        values[key] = value ? decodeURIComponent(value) : '';
        return values;
      }, {})[name];

    const getPublicCsrfToken = () => getCookie('webcam_csrf') || getCookie('admin_csrf') || '';

    const normalizeLikePath = (path, slug) => {
      if (!path || !slug || !path.startsWith('bestof/')) return path;
      const match = path.match(
        /bestof\/[a-z0-9-]+\/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})_(la|lm|sm|hu|full)\.jpe?g/i
      );
      if (!match) return path;
      return `cache/${slug}/${match[1]}/${match[2]}/${match[3]}/${match[1]}${match[2]}${match[3]}_${match[4]}${match[5]}${match[6]}_${match[7].toLowerCase()}.jpg`;
    };

    const extractBestOfFilename = (value) => {
      if (!value) return '';
      const filename = String(value).split('?')[0].split('/').pop() || '';
      return /^\d{8}_\d{6}_(la|lm|sm|hu|full)\.jpe?g$/i.test(filename) ? filename : '';
    };

    // -----------------------------------------------------------------------
    // 2. Sichtbaren Herz- und Navigationszustand aktualisieren
    // -----------------------------------------------------------------------

    const setActive = (button, active) => {
      const isActive = Boolean(active);
      button.dataset.active = isActive ? '1' : '0';
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-label', isActive ? 'Bereits geliked' : 'Bild liken');
      const screenReaderLabel = button.querySelector('.sr-only');
      if (screenReaderLabel) {
        screenReaderLabel.textContent = isActive ? 'Dieses Bild wurde geliked' : 'Bild liken';
      }
      button.closest('[data-bestof-hotspot]')?.classList.toggle('is-active', isActive);
    };

    const activateBestOfMode = (filename) => {
      if (!filename) return;
      const parameters = new URLSearchParams(window.location.search);
      parameters.set('bestof', filename);
      parameters.set('nav', 'bestof');
      const query = parameters.toString();
      const nextUrl = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash || ''}`;
      history.replaceState({ cameraAjax: true }, '', nextUrl);
      updateLiveModeBadge('bestof');
    };

    // -----------------------------------------------------------------------
    // 3. Serverseitig geprüften Like absenden
    // -----------------------------------------------------------------------

    const performToggle = async (button, { slug, path, captchaId, captchaCode }) => {
      const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };
      const csrf = getPublicCsrfToken();
      if (csrf) headers['X-CSRF-Token'] = csrf;

      const response = await fetch(buildAppPath('bestof-toggle.php'), {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body: JSON.stringify({
          slug,
          path,
          captcha_id: captchaId,
          captcha_code: captchaCode,
        }),
      });
      const raw = await response.text();
      let payload = null;
      try {
        payload = raw ? JSON.parse(raw) : null;
      } catch {
        const error = new Error('Ungültige Server-Antwort');
        error.code = 'invalid_response';
        throw error;
      }
      if (!response.ok || !payload || payload.ok === false) {
        const error = new Error(payload?.error || `HTTP ${response.status}`);
        if (payload?.code) error.code = payload.code;
        throw error;
      }
      if (payload.path) button.setAttribute('data-path', payload.path);
      if (typeof payload.active === 'boolean') setActive(button, payload.active);
      return payload;
    };

    // -----------------------------------------------------------------------
    // 4. Captcha-Dialog und vollständigen Klickablauf steuern
    // -----------------------------------------------------------------------

    let captchaModal = null;
    const getCaptchaModal = () => {
      if (captchaModal) return captchaModal;
      const factory = window.WebcamCameraCaptcha;
      if (!factory || typeof factory.create !== 'function') {
        throw new Error('Captcha-Modul konnte nicht geladen werden.');
      }
      captchaModal = factory.create({
        buildCaptchaUrl: () => buildAppPath('bestof-captcha.php'),
      });
      return captchaModal;
    };

    const handleLike = async (button) => {
      if (button.dataset.bestofBusy === '1') return;
      const slug = button.getAttribute('data-camera') || '';
      const path = normalizeLikePath(button.getAttribute('data-path') || '', slug);
      if (!slug || !path) return;

      button.dataset.bestofBusy = '1';
      try {
        const modal = getCaptchaModal();
        let message = '';
        // Nach einem falschen Code wird bewusst eine neue Aufgabe angefordert.
        // Der Dialog kann jederzeit mit `null` abbrechen.
        // eslint-disable-next-line no-constant-condition
        while (true) {
          const challenge = await modal.request(message, Boolean(message));
          if (!challenge) break;
          modal.setLoading(true);
          button.classList.add('is-busy');
          try {
            const payload = await performToggle(button, {
              slug,
              path,
              captchaId: challenge.captchaId,
              captchaCode: challenge.captchaCode,
            });
            modal.hide();
            setActive(button, true);
            const filename = extractBestOfFilename(payload?.path || button.getAttribute('data-path') || path);
            if (filename) {
              activateBestOfMode(filename);
              const currentSlug = getLiveRoot()?.dataset.cameraSlug || slug;
              if (currentSlug) ensureBestOfState(currentSlug, filename).catch(() => {});
            }
            break;
          } catch (error) {
            if (error?.code === 'invalid_captcha') {
              modal.setLoading(false);
              message = 'Der Code war nicht korrekt. Bitte erneut versuchen.';
              modal.setMessage(message, true);
              continue;
            }
            modal.hide();
            if (error?.code === 'duplicate_image') {
              // eslint-disable-next-line no-alert
              alert('Du hast dieses Bild bereits geliked.');
              break;
            }
            throw error;
          } finally {
            button.classList.remove('is-busy');
            modal.setLoading(false);
          }
        }
      } catch (error) {
        // eslint-disable-next-line no-alert
        alert(error instanceof Error ? error.message : 'Aktion fehlgeschlagen.');
      } finally {
        delete button.dataset.bestofBusy;
      }
    };

    // -----------------------------------------------------------------------
    // 5. Aktuellen Button idempotent binden
    // -----------------------------------------------------------------------

    const initializedButtons = new WeakSet();
    const init = () => {
      const button = getLiveRoot()?.querySelector('[data-bestof-toggle]');
      if (!button || initializedButtons.has(button)) return;
      initializedButtons.add(button);
      setActive(button, button.dataset.active === '1');
      button.addEventListener('click', (event) => {
        // Das Herz liegt über dem Hauptbild; sein Klick darf die Lightbox nicht öffnen.
        event.stopPropagation();
        event.preventDefault();
        handleLike(button);
      });
    };

    return Object.freeze({ init });
  };

  window.WebcamCameraBestOfLike = Object.freeze({ create });
})();
