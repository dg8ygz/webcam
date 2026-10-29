(() => {
  'use strict';

  /**
   * DOM-freies Datenmodell für die Wetteransicht.
   *
   * Die Timeline liefert historische Wetterinformationen als frei formatierten
   * Text. Dieses Modul bildet daraus genau ein stabiles Tagesmodell:
   *
   * 1. API-Felder und Uhrzeiten defensiv normalisieren.
   * 2. Temperatur, Luftdruck und Windrichtung aus bekannten Legacy-Formaten
   *    lesen; unbekannte Texte werden verworfen statt erraten.
   * 3. Messpunkte chronologisch sortieren und je Metrik eigene Reihen bilden.
   * 4. Den jeweils letzten gültigen Wert für die Kopf-/Statistikdarstellung
   *    bestimmen.
   *
   * SVG, Tooltip, Modal und Netzwerkcache bleiben bewusst außerhalb. Dadurch
   * lässt sich die fehleranfällige Textübersetzung isoliert prüfen.
   */
  const DIRECTION_DEGREES = Object.freeze({
    N: 0, NNO: 22.5, NO: 45, ONO: 67.5,
    O: 90, OSO: 112.5, SO: 135, SSO: 157.5,
    S: 180, SSW: 202.5, SW: 225, WSW: 247.5,
    W: 270, WNW: 292.5, NW: 315, NNW: 337.5,
  });
  const DIRECTION_ALIASES = Object.freeze({
    E: 'O', ENE: 'ONO', ESE: 'OSO', NE: 'NO', NNE: 'NNO', SE: 'SO', SSE: 'SSO',
  });
  const DIRECTION_ORDER = Object.freeze([
    'N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO',
    'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW',
  ]);

  const parseInteger = (value) => {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : null;
  };

  const parseNumber = (value) => {
    if (typeof value !== 'string') return null;
    const parsed = Number.parseFloat(value.replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : null;
  };

  const formatNumber = (value, fractionDigits = 1) => {
    if (!Number.isFinite(value)) return '—';
    return value.toFixed(fractionDigits).replace('.', ',');
  };

  const formatTime = (seconds) => {
    if (!Number.isFinite(seconds)) return '—';
    const total = Math.max(0, Math.min(86400, Math.round(seconds)));
    const hours = String(Math.floor(total / 3600)).padStart(2, '0');
    const minutes = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
    return `${hours}:${minutes} Uhr`;
  };

  const normalizeDirection = (value) => {
    if (!value || typeof value !== 'string') return null;
    const compact = value
      .trim()
      .toUpperCase()
      .replace(/Ä/g, 'AE')
      .replace(/Ö/g, 'OE')
      .replace(/Ü/g, 'UE')
      .replace(/[^A-Z]/g, '');
    return compact ? (DIRECTION_ALIASES[compact] || compact) : null;
  };

  const parseMeasurement = (text) => {
    if (!text || typeof text !== 'string') return null;
    const normalized = text.replace(/\s+/g, ' ').trim();
    if (!normalized) return null;

    const temperatureMatch = normalized.match(/\b(?:Temp(?:\.|eratur)?|T)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*°?\s*C\b/i);
    const pressureMatch = normalized.match(/\b(?:Press(?:\.|ure)?|Luftdruck|P)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*(?:mbar|hpa)\b/i);
    const humidityMatch = normalized.match(/\b(?:Hum(?:idity)?|Luftfeuchte)\s*:\s*(-?\d+(?:[.,]\d+)?)\s*%/i);
    const windMatch = normalized.match(/\b(?:Windr\.?|Windrichtung|Winddir)\s*:\s*([A-Za-zÄÖÜäöü\/-]+)\b/i);
    const windSpeedMatch = normalized.match(/\bWind\s*:\s*-?\d+(?:[.,]\d+)?\s*(?:km\/h|kmh|m\/s|kn)\s*,?\s*([A-Za-zÄÖÜäöü\/-]+)\b/i);
    const compactWindMatch = normalized.match(/\bW\s*:\s*-?\d+(?:[.,]\d+)?\s*(?:km\/h|kmh|m\/s|kn)?\s*,?\s*([A-Za-zÄÖÜäöü\/-]+)\b/i);
    const windDirection = normalizeDirection(
      windMatch?.[1] || windSpeedMatch?.[1] || compactWindMatch?.[1] || ''
    );
    const temperature = parseNumber(temperatureMatch?.[1] || '');
    const pressure = parseNumber(pressureMatch?.[1] || '');
    const humidityValue = parseNumber(humidityMatch?.[1] || '');
    const humidity = humidityValue !== null && humidityValue >= 0 && humidityValue <= 100 ? humidityValue : null;
    const windDegrees = windDirection
      && Object.prototype.hasOwnProperty.call(DIRECTION_DEGREES, windDirection)
      ? DIRECTION_DEGREES[windDirection]
      : null;
    if (!Number.isFinite(temperature) && !Number.isFinite(pressure) && !Number.isFinite(humidity) && !windDirection) {
      return null;
    }
    return {
      tempC: temperature,
      pressureMBar: pressure,
      humidityPercent: humidity,
      windDir: windDirection,
      windDeg: windDegrees,
      rawText: normalized,
    };
  };

  const normalizeEntry = (raw) => {
    if (!raw || typeof raw !== 'object') return null;
    return {
      timestamp: parseInteger(raw.timestamp),
      time: parseInteger(raw.time),
      time_label: typeof raw.time_label === 'string' ? raw.time_label : null,
      datetime_label: typeof raw.datetime_label === 'string' ? raw.datetime_label : null,
      url: typeof raw.url === 'string' ? raw.url : null,
      preview_url: typeof raw.preview_url === 'string' ? raw.preview_url : null,
      variants: raw.variants && typeof raw.variants === 'object' ? raw.variants : null,
      weather_text: typeof raw.weather_text === 'string'
        ? raw.weather_text
        : (typeof raw.weather === 'string' ? raw.weather : null),
    };
  };

  const resolveDaySeconds = (entry) => {
    if (!entry) return null;
    if (entry.time !== null && entry.time !== undefined) {
      const compact = String(entry.time).padStart(6, '0');
      if (/^\d{6}$/.test(compact)) {
        const hours = Number.parseInt(compact.slice(0, 2), 10);
        const minutes = Number.parseInt(compact.slice(2, 4), 10);
        const seconds = Number.parseInt(compact.slice(4, 6), 10);
        if (hours <= 23 && minutes <= 59 && seconds <= 59) {
          return (hours * 3600) + (minutes * 60) + seconds;
        }
      }
    }
    const labelMatch = typeof entry.time_label === 'string'
      ? entry.time_label.match(/(\d{2}):(\d{2})(?::(\d{2}))?/)
      : null;
    if (labelMatch) {
      return (Number.parseInt(labelMatch[1], 10) * 3600)
        + (Number.parseInt(labelMatch[2], 10) * 60)
        + Number.parseInt(labelMatch[3] || '0', 10);
    }
    const timestamp = parseInteger(entry.timestamp);
    if (!timestamp) return null;
    const date = new Date(timestamp * 1000);
    return Number.isFinite(date.getTime())
      ? (date.getHours() * 3600) + (date.getMinutes() * 60) + date.getSeconds()
      : null;
  };

  const latestMatching = (samples, predicate) => {
    for (let index = samples.length - 1; index >= 0; index -= 1) {
      if (predicate(samples[index])) return samples[index];
    }
    return null;
  };

  const metricSeries = (samples, key) => samples
    .filter((sample) => Number.isFinite(sample[key]))
    .map((sample) => ({
      timestamp: sample.timestamp,
      seconds: sample.seconds,
      timeLabel: sample.timeLabel,
      datetimeLabel: sample.datetimeLabel,
      previewUrl: sample.previewUrl,
      rawText: sample.rawText,
      value: sample[key],
    }));

  const create = ({ resolvePreviewUrl } = {}) => {
    if (typeof resolvePreviewUrl !== 'function') {
      throw new TypeError('Wettermodell benötigt resolvePreviewUrl().');
    }

    const buildDayPayload = (data) => {
      const entries = Array.isArray(data?.entries)
        ? data.entries.map(normalizeEntry).filter(Boolean)
        : [];
      const samples = entries
        .map((entry) => {
          const measurement = parseMeasurement(entry.weather_text || '');
          const seconds = resolveDaySeconds(entry);
          if (!measurement || !Number.isFinite(seconds)) return null;
          return {
            timestamp: parseInteger(entry.timestamp),
            seconds,
            timeLabel: entry.time_label || formatTime(seconds),
            datetimeLabel: entry.datetime_label || null,
            previewUrl: resolvePreviewUrl(entry),
            ...measurement,
          };
        })
        .filter(Boolean)
        .sort((left, right) => (left.timestamp || left.seconds) - (right.timestamp || right.seconds));
      const latestSample = samples.at(-1) || null;
      return {
        sampleCount: samples.length,
        latestSample,
        latestTimeLabel: latestSample?.timeLabel || '—',
        tempSeries: metricSeries(samples, 'tempC'),
        pressureSeries: metricSeries(samples, 'pressureMBar'),
        humiditySeries: metricSeries(samples, 'humidityPercent'),
        windSeries: samples
          .filter((sample) => Number.isFinite(sample.windDeg))
          .map((sample) => ({ seconds: sample.seconds, value: sample.windDeg })),
        latestTempSample: latestMatching(samples, (sample) => Number.isFinite(sample.tempC)),
        latestPressureSample: latestMatching(samples, (sample) => Number.isFinite(sample.pressureMBar)),
        latestHumiditySample: latestMatching(samples, (sample) => Number.isFinite(sample.humidityPercent)),
        latestWindSample: latestMatching(samples, (sample) => !!sample.windDir),
        windSamples: samples.filter((sample) => !!sample.windDir),
      };
    };

    return Object.freeze({ buildDayPayload });
  };

  window.WebcamCameraWeatherModel = Object.freeze({
    create,
    formatNumber,
    formatTime,
    directionDegrees: DIRECTION_DEGREES,
    directionOrder: DIRECTION_ORDER,
  });
})();
