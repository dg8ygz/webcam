// Bildbereiche werden in Eingangskoordinaten gespeichert. Nur der Server
// berechnet das Ergebnis; Canvas zeigt Auswahlrahmen, keine Ersatzfilter.
(function (global) {
  "use strict";
  const labels = { edge: "Details glätten · Kanten erhalten", blur: "Weichzeichnen", pixelate: "Verpixeln", mask: "Abdecken", copy: "Kopieren", scramble: "Kacheln mischen" };
  const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const defaults = () => ({ enabled: false, gamma: 1, crop: { top: 0, right: 0, bottom: 0, left: 0 }, regions: [] });
  const options = (selected) => Object.entries(labels).map(([key, label]) => `<option value="${key}" ${key === selected ? "selected" : ""}>${label}</option>`).join("");
  const number = (key, label, value, min, max, step = 1) => `<label>${label}<input data-ip-field="${key}" type="number" value="${esc(value)}" min="${min}" max="${max}" step="${step}"></label>`;

  function render(camera, creating) {
    const c = camera.capture?.image_processing || defaults();
    return `<div class="image-processing" data-image-processing data-slug="${esc(camera.slug || "")}" data-new="${creating ? "1" : "0"}">
      <textarea name="image_processing" hidden>${esc(JSON.stringify(c))}</textarea>
      <p>Rechtecke im Eingangsbild zeichnen, Aktion wählen und die Liste per Ziehen umordnen. Jeder Schritt verarbeitet das Ergebnis des vorherigen. Gamma zuerst, Beschnitt zuletzt.</p>
      <label><input type="checkbox" data-ip-enable ${c.enabled ? "checked" : ""}> Für künftige JPEG-Aufnahmen aktivieren</label>
      <p class="hint">Die Vorschau simuliert auch bei ausgeschalteter Aktivierung. Erst „Sektion speichern“ übernimmt die Einstellungen. Alle neuen JPEG-Größen einschließlich raw.jpg werden bearbeitet; alte Bilder und Live-Streams bleiben unverändert. Kantenglättung und Verpixelung garantieren keine Anonymisierung. Für vollständige Abdeckung die Aktion „Abdecken“ verwenden.</p>
      <div class="ip-controls" data-ip-global>${number("gamma", "Gamma (1 = unverändert)", c.gamma ?? 1, .2, 3, .05)}${Object.entries({ top: "Oben", right: "Rechts", bottom: "Unten", left: "Links" }).map(([k, l]) => number(`crop.${k}`, `Beschnitt ${l} (px)`, c.crop?.[k] ?? 0, 0, 8191)).join("")}</div>
      <div class="ip-toolbar"><label>Neue Aktion <select data-ip-action>${options("edge")}</select></label><button type="button" data-ip-tool="draw">Bereich zeichnen</button><button type="button" data-ip-tool="move">Bereich verschieben</button><button type="button" data-ip-tool="target">Kopierziel setzen</button><button type="button" data-ip-tool="crop">Beschnitt wählen</button><button type="button" data-ip-refresh>Vorschau neu berechnen</button></div>
      <p data-ip-message role="status" aria-live="polite">${creating ? "Kamera zuerst anlegen und ein Bild empfangen." : "Vorschau wird beim Öffnen des Tabs geladen."}</p>
      <div class="ip-images"><figure><figcaption>Eingangsbild · Rechtecke aufziehen</figcaption><canvas data-ip-canvas width="800" height="450" aria-label="Bildbereiche auswählen"></canvas></figure><figure><figcaption>Ergebnis der aktuellen Reihenfolge</figcaption><img data-ip-preview alt="Bearbeitungsvorschau" hidden></figure></div>
      <ol data-ip-list aria-label="Reihenfolge der Bildbearbeitung"></ol><div class="ip-controls" data-ip-selected></div>
    </div>`;
  }

  function bind({ root, api }) {
    const instances = new WeakMap();
    const check = () => root.querySelectorAll("[data-image-processing]").forEach((el) => {
      if (!instances.has(el)) instances.set(el, editor(el, api));
      if (el.closest('[data-tab-panel]')?.classList.contains('is-active')) instances.get(el).open();
    });
    // Der Admin ersetzt Formulare nach Speichern/Kamerawechsel. Alte Instanzen
    // senden nach dem Entfernen nichts mehr und können keine neuen überschreiben.
    new MutationObserver(check).observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ["class"] });
    check();
  }

  function editor(el, api) {
    const field = el.querySelector('[name="image_processing"]');
    const c = { ...defaults(), ...JSON.parse(field.value) };
    c.crop = { ...defaults().crop, ...c.crop };
    const canvas = el.querySelector('[data-ip-canvas]'), ctx = canvas.getContext('2d');
    const output = el.querySelector('[data-ip-preview]'), message = el.querySelector('[data-ip-message]');
    const list = el.querySelector('[data-ip-list]'), selected = el.querySelector('[data-ip-selected]');
    let index = -1, tool = 'draw', picture = null, width = 0, height = 0, drag = null;
    let loaded = false, busy = false, pending = false, revision = 0, timer = null, from = null;
    const say = (text) => { message.textContent = text; };
    const save = () => { field.value = JSON.stringify(c); };
    const current = () => c.regions[index];
    const dirty = () => {
      save(); revision++; pending = true; output.hidden = true;
      say('Änderungen noch nicht berechnet …');
      clearTimeout(timer); timer = setTimeout(request, 500); draw();
    };
    async function request() {
      if (!el.isConnected || el.dataset.new === '1') return;
      if (busy) { pending = true; return; }
      busy = true; pending = false; const version = revision;
      say('Vorschau wird aus dem vorhandenen Bild berechnet …');
      try {
        const result = await api(`cameras/${encodeURIComponent(el.dataset.slug)}/image-preview`, { method: 'POST', body: JSON.stringify({ image_processing: { ...c, enabled: true } }) });
        if (!el.isConnected || revision !== version) return;
        const img = new Image(); img.src = result.original; await img.decode();
        if (!el.isConnected || revision !== version) return;
        picture = img; width = result.width; height = result.height;
        canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
        output.src = result.preview; output.hidden = false; draw();
        say(`Vorschau aktuell · Eingang ${width} × ${height} → ${result.result_width} × ${result.result_height} · Bild ${new Date(result.captured_at * 1000).toLocaleString('de-DE')}. Noch nicht gespeicherte Änderungen gelten nur hier.`);
      } catch (error) {
        if (el.isConnected && revision === version) { output.hidden = true; say(error.message); }
      } finally {
        busy = false;
        if (pending && el.isConnected) { clearTimeout(timer); timer = setTimeout(request, 500); }
      }
    }
    function draw() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      if (!picture) return;
      ctx.drawImage(picture, 0, 0);
      const sx = canvas.width / width, sy = canvas.height / height;
      const rect = (r, color, caption) => {
        ctx.strokeStyle = color; ctx.fillStyle = color; ctx.lineWidth = 2;
        ctx.strokeRect(r.x * sx, r.y * sy, r.width * sx, r.height * sy);
        ctx.font = 'bold 14px sans-serif'; ctx.fillText(caption, r.x * sx + 4, r.y * sy + 17);
      };
      c.regions.forEach((r, i) => {
        rect(r, i === index ? '#ffcf40' : '#48dcff', String(i + 1));
        if (r.type === 'copy') { ctx.setLineDash([5, 4]); rect({ ...r, x: r.dest_x ?? r.x, y: r.dest_y ?? r.y }, '#e17fff', `${i + 1} Ziel`); ctx.setLineDash([]); }
      });
      if (drag?.box) rect(drag.box, '#fff', tool === 'crop' ? 'Beschnitt' : 'Neu');
      if (Object.values(c.crop).some(Boolean)) {
        ctx.setLineDash([8, 4]); rect({ x: c.crop.left, y: c.crop.top, width: width - c.crop.left - c.crop.right, height: height - c.crop.top - c.crop.bottom }, '#ff8a65', 'Ausgabe'); ctx.setLineDash([]);
      }
    }
    function controls() {
      const r = current(); selected.innerHTML = '';
      if (!r) return;
      selected.innerHTML = `<label>Aktion ${index + 1}<select data-ip-region="type">${options(r.type)}</select></label>` +
        [['x', 'X', 0, 8192], ['y', 'Y', 0, 8192], ['width', 'Breite', 1, 8192], ['height', 'Höhe', 1, 8192],
          ...(r.type === 'copy' ? [['dest_x', 'Ziel X', 0, 8192], ['dest_y', 'Ziel Y', 0, 8192]] : []),
          ...(r.type !== 'mask' ? [['opacity', 'Deckkraft %', 1, 100], ['feather', 'Weicher Rand (px)', 0, 64]] : []),
          ...(['edge', 'blur', 'pixelate', 'scramble'].includes(r.type) ? [['strength', 'Stärke (px)', 1, 64]] : [])]
          .map(([k, title, min, max]) => `<label>${title}<input data-ip-region="${k}" type="number" min="${min}" max="${max}" step="1" value="${esc(r[k] ?? ({ opacity: 100, feather: 0, strength: 12 }[k] ?? r[k.replace('dest_', '')]))}"></label>`).join('') +
        (r.type === 'mask' ? `<label>Farbe<input type="color" data-ip-region="color" value="${esc(r.color || '#000000')}"></label>` : '');
    }
    function rows() {
      list.innerHTML = c.regions.map((r, i) => `<li draggable="true" data-ip-index="${i}" ${i === index ? 'aria-current="true"' : ''}><span class="ip-grip" aria-hidden="true">↕</span><button type="button" data-ip-select="${i}">${i + 1}. ${labels[r.type]} · ${r.width} × ${r.height}</button><button type="button" data-ip-up="${i}" aria-label="Bereich ${i + 1} nach oben" ${i === 0 ? 'disabled' : ''}>↑</button><button type="button" data-ip-down="${i}" aria-label="Bereich ${i + 1} nach unten" ${i === c.regions.length - 1 ? 'disabled' : ''}>↓</button><button type="button" data-ip-delete="${i}" aria-label="Bereich ${i + 1} löschen">Löschen</button></li>`).join('');
      controls(); draw();
    }
    function reorder(a, b) {
      if (!Number.isInteger(a) || !Number.isInteger(b) || a < 0 || b < 0 || a >= c.regions.length || b >= c.regions.length || a === b) return;
      const active = current(); c.regions.splice(b, 0, c.regions.splice(a, 1)[0]); index = c.regions.indexOf(active); rows(); dirty();
    }
    list.addEventListener('dragstart', (e) => { const li = e.target.closest('[data-ip-index]'); if (!li) return; from = Number(li.dataset.ipIndex); e.dataTransfer.setData('text/plain', String(from)); e.dataTransfer.effectAllowed = 'move'; });
    list.addEventListener('dragover', (e) => { if (from !== null) e.preventDefault(); });
    list.addEventListener('drop', (e) => { e.preventDefault(); const li = e.target.closest('[data-ip-index]'); if (li && from !== null) reorder(from, Number(li.dataset.ipIndex)); from = null; });
    list.addEventListener('dragend', () => { from = null; });
    el.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.hasAttribute('data-ip-select')) { index = Number(b.dataset.ipSelect); rows(); }
      if (b.hasAttribute('data-ip-delete')) { c.regions.splice(Number(b.dataset.ipDelete), 1); index = Math.min(index, c.regions.length - 1); rows(); dirty(); }
      if (b.hasAttribute('data-ip-up')) reorder(Number(b.dataset.ipUp), Number(b.dataset.ipUp) - 1);
      if (b.hasAttribute('data-ip-down')) reorder(Number(b.dataset.ipDown), Number(b.dataset.ipDown) + 1);
      if (b.hasAttribute('data-ip-refresh')) { pending = true; request(); }
      if (b.dataset.ipTool) { tool = b.dataset.ipTool; say({ draw: 'Im Eingangsbild ein Rechteck aufziehen.', move: 'Gewählten Bereich im Bild ziehen.', target: 'Bei einer Kopieraktion im Bild die linke obere Zielecke anklicken.', crop: 'Im Bild den gewünschten Ausgabeausschnitt aufziehen.' }[tool]); }
    });
    el.addEventListener('input', (e) => {
      const input = e.target;
      if (input.matches('[data-ip-enable]')) { c.enabled = input.checked; save(); return; }
      if (input.matches('[data-ip-field]')) {
        if (!input.checkValidity() || input.value === '') return;
        const [key, side] = input.dataset.ipField.split('.');
        if (side) c.crop[side] = Number(input.value); else c[key] = Number(input.value);
        dirty();
      }
      if (input.matches('[data-ip-region]') && current()) {
        if (!input.checkValidity() || input.value === '') return;
        const key = input.dataset.ipRegion;
        current()[key] = ['type', 'color'].includes(key) ? input.value : Number(input.value);
        if (key === 'type') { if (current().type === 'mask') { current().opacity = 100; current().feather = 0; } rows(); }
        dirty();
      }
    });
    const point = (e) => { const box = canvas.getBoundingClientRect(); return { x: Math.max(0, Math.min(width - 1, Math.round((e.clientX - box.left) * width / box.width))), y: Math.max(0, Math.min(height - 1, Math.round((e.clientY - box.top) * height / box.height))) }; };
    canvas.addEventListener('pointerdown', (e) => {
      if (!picture || e.button !== 0) return;
      e.preventDefault(); const p = point(e);
      if (tool === 'target') {
        const r = current(); if (!r || r.type !== 'copy') { say('Zuerst eine Kopieraktion auswählen.'); return; }
        r.dest_x = Math.min(p.x, width - r.width); r.dest_y = Math.min(p.y, height - r.height); controls(); dirty(); return;
      }
      if (tool === 'draw' && c.regions.length >= 16) { say('Maximal 16 Bereiche möglich.'); return; }
      if (tool === 'move' && !current()) { say('Zuerst einen Bereich in der Liste wählen.'); return; }
      canvas.setPointerCapture(e.pointerId); drag = { start: p, original: current() ? { ...current() } : null };
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!drag) return; const p = point(e), a = drag.start;
      if (tool === 'move' && current()) {
        current().x = Math.max(0, Math.min(width - current().width, drag.original.x + p.x - a.x));
        current().y = Math.max(0, Math.min(height - current().height, drag.original.y + p.y - a.y));
      } else drag.box = { x: Math.min(a.x, p.x), y: Math.min(a.y, p.y), width: Math.abs(p.x - a.x) + 1, height: Math.abs(p.y - a.y) + 1 };
      draw();
    });
    canvas.addEventListener('pointerup', () => {
      if (!drag) return;
      if (drag.box && drag.box.width > 1 && drag.box.height > 1) {
        const r = drag.box;
        if (tool === 'crop') {
          c.crop = { top: r.y, left: r.x, right: width - r.x - r.width, bottom: height - r.y - r.height };
          Object.entries(c.crop).forEach(([key, value]) => { el.querySelector(`[data-ip-field="crop.${key}"]`).value = value; });
        } else {
          c.regions.push({ ...r, type: el.querySelector('[data-ip-action]').value, dest_x: r.x, dest_y: r.y, strength: 12, opacity: 100, feather: 0, color: '#000000' }); index = c.regions.length - 1;
        }
      }
      drag = null; rows(); dirty();
    });
    canvas.addEventListener('pointercancel', () => { if (tool === 'move' && drag?.original && current()) Object.assign(current(), drag.original); drag = null; draw(); });
    rows(); save();
    return { open() { if (!loaded && el.dataset.new !== '1') { loaded = true; request(); } } };
  }
  global.WebcamAdminImageProcessing = { render, bind };
})(window);
