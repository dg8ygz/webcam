<?php
// Presentation only. Allocations come through the authenticated root bridge.
$gpio=gpio_view();
?>
<section class="panel" id="gpio">
  <p class="eyebrow">Gemeinsame Hardware für alle Zielserver</p>
  <h2>GPIO-Belegung</h2>
  <?php if (isset($gpio['error'])): ?>
    <p class="notice error"><?= e($gpio['error']) ?></p>
  <?php else: ?>
    <p><strong><?= e($gpio['board']['model']) ?></strong>
      · <?= $gpio['board']['header_pins']===null ? 'Header nicht automatisch bestimmbar' : e((string)$gpio['board']['header_pins']).'-Pin-Header' ?>
      <?php if ($gpio['board']['revision']!==''): ?> · Revision <?= e($gpio['board']['revision']) ?><?php endif; ?>
    </p>
    <p><?= e($gpio['board']['reason']) ?></p>
    <?php if ($gpio['board']['supported']): ?>
    <p>BCM-Nummer mit physischem Pin. Jeder GPIO darf nur einmal verwendet werden.</p>
    <?php if ($gpio['pending']): ?>
      <p class="notice">Neue Belegung gespeichert. Noch nicht aktiv – Übernahme beim nächsten Neustart.</p>
    <?php endif; ?>
    <form method="post" class="server-form" data-gpio-form>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="gpio_save">
      <input type="hidden" name="gpio_revision" value="<?= e($gpio['revision']) ?>">
      <?php foreach ($gpio['labels'] as $role=>$label):
        $selected=$gpio['pins'][$role];
        $used=array_filter($gpio['pins'],static fn($value,$key)=>$key!==$role && $value!==null,ARRAY_FILTER_USE_BOTH);
        $active=$gpio['active'][$role];
      ?>
        <label><?= e($label) ?>
          <select name="gpio_<?= e($role) ?>" data-gpio-select <?= !in_array($role,['camera_power','focus'],true) ? 'required' : '' ?>>
            <?php if ($selected!==null && !isset($gpio['header'][$selected])): ?>
              <option value="" selected disabled>GPIO <?= e((string)$selected) ?> nicht auf diesem Header – neu wählen</option>
            <?php endif; ?>
            <?php if (in_array($role,['camera_power','focus'],true)): ?>
              <option value="" <?= $selected===null ? 'selected' : '' ?>>Nicht angeschlossen</option>
            <?php endif; ?>
            <?php foreach ($gpio['header'] as $pin=>$physical): ?>
              <option value="<?= e((string)$pin) ?>" <?= $selected===$pin ? 'selected' : '' ?>
                <?= in_array($pin,$used,true) ? 'disabled hidden' : '' ?>>GPIO <?= e((string)$pin) ?> · Pin <?= e((string)$physical) ?></option>
            <?php endforeach; ?>
          </select>
          <small>Aktuell: <?= $active===null ? 'Nicht angeschlossen' : e('GPIO '.$active.(isset($gpio['header'][$active]) ? ' · Pin '.$gpio['header'][$active] : ' · nicht auf diesem Header verfügbar')) ?></small>
        </label>
      <?php endforeach; ?>
      <button type="submit">GPIO-Belegung speichern</button>
    </form>
    <p>Keine Live-Umschaltung und kein automatischer Neustart. Vor geänderter Verdrahtung ausschalten. Heizung: LOW = an; Kamerastrom: LOW = Versorgung an. Lüfter-PWM: 100 Hz. Optionale Fokusfreigabe: LOW für normale Aufnahmen, HIGH nur während eines ausdrücklichen Fokusauftrags.</p>
    <p>GPIOs 0/1, Versorgungs- und Massepins sind nicht auswählbar. Andere Hardwarefunktionen wie I²C, SPI oder UART müssen ebenfalls berücksichtigt werden. Belegte Kernel-GPIOs werden beim Start nicht übernommen.</p>
    <script src="gpio.js?v=1" defer></script>
    <?php else: ?>
      <p class="notice error">GPIO-Auswahl gesperrt. Gespeicherte Belegung wird nicht geändert.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
