(() => {
  'use strict';

  // -------------------------------------------------------------------------
  // Öffentliche Fabrik.
  //
  // Diese Datei kennt weder das Kamera-Modal noch die übrige Timeline-
  // Oberfläche. camera.js liefert genau drei Adapter:
  // - resolveContext bestimmt Kamera und ausgewählten Kalendertag,
  // - loadTimelineDay liefert die vorhandene API-Antwort dieses Tages,
  // - resolvePreviewUrl wählt eine erreichbare Bildvariante aus.
  //
  // Das Ergebnis ist ein kleines Objekt mit loadContent(). Dadurch kann die
  // allgemeine Modalsteuerung den fertigen DOM-Baum einsetzen, ohne Details
  // über Wetterformate, SVGs oder Hover-Zustände kennen zu müssen.
  // -------------------------------------------------------------------------
  const create = ({
    resolvePreviewUrl,
    resolveContext,
    loadTimelineDay,
  } = {}) => {
    if (typeof resolvePreviewUrl !== 'function') {
      throw new TypeError('WebcamCameraWeather benötigt resolvePreviewUrl().');
    }
    if (typeof resolveContext !== 'function') {
      throw new TypeError('WebcamCameraWeather benötigt resolveContext().');
    }
    if (typeof loadTimelineDay !== 'function') {
      throw new TypeError('WebcamCameraWeather benötigt loadTimelineDay().');
    }

    // Der Cache lebt nur während dieses Browserdokuments. Aktuelle Tage werden
    // kurz gehalten, historische Tage ändern sich nicht mehr und dürfen länger
    // wiederverwendet werden. Es entsteht bewusst keine neue Server-/JSON-
    // Cachequelle.
    const weatherDayCache = new Map();

    // Das DOM-freie Teilmodul übernimmt Parsing, Sortierung und die Auswahl
    // der letzten gültigen Messwerte. Diese Datei konzentriert sich dadurch
    // auf SVG/DOM und den kleinen Browsercache.
    const weatherModelFactory = window.WebcamCameraWeatherModel;
    if (!weatherModelFactory || typeof weatherModelFactory.create !== 'function') {
      throw new Error('WebcamCameraWeatherModel wurde nicht vor camera-weather.js geladen.');
    }
    const weatherModel = weatherModelFactory.create({ resolvePreviewUrl });
    const formatWeatherNumber = weatherModelFactory.formatNumber;
    const formatWeatherTimeLabel = weatherModelFactory.formatTime;
    const WEATHER_DIRECTION_DEGREES = weatherModelFactory.directionDegrees;
    const WEATHER_DIRECTION_ORDER = weatherModelFactory.directionOrder;

    // Diagramm-Attribute kommen als Zeichenketten aus dem DOM. Für diese rein
    // visuelle Konvertierung bleiben zwei bewusst kleine lokale Helfer hier.
    const parseIntSafe = (value) => {
      const parsed = Number.parseInt(value, 10);
      return Number.isFinite(parsed) ? parsed : null;
    };

    const parseFloatSafe = (value) => {
      const parsed = Number.parseFloat(value);
      return Number.isFinite(parsed) ? parsed : null;
    };

    const weatherPointLabels = (sample, valueFormatter) => {
      if (!sample) {
        return { time: '', value: '—' };
      }
      return {
        time: sample.timeLabel || formatWeatherTimeLabel(sample.seconds),
        value: typeof valueFormatter === 'function'
          ? valueFormatter(sample.value)
          : String(sample.value ?? '—'),
      };
    };

    // Uhrzeit und Messwert stehen bewusst in getrennten Zeilen. Der frühere
    // Einzeiler "11:50 · 20,3 °C" benötigte zu viel Breite und konnte dadurch
    // in den Bereich der Skalen-/Maximalwerte hineinragen.
    const renderWeatherCurrentValue = (container, sample, valueFormatter) => {
      const labels = weatherPointLabels(sample, valueFormatter);
      const time = document.createElement('span');
      time.className = 'weather-chart__current-time';
      time.textContent = labels.time;
      const value = document.createElement('span');
      value.className = 'weather-chart__current-value';
      value.textContent = labels.value;
      container.replaceChildren(time, value);
    };

    // -----------------------------------------------------------------------
    // Diagrammgeometrie und sicheres SVG-Markup.
    // Alle interpolierten Werte stammen aus Zahlen, festen Farben oder einer
    // geschlossenen Liste normalisierter Windrichtungen.
    // -----------------------------------------------------------------------
    const buildNumericWeatherRange = (series, minimumPadding) => {
      const values = Array.isArray(series) ? series.map((point) => point.value).filter(Number.isFinite) : [];
      if (!values.length) {
        return null;
      }
      let min = Math.min(...values);
      let max = Math.max(...values);
      if (min === max) {
        const pad = Math.max(minimumPadding, Math.abs(min) * 0.02 || 0.5);
        min -= pad;
        max += pad;
      } else {
        const pad = Math.max((max - min) * 0.18, minimumPadding);
        min -= pad;
        max += pad;
      }
      return { min, max };
    };

    const buildNumericWeatherTicks = (range, formatter) => {
      if (!range) {
        return [];
      }
      const mid = (range.min + range.max) / 2;
      return [
        { value: range.max, label: formatter(range.max) },
        { value: mid, label: formatter(mid) },
        { value: range.min, label: formatter(range.min) },
      ];
    };

    const buildWeatherPath = (series, xScale, yScale, breakDistance = null) => {
      let path = '';
      let previous = null;
      series.forEach((point) => {
        const x = xScale(point.seconds);
        const y = yScale(point.value);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
          return;
        }
        const shouldBreak = previous && Number.isFinite(breakDistance)
          && Math.abs(point.value - previous.value) > breakDistance;
        path += `${(!previous || shouldBreak) ? 'M' : ' L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
        previous = point;
      });
      return path.trim();
    };

    const buildWeatherPoints = (series, xScale, yScale, color) => {
      return series.map((point, index) => {
        const x = xScale(point.seconds).toFixed(1);
        const y = yScale(point.value).toFixed(1);
        return `<circle class="weather-chart__point" data-weather-point="${index}" cx="${x}" cy="${y}" r="2.8" fill="${color}" />`;
      }).join('');
    };

    const buildWeatherMetricSvg = ({
      series,
      range,
      ticks,
      color,
      currentSeconds,
      showXAxis = false,
      breakDistance = null,
      pointsOnly = false,
    }) => {
      if (!Array.isArray(series) || !series.length || !range) {
        return '';
      }
      const width = 720;
      const height = showXAxis ? 146 : 122;
      const padding = {
        top: 10,
        right: 14,
        bottom: showXAxis ? 28 : 10,
        left: 44,
      };
      const innerWidth = width - padding.left - padding.right;
      const innerHeight = height - padding.top - padding.bottom;
      const ySpan = Math.max(range.max - range.min, 0.0001);
      const xScale = (seconds) => padding.left + ((Math.max(0, Math.min(86400, seconds)) / 86400) * innerWidth);
      const yScale = (value) => padding.top + innerHeight - (((value - range.min) / ySpan) * innerHeight);
      const hourTicks = [0, 21600, 43200, 64800, 86400];
      const horizontalTicks = Array.isArray(ticks) ? ticks : [];
      const gridLines = hourTicks.map((tick) => {
        const x = xScale(tick).toFixed(1);
        return `<line x1="${x}" y1="${padding.top}" x2="${x}" y2="${padding.top + innerHeight}" stroke="rgba(255,255,255,0.08)" stroke-width="1" />`;
      }).join('');
      const axisLines = horizontalTicks.map((tick) => {
        const y = yScale(tick.value).toFixed(1);
        return `<line x1="${padding.left}" y1="${y}" x2="${padding.left + innerWidth}" y2="${y}" stroke="rgba(255,255,255,0.08)" stroke-width="1" />`;
      }).join('');
      const tickLabels = horizontalTicks.map((tick) => {
        const y = yScale(tick.value).toFixed(1);
        return `<text x="${padding.left - 8}" y="${Number.parseFloat(y) + 4}" text-anchor="end" fill="rgba(235,245,255,0.72)" font-size="11">${tick.label}</text>`;
      }).join('');
      const xLabels = showXAxis ? hourTicks.map((tick, idx) => {
        const x = xScale(tick).toFixed(1);
        const label = ['00', '06', '12', '18', '24'][idx];
        return `<text x="${x}" y="${height - 8}" text-anchor="middle" fill="rgba(235,245,255,0.72)" font-size="11">${label}</text>`;
      }).join('') : '';
      const currentMarker = Number.isFinite(currentSeconds)
        ? `<line x1="${xScale(currentSeconds).toFixed(1)}" y1="${padding.top}" x2="${xScale(currentSeconds).toFixed(1)}" y2="${padding.top + innerHeight}" stroke="rgba(255,255,255,0.28)" stroke-width="1" stroke-dasharray="4 4" />`
        : '';
      const path = pointsOnly ? '' : buildWeatherPath(series, xScale, yScale, breakDistance);
      const pathMarkup = path
        ? `<path d="${path}" fill="none" stroke="${color}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />`
        : '';
      const pointMarkup = buildWeatherPoints(series, xScale, yScale, color);
      return `
        <svg class="weather-chart__svg" viewBox="0 0 ${width} ${height}" role="img" aria-hidden="true">
          ${gridLines}
          ${axisLines}
          ${currentMarker}
          ${pathMarkup}
          ${pointMarkup}
          ${tickLabels}
          ${xLabels}
        </svg>
      `.trim();
    };

    const buildWindCompassPayload = (samples) => {
      const grouped = new Map();
      WEATHER_DIRECTION_ORDER.forEach((direction) => {
        grouped.set(direction, []);
      });
      samples.forEach((sample) => {
        if (!sample || !sample.windDir || !grouped.has(sample.windDir)) {
          return;
        }
        grouped.get(sample.windDir).push(sample);
      });
      const counts = WEATHER_DIRECTION_ORDER.map((direction) => ({
        direction,
        count: grouped.get(direction).length,
      })).filter((item) => item.count > 0);
      return {
        grouped,
        counts,
        maxCount: counts.length ? Math.max(...counts.map((item) => item.count)) : 0,
        total: samples.length,
      };
    };

    const buildWindCompassSvg = (samples, color, highlightDirection = null) => {
      const payload = buildWindCompassPayload(samples);
      if (!payload.total) {
        return '';
      }
      const width = 320;
      const height = 320;
      const centerX = 160;
      const centerY = 160;
      const labelRadius = 136;
      const ringOuterRadius = 104;
      const startRadius = 30;
      const availableRadius = Math.max(10, ringOuterRadius - startRadius);
      const rawStep = payload.maxCount > 1
        ? (availableRadius / (payload.maxCount - 1))
        : 0;
      const dotRadius = Math.max(1.6, Math.min(3.8, rawStep > 0 ? rawStep * 0.42 : 3.8));
      const highlightRadius = Math.min(dotRadius + 1, 4.8);
      const step = payload.maxCount > 1 ? rawStep : 0;
      const maxDotRadius = ringOuterRadius - highlightRadius - 1;
      const compassToPoint = (degrees, radius) => {
        const radians = ((degrees - 90) * Math.PI) / 180;
        return {
          x: centerX + (Math.cos(radians) * radius),
          y: centerY + (Math.sin(radians) * radius),
        };
      };
      const ringCount = Math.max(3, Math.min(6, payload.maxCount || 3));
      const rings = Array.from({ length: ringCount }, (_, index) => {
        const radius = startRadius + ((availableRadius / ringCount) * (index + 1));
        return `<circle cx="${centerX}" cy="${centerY}" r="${radius.toFixed(1)}" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1" />`;
      }).join('');
      const spokes = WEATHER_DIRECTION_ORDER.map((direction) => {
        const degrees = WEATHER_DIRECTION_DEGREES[direction];
        const point = compassToPoint(degrees, ringOuterRadius);
        const strength = ['N', 'O', 'S', 'W'].includes(direction) ? '0.24' : '0.12';
        return `<line x1="${centerX}" y1="${centerY}" x2="${point.x.toFixed(1)}" y2="${point.y.toFixed(1)}" stroke="rgba(255,255,255,${strength})" stroke-width="1" />`;
      }).join('');
      const labels = WEATHER_DIRECTION_ORDER.map((direction) => {
        const degrees = WEATHER_DIRECTION_DEGREES[direction];
        const point = compassToPoint(degrees, labelRadius);
        const isMajor = ['N', 'O', 'S', 'W'].includes(direction);
        const fill = direction === highlightDirection ? '#f8fafc' : 'rgba(235,245,255,0.74)';
        return `<text x="${point.x.toFixed(1)}" y="${(point.y + (isMajor ? 5 : 4)).toFixed(1)}" text-anchor="middle" fill="${fill}" font-size="${isMajor ? 12 : 10}" font-weight="${isMajor ? 700 : 500}">${direction}</text>`;
      }).join('');
      const dots = WEATHER_DIRECTION_ORDER.map((direction) => {
        const directionSamples = payload.grouped.get(direction) || [];
        if (!directionSamples.length) {
          return '';
        }
        const degrees = WEATHER_DIRECTION_DEGREES[direction];
        return directionSamples.map((sample, index) => {
          const radius = Math.min(maxDotRadius, startRadius + (index * step));
          const point = compassToPoint(degrees, radius);
          const isHighlight = highlightDirection && direction === highlightDirection && index === directionSamples.length - 1;
          return `<circle cx="${point.x.toFixed(1)}" cy="${point.y.toFixed(1)}" r="${(isHighlight ? highlightRadius : dotRadius).toFixed(1)}" fill="${color}" fill-opacity="${isHighlight ? '1' : '0.88'}" stroke="${isHighlight ? '#f8fafc' : 'rgba(15,23,42,0.55)'}" stroke-width="${isHighlight ? '1.8' : '1'}" />`;
        }).join('');
      }).join('');
      const countBadges = payload.counts.map((item) => {
        const point = compassToPoint(WEATHER_DIRECTION_DEGREES[item.direction], ringOuterRadius + 16);
        return `<text x="${point.x.toFixed(1)}" y="${(point.y + 4).toFixed(1)}" text-anchor="middle" fill="rgba(235,245,255,0.56)" font-size="10">${item.count}</text>`;
      }).join('');
      return `
        <svg class="weather-chart__svg weather-chart__svg--compass" viewBox="0 0 ${width} ${height}" role="img" aria-hidden="true">
          <circle cx="${centerX}" cy="${centerY}" r="${ringOuterRadius}" fill="rgba(255,255,255,0.02)" stroke="rgba(255,255,255,0.12)" stroke-width="1.2" />
          ${rings}
          ${spokes}
          ${labels}
          ${dots}
          ${countBadges}
          <circle cx="${centerX}" cy="${centerY}" r="4.5" fill="#f8fafc" />
        </svg>
      `.trim();
    };

    const createWeatherStat = (label, value) => {
      const stat = document.createElement('div');
      stat.className = 'weather-stat';
      const statLabel = document.createElement('div');
      statLabel.className = 'weather-stat__label';
      statLabel.textContent = label;
      const statValue = document.createElement('div');
      statValue.className = 'weather-stat__value';
      statValue.textContent = value;
      stat.append(statLabel, statValue);
      return stat;
    };

    const buildWeatherPreviewCard = () => {
      const card = document.createElement('div');
      card.className = 'weather-chart__preview';

      const imageWrap = document.createElement('div');
      imageWrap.className = 'weather-chart__preview-image';
      const image = document.createElement('img');
      image.alt = 'Vorschaubild zum Messpunkt';
      image.loading = 'lazy';
      const empty = document.createElement('div');
      empty.className = 'weather-chart__preview-empty';
      empty.textContent = 'Kein Bild';
      imageWrap.append(image, empty);

      card.append(imageWrap);
      return { card, image, empty };
    };

    const updateWeatherPreviewCard = (preview, sample) => {
      if (!preview) {
        return;
      }
      const previewUrl = sample?.previewUrl || '';
      if (previewUrl) {
        if (preview.image.src !== previewUrl) {
          preview.image.src = previewUrl;
        }
        preview.image.hidden = false;
        preview.empty.hidden = true;
        return;
      }
      preview.image.hidden = true;
      preview.image.removeAttribute('src');
      preview.empty.hidden = false;
    };

    const updateWeatherPreviewPlacement = (preview, svg, pointEl) => {
      if (!preview?.card || !svg || !pointEl) {
        return;
      }
      const viewBox = svg.viewBox?.baseVal;
      const pointX = parseFloatSafe(pointEl.getAttribute('cx'));
      if (!viewBox || !Number.isFinite(viewBox.width) || viewBox.width <= 0 || !Number.isFinite(pointX)) {
        preview.card.classList.remove('weather-chart__preview--left');
        return;
      }
      const pointRatio = pointX / viewBox.width;
      preview.card.classList.toggle('weather-chart__preview--left', pointRatio >= 0.58);
    };

    const buildWeatherChartTooltip = () => {
      const tooltip = document.createElement('div');
      tooltip.className = 'weather-chart__tooltip';
      tooltip.hidden = true;

      const title = document.createElement('strong');
      const value = document.createElement('span');
      tooltip.append(title, value);

      return { tooltip, title, value };
    };

    const positionWeatherTooltip = (tooltip, chartWrap, svg, pointEl) => {
      if (!tooltip || !chartWrap || !svg || !pointEl) {
        return;
      }
      const viewBox = svg.viewBox?.baseVal;
      if (!viewBox || !Number.isFinite(viewBox.width) || !Number.isFinite(viewBox.height) || viewBox.width <= 0 || viewBox.height <= 0) {
        return;
      }
      const wrapRect = chartWrap.getBoundingClientRect();
      const svgRect = svg.getBoundingClientRect();
      const pointX = parseFloatSafe(pointEl.getAttribute('cx'));
      const pointY = parseFloatSafe(pointEl.getAttribute('cy'));
      if (!Number.isFinite(pointX) || !Number.isFinite(pointY)) {
        return;
      }
      const scaleX = svgRect.width / viewBox.width;
      const scaleY = svgRect.height / viewBox.height;
      const baseLeft = svgRect.left - wrapRect.left;
      const baseTop = svgRect.top - wrapRect.top;
      const left = baseLeft + (pointX * scaleX);
      const top = baseTop + (pointY * scaleY);

      const tooltipWidth = tooltip.offsetWidth || 120;
      const tooltipHeight = tooltip.offsetHeight || 46;
      const clampedLeft = Math.max((tooltipWidth / 2) + 8, Math.min(left, wrapRect.width - (tooltipWidth / 2) - 8));
      const clampedTop = Math.max(10, top - tooltipHeight - 16);

      tooltip.style.left = `${clampedLeft}px`;
      tooltip.style.top = `${clampedTop}px`;
    };

    // Ermittelt den zeitlich nächstgelegenen Messpunkt allein über die
    // horizontale Zeigerposition. Dadurch muss niemand mehr den nur wenige
    // Pixel großen Kreis auf der Messkurve treffen: Jede Höhe innerhalb des
    // Diagramms wählt den zur Uhrzeit passenden Punkt aus.
    const resolveNearestWeatherPointIndex = (svg, pointNodes, clientX) => {
      if (!svg || !Array.isArray(pointNodes) || !pointNodes.length || !Number.isFinite(clientX)) {
        return null;
      }
      const svgRect = svg.getBoundingClientRect();
      const viewBox = svg.viewBox?.baseVal;
      if (!viewBox || svgRect.width <= 0 || viewBox.width <= 0) {
        return null;
      }

      // Browserkoordinaten werden in dieselbe Skala wie die cx-Werte der
      // SVG-Punkte übersetzt. Außerhalb liegende Werte werden an den Rand
      // geklemmt, sodass dort zuverlässig erster bzw. letzter Wert gilt.
      const pointerRatio = Math.max(0, Math.min(1, (clientX - svgRect.left) / svgRect.width));
      const pointerX = viewBox.x + (pointerRatio * viewBox.width);
      let nearestIndex = null;
      let nearestDistance = Number.POSITIVE_INFINITY;

      pointNodes.forEach((pointNode, pointIndex) => {
        const pointX = parseFloatSafe(pointNode.getAttribute('cx'));
        if (!Number.isFinite(pointX)) {
          return;
        }
        const distance = Math.abs(pointX - pointerX);
        if (distance < nearestDistance) {
          nearestDistance = distance;
          nearestIndex = pointIndex;
        }
      });
      return nearestIndex;
    };

    const renderWeatherChartSection = ({
      title,
      series,
      color,
      range,
      ticks,
      currentSeconds,
      valueFormatter,
      defaultSample = null,
      showPreview = false,
      sharedPreview = null,
      hostSharedPreview = false,
      showXAxis = false,
      breakDistance = null,
      pointsOnly = false,
    }) => {
      const section = document.createElement('section');
      section.className = 'weather-chart';
      const header = document.createElement('div');
      header.className = 'weather-chart__header';
      const titleEl = document.createElement('div');
      titleEl.className = 'weather-chart__title';
      titleEl.textContent = title;
      const currentEl = document.createElement('div');
      currentEl.className = 'weather-chart__current';
      currentEl.textContent = '—';
      header.append(titleEl, currentEl);
      section.appendChild(header);
      if (!series.length || !range) {
        const empty = document.createElement('div');
        empty.className = 'weather-chart__empty';
        empty.textContent = 'Keine Tagesdaten vorhanden.';
        section.appendChild(empty);
        return section;
      }
      const chartWrap = document.createElement('div');
      chartWrap.className = 'weather-chart__body';
      chartWrap.innerHTML = buildWeatherMetricSvg({
        series,
        range,
        ticks,
        color,
        currentSeconds,
        showXAxis,
        breakDistance,
        pointsOnly,
      });
      const svg = chartWrap.firstElementChild;
      if (!svg) {
        return section;
      }

      const pointNodes = Array.from(svg.querySelectorAll('[data-weather-point]'));
      const fallbackIndex = Math.max(0, series.length - 1);
      const fallbackSample = defaultSample || series[fallbackIndex] || null;

      // Der Kopf zeigt ausschließlich den neuesten Tageswert. Hover und Tap
      // wählen zwar einen Messpunkt für Vorschau und Tooltip aus, dürfen diese
      // feste Orientierung aber nicht durch einen historischen Wert ersetzen.
      renderWeatherCurrentValue(currentEl, fallbackSample, valueFormatter);

      const preview = sharedPreview || (showPreview ? buildWeatherPreviewCard() : null);
      if (preview) {
        const shouldAttachPreview = sharedPreview ? hostSharedPreview : true;
        if (shouldAttachPreview && preview.card.parentNode !== chartWrap) {
          chartWrap.appendChild(preview.card);
        }
      }
      const tooltip = buildWeatherChartTooltip();
      chartWrap.appendChild(tooltip.tooltip);

      const applyActiveSample = (index, { showTooltip = false } = {}) => {
        const resolvedIndex = Number.isInteger(index) && index >= 0 && index < series.length
          ? index
          : fallbackIndex;
        const sample = series[resolvedIndex] || fallbackSample;
        if (preview) {
          updateWeatherPreviewCard(preview, sample);
          updateWeatherPreviewPlacement(preview, svg, pointNodes[resolvedIndex] || null);
        }
        pointNodes.forEach((pointNode, pointIndex) => {
          pointNode.classList.toggle('is-active', pointIndex === resolvedIndex);
        });
        if (!showTooltip) {
          tooltip.tooltip.hidden = true;
          return;
        }
        const pointNode = pointNodes[resolvedIndex];
        if (!pointNode || !sample) {
          tooltip.tooltip.hidden = true;
          return;
        }
        tooltip.title.textContent = sample.timeLabel || formatWeatherTimeLabel(sample.seconds);
        tooltip.value.textContent = typeof valueFormatter === 'function'
          ? valueFormatter(sample.value)
          : String(sample.value ?? '—');
        tooltip.tooltip.hidden = false;
        positionWeatherTooltip(tooltip.tooltip, chartWrap, svg, pointNode);
      };

      pointNodes.forEach((pointNode) => {
        const pointIndex = parseIntSafe(pointNode.getAttribute('data-weather-point'));
        if (!Number.isInteger(pointIndex)) {
          return;
        }
        pointNode.addEventListener('pointerenter', () => {
          applyActiveSample(pointIndex, { showTooltip: true });
        });
        pointNode.addEventListener('pointerdown', () => {
          applyActiveSample(pointIndex, { showTooltip: true });
        });
      });

      // Maus und Stift dürfen das gesamte Diagramm als Zeitachse benutzen.
      // pointerdown deckt zusätzlich Tap/Touch ab, ohne die bisherige direkte
      // Bedienung der einzelnen Punkte zu entfernen.
      const applyNearestSampleFromPointer = (event) => {
        const nearestIndex = resolveNearestWeatherPointIndex(svg, pointNodes, event.clientX);
        if (Number.isInteger(nearestIndex)) {
          applyActiveSample(nearestIndex, { showTooltip: true });
        }
      };
      svg.addEventListener('pointermove', applyNearestSampleFromPointer);
      svg.addEventListener('pointerdown', applyNearestSampleFromPointer);

      chartWrap.addEventListener('pointerleave', () => {
        applyActiveSample(fallbackIndex, { showTooltip: false });
      });

      applyActiveSample(fallbackIndex, { showTooltip: false });
      section.appendChild(chartWrap);
      return section;
    };

    const renderWeatherCompassSection = ({
      title,
      currentLabel,
      samples,
      color,
      highlightDirection,
    }) => {
      const section = document.createElement('section');
      section.className = 'weather-chart weather-chart--compass';
      const header = document.createElement('div');
      header.className = 'weather-chart__header';
      const titleEl = document.createElement('div');
      titleEl.className = 'weather-chart__title';
      titleEl.textContent = title;
      const currentEl = document.createElement('div');
      currentEl.className = 'weather-chart__current';
      currentEl.textContent = currentLabel;
      header.append(titleEl, currentEl);
      section.appendChild(header);
      if (!samples.length) {
        const empty = document.createElement('div');
        empty.className = 'weather-chart__empty';
        empty.textContent = 'Keine Tagesdaten vorhanden.';
        section.appendChild(empty);
        return section;
      }
      const chartWrap = document.createElement('div');
      chartWrap.className = 'weather-chart__compass-wrap';
      chartWrap.innerHTML = buildWindCompassSvg(samples, color, highlightDirection);
      section.appendChild(chartWrap.firstElementChild);
      const summary = document.createElement('div');
      summary.className = 'weather-chart__summary';
      summary.textContent = `${samples.length} Richtungswerte. Jeder Punkt steht für einen Messwert; gleiche Richtungen werden radial addiert.`;
      section.appendChild(summary);
      return section;
    };

    const renderWeatherModalContent = (payload) => {
      const container = document.createElement('div');
      container.className = 'weather-panel';
      const hasTemperature = payload.tempSeries.length > 0;
      const hasPressure = payload.pressureSeries.length > 0;
      const hasHumidity = payload.humiditySeries.length > 0;
      const hasWind = payload.windSamples.length > 0;

      const intro = document.createElement('div');
      intro.className = 'weather-panel__intro';
      const rangeLabel = document.createElement('div');
      rangeLabel.textContent = payload.latestTimeLabel && payload.latestTimeLabel !== '—'
        ? `Tagesverlauf von 00:00 bis ${payload.latestTimeLabel}`
        : 'Tagesverlauf ab 00:00';
      const countLabel = document.createElement('div');
      countLabel.textContent = payload.sampleCount > 0
        ? `${payload.sampleCount} Messpunkte`
        : 'Keine Messpunkte';
      intro.append(rangeLabel, countLabel);
      container.appendChild(intro);

      const latestSeconds = payload.latestSample ? payload.latestSample.seconds : null;
      const charts = document.createElement('div');
      charts.className = 'weather-panel__charts';
      const sharedPreview = (hasTemperature || hasPressure || hasHumidity) ? buildWeatherPreviewCard() : null;
      if (hasTemperature) {
        const range = buildNumericWeatherRange(payload.tempSeries, 0.6);
        charts.appendChild(renderWeatherChartSection({
          title: 'Temperatur',
          series: payload.tempSeries,
          color: '#f97316',
          range,
          ticks: buildNumericWeatherTicks(range, (value) => formatWeatherNumber(value)),
          currentSeconds: latestSeconds,
          defaultSample: payload.tempSeries[payload.tempSeries.length - 1] || null,
          valueFormatter: (value) => `${formatWeatherNumber(value)} °C`,
          sharedPreview,
          hostSharedPreview: true,
        }));
      }
      if (hasPressure) {
        const range = buildNumericWeatherRange(payload.pressureSeries, 0.8);
        charts.appendChild(renderWeatherChartSection({
          title: 'Luftdruck',
          series: payload.pressureSeries,
          color: '#38bdf8',
          range,
          ticks: buildNumericWeatherTicks(range, (value) => formatWeatherNumber(value)),
          currentSeconds: latestSeconds,
          defaultSample: payload.pressureSeries[payload.pressureSeries.length - 1] || null,
          valueFormatter: (value) => `${formatWeatherNumber(value)} mBar`,
          sharedPreview,
          hostSharedPreview: !hasTemperature,
        }));
      }
      // Luftfeuchte ist auch ohne Temperatur eine eigenstaendige Datenreihe;
      // die gemeinsame Bildvorschau genau einmal an das erste Diagramm haengen.
      if (hasHumidity) {
        const range = { min: 0, max: 100 };
        charts.appendChild(renderWeatherChartSection({
          title: 'Luftfeuchte',
          series: payload.humiditySeries,
          color: '#14b8a6',
          range,
          ticks: buildNumericWeatherTicks(range, (value) => `${formatWeatherNumber(value)} %`),
          currentSeconds: latestSeconds,
          defaultSample: payload.humiditySeries[payload.humiditySeries.length - 1] || null,
          valueFormatter: (value) => `${formatWeatherNumber(value)} %`,
          sharedPreview,
          hostSharedPreview: !hasTemperature && !hasPressure,
        }));
      }
      if (hasWind) {
        charts.appendChild(renderWeatherCompassSection({
          title: 'Windrichtung',
          currentLabel: payload.latestWindSample && payload.latestWindSample.windDir
            ? payload.latestWindSample.windDir
            : '—',
          samples: payload.windSamples,
          color: '#22c55e',
          highlightDirection: payload.latestWindSample?.windDir || null,
        }));
      }
      if (charts.childElementCount > 0) {
        container.appendChild(charts);
      }

      const stats = document.createElement('div');
      stats.className = 'weather-panel__stats';
      if (payload.latestTimeLabel && payload.latestTimeLabel !== '—') {
        stats.appendChild(createWeatherStat('Stand', payload.latestTimeLabel));
      }
      if (hasTemperature) {
        stats.appendChild(createWeatherStat(
          'Temperatur aktuell',
          payload.latestTempSample && Number.isFinite(payload.latestTempSample.tempC)
            ? `${formatWeatherNumber(payload.latestTempSample.tempC)} °C`
            : '—'
        ));
      }
      if (hasWind) {
        stats.appendChild(createWeatherStat(
          'Windrichtung aktuell',
          payload.latestWindSample && payload.latestWindSample.windDir
            ? payload.latestWindSample.windDir
            : '—'
        ));
      }
      if (hasPressure) {
        stats.appendChild(createWeatherStat(
          'Luftdruck aktuell',
          payload.latestPressureSample && Number.isFinite(payload.latestPressureSample.pressureMBar)
            ? `${formatWeatherNumber(payload.latestPressureSample.pressureMBar)} mBar`
            : '—'
        ));
      }
      if (hasHumidity) stats.appendChild(createWeatherStat('Luftfeuchte aktuell',
        `${formatWeatherNumber(payload.latestHumiditySample.humidityPercent)} %`));
      if (stats.childElementCount > 0) {
        container.appendChild(stats);
      }

      const hint = document.createElement('div');
      hint.className = 'weather-panel__hint';
      hint.textContent = 'Basis: Tages-Timeline der Kamera. Werte stammen aus den vorhandenen Wetterdaten des ausgewählten Tags.';
      container.appendChild(hint);

      return container;
    };

    // -----------------------------------------------------------------------
    // Tagesdaten laden und den vollständig gerenderten Modalinhalt liefern.
    // null bedeutet: Für diesen Kontext existieren keine verwertbaren
    // Diagrammdaten. Die aufrufende Modalsteuerung zeigt dann ihren bestehenden
    // Klartext-Fallback.
    // -----------------------------------------------------------------------
    const resolveCachedPayload = (cacheKey, isCurrentDay) => {
      const cached = weatherDayCache.get(cacheKey);
      if (!cached) {
        return null;
      }
      const ttlMs = isCurrentDay ? 60000 : 12 * 60 * 60 * 1000;
      if ((Date.now() - cached.fetchedAt) > ttlMs) {
        weatherDayCache.delete(cacheKey);
        return null;
      }
      return cached.payload;
    };

    const loadContent = async () => {
      const context = resolveContext();
      if (!context || !context.cameraSlug || !context.year || !context.month || !context.day) {
        return null;
      }

      const cacheKey = [
        context.cameraSlug,
        context.year,
        context.month,
        context.day,
      ].join(':');
      let payload = resolveCachedPayload(cacheKey, context.isCurrentDay === true);

      if (!payload) {
        const timelineData = await loadTimelineDay(context);
        if (!timelineData) {
          return null;
        }
        payload = weatherModel.buildDayPayload(timelineData);
        weatherDayCache.set(cacheKey, {
          fetchedAt: Date.now(),
          payload,
        });
      }

      const hasChartData = payload
        && (payload.tempSeries.length || payload.pressureSeries.length || payload.humiditySeries.length || payload.windSeries.length);
      return hasChartData ? renderWeatherModalContent(payload) : null;
    };

    return Object.freeze({
      loadContent,
    });
  };

  window.WebcamCameraWeather = Object.freeze({
    create,
  });
})();
