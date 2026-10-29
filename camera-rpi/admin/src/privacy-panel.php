<?php
// Read-only presentation behind existing Apache login/session; no camera action.
$privacy=privacy_view();
?>
<section class="panel" id="capture-privacy">
  <h2>Aufnahmefenster &amp; Helligkeit</h2>
  <?php if (isset($privacy['error'])): ?>
    <p class="notice error"><?= e($privacy['error']) ?></p>
  <?php else: $pc=$privacy['config']; $pd=$privacy['decision']; ?>
    <p>Geräteweit für automatische und manuelle Fotos sowie den Livestream. Klima und Uploads bereits freigegebener Bilder laufen weiter.</p>
    <p class="notice" role="status"><?= e($pd['message']) ?></p>
    <?php if (!array_filter($privacy['positions'])): ?>
      <p class="notice error">Für Sonnenaufgang und Sonnenuntergang bitte zuerst den Kamerastandort auf dem Webcam-Server hinterlegen. Der aktualisierte Server übermittelt ihn beim nächsten Poll; alte Server unterstützen dies nicht.</p>
    <?php endif; ?>
    <?php foreach ($privacy['positions'] as $source=>$site): if ($site && (float)$site['position']['latitude']===0.0 && (float)$site['position']['longitude']===0.0): ?>
      <p class="notice error">Standort von <?= e($source) ?> ist 0° / 0°. Das ist ein Punkt im Golf von Guinea, kein automatisch erkannter Kamerastandort. Bitte vor Nutzung der Sonnenzeiten im Webcam-Server prüfen und gegebenenfalls korrigieren.</p>
    <?php endif; endforeach; ?>
    <?php if (!empty($pd['windows'])): ?>
      <p>Freigabezeiten (<?= e($pd['timezone']) ?>):</p><ul>
      <?php foreach ($pd['windows'] as $pw): if ($pw['date'] !== (new DateTimeImmutable('now',new DateTimeZone($pd['timezone'])))->format('Y-m-d')) continue;
        $fmt=static fn(int $ts): string => (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone($pd['timezone']))->format('d.m. H:i:s T'); ?>
        <li><?= e($fmt($pw['from'])) ?> bis <?= e($fmt($pw['until'])) ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($privacy['measurement']): $pm=$privacy['measurement']; ?>
      <p>Letzte Prüfung: <?= e((string)$pm['percent']) ?> % / Schwelle <?= e((string)$pm['threshold']) ?> % – <?= $pm['accepted'] ? 'freigegeben' : 'verworfen' ?>
        (<?= e(date('d.m.Y H:i:s T',$pm['measured_at'])) ?>).</p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="privacy_save">
      <input type="hidden" name="privacy_revision" value="<?= e($privacy['revision']) ?>">
      <div class="form-grid">
        <label>Aufnahmefenster<select name="privacy_mode">
          <?php foreach (['off'=>'Uneingeschränkt','fixed'=>'Feste Uhrzeiten','solar'=>'Sonnenaufgang / Sonnenuntergang'] as $key=>$label): ?>
            <option value="<?= e($key) ?>" <?= $pc['mode']===$key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Hinterlegte Kameraposition von<select name="privacy_source_server">
          <option value="">Keine Quelle ausgewählt</option>
          <?php foreach ($privacy['positions'] as $id=>$position): ?>
            <option value="<?= e($id) ?>" <?= $pc['source_server']===$id ? 'selected' : '' ?>><?= e($id) ?> — <?= $position ? e($position['position']['latitude'].', '.$position['position']['longitude'].' · '.$position['position']['timezone']) : 'Standort fehlt / Poll veraltet' ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Sonnenaufgang: Versatz (Minuten)<input name="privacy_sunrise_offset" type="number" min="-720" max="720" step="1" required value="<?= e((string)$pc['sunrise_offset']) ?>"><small>Minus: früher beginnen. Plus: später beginnen.</small></label>
        <label>Sonnenuntergang: Versatz (Minuten)<input name="privacy_sunset_offset" type="number" min="-720" max="720" step="1" required value="<?= e((string)$pc['sunset_offset']) ?>"><small>Minus: früher beenden. Plus: später beenden.</small></label>
        <label>Fester Beginn<input type="time" name="privacy_start" required value="<?= e($pc['start']) ?>"></label>
        <label>Festes Ende<input type="time" name="privacy_end" required value="<?= e($pc['end']) ?>"></label>
        <label>Zeitzone für feste Uhrzeiten<input name="privacy_timezone" required value="<?= e($pc['timezone']) ?>"><small>Sonnenzeiten verwenden die Zeitzone des Standort-Servers.</small></label>
      </div>
      <label class="check"><input type="checkbox" name="privacy_dark_enabled" <?= $pc['dark_enabled'] ? 'checked' : '' ?>><span>Dunkle Fotos vor Upload und Poolfreigabe verwerfen</span></label>
      <div class="form-grid">
        <label>Mindesthelligkeit (%)<input type="number" min="0" max="100" step="0.1" required name="privacy_minimum_percent" value="<?= e((string)$pc['minimum_percent']) ?>"></label>
        <label>Hysterese (%)<input type="number" min="0" max="100" step="0.1" required name="privacy_hysteresis_percent" value="<?= e((string)$pc['hysteresis_percent']) ?>"><small>Nach dunklem Bild: Freigabe erst ab Mindesthelligkeit plus Hysterese. 0 schaltet sie aus.</small></label>
        <?php foreach (['x'=>'Region links (%)','y'=>'Region oben (%)','width'=>'Region Breite (%)','height'=>'Region Höhe (%)'] as $key=>$label): ?>
          <label><?= e($label) ?><input type="number" min="<?= in_array($key,['width','height']) ? '1' : '0' ?>" max="100" step="1" required name="privacy_<?= e($key) ?>" value="<?= e((string)$pc['region'][$key]) ?>"></label>
        <?php endforeach; ?>
      </div>
      <p>Helligkeit ist ein Bildwert, keine Lux-Messung und kein Datenschutzfilter. Helle Fenster dürfen die Zeitsperre niemals aufheben. Die Region begrenzt nur die Messung, sie maskiert keine Bildbereiche.</p>
      <p class="notice">Vor Aktivierung auf Legacy-Zielen deren „kein Bild → Neustart“-Watchdog anpassen. Der alte Server kennt geplante Nachtpausen nicht. Fehlende/veraltete Koordinaten und ungültige Sonnenereignisse sperren Aufnahmen.</p>
      <button type="submit">Aufnahmeoptionen speichern</button>
    </form>
  <?php endif; ?>
</section>
