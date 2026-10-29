<?php $power=camera_power_view(); ?>
<section class="panel" id="camera-power" data-camera-power data-power-state="<?= e($power['usb_state']) ?>">
  <h2>Kamera ein-/ausschalten</h2>
  <p data-power-status role="status" aria-live="polite"><?= e(camera_power_label($power['usb_state'])) ?></p>
  <div class="actions">
    <?php foreach (['on'=>'Kamera einschalten','off'=>'Kamera ausschalten'] as $state=>$label): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="power_<?= $state ?>">
      <button type="submit" data-power-button="<?= $state ?>" aria-pressed="<?= $power['usb_state']===$state?'true':'false' ?>" <?= !$power['can_control']?'disabled':'' ?>><?= $label ?></button>
    </form>
    <?php endforeach; ?>
  </div>
  <p data-power-detail><?= e($power['detail']) ?></p>
  <small>Grün = an, Rot = aus. Maßgeblich ist „Canon“ in der USB-Geräteliste.
    Nach dem Einschalten kann die Erkennung einige Sekunden dauern.
    Während einer Aufnahme ist das Schalten gesperrt.</small>
  <h3>Automatischer Kamera-Reset</h3>
  <?php if (isset($power['settings']['reset_off_seconds'], $power['settings']['revision'])): ?>
  <form method="post" data-power-settings>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="power_save">
    <input type="hidden" name="power_revision" value="<?= e($power['settings']['revision']) ?>">
    <label for="reset-off-seconds">Kamerastrom ausgeschaltet lassen (Sekunden)</label>
    <input id="reset-off-seconds" type="number" name="reset_off_seconds" min="10" max="300" step="1" required value="<?= (int)$power['settings']['reset_off_seconds'] ?>">
    <button type="submit">Reset-Ausschaltzeit speichern</button>
  </form>
  <?php else: ?>
  <p><?= e($power['settings']['error'] ?? 'Reset-Einstellung nicht verfügbar.') ?></p>
  <?php endif; ?>
  <p>Danach 15 Sekunden Anlaufzeit. Speichern schaltet die Kamera nicht um.
    Die Zeit gilt ab dem nächsten automatischen Reset nach drei Aufnahmefehlern.
    Mindestens 30 Minuten Abstand und höchstens drei Resets ohne erfolgreiche Aufnahme bleiben bestehen.
    Manuelles Ausschalten bleibt ausgeschaltet.</p>
</section>
<script src="power.js?v=1" defer></script>
