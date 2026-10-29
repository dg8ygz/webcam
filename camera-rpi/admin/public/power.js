(() => {
  'use strict';
  const panel = document.querySelector('[data-camera-power]');
  if (!panel) return;
  let stopped = false, timer, controller;
  const labels = {
    on: 'Kamera an – Canon über USB erkannt.',
    off: 'Kamera aus – keine Canon über USB erkannt.',
    unknown: 'Kamerastatus unbekannt – USB-Abfrage fehlgeschlagen.',
  };
  const render = (data) => {
    const state = ['on', 'off'].includes(data.usb_state) ? data.usb_state : 'unknown';
    panel.dataset.powerState = state;
    panel.querySelector('[data-power-status]').textContent = labels[state];
    panel.querySelector('[data-power-detail]').textContent = data.detail || '';
    panel.querySelectorAll('[data-power-button]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.powerButton === state));
      button.disabled = data.can_control !== true;
    });
  };
  const poll = async () => {
    if (stopped) return;
    controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch('power-status.php', {cache: 'no-store', signal: controller.signal});
      if (!response.ok) throw new Error('status');
      const data = await response.json();
      if (!stopped) render(data);
    } catch {
      if (!stopped) render({usb_state: 'unknown', can_control: false});
    } finally {
      clearTimeout(timeout);
      if (!stopped) timer = setTimeout(poll, 3000);
    }
  };
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); controller?.abort(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; poll(); } });
  timer = setTimeout(poll, 3000);
})();
