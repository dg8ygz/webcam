(() => {
  'use strict';

  /**
   * Übersetzt API-/DOM-Daten in die einheitlichen Einträge der Lightbox.
   *
   * Das Modul hat drei eng zusammengehörige Aufgaben:
   * 1. die beste verfügbare Bildvariante und eine kurze EXIF-Zeile wählen,
   * 2. Tageskennungen in der Zeitzone der Kamera zuverlässig bilden,
   * 3. das eingeblendete Bild-Overlay ausschließlich mit Textknoten ergänzen.
   *
   * Laden benachbarter Tage, Best-of-Paginierung, Zoom und Tastatursteuerung
   * bleiben im Lightbox-Koordinator. So ist hier keine eigene Netzwerkschicht
   * und kein zweiter Navigationszustand versteckt.
   */
  const create = ({
    liveRoot,
    parseIntSafe,
    normalizeAssetUrl,
    applyEmbeddedOverlayState,
    formatDateInTimezone,
  } = {}) => {
    const dependencies = [parseIntSafe, normalizeAssetUrl, applyEmbeddedOverlayState, formatDateInTimezone];
    if (dependencies.some((dependency) => typeof dependency !== 'function')) {
      throw new Error('Lightbox-Eintragsmodell: notwendige Seitenadapter fehlen.');
    }

    // Timeline-Antworten aus mehreren Ausbauphasen verwenden verschiedene
    // Variantennamen. Die feste Reihenfolge bevorzugt stets das größte Bild.
    const pickBestVariantUrl = (entry) => {
      if (!entry) return null;
      const variants = entry.variants || {};
      const order = ['full', 'original', '1600', 'la', 'lm', 'hu', 'sm', 'best'];
      for (const key of order) {
        if (variants[key]?.url) return variants[key].url;
      }
      return entry.url || entry.preview_url || null;
    };

    // EXIF-Sidecars enthalten „Schlüssel | Wert“-Zeilen. In der Lightbox
    // reichen Belichtungszeit, Blende und ISO; unbekannte Zeilen bleiben weg.
    const computeExifSummary = (text = '') => {
      if (!text) return '';
      let exposureTime = '';
      let exposureBias = '';
      let aperture = '';
      let iso = '';
      text.split(/\r?\n/).forEach((line) => {
        const [rawKey, rawValue] = line.split('|').map((part) => part && part.trim());
        if (!rawKey || !rawValue) return;
        const key = rawKey.toLowerCase();
        if (key.includes('exposure time') || key === 'exposuretime') {
          exposureTime = rawValue.replace(/seconds?/i, 'sec.').replace(/\s+/g, ' ');
        } else if (key.includes('exposure bias')) {
          exposureBias = rawValue.replace(/\s+/g, ' ');
        } else if (key.includes('fnumber') || key === 'f-number') {
          aperture = rawValue.startsWith('f') ? rawValue : `f/${rawValue}`;
        } else if (key === 'iso' || key.includes('iso ')) {
          iso = rawValue.startsWith('ISO') ? rawValue : `ISO ${rawValue}`;
        }
      });
      return [exposureTime || exposureBias, aperture, iso].filter(Boolean).join(' · ');
    };

    // Fehlermeldungen externer Wetterkommandos dürfen weder als Messwert noch
    // als sichtbarer Overlaytext an öffentliche Besucher gelangen.
    const looksLikeWeatherCommandError = (value) => {
      if (typeof value !== 'string') return false;
      const normalized = value.trim().toLowerCase();
      return normalized.startsWith('curl:')
        || normalized.includes('could not resolve host')
        || normalized.includes('resolving timed out')
        || normalized.includes('operation timed out')
        || normalized.includes('weather model:');
    };

    const normalizeOverlayText = (value, preserveLines = false) => {
      if (typeof value !== 'string' || looksLikeWeatherCommandError(value)) return '';
      // Nur die Anzeige verdichten; Herkunft und Messzeit bleiben im Roh-Sidecar.
      if (/^Source:\s*local DHT22\s*$/mi.test(value)) {
        const temp = value.match(/^Temp:\s*(-?\d+(?:[.,]\d+)?)\s*C\s*$/mi);
        const hum = value.match(/^Humidity:\s*(\d+(?:[.,]\d+)?)\s*%\s*$/mi);
        if (temp && hum) return `Temp: ${temp[1].replace('.', ',')} °C · Hum: ${hum[1].replace('.', ',')} %`;
      }
      if (!preserveLines) return value.replace(/\s+/g, ' ').trim();
      const normalized = value
        .split(/\r?\n/)
        .map((line) => line.replace(/[ \t]+/g, ' ').trim())
        .filter(Boolean)
        .join('\n');
      return looksLikeWeatherCommandError(normalized) ? '' : normalized;
    };

    const normalizeOverlayTimestampLabel = (value) => {
      const label = normalizeOverlayText(value);
      if (!label) return '';
      return /\bUhr$/i.test(label) ? label : `${label} Uhr`;
    };

    const formatOverlayTimestamp = (entry) => {
      if (!entry) return '';
      if (entry.datetime_label) return normalizeOverlayTimestampLabel(entry.datetime_label);
      const timestamp = parseIntSafe(entry.timestamp);
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      if (!Number.isFinite(date.getTime())) return '';
      try {
        return `${new Intl.DateTimeFormat('de-DE', {
          timeZone: 'Europe/Berlin',
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        }).format(date).replace(',', '')} Uhr`;
      } catch {
        return `${date.toLocaleString('de-DE')} Uhr`;
      }
    };

    /**
     * Übernimmt Metadaten des ausgewählten Timeline-Eintrags in das Overlay.
     * Alle dynamischen Werte werden mit textContent ausgegeben. Das explizite
     * Leeren per innerHTML erzeugt selbst keinen benutzergesteuerten HTML-Code.
     */
    const applyEntryOverlay = (entry) => {
      if (!entry || !liveRoot) return;
      applyEmbeddedOverlayState(entry);
      const rawExifText = typeof entry.exif_text === 'string' ? entry.exif_text.trim() : '';
      const weatherText = normalizeOverlayText(entry.weather_text || '', true);
      const likesCount = Number.isFinite(entry.likes_count)
        ? entry.likes_count
        : parseIntSafe(entry.likes_count) || 0;
      if (rawExifText) liveRoot.dataset.exifText = rawExifText;
      if (weatherText) liveRoot.dataset.weatherText = weatherText;

      const overlay = liveRoot.querySelector('[data-live-overlay]');
      if (!overlay) return;
      const datePattern = /\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}\s+Uhr\b/i;
      const weatherPattern = /(°C|\bT\s*:|\bP\s*:|\bW\s*:|Wind|Press|Temp)/i;
      const existingParts = Array.from(overlay.querySelectorAll('.live-overlay__item'))
        .map((element) => element?.textContent?.trim() || '')
        .filter(Boolean);
      const existingDate = existingParts
        .map((part) => part.match(datePattern)?.[0] || '')
        .find(Boolean) || existingParts[0] || '';
      const existingWeather = existingParts
        .map((part) => part.replace(datePattern, '').trim())
        .find((part) => weatherPattern.test(part)) || '';
      const existingExif = existingParts.find((part) => {
        const lower = part.toLowerCase();
        return lower.includes('iso') || lower.includes('sec') || lower.includes('f/');
      }) || '';
      const existingLikes = existingParts.find((part) => /Likes/i.test(part)) || '';
      const dateLabel = formatOverlayTimestamp(entry) || existingDate;
      const primaryPart = [dateLabel, weatherText || existingWeather].filter(Boolean).join(' ');
      const parts = [
        primaryPart,
        computeExifSummary(rawExifText) || existingExif,
        likesCount > 0 ? `❤ ${likesCount} Likes` : existingLikes,
      ].filter(Boolean);
      if (!parts.length || (parts.length === existingParts.length
        && parts.every((part, index) => part === existingParts[index]))) return;

      overlay.innerHTML = '';
      parts.forEach((part) => {
        const span = document.createElement('span');
        span.className = 'live-overlay__item';
        span.textContent = part;
        overlay.appendChild(span);
      });
    };

    // Tageskennungen werden in der Kamerazeitzone gebildet. Dies ist für
    // Navigation über Mitternacht und Sommerzeitgrenzen zwingend.
    const getTimezone = () => liveRoot?.dataset.timezone || 'Europe/Berlin';
    const buildDayKey = (year, month, day) => {
      if (!year || !month || !day) return null;
      const pad2 = (value) => String(value).padStart(2, '0');
      return `${year}-${pad2(month)}-${pad2(day)}`;
    };
    const parseDayKey = (value) => {
      const match = typeof value === 'string' ? value.match(/^(\d{4})-(\d{2})-(\d{2})$/) : null;
      return match ? {
        year: Number.parseInt(match[1], 10),
        month: Number.parseInt(match[2], 10),
        day: Number.parseInt(match[3], 10),
      } : null;
    };
    const resolveEntryDayKey = (entry) => {
      if (!entry) return null;
      const year = parseIntSafe(entry.year);
      const month = parseIntSafe(entry.month);
      const day = parseIntSafe(entry.day);
      if (year && month && day) return buildDayKey(year, month, day);
      const timestamp = parseIntSafe(entry.timestamp);
      return timestamp ? formatDateInTimezone(timestamp, getTimezone()) : null;
    };
    const resolveSelectedDayKey = (fallbackTimestamp = null) => {
      const year = parseIntSafe(liveRoot?.dataset.selectedYear);
      const month = parseIntSafe(liveRoot?.dataset.selectedMonth);
      const day = parseIntSafe(liveRoot?.dataset.selectedDay);
      if (year && month && day) return buildDayKey(year, month, day);
      return fallbackTimestamp ? formatDateInTimezone(fallbackTimestamp, getTimezone()) : null;
    };

    const mapEntryToItem = (entry) => {
      if (!entry) return null;
      const url = normalizeAssetUrl(pickBestVariantUrl(entry));
      if (!url) return null;
      const exifPath = entry.exif_relative || entry.exif_path || null;
      const exif = entry.exif_text || entry.exif || '';
      const dayKey = resolveEntryDayKey(entry);
      const dayParts = parseDayKey(dayKey);
      return {
        url,
        timestamp: parseIntSafe(entry.timestamp),
        datetime: entry.datetime_label || entry.date_label || '',
        time: entry.time_label || '',
        exif,
        exifShort: computeExifSummary(exif),
        exifPath: exifPath ? normalizeAssetUrl(exifPath) : null,
        dayKey,
        year: dayParts?.year || null,
        month: dayParts?.month || null,
        day: dayParts?.day || null,
      };
    };
    const buildTimelineItems = (data) => {
      const entries = Array.isArray(data?.entries) ? data.entries : [];
      return entries
        .map(mapEntryToItem)
        .filter(Boolean)
        .sort((left, right) => (left.timestamp || 0) - (right.timestamp || 0));
    };

    return Object.freeze({
      applyEntryOverlay,
      computeExifSummary,
      formatOverlayTimestamp,
      getTimezone,
      buildDayKey,
      parseDayKey,
      resolveEntryDayKey,
      resolveSelectedDayKey,
      mapEntryToItem,
      buildTimelineItems,
    });
  };

  window.WebcamCameraLightboxEntry = Object.freeze({ create });
})();
