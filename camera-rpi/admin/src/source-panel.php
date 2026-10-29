<?php
// Authenticated local source form. Secrets stay out of status pages and logs.
$sourceError=''; $sourceDevices=[];
$source=['source'=>(string)($editServer['capture_backend']??'gphoto2'), 'rtsp_url'=>'',
    'video_device'=>(string)($editServer['video_device']??'/dev/video0'),
    'video_width'=>(string)($editServer['video_width']??'1920'),
    'video_height'=>(string)($editServer['video_height']??'1080'),
    'video_warmup_frames'=>(string)($editServer['video_warmup_frames']??'15'),
    'video_input_fps'=>'30','gphoto_capture_mode'=>(string)($editServer['gphoto_capture_mode']??'eos-legacy')];
try {
    if ($sharedSource) {
        $loaded=json_decode(bridge_call(['action'=>'source_get'])['stdout'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($loaded)) throw new RuntimeException('Quellenprofil fehlt.');
        $source=array_replace($source,$loaded);
    }
    $sourceDevices=json_decode(bridge_call(['action'=>'source_devices'])['stdout'],true,16,JSON_THROW_ON_ERROR);
    if (!is_array($sourceDevices)) $sourceDevices=[];
} catch (Throwable $error) { $sourceError='Quelleneinstellungen konnten nicht vollständig geladen werden.'; }
?>
<section class="panel" id="camera-source">
  <h2>Aufnahmequelle</h2>
  <p>Gilt gemeinsam für alle Zielserver. Speichern übernimmt die Quelle für Fotos und den Livestream.</p>
  <?php if ($sourceError): ?><p role="alert"><?= e($sourceError) ?></p><?php endif; ?>
  <form method="post" autocomplete="off" class="server-form source-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="source_save">
    <fieldset <?= $sourceError && $sharedSource ? 'disabled' : '' ?>>
      <legend>Kameraeinstellungen</legend>
      <label>Aufnahmequelle
        <select name="capture_backend">
          <?php foreach (['rtsp'=>'RTSP – Netzwerkkamera','v4l2'=>'V4L2 – USB-Cam','gphoto2'=>'gphoto2 – Fotokamera'] as $value=>$label): ?>
          <option value="<?= e($value) ?>" <?= $source['source']===$value?'selected':'' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div data-source-fields="rtsp" <?= $source['source']!=='rtsp'?'hidden':'' ?>>
        <label>RTSP-URL
          <input name="rtsp_url" maxlength="2048" value="<?= e($source['rtsp_url']) ?>" placeholder="rtsp://192.168.1.10:554/stream" autocomplete="off" <?= $source['source']==='rtsp'?'required':'' ?>>
        </label>
      </div>
      <div data-source-fields="v4l2" <?= $source['source']!=='v4l2'?'hidden':'' ?>>
        <label>Gefundene USB-Geräte
          <select data-source-devices>
            <option value="">Anderen Gerätepfad eingeben</option>
            <?php foreach ($sourceDevices as $device): ?>
            <option value="<?= e($device) ?>" <?= $source['video_device']===$device?'selected':'' ?>><?= e(basename($device)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>V4L2-Gerät / eigener Gerätepfad
          <input name="video_device" maxlength="255" value="<?= e($source['video_device']) ?>" placeholder="/dev/video0">
        </label>
        <?php foreach (['video_width'=>['Breite (Pixel)',16,4096],'video_height'=>['Höhe (Pixel)',16,2160],'video_warmup_frames'=>['Anlaufbilder überspringen',0,120],'video_input_fps'=>['Video-Eingangsbilder pro Sekunde',1,30]] as $field=>[$label,$min,$max]): ?>
        <label><?= e($label) ?><input type="number" name="<?= e($field) ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= e($source[$field]) ?>"></label>
        <?php endforeach; ?>
      </div>
      <div data-source-fields="gphoto2" <?= $source['source']!=='gphoto2'?'hidden':'' ?>>
        <label>gphoto2-Aufnahmesequenz
          <select name="gphoto_capture_mode">
            <option value="eos-legacy" <?= $source['gphoto_capture_mode']==='eos-legacy'?'selected':'' ?>>Standard – bewährte EOS-Sequenz</option>
            <option value="standard" <?= $source['gphoto_capture_mode']==='standard'?'selected':'' ?>>Alternativ – direktes gphoto2 Capture &amp; Download</option>
          </select>
        </label>
        <p>Für gphoto2 ist kein HLS-Livestream eingerichtet.</p>
      </div>
      <button type="submit">Aufnahmequelle speichern</button>
    </fieldset>
  </form>
</section>
