<?php
// Presentation only: all values escaped, settings loaded via fixed privileged GET.
// A relay demand is NOT feedback from the physical heater or a fan tachometer.
$climate = climate_view();
$climateConfig = $climate['config'] ?? [];
$climateStatus = $climate['status'] ?? [];
$climateFault = trim((string)($climateStatus['fault'] ?? ''));
$climateFresh = isset($climateStatus['updated_epoch']) && ctype_digit((string)$climateStatus['updated_epoch'])
    && time() - (int)$climateStatus['updated_epoch'] >= 0 && time() - (int)$climateStatus['updated_epoch'] <= 10;
?>
<section class="panel" id="climate">
  <p class="eyebrow">Ein Regler für das gemeinsame Kameragehäuse</p>
  <h2>Heizung &amp; Lüfter</h2>
  <p>GPIO-Belegung siehe „GPIO-Anschlüsse“. Lüfter-PWM: 100 Hz; Heizungsrelais: LOW-aktiv.</p>
  <?php if (isset($climate['error'])): ?>
    <p class="notice error"><?= e($climate['error']) ?></p>
  <?php else: ?>
    <?php if ($climateFault !== ''): ?>
      <div class="notice error" role="alert">
        <strong>Gespeicherte Klimastörung: <?= e($climateFault) ?></strong>
        <p><?= $climateFault === 'capture_timeout'
          ? 'Eine Aufnahmepause hat ihr Zeitlimit überschritten. Neue Fotos sind nach bestätigtem Abschalten von Heizung und Lüfter weiterhin möglich.'
          : 'Die Klimaregelung meldet einen technischen Fehler. Ursache prüfen und anschließend quittieren.' ?></p>
        <?php if ($climateFresh): ?>
          <p>Die Quittierung wirkt nur mit frischen gültigen Sensorwerten. Danach baut der Regler seinen Messpuffer neu auf.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="climate_reset">
            <button type="submit">Klima-Störung quittieren</button>
          </form>
        <?php else: ?>
          <p>Letzter bekannter Fehler; der Reglerstatus ist veraltet. Zuerst die Erreichbarkeit des Klimadienstes prüfen.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <p role="note"><?= $climateFresh ? e((string)($climateStatus['message'] ?? '')) : 'Kein frischer Reglerstatus — Einstellungen allein bestätigen keinen laufenden Dienst.' ?></p>
    <?php if ($climateFresh): ?>
      <dl>
        <div><dt>Regler / Ausgänge</dt><dd><?= e((string)($climateStatus['mode'] ?? '')) ?> / <?= ($climateStatus['outputs'] ?? '') === 'gpio' ? 'GPIO angesteuert' : 'keine GPIO-Ansteuerung' ?></dd></div>
        <div><dt>Heizungs-Sollzustand</dt><dd><?= ($climateStatus['heater'] ?? '') === 'on' ? 'An' : 'Aus' ?></dd></div>
        <div><dt>Lüfter-PWM / Ziel</dt><dd><?= e((string)($climateStatus['fan_percent'] ?? '–')) ?> % / <?= e((string)($climateStatus['fan_target_percent'] ?? '–')) ?> %</dd></div>
        <div><dt>Taupunkt / Abstand</dt><dd><?= e((string)($climateStatus['dew_point_c'] ?? '–')) ?> °C / <?= e((string)($climateStatus['dew_gap_c'] ?? '–')) ?> °C</dd></div>
      </dl>
      <?php if (($climateStatus['measurement_source'] ?? '') === 'current'): ?>
        <p>Taupunkt und Abstand aus dem aktuellen gültigen Messpaar. Die Regelung wartet weiterhin auf Freigabe oder ihren vollständigen Messpuffer.</p>
      <?php elseif (($climateStatus['measurement_source'] ?? '') === 'average'): ?>
        <p>Taupunkt und Abstand aus dem gemittelten Messpuffer des Reglers.</p>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" class="server-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="climate_save">
      <input type="hidden" name="climate_revision" value="<?= e((string)$climate['revision']) ?>">
      <label>Betriebsart
        <select name="climate_mode">
          <?php foreach (['off' => 'Aus – keine Regelung', 'simulate' => 'Simulation – nur Sollwerte, keine GPIO-Ausgänge', 'auto' => 'Automatik – Heizung und Lüfter ansteuern', 'manual80' => 'Festbetrieb – Heizung an, Lüfter 80 %, ohne DHT22'] as $mode => $label): ?>
            <option value="<?= e($mode) ?>" <?= ($climateConfig['mode'] ?? '') === $mode ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <small>Geräteweit für alle Zielserver. Default ist Aus. Simulation schaltet weder Relais noch PWM. Festbetrieb verwendet keine DHT22-Werte; die folgenden Automatik-Einstellungen wirken dort nicht.</small>
      </label>
      <?php foreach (climate_labels() as $name => [$label, $help]): ?>
        <label><?= e($label) ?>
          <input type="number" name="<?= e($name) ?>" required
            min="<?= e((string)$climate['limits'][$name][0]) ?>" max="<?= e((string)$climate['limits'][$name][1]) ?>"
            step="<?= in_array($name, ['dew_margin_c', 'hysteresis_c', 'summer_c'], true) ? '0.1' : '1' ?>"
            value="<?= e((string)$climateConfig[$name]) ?>">
          <small><?= e($help) ?></small>
        </label>
      <?php endforeach; ?>
      <button type="submit">Klima-Einstellungen speichern</button>
    </form>
  <?php endif; ?>
  <details class="climate-help"><summary>Regelverhalten und Sicherheit</summary>
    <p>Wie bei fan.py: Taupunkt aus Temperatur und Luftfeuchte, Heizbetrieb bei zu kleinem Taupunktabstand. Lüfter-PWM entspricht dann der gemittelten Feuchte innerhalb der eingestellten Grenzen.</p>
    <p>Keine feste Temperaturabschaltung und keine maximale Heizdauer. Die Heizung darf bei Bedarf dauerhaft laufen. Oberhalb der Sommerschwelle bleibt sie aus; der Lüfter läuft auf Sommer-PWM. Nach Abkühlung geht es automatisch weiter, ohne Quittierung.</p>
    <p>Bei jedem Anlauf aus Stillstand zunächst <strong>2 Sekunden 100 %</strong>, anschließend der aktuelle Zielwert. Während des Laufens werden Änderungen ohne neuen Anlaufimpuls übernommen. Ausschalten unterbricht den Impuls sofort.</p>
    <p>Vor einer Aufnahme bestätigt der Regler, dass Heizung und Lüfter aus sind. Fehlende Sensorwerte oder ein früherer Aufnahme-Timeout verhindern diese Freigabe nicht. Fehlgeschlagenes Abschalten oder eine ungültige Konfiguration verhindern sie weiterhin.</p>
    <p>Kein zusätzlicher DHT-Zugriff. Status, Mittelwert und Fehlermerker liegen im RAM. Nur das bewusste Speichern schreibt die Konfiguration mit vorheriger Sicherung auf die SD-Karte.</p>
    <p>Die Anzeige zeigt Stellbefehle, keine gemessene Heizleistung oder Drehzahl. Software ersetzt weder Thermosicherung noch einen externen Pull-up für das LOW-aktive Relais. In der Automatik bleibt ohne funktionierenden Sensor die Heizung aus. Im ausdrücklich gewählten Festbetrieb bleibt sie unabhängig vom DHT22 an, der Lüfter läuft auf 80 %. Die Aufnahmepause und technischen Abschaltungen gelten auch dort.</p>
  </details>
</section>
