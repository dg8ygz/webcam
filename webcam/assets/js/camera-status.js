(() => {
  'use strict';

  /**
   * Status- und Telemetriedialog der Kameraansicht.
   *
   * Leseführung
   * -----------
   * 1. Kleine, fehlertolerante Formathelfer bereiten API-Werte für Menschen auf.
   * 2. Gleichzeitige API-Abrufe werden pro URL zusammengeführt.
   * 3. Aus den kontrollierten Statusfeldern entsteht der Dialoginhalt.
   * 4. Modal und Öffner werden idempotent an den jeweils aktuellen DOM gebunden.
   * 5. `init()` ist die schmale Schnittstelle für `camera.js`.
   *
   * Der letzte Punkt ist wichtig, weil die Archivnavigation den Inhalt von
   * `#camera-live-root` austauscht. Das Modul speichert deshalb keine dauerhafte
   * Referenz auf Menülink oder Dialog. Der Menülink liegt im austauschbaren
   * Kamera-Root, der Dialog absichtlich außerhalb. Nach jedem Austausch findet
   * `init()` beide Seiten neu und bindet jeden Knoten höchstens einmal.
   */

  const create = ({ getLiveRoot } = {}) => {
    if (typeof getLiveRoot !== 'function') {
      throw new TypeError('CameraStatus benötigt einen Root-Adapter.');
    }

    // -----------------------------------------------------------------------
    // 1. Sichere Anzeigehelfer
    // -----------------------------------------------------------------------

    const parseInteger = (value) => {
      const parsed = parseInt(value, 10);
      return Number.isFinite(parsed) ? parsed : null;
    };

    const formatDateTime = (isoValue) => {
      if (!isoValue) return '—';
      const date = new Date(isoValue);
      if (!Number.isFinite(date.getTime())) return '—';
      try {
        const formatted = new Intl.DateTimeFormat('de-DE', {
          timeZone: 'Europe/Berlin',
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        }).format(date);
        return `${formatted.replace(',', '')} Uhr`;
      } catch {
        return `${date.toLocaleString('de-DE')} Uhr`;
      }
    };

    const formatMinutes = (seconds) => {
      const value = Number.isFinite(seconds) ? seconds : parseInteger(seconds);
      if (!value) return '—';
      return `${Math.max(0, Math.round(value / 60))} min`;
    };

    // -----------------------------------------------------------------------
    // 2. Statusdaten laden
    // -----------------------------------------------------------------------

    // Mehrere schnelle Klicks sollen nicht dieselbe Public-API parallel laden.
    // Nur laufende Requests teilen: erneutes Öffnen braucht aktuellen Status.
    const pendingRequests = new Map();

    const getDetailUrl = () => {
      const root = getLiveRoot();
      return root?.getAttribute('data-api-camera-detail')
        || root?.dataset.apiCameraDetail
        || '';
    };

    const fetchCameraDetail = async () => {
      const apiUrl = getDetailUrl();
      if (!apiUrl) return null;
      if (pendingRequests.has(apiUrl)) return pendingRequests.get(apiUrl);

      const request = fetch(apiUrl, {
        headers: { Accept: 'application/json', 'X-WC-TEST': '1' },
        credentials: 'same-origin',
      })
        .then((response) => {
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          return response.json();
        })
        .catch(() => null)
        .finally(() => pendingRequests.delete(apiUrl));

      pendingRequests.set(apiUrl, request);
      return request;
    };

    // -----------------------------------------------------------------------
    // 3. Telemetrie darstellen
    // -----------------------------------------------------------------------

    const buildStatusHtml = (detail) => {
      if (!detail || typeof detail !== 'object') return '';
      const camera = detail.camera && typeof detail.camera === 'object' ? detail.camera : {};
      const status = detail.status && typeof detail.status === 'object' ? detail.status : {};
      const lastCapture = status.last_capture_at || camera.last_capture_at || null;
      const intervalSeconds = Number.isFinite(camera.interval_seconds)
        ? camera.interval_seconds
        : parseInteger(camera.interval_seconds);
      const failures = Number.isFinite(status.consecutive_failures)
        ? status.consecutive_failures
        : parseInteger(status.consecutive_failures);
      const resetsTotal = Number.isFinite(status.resets_total)
        ? status.resets_total
        : parseInteger(status.resets_total);

      // Ausschließlich formatierte Datumswerte und Zahlen werden eingesetzt;
      // freie Kamera- oder Fehlermeldungstexte gelangen nicht als HTML hierher.
      return `
        <h3>Status &amp; Telemetrie</h3>
        <dl class="telemetry-list">
          <div><dt>Letzte Auslösung</dt><dd>${formatDateTime(lastCapture)}</dd></div>
          <div><dt>Letzter Erfolg</dt><dd>${formatDateTime(status.last_success_at)}</dd></div>
          <div><dt>Letzter Fehler</dt><dd>${formatDateTime(status.last_failure_at)}</dd></div>
          <div><dt>Fehler in Folge</dt><dd>${Number.isFinite(failures) ? failures : 0}</dd></div>
          <div><dt>Intervall</dt><dd>${formatMinutes(intervalSeconds ?? 600)}</dd></div>
          <div><dt>Resets gesamt</dt><dd>${Number.isFinite(resetsTotal) ? resetsTotal : 0}</dd></div>
          <div><dt>Letzte Wetterdaten</dt><dd>${formatDateTime(status.last_weather_at)}</dd></div>
          <div><dt>Letzter Reset</dt><dd>${formatDateTime(status.last_reset_at)}</dd></div>
        </dl>
      `.trim();
    };

    const applyStatusDetail = (detail, contentTarget) => {
      const html = buildStatusHtml(detail);
      if (!html) return;
      const statusSource = document.querySelector('[data-status-content][hidden]');
      if (statusSource) statusSource.innerHTML = html;
      if (contentTarget) contentTarget.innerHTML = html;

      // Der Zeitstempel wird auch von der Liveansicht benötigt. Die API kann
      // ISO-Zeit liefern; nur ein erfolgreich geparster Unixwert wird gesetzt.
      const lastCapture = detail?.status?.last_capture_at || detail?.camera?.last_capture_at || null;
      const timestamp = lastCapture ? Math.floor(new Date(lastCapture).getTime() / 1000) : NaN;
      const root = getLiveRoot();
      const currentTimestamp = Number(root?.dataset.lastTs);
      if (root && Number.isFinite(timestamp)
        && (!Number.isFinite(currentTimestamp) || timestamp > currentTimestamp)) {
        root.dataset.lastTs = String(timestamp);
      }
    };

    // -----------------------------------------------------------------------
    // 4. Aktuellen Dialog und seine Trigger binden
    // -----------------------------------------------------------------------

    const boundModals = new WeakSet();
    const boundTriggers = new WeakSet();
    let keyboardBound = false;

    const closeModal = (modal) => {
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.hidden = true;
    };

    const bindModal = (modal) => {
      if (!modal || boundModals.has(modal)) return;
      boundModals.add(modal);
      modal.querySelectorAll('[data-status-close]').forEach((element) => {
        element.addEventListener('click', () => closeModal(modal));
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
      });
    };

    const init = () => {
      const root = getLiveRoot();
      if (!root) return;
      // Der Trigger wird zusammen mit dem Kamerainhalt ausgetauscht. Das Modal
      // liegt dagegen außerhalb dieses Roots und bleibt beim Blättern bestehen.
      const modal = document.querySelector('[data-status-modal]');
      const contentTarget = modal?.querySelector('[data-status-content]') || null;
      if (!modal || !contentTarget) return;
      bindModal(modal);
      if (!keyboardBound) {
        keyboardBound = true;
        document.addEventListener('keydown', (event) => {
          if (event.key !== 'Escape') return;
          const currentModal = document.querySelector('[data-status-modal]');
          if (currentModal?.classList.contains('is-open')) closeModal(currentModal);
        });
      }

      root.querySelectorAll('[data-open-status]').forEach((trigger) => {
        if (boundTriggers.has(trigger)) return;
        boundTriggers.add(trigger);
        trigger.addEventListener('click', async (event) => {
          event.preventDefault();
          const fallback = document.querySelector('[data-status-content][hidden]')?.innerHTML || '';
          contentTarget.innerHTML = fallback.trim() !== ''
            ? fallback
            : '<p class="muted">Status wird geladen…</p>';
          modal.hidden = false;
          modal.classList.add('is-open');
          const detail = await fetchCameraDetail();
          if (detail && modal.isConnected) applyStatusDetail(detail, contentTarget);
        });
      });
    };

    return Object.freeze({ init });
  };

  window.WebcamCameraStatus = Object.freeze({ create });
})();
