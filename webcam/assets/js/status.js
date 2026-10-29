(() => {
  'use strict';

  // -------------------------------------------------------------------------
  // Öffentliche Statusseite
  // -------------------------------------------------------------------------
  //
  // Dieses Modul besitzt die beiden dynamischen Aufgaben der Statusseite:
  //
  // 1. Detaildialoge aus den serverseitig vorbereiteten Zustandsdaten öffnen.
  // 2. Die sichtbaren Tabellenzeilen minütlich über die Public API auffrischen.
  //
  // Das PHP-Template rendert weiterhin die vollständige, ohne JavaScript
  // nutzbare Tabelle. Es übergibt dem Browser lediglich JSON in einem
  // application/json-Element. Dadurch bleiben Daten und Verhalten getrennt,
  // ohne einen neuen API- oder Cachepfad einzuführen.

  const readPageData = () => {
    const element = document.getElementById('status-page-data');
    if (!element) {
      return null;
    }

    try {
      const payload = JSON.parse(element.textContent || '{}');
      return payload && typeof payload === 'object' ? payload : null;
    } catch (error) {
      console.warn('status page data could not be parsed', error);
      return null;
    }
  };

  // Alle Texte, die später in HTML-Fragmente eingefügt werden, passieren diese
  // Grenze. Der Browser übernimmt die eigentliche HTML-Kodierung über
  // textContent; Status-, Wetter- oder EXIF-Werte werden nie als Markup gelesen.
  const escapeHtml = (value) => {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  };

  const formatTimestamp = (value) => {
    if (!value) {
      return '—';
    }

    const numeric = typeof value === 'number'
      ? value
      : (typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : null);
    const date = numeric !== null ? new Date(numeric * 1000) : new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
  };

  const formatAge = (seconds) => {
    if (seconds === null || seconds === undefined) {
      return '—';
    }

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
      return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    return `${hours} h ${minutes % 60} min`;
  };

  const formatIso = (value) => {
    if (!value) {
      return '—';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
  };

  const computeSeverity = (ageSeconds, intervalSeconds) => {
    if (ageSeconds === null || ageSeconds === undefined) {
      return 'unknown';
    }

    const interval = Math.max(60, Number(intervalSeconds) || 600);
    if (ageSeconds <= interval * 2) {
      return 'ok';
    }
    if (ageSeconds <= interval * 3) {
      return 'warn';
    }
    return 'error';
  };

  // -------------------------------------------------------------------------
  // Detaildialog
  // -------------------------------------------------------------------------

  const renderSummary = (summaryElement, state, exifUrl) => {
    const failureNote = state.last_failure_note
      ? escapeHtml(state.last_failure_note)
      : '—';
    const successNote = state.last_success_note
      ? escapeHtml(state.last_success_note)
      : '—';
    const capturePath = state.last_capture_path
      ? `<code>${escapeHtml(state.last_capture_path)}</code>`
      : '—';
    const weatherDetail = state.last_weather_data
      ? `<pre class="summary-weather">${escapeHtml(state.last_weather_data)}</pre>`
      : '—';
    const weatherQuality = state.weather_quality || {};
    const weatherQualityCounts = weatherQuality.counts && typeof weatherQuality.counts === 'object'
      ? Object.entries(weatherQuality.counts)
        .map(([key, value]) => `${escapeHtml(key)}=${escapeHtml(String(value))}`)
        .join(', ')
      : '';
    const weatherQualityDetail = weatherQuality.status === 'error'
      ? `<div class="summary-span">
          <dt>Wetterqualität</dt>
          <dd>
            <strong>Fehler</strong>
            ${weatherQualityCounts ? `<br>${weatherQualityCounts}` : ''}
            ${weatherQuality.latest_reason ? `<br>${escapeHtml(weatherQuality.latest_reason)}` : ''}
          </dd>
        </div>`
      : '';

    const exifEntries = Object.entries(state.last_exif || {});
    let exifHtml = '';
    if (exifEntries.length) {
      const listItems = exifEntries
        .map(([key, value]) => `
          <li>
            <strong>${escapeHtml(key)}</strong>
            <span>${escapeHtml(value)}</span>
          </li>`)
        .join('');
      const exifDownload = exifUrl
        ? `<a class="summary-exif__download" href="${escapeHtml(exifUrl)}" target="_blank" rel="noopener">als Datei öffnen</a>`
        : '';
      exifHtml = `
        <div class="summary-span">
          <dt>EXIF</dt>
          <dd>
            <details class="exif-details summary-exif">
              <summary>Daten anzeigen</summary>
              <ul>${listItems}</ul>
              ${exifDownload}
            </details>
          </dd>
        </div>`;
    }

    summaryElement.innerHTML = `
      <dl>
        <div><dt>Letzter Erfolg</dt><dd>${formatTimestamp(state.last_success_at)}</dd></div>
        <div><dt>Erfolgsnotiz</dt><dd>${successNote}</dd></div>
        <div class="summary-span"><dt>Letzte Auslösung</dt><dd>${formatTimestamp(state.last_capture_at)}</dd></div>
        <div class="summary-span"><dt>Speicherpfad</dt><dd>${capturePath}</dd></div>
        <div><dt>Letzter Fehler</dt><dd>${formatTimestamp(state.last_failure_at)}</dd></div>
        <div><dt>Fehlernotiz</dt><dd>${failureNote}</dd></div>
        <div><dt>Fehler in Folge</dt><dd>${state.consecutive_failures ?? 0}</dd></div>
        <div class="summary-span"><dt>Wetterstand</dt><dd>${formatTimestamp(state.last_weather_at)}</dd></div>
        <div class="summary-span"><dt>Wetterdaten</dt><dd>${weatherDetail}</dd></div>
        ${weatherQualityDetail}
        ${exifHtml}
        <div><dt>Resets gesamt</dt><dd>${state.resets_total ?? 0}</dd></div>
      </dl>`;
  };

  const renderEvents = (eventsElement, state) => {
    let events = Array.isArray(state.events) ? [...state.events] : [];
    events.sort((left, right) => (right.timestamp ?? 0) - (left.timestamp ?? 0));
    events = events.slice(0, 10);

    // Ältere Zustandsdateien besitzen noch keine Ereignisliste. Die beiden
    // bekannten letzten Zustände ergeben dann weiterhin eine nützliche Ansicht.
    if (!events.length) {
      if (state.last_failure_at) {
        events.push({
          timestamp: state.last_failure_at,
          type: 'failure',
          message: state.last_failure_note || null,
          path: state.last_capture_path || null,
        });
      }
      if (state.last_success_at) {
        events.push({
          timestamp: state.last_success_at,
          type: 'success',
          message: state.last_success_note || null,
          path: state.last_capture_path || null,
        });
      }
    }

    if (!events.length) {
      eventsElement.innerHTML = '<p>Keine Ereignisse aufgezeichnet.</p>';
      return;
    }

    eventsElement.innerHTML = `
      <ul>
        ${events.map((event) => `
          <li>
            <time>${formatTimestamp(event.timestamp)}</time>
            <strong>${escapeHtml(event.type || 'event')}</strong>
            ${event.message ? `<span>${escapeHtml(event.message)}</span>` : ''}
            ${event.path ? `<code>${escapeHtml(event.path)}</code>` : ''}
          </li>`).join('')}
      </ul>`;
  };

  const stopModalEventPropagation = (container) => {
    ['click', 'mousedown', 'mouseup', 'pointerdown', 'pointerup'].forEach((type) => {
      container.addEventListener(type, (event) => event.stopPropagation());
    });
    container.addEventListener('wheel', (event) => event.stopPropagation(), { passive: true });
    container.addEventListener('touchstart', (event) => event.stopPropagation(), { passive: true });
  };

  const initialiseModal = ({ template, entries, detailApiBase }) => {
    const rowBySlug = new Map(entries.map((entry) => [entry.slug, entry]));

    const openModal = (slug) => {
      const data = rowBySlug.get(slug);
      if (!data) {
        return;
      }

      const modal = template.content.firstElementChild.cloneNode(true);
      const container = modal.querySelector('.status-modal__content');
      const closeButton = modal.querySelector('.status-modal__close');
      const summary = modal.querySelector('.status-modal__summary');
      const events = modal.querySelector('.status-modal__events');
      if (!container || !closeButton || !summary || !events) {
        return;
      }

      container.setAttribute('tabindex', '-1');
      modal.querySelector('.status-modal__title').textContent = data.title;
      modal.querySelector('[data-modal-link]').setAttribute('href', data.detailUrl);
      modal.querySelector('[data-modal-log]').setAttribute('href', data.logUrl);
      renderSummary(summary, data.state || {}, data.exifUrl);
      renderEvents(events, data.state || {});
      stopModalEventPropagation(container);

      const removeModal = () => {
        modal.remove();
        document.removeEventListener('keydown', onKeyDown);
      };
      const onKeyDown = (event) => {
        if (event.key === 'Escape') {
          removeModal();
        }
      };

      closeButton.addEventListener('click', removeModal);
      document.addEventListener('keydown', onKeyDown);
      document.body.appendChild(modal);

      // Der Serverrender bleibt sofort sichtbar. Die Detail-API ergänzt danach
      // nur frischere Statuswerte und darf einen Dialogfehler nicht blockieren.
      const refreshDetail = async () => {
        if (!detailApiBase) {
          return;
        }
        try {
          const response = await fetch(`${detailApiBase}${encodeURIComponent(slug)}`, {
            headers: { 'X-WC-TEST': '1' },
          });
          if (!response.ok) {
            return;
          }
          const payload = await response.json();
          if (payload && payload.status && typeof payload.status === 'object') {
            data.state = { ...data.state, ...payload.status };
            renderSummary(summary, data.state, data.exifUrl);
          }
        } catch (error) {
          console.warn('status detail refresh failed', error);
        }
      };

      refreshDetail();
      container.focus({ preventScroll: true });
    };

    document.querySelectorAll('[data-modal-trigger]').forEach((button) => {
      button.addEventListener('click', () => {
        const slug = button.getAttribute('data-modal-trigger');
        if (slug) {
          openModal(slug);
        }
      });
    });
  };

  // -------------------------------------------------------------------------
  // Minütlicher Tabellen-Refresh
  // -------------------------------------------------------------------------

  const updateBadge = (cell, severity) => {
    if (!cell) {
      return;
    }

    cell.classList.remove('severity-ok', 'severity-warn', 'severity-error', 'severity-unknown');
    cell.classList.add(`severity-${severity}`);
    const badge = cell.querySelector('.badge') || cell.appendChild(document.createElement('span'));
    badge.className = 'badge';
    if (severity === 'ok') badge.classList.add('badge-ok');
    else if (severity === 'warn') badge.classList.add('badge-warn');
    else if (severity === 'error') badge.classList.add('badge-error');
    badge.textContent = severity === 'ok'
      ? 'OK'
      : severity === 'warn'
        ? 'Warnung'
        : severity === 'error' ? 'Fehler' : 'Unbekannt';
  };

  const applyRow = (row, camera) => {
    const age = camera.age_seconds ?? null;
    const interval = camera.interval_seconds ?? (Number(row.dataset.interval) || 600);
    const weatherQualityError = camera.weather_quality?.status === 'error';
    const duplicateActive = camera.duplicate_active === true;
    row.dataset.weatherQualityError = weatherQualityError ? '1' : '0';
    row.dataset.duplicateActive = duplicateActive ? '1' : '0';

    const severity = weatherQualityError || duplicateActive
      ? 'error'
      : computeSeverity(age, interval);
    row.classList.remove('severity-ok', 'severity-warn', 'severity-error', 'severity-unknown');
    row.classList.add(`severity-${severity}`);

    const setText = (field, text) => {
      const cell = row.querySelector(`[data-field="${field}"]`);
      if (cell) {
        cell.textContent = text;
      }
    };
    setText('last_capture', formatIso(camera.last_capture_at));
    // last_success ist nicht Teil der kompakten Public-Liste und bleibt daher
    // beim serverseitig gerenderten Wert.
    setText('age', formatAge(age));
    updateBadge(row.querySelector('[data-field="severity"]'), severity);
  };

  const initialiseLiveRefresh = (apiUrl) => {
    if (!apiUrl) {
      return;
    }

    const rows = new Map(
      Array.from(document.querySelectorAll('tbody tr[data-slug]'))
        .map((row) => [row.dataset.slug, row]),
    );

    const refresh = async () => {
      try {
        const response = await fetch(apiUrl, { headers: { 'X-WC-TEST': '1' } });
        if (!response.ok) {
          return;
        }
        const payload = await response.json();
        (payload.cameras || []).forEach((camera) => {
          const row = rows.get(camera.slug);
          if (row) {
            applyRow(row, camera);
          }
        });
      } catch (error) {
        // Die statische Tabelle bleibt bei einem Netzfehler vollständig nutzbar.
        console.warn('status refresh failed', error);
      }
    };

    refresh();
    window.setInterval(refresh, 60000);
  };

  const initialise = () => {
    const pageData = readPageData();
    const template = document.getElementById('status-modal-template');
    if (!pageData || !template) {
      return;
    }

    initialiseModal({
      template,
      entries: Array.isArray(pageData.rows) ? pageData.rows : [],
      detailApiBase: String(pageData.detailApiBase || ''),
    });
    initialiseLiveRefresh(String(pageData.listApiUrl || ''));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialise, { once: true });
  } else {
    initialise();
  }
})();
