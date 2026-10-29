(() => {
  'use strict';

  /**
   * Captcha-Dialog für öffentliche Likes.
   *
   * Das Modul kümmert sich ausschließlich um die Oberfläche des Dialogs und
   * das Laden einer neuen Aufgabe. Es entscheidet weder, ob ein Like zulässig
   * ist, noch schreibt es selbst Best-of-Daten. Diese verbindlichen Prüfungen
   * bleiben im PHP-Backend; camera.js übergibt dort Captcha-ID und Eingabe.
   *
   * Die Factory erhält die URL-Funktion von camera.js. Dadurch bleibt das
   * Modul unabhängig davon, ob die Anwendung unter /webcam/, /testx/ oder
   * direkt im Document-Root ausgeliefert wird.
   */
  const create = ({ buildCaptchaUrl } = {}) => {
    if (typeof buildCaptchaUrl !== 'function') {
      throw new Error('Captcha-Dialog: URL-Adapter fehlt.');
    }

    // Der Dialog wird erst beim ersten Like-Klick erzeugt. Seiten ohne Like
    // benötigen dadurch weder zusätzliche DOM-Knoten noch einen Captcha-Abruf.
    const wrapper = document.createElement('div');
    wrapper.className = 'bestof-modal';
    wrapper.innerHTML = `
      <div class="bestof-modal__backdrop" data-captcha-dismiss></div>
      <div class="bestof-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bestof-captcha-title">
        <h2 id="bestof-captcha-title" class="bestof-modal__title">Like bestätigen</h2>
        <p class="bestof-modal__text">
          Bitte gib den angezeigten Code ein, um dein Like für diese Aufnahme zu speichern.
        </p>
        <div class="bestof-modal__captcha">
          <img data-captcha-image src="" alt="Captcha" width="120" height="40">
          <button type="button" class="bestof-modal__button" data-captcha-refresh>Neu laden</button>
        </div>
        <label class="bestof-modal__label">
          <span>Code eingeben</span>
          <input class="bestof-modal__input" type="text" name="captcha" inputmode="text" autocomplete="off" maxlength="8" data-captcha-input>
        </label>
        <p class="bestof-modal__message" data-captcha-message></p>
        <div class="bestof-modal__actions">
          <button type="button" class="bestof-modal__button bestof-modal__button--cancel" data-captcha-cancel>Abbrechen</button>
          <button type="button" class="bestof-modal__button bestof-modal__button--confirm" data-captcha-submit>Bestätigen</button>
        </div>
      </div>
    `;
    document.body.appendChild(wrapper);

    const backdrop = wrapper.querySelector('[data-captcha-dismiss]');
    const image = wrapper.querySelector('[data-captcha-image]');
    const refreshButton = wrapper.querySelector('[data-captcha-refresh]');
    const cancelButton = wrapper.querySelector('[data-captcha-cancel]');
    const submitButton = wrapper.querySelector('[data-captcha-submit]');
    const input = wrapper.querySelector('[data-captcha-input]');
    const message = wrapper.querySelector('[data-captcha-message]');

    let currentId = null;
    let resolver = null;
    let isVisible = false;

    const setVisible = (visible) => {
      isVisible = visible;
      wrapper.classList.toggle('is-visible', visible);
      document.body.classList.toggle('modal-open', visible);
    };

    // Während Netzwerk- oder Like-Anfragen verhindern gesperrte Eingaben,
    // dass dieselbe Aufgabe parallel mehrfach abgeschickt oder ersetzt wird.
    const setLoading = (state) => {
      submitButton.disabled = state;
      refreshButton.disabled = state;
      input.disabled = state;
    };

    const setMessage = (text, isError = false) => {
      message.textContent = text || '';
      message.classList.toggle('is-error', Boolean(isError));
    };

    const loadCaptcha = async () => {
      setLoading(true);
      try {
        const response = await fetch(buildCaptchaUrl(), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true || !payload.captcha) {
          throw new Error(payload && payload.error ? payload.error : 'Captcha konnte nicht geladen werden.');
        }

        currentId = String(payload.captcha.id || '');
        const imageSrc = String(payload.captcha.image || '');
        if (!currentId || !imageSrc) {
          throw new Error('Captcha-Antwort unvollständig.');
        }

        // Das Bild kommt als kontrollierte Captcha-Antwort vom eigenen Server.
        // Meldungen und Nutzereingaben werden dagegen nur per textContent bzw.
        // value gesetzt und niemals als HTML interpretiert.
        image.src = imageSrc;
        setMessage('', false);
        input.value = '';
        window.setTimeout(() => input.focus(), 50);
      } catch (error) {
        currentId = null;
        setMessage(error instanceof Error ? error.message : 'Captcha konnte nicht geladen werden.', true);
      } finally {
        setLoading(false);
      }
    };

    // request() liefert ein Promise. Genau dieser Resolver wird erfüllt,
    // sobald der Besucher bestätigt oder den Dialog abbricht.
    const resolvePromise = (value) => {
      if (!resolver) {
        return;
      }
      const resolve = resolver;
      resolver = null;
      resolve(value);
    };

    const hide = () => {
      setVisible(false);
      currentId = null;
      resolver = null;
      image.src = '';
      input.value = '';
    };

    const show = async (infoMessage = '', isError = false) => {
      setMessage(infoMessage, isError);
      setVisible(true);
      await loadCaptcha();
      // Nach einem falschen Code soll der erklärende Text sichtbar bleiben,
      // auch wenn loadCaptcha seine eigene Statusmeldung zurückgesetzt hat.
      setMessage(infoMessage, isError);
      return new Promise((resolve) => {
        resolver = resolve;
      });
    };

    const submit = (event) => {
      if (event) {
        event.preventDefault();
      }
      if (!currentId) {
        setMessage('Captcha konnte nicht geladen werden. Bitte neu laden.', true);
        return;
      }
      const value = input.value.trim();
      if (value === '') {
        setMessage('Bitte den Code eingeben.', true);
        return;
      }
      setMessage('', false);
      resolvePromise({ captchaId: currentId, captchaCode: value });
    };

    const cancel = (event = null) => {
      if (event) {
        event.preventDefault();
      }
      resolvePromise(null);
      hide();
    };

    refreshButton.addEventListener('click', (event) => {
      event.preventDefault();
      loadCaptcha();
    });
    submitButton.addEventListener('click', submit);
    cancelButton.addEventListener('click', cancel);
    backdrop.addEventListener('click', cancel);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        submit(event);
      }
    });
    document.addEventListener('keydown', (event) => {
      if (isVisible && event.key === 'Escape') {
        cancel(event);
      }
    });

    return Object.freeze({
      request: show,
      hide,
      refresh: loadCaptcha,
      setLoading,
      setMessage,
      isOpen: () => isVisible,
    });
  };

  window.WebcamCameraCaptcha = Object.freeze({ create });
})();
