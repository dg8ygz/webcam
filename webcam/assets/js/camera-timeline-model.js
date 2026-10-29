(() => {
  'use strict';

  /**
   * Berechnet das Präsentationsmodell der Kamera-Timeline.
   *
   * Das Modul führt keine Requests aus und verändert keinen DOM. Es übersetzt
   * eine bereits geladene API-Antwort in direkte Bildnachbarn, Kalenderziele,
   * Pickeroptionen und dokumentrelative URLs. Damit lassen sich die zahlreichen
   * Navigationsregeln getrennt vom Cache- und Abbruchverhalten prüfen.
   */
  const MONTH_NAMES = Object.freeze([
    '',
    'Januar',
    'Februar',
    'März',
    'April',
    'Mai',
    'Juni',
    'Juli',
    'August',
    'September',
    'Oktober',
    'November',
    'Dezember',
  ]);

  const create = ({ parseIntSafe, getAppBasePath, normalizeNavMode } = {}) => {
    if ([parseIntSafe, getAppBasePath, normalizeNavMode].some(
      (dependency) => typeof dependency !== 'function'
    )) {
      throw new Error('Timeline-Modell: notwendige Seitenadapter fehlen.');
    }

  // ---------------------------------------------------------------------
  // Dokumentrelative Navigations-URLs.
  // ---------------------------------------------------------------------

  const buildCameraPath = (slug) => {
    const safeSlug = encodeURIComponent(slug || '');
    const base = getAppBasePath();
    return base ? `${base}/camera/${safeSlug}` : `/camera/${safeSlug}`;
  };

  /**
   * Übernimmt Auswahl und Ansichtsmodus in einen Snapshot-Link. History und
   * Playback bleiben dadurch beim Bildwechsel erhalten. Ein Nav-Modus wird
   * nur geschrieben, wenn er von der normalen Zeitnavigation abweicht.
   */
  const buildEntryUrl = (entry, cameraSlug, navModeExplicit = undefined) => {
    if (!entry) {
      return null;
    }
    if (entry.url && !entry.timestamp && !entry.year) {
      return entry.url;
    }

    const params = new URLSearchParams();
    const currentParams = new URLSearchParams(window.location.search);
    const viewParam = currentParams.get('view');
    if (viewParam === 'history' || viewParam === 'playback') {
      params.set('view', viewParam);
    }
    if (entry.timestamp) {
      params.set('ts', String(entry.timestamp));
    }
    if (entry.year) {
      params.set('year', String(entry.year).padStart(4, '0'));
    }
    if (entry.month) {
      params.set('month', String(entry.month).padStart(2, '0'));
    }
    if (entry.day) {
      params.set('day', String(entry.day).padStart(2, '0'));
    }
    if (entry.time !== null && entry.time !== undefined) {
      params.set('time', String(entry.time).padStart(6, '0'));
    }

    const requestedMode = navModeExplicit !== undefined
      ? normalizeNavMode(navModeExplicit)
      : normalizeNavMode(currentParams.get('nav'));
    if (requestedMode && requestedMode !== 'time') {
      params.set('nav', requestedMode);
    }

    const basePath = cameraSlug
      ? buildCameraPath(cameraSlug)
      : (window.location.pathname || '/camera');
    const query = params.toString();
    return query ? `${basePath}?${query}` : basePath;
  };

  // ---------------------------------------------------------------------
  // API-Normalisierung und direkte Bildnachbarn.
  // ---------------------------------------------------------------------

  const normalizeEntry = (raw) => {
    if (!raw || typeof raw !== 'object') {
      return null;
    }
    const likesCount = Number.isFinite(raw.likes_count)
      ? raw.likes_count
      : parseIntSafe(raw.likes_count);
    return {
      timestamp: parseIntSafe(raw.timestamp),
      year: parseIntSafe(raw.year),
      month: parseIntSafe(raw.month),
      day: parseIntSafe(raw.day),
      time: parseIntSafe(raw.time),
      time_label: raw.time_label || null,
      datetime_label: raw.datetime_label || null,
      url: raw.url || null,
      preview_url: raw.preview_url || null,
      variants: raw.variants || null,
      relative_path: typeof raw.relative_path === 'string' ? raw.relative_path : null,
      logo_detected: raw.logo_detected ?? null,
      exif_text: typeof raw.exif_text === 'string'
        ? raw.exif_text
        : (typeof raw.exif === 'string' ? raw.exif : null),
      weather_text: typeof raw.weather_text === 'string'
        ? raw.weather_text
        : (typeof raw.weather === 'string' ? raw.weather : null),
      likes_count: Number.isFinite(likesCount) ? likesCount : null,
    };
  };

  /**
   * Findet Nachbarn in einer chronologisch sortierten Liste. Der Stunden-
   * sprung sucht das Bild mit dem kleinsten Abstand zu exakt +/- 3600
   * Sekunden; bei Lücken bleibt die Richtung trotzdem strikt erhalten.
   */
  const findNeighbors = (entries, selectedTs) => {
    if (!entries.length || !selectedTs) {
      return {
        selectedIndex: -1,
        selected: null,
        prev: null,
        next: null,
        dayPrev: null,
        dayNext: null,
        hourPrev: null,
        hourNext: null,
      };
    }

    let selectedIndex = entries.findIndex((entry) => entry.timestamp === selectedTs);
    if (selectedIndex === -1) {
      selectedIndex = entries.findIndex((entry) => entry.timestamp > selectedTs);
      if (selectedIndex === -1) {
        selectedIndex = entries.length - 1;
      }
    }
    const selected = entries[selectedIndex] || entries[entries.length - 1];
    const prev = entries[selectedIndex - 1] || null;
    const next = entries[selectedIndex + 1] || null;
    const selectedDateKey = selected
      ? `${selected.year}-${selected.month}-${selected.day}`
      : null;

    let dayPrev = null;
    for (let index = selectedIndex - 1; index >= 0; index -= 1) {
      const entry = entries[index];
      if (`${entry.year}-${entry.month}-${entry.day}` !== selectedDateKey) {
        dayPrev = entry;
        break;
      }
    }

    let dayNext = null;
    for (let index = selectedIndex + 1; index < entries.length; index += 1) {
      const entry = entries[index];
      if (`${entry.year}-${entry.month}-${entry.day}` !== selectedDateKey) {
        dayNext = entry;
        break;
      }
    }

    const closestToHour = (target, direction) => {
      let best = null;
      let bestDelta = null;
      entries.forEach((entry) => {
        if ((direction < 0 && entry.timestamp >= selectedTs)
          || (direction > 0 && entry.timestamp <= selectedTs)) {
          return;
        }
        const delta = Math.abs(entry.timestamp - target);
        const winsTie = best && delta === bestDelta
          && ((direction < 0 && entry.timestamp > best.timestamp)
            || (direction > 0 && entry.timestamp < best.timestamp));
        if (bestDelta === null || delta < bestDelta || winsTie) {
          best = entry;
          bestDelta = delta;
        }
      });
      return best;
    };

    return {
      selectedIndex,
      selected,
      prev,
      next,
      dayPrev,
      dayNext,
      hourPrev: closestToHour(selectedTs - 3600, -1),
      hourNext: closestToHour(selectedTs + 3600, 1),
    };
  };

  const buildTimePickerOptions = (entries, selectedIndex, maxSteps = 6) => {
    if (selectedIndex < 0 || !entries.length) {
      return [];
    }
    const options = [];
    for (let index = selectedIndex - maxSteps; index <= selectedIndex + maxSteps; index += 1) {
      if (index < 0 || index >= entries.length) {
        continue; // eslint-disable-line no-continue
      }
      const entry = entries[index];
      const compactTime = entry.time
        ? String(entry.time).padStart(6, '0').slice(0, 4).replace(/(\d{2})(\d{2})/, '$1:$2')
        : '–';
      options.push({
        entry,
        kind: index < selectedIndex ? 'past' : (index === selectedIndex ? 'current' : 'future'),
        label: entry.time_label || compactTime,
      });
    }
    return options;
  };

  // ---------------------------------------------------------------------
  // Kalender-Nachbarn für Jahr, Monat und Tag.
  // ---------------------------------------------------------------------

  const buildSelectedHint = (context) => {
    if (!context.requestYear || !context.requestMonth || !context.requestDay) {
      return null;
    }
    return {
      year: context.requestYear,
      month: context.requestMonth,
      day: context.requestDay,
      timestamp: context.currentTs || null,
      time: parseIntSafe(context.urlParams.get('time')) || null,
      time_label: context.urlParams.get('time') || null,
    };
  };

  const buildLabels = (selected, years) => ({
    year: selected ? String(selected.year) : (years.length ? String(years[years.length - 1]) : '—'),
    month: selected ? (MONTH_NAMES[selected.month] || String(selected.month).padStart(2, '0')) : '—',
    day: selected
      ? `${String(selected.day).padStart(2, '0')}.${selected.month ? `${String(selected.month).padStart(2, '0')}.` : ''}`
      : '—',
    hour: selected
      ? `${String(Math.floor((selected.time || 0) / 10000)).padStart(2, '0')} Uhr`
      : '—',
    time: selected ? (selected.time_label || 'Zeit') : 'Zeit',
  });

  const resolveSelection = (data, context) => {
    const entries = Array.isArray(data.entries)
      ? data.entries.map(normalizeEntry).filter(Boolean)
      : [];
    const combined = [...entries];
    const dayPrevEntry = normalizeEntry(data.day_prev_entry);
    const dayNextEntry = normalizeEntry(data.day_next_entry);
    if (dayPrevEntry) combined.push(dayPrevEntry);
    if (dayNextEntry) combined.push(dayNextEntry);
    combined.sort((left, right) => left.timestamp - right.timestamp);
    if (!combined.length) {
      return null;
    }

    let neighbors = findNeighbors(combined, context.currentTs);
    let selected = neighbors.selected || buildSelectedHint(context);
    if (!selected) {
      selected = combined[combined.length - 1];
      neighbors = findNeighbors(combined, selected.timestamp);
    }

    let timePicker = [];
    if (entries.length) {
      let selectedIndex = selected?.timestamp
        ? entries.findIndex((entry) => entry.timestamp === selected.timestamp)
        : -1;
      if (selectedIndex === -1) {
        selectedIndex = entries.length - 1;
      }
      timePicker = buildTimePickerOptions(entries, selectedIndex);
    }

    const years = Array.isArray(data.years)
      ? data.years.map((year) => parseIntSafe(year)).filter(Boolean)
      : [];
    return {
      entries,
      years,
      selected,
      prev: neighbors.prev,
      next: neighbors.next,
      dayPrev: neighbors.dayPrev,
      dayNext: neighbors.dayNext,
      hourPrev: neighbors.hourPrev,
      hourNext: neighbors.hourNext,
      timePicker,
      labels: buildLabels(selected, years),
    };
  };

  const getMonthList = (months, daysMeta, year) => {
    if (!year) {
      return [];
    }
    const yearKey = String(year);
    const rawMonths = months[yearKey]
      ? months[yearKey]
      : (daysMeta[yearKey] ? Object.keys(daysMeta[yearKey]) : []);
    return rawMonths
      .map((month) => parseIntSafe(month))
      .filter(Boolean)
      .sort((left, right) => left - right);
  };

  const pickDayForMonth = (daysMeta, year, month, desiredDay, direction) => {
    if (!year || !month) {
      return null;
    }
    const yearDays = daysMeta[String(year)] || {};
    const rawDays = Array.isArray(yearDays[String(month)]) ? yearDays[String(month)] : [];
    const days = rawDays
      .map((day) => parseIntSafe(day))
      .filter(Boolean)
      .sort((left, right) => left - right);
    if (!days.length) {
      return null;
    }
    if (!desiredDay) {
      return direction === 'next' ? days[0] : days[days.length - 1];
    }
    const candidates = direction === 'next'
      ? days.filter((day) => day >= desiredDay)
      : days.filter((day) => day <= desiredDay);
    if (candidates.length) {
      return direction === 'next' ? candidates[0] : candidates[candidates.length - 1];
    }
    return direction === 'next' ? days[0] : days[days.length - 1];
  };

  const pickMonthForYear = (months, daysMeta, year, preferredMonth, direction) => {
    const monthList = getMonthList(months, daysMeta, year);
    if (!monthList.length) {
      return null;
    }
    if (preferredMonth && monthList.includes(preferredMonth)) {
      return preferredMonth;
    }
    return direction === 'next' ? monthList[0] : monthList[monthList.length - 1];
  };

  /** Ergänzt Kalenderziele, die nicht allein aus den geladenen Tageszeilen folgen. */
  const applyCalendarMeta = (state, data, selection) => {
    const months = data.months || {};
    const daysMeta = data.days || {};
    const { years, selected, prev, next, dayPrev, dayNext, hourPrev, hourNext } = selection;
    let selectedYear = selected?.year || (years.length ? years[years.length - 1] : null);
    const selectedMonth = selected?.month || null;
    let dayPrevMeta = null;
    let dayNextMeta = null;

    if (years.length) {
      const sortedYears = [...years].sort((left, right) => left - right);
      selectedYear = selectedYear || sortedYears[sortedYears.length - 1];
      const previousYears = sortedYears.filter((year) => year < selectedYear);
      const nextYears = sortedYears.filter((year) => year > selectedYear);
      const prevYear = previousYears.length ? previousYears[previousYears.length - 1] : null;
      const nextYear = nextYears.length ? nextYears[0] : null;

      if (prevYear) {
        const month = pickMonthForYear(months, daysMeta, prevYear, selectedMonth, 'prev');
        const day = pickDayForMonth(daysMeta, prevYear, month, selected?.day, 'prev');
        if (month && day) {
          state.yearPrev = { year: prevYear, month, day, time: selected?.time || null };
        }
      }
      if (nextYear) {
        const month = pickMonthForYear(months, daysMeta, nextYear, selectedMonth, 'next');
        const day = pickDayForMonth(daysMeta, nextYear, month, selected?.day, 'next');
        if (month && day) {
          state.yearNext = { year: nextYear, month, day, time: selected?.time || null };
        }
      }
    }

    if (selectedYear) {
      const monthList = getMonthList(months, daysMeta, selectedYear);
      const currentMonth = selectedMonth || (monthList.length ? monthList[monthList.length - 1] : null);
      if (currentMonth) {
        const previousMonths = monthList.filter((month) => month < currentMonth);
        const nextMonths = monthList.filter((month) => month > currentMonth);
        if (previousMonths.length) {
          const month = previousMonths[previousMonths.length - 1];
          const day = pickDayForMonth(daysMeta, selectedYear, month, selected?.day, 'prev');
          if (day) state.monthPrev = { year: selectedYear, month, day, time: selected?.time || null };
        }
        if (nextMonths.length) {
          const month = nextMonths[0];
          const day = pickDayForMonth(daysMeta, selectedYear, month, selected?.day, 'next');
          if (day) state.monthNext = { year: selectedYear, month, day, time: selected?.time || null };
        }

        // Am Jahresrand wird der letzte beziehungsweise erste vorhandene
        // Monat des Nachbarjahres zum Monatsziel.
        if (!state.monthPrev) {
          const previousYears = years.filter((year) => year < selectedYear).sort((a, b) => a - b);
          const year = previousYears.length ? previousYears[previousYears.length - 1] : null;
          const month = year ? pickMonthForYear(months, daysMeta, year, null, 'prev') : null;
          const day = month ? pickDayForMonth(daysMeta, year, month, selected?.day, 'prev') : null;
          if (year && month && day) state.monthPrev = { year, month, day, time: selected?.time || null };
        }
        if (!state.monthNext) {
          const nextYears = years.filter((year) => year > selectedYear).sort((a, b) => a - b);
          const year = nextYears.length ? nextYears[0] : null;
          const month = year ? pickMonthForYear(months, daysMeta, year, null, 'next') : null;
          const day = month ? pickDayForMonth(daysMeta, year, month, selected?.day, 'next') : null;
          if (year && month && day) state.monthNext = { year, month, day, time: selected?.time || null };
        }

        const rawDays = daysMeta[String(selectedYear)]?.[String(currentMonth)] || [];
        const dayList = rawDays
          .map((day) => parseIntSafe(day))
          .filter(Boolean)
          .sort((left, right) => left - right);
        const selectedDay = selected?.day || dayList[dayList.length - 1];
        const previousDays = dayList.filter((day) => day < selectedDay);
        const nextDays = dayList.filter((day) => day > selectedDay);
        if (previousDays.length) {
          dayPrevMeta = {
            year: selectedYear,
            month: currentMonth,
            day: previousDays[previousDays.length - 1],
            time: null,
          };
        }
        if (nextDays.length) {
          dayNextMeta = { year: selectedYear, month: currentMonth, day: nextDays[0], time: null };
        }
      }
    }

    // API day entries are also the edges used by next/previous-image links.
    // Calendar-day links must instead retain the selected wall-clock time.
    // Omit the edge timestamp/URL so the server resolves the nearest image
    // on that date rather than giving the old edge timestamp precedence.
    const sameTimeOnDay = (entry) => entry ? {
      year: entry.year, month: entry.month, day: entry.day,
      time: selected?.time ?? entry.time ?? null,
    } : null;
    state.dayPrev = sameTimeOnDay(state.dayPrev || dayPrevMeta || dayPrev || null);
    state.dayNext = sameTimeOnDay(state.dayNext || dayNextMeta || dayNext || null);
    state.hourPrev = hourPrev || prev || dayPrevMeta || dayPrev || null;
    state.hourNext = hourNext || next || dayNextMeta || dayNext || null;
  };

  const buildState = (data, selection, context) => {
    const { selected, labels } = selection;
    const state = {
      selectedTs: selected?.timestamp || context.currentTs,
      selectedLabel: selected ? (selected.time_label || labels.time) : labels.time,
      prev: selection.prev,
      next: selection.next,
      dayPrev: selection.dayPrev,
      dayNext: selection.dayNext,
      hourPrev: selection.hourPrev,
      hourNext: selection.hourNext,
      timePicker: selection.timePicker,
      labels,
      latestUrl: data.latest_global
        ? buildEntryUrl(normalizeEntry(data.latest_global), context.cameraSlug)
        : (data.latest ? buildEntryUrl(normalizeEntry(data.latest), context.cameraSlug) : null),
      yearPrev: null,
      yearNext: null,
      monthPrev: null,
      monthNext: null,
    };
    applyCalendarMeta(state, data, selection);
    return state;
  };

    const buildPresentation = (data, context) => {
      const selection = resolveSelection(data, context);
      return {
        data,
        context,
        selection,
        state: selection ? buildState(data, selection, context) : null,
      };
    };

    return Object.freeze({
      buildCameraPath,
      buildEntryUrl,
      buildPresentation,
    });
  };

  window.WebcamTimelineModel = Object.freeze({ create });
})();
