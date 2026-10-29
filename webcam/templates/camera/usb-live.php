<?php
declare(strict_types=1);

// Embedded in the existing live-image area, not an iframe or second page.
// Only the opt-in testcam reaches this template; URLs stay proxy-relative.
?>
<div class="usb-live-player" data-usb-player>
  <div class="usb-live-player__controls">
    <strong>Livestream</strong>
    <span>1920 × 1080 · 3 Bilder/s</span>
    <button data-usb-start type="button">Stream starten</button>
    <button data-usb-stop type="button" disabled>Stoppen</button>
    <a href="<?= htmlspecialchars($viewLinks['live'] ?? '#', ENT_QUOTES) ?>" data-no-ajax="1" data-usb-snapshot-link>Zum Livebild</a>
  </div>
  <p data-usb-state role="status">Kamera wird angefragt …</p>
  <video muted playsinline controls preload="none" data-autostart="1" data-duration="300"
    aria-label="Livestream <?= htmlspecialchars($camera['title'], ENT_QUOTES) ?>"
    data-source="<?= htmlspecialchars($relativePrefix . 'stream.php?slug=' . rawurlencode($cameraSlug) . '&format=hls', ENT_QUOTES) ?>"></video>
</div>
