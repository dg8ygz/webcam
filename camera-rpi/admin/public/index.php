<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/climate.php';
// Optional during staged upgrades; existing target forms remain usable.
if (is_file(dirname(__DIR__).'/src/gpio.php')) { require dirname(__DIR__).'/src/gpio.php'; }
if (is_file(dirname(__DIR__).'/src/privacy.php')) { require dirname(__DIR__).'/src/privacy.php'; }
if (is_file(dirname(__DIR__).'/src/power.php')) { require dirname(__DIR__).'/src/power.php'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = post_text('csrf', 128);
        if (!hash_equals((string)$_SESSION['csrf'], $csrf)) {
            throw new RuntimeException('Formular ist abgelaufen. Bitte Seite neu laden.');
        }
        $action = post_text('action', 32);
        // Global climate/GPIO forms have no target ID. Target actions require
        // an explicit validated ID even when native browser checks are bypassed.
        $serverId = in_array($action, ['save', 'set_enabled', 'validate', 'capture', 'restart', 'logs'], true)
            ? post_identifier('server_id', 48, 'Interner Name (Server-ID)') : '';
        // Nur feste Brueckenaktionen hinter Session-CSRF/Apache-Anmeldung.
        // Ein leeres Keyfeld bedeutet Beibehalten, nicht Loeschen des Secrets.
        if (in_array($action, ['power_on', 'power_off'], true)) {
            bridge_call(['action'=>$action]);
            flash('success', $action==='power_on'
                ? 'Kamerastrom eingeschaltet. USB-Erkennung wird aktualisiert.'
                : 'Kamerastrom ausgeschaltet. USB-Erkennung wird aktualisiert.');
        } elseif ($action === 'power_save') {
            save_power_form();
            flash('success','Reset-Ausschaltzeit gespeichert. Gilt beim nächsten automatischen Reset; die Kamera wird jetzt nicht geschaltet.');
        } elseif ($action === 'privacy_save') {
            if (!function_exists('save_privacy_form')) { throw new RuntimeException('Aufnahmeschutz-Modul fehlt.'); }
            save_privacy_form();
            flash('success','Aufnahmeoptionen gespeichert. Laufende Regler und Sensoren bleiben unverändert.');
        } elseif ($action === 'gpio_save') {
            if (!function_exists('save_gpio_form')) { throw new RuntimeException('GPIO-Modul fehlt.'); }
            save_gpio_form();
            flash('success','GPIO-Belegung gespeichert. Übernahme erst beim nächsten Neustart.');
        } elseif ($action === 'climate_save') {
            save_climate_form();
            flash('success', 'Klima-Einstellungen gespeichert. Der Regler übernimmt sie ohne Neustart des Sensors.');
        } elseif ($action === 'climate_reset') {
            bridge_call(['action' => 'climate_reset']);
            flash('success', 'Klima-Störung quittiert. Der Regler hat die Quittierung bestätigt und baut seinen Messpuffer neu auf.');
         } elseif ($action === 'source_save') {
            $source = ['source'=>post_text('capture_backend',16)];
            foreach (['rtsp_url'=>2048,'video_device'=>255,'video_width'=>4,'video_height'=>4,'video_warmup_frames'=>3,'video_input_fps'=>2,'gphoto_capture_mode'=>16] as $field=>$limit) {
                $source[$field]=post_text($field,$limit);
            }
            bridge_call(['action'=>'source_save','source_json'=>json_encode($source,JSON_THROW_ON_ERROR)]);
            flash('success','Aufnahmequelle für alle Ziele gespeichert und übernommen.');
        } elseif ($action === 'save') {
            $key = post_text('key', 512);
            $bridgeFields = [
                'action' => 'save',
                'server_id' => $serverId,
                'server_base_url' => post_text('server_base_url', 255),
                'camera_slug' => post_identifier('camera_slug', 64, 'Webcam-Kennung'),
                'enabled' => isset($_POST['enabled']) ? 'true' : 'false',
                'upload_mode' => post_text('upload_mode', 16),
                'capture_backend' => post_text('capture_backend', 16),
                'gphoto_capture_mode' => post_text('gphoto_capture_mode', 16),
                'video_device' => post_text('video_device', 255),
                'video_width' => post_text('video_width', 4),
                'video_height' => post_text('video_height', 4),
                'video_warmup_frames' => post_text('video_warmup_frames', 3),
                'key_mode' => $key === '' ? 'keep' : 'replace',
                'key' => $key,
            ];
            bridge_call($bridgeFields);
            // Den Key so frueh wie moeglich aus dem PHP-Speicher entfernen.
            $bridgeFields['key'] = '';
            $key = '';
            flash('success', "Server $serverId wurde gespeichert.");
        } elseif ($action === 'set_enabled') {
            bridge_call([
                'action' => 'set_enabled',
                'server_id' => $serverId,
                'enabled' => post_text('enabled_value', 5),
            ]);
            flash('success', "Betriebsstatus von $serverId wurde geändert.");
        } elseif ($action === 'validate') {
            bridge_call(['action' => 'validate', 'server_id' => $serverId]);
            flash('success', "Konfiguration und Key von $serverId sind gültig.");
        } elseif ($action === 'capture') {
            bridge_call(['action' => 'capture', 'server_id' => $serverId]);
            flash('success', "Aufnahme für $serverId vorgemerkt. Nur dieses Ziel erhält das Bild; bei laufender Arbeit kann es etwas dauern.");
        } elseif ($action === 'focus') {
            $mode = post_text('focus_mode', 4);
            if (!in_array($mode, ['--', '-', 'auto', '+', '++'], true)) throw new RuntimeException('Ungültiger Fokusmodus.');
            // Resolve afresh on POST; never trust a submitted target ID for a
            // camera-wide action. The bridge retains its hardware/queue checks.
            $focusTarget = camera_focus_target(server_list());
            if (!$focusTarget || ($focusTarget['capture_backend'] ?? '') !== 'gphoto2'
                || (string)($focusTarget['enabled'] ?? '0') !== '1' || ($focusTarget['active'] ?? '') !== 'active') {
                throw new RuntimeException('Kamerafokus nicht verfügbar: eindeutiges, aktives gphoto2-Kameraprofil erforderlich.');
            }
            bridge_call(['action' => 'focus', 'server_id' => (string)$focusTarget['server_id'], 'focus_mode' => $mode]);
            flash('success', "Kamerafokus $mode vorgemerkt. Ausführung nach dem laufenden Poll; gilt für alle Zielserver.");
        } elseif ($action === 'restart') {
            bridge_call(['action' => 'restart', 'server_id' => $serverId]);
            flash('success', "Worker $serverId wurde neu gestartet.");
        } elseif ($action === 'logs') {
            // Explicit per-target read behind the same login/CSRF boundary;
            // display as escaped text, never interpret journal content as HTML.
            $response = bridge_call(['action' => 'logs', 'server_id' => $serverId]);
            $_SESSION['worker_logs'] = ['server_id' => $serverId, 'text' => substr($response['stdout'], 0, 32768)];
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $error) {
        flash('error', $error->getMessage());
    }
    redirect_home();
}

$loadError = '';
try {
    $servers = server_list();
} catch (Throwable $error) {
    $servers = [];
    $loadError = $error->getMessage();
}

$editId = isset($_GET['edit']) && is_string($_GET['edit']) ? $_GET['edit'] : '';
// Bestehende Konfiguration zuerst zeigen; ein neues Ziel ist eine bewusste Aktion.
if (!isset($_GET['edit']) && !isset($_GET['new']) && $servers !== []) {
    $editId = (string)$servers[0]['server_id'];
}
$editServer = null;
// Hardware belongs to the shared source profile. Other cards still own URL,
// credentials and upload mode, but must not offer ineffective camera edits.
$poolSource = '';
$sharedSource = false;
foreach ($servers as $candidate) {
    $sharedSource = $sharedSource || ($candidate['source_configured'] ?? '0') === '1';
    if (!in_array(($candidate['capture_source_server'] ?? ''), ['', '-'], true)) {
        $poolSource = (string)$candidate['capture_source_server'];
    }
    if (($candidate['server_id'] ?? '') === $editId) {
        $editServer = $candidate;
    }
}
$focusTarget = camera_focus_target($servers);
$focusAvailable = $focusTarget && ($focusTarget['capture_backend'] ?? '') === 'gphoto2'
    && (string)($focusTarget['enabled'] ?? '0') === '1' && ($focusTarget['active'] ?? '') === 'active';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
// One-shot POST/redirect output, not a persistent log archive or live tail.
$workerLogs = $_SESSION['worker_logs'] ?? null;
unset($_SESSION['worker_logs']);
$csrf = (string)$_SESSION['csrf'];
// Read only a fixed installed release file, or the source-tree copy in dev.
// Missing metadata is explicit; never infer a release from a mutable timestamp.
$versionFile = '/usr/local/share/doc/camera-rpi/VERSION';
if (!is_file($versionFile)) { $versionFile = dirname(__DIR__, 2) . '/VERSION'; }
$softwareVersion = trim((string)@file_get_contents($versionFile));
if (!preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $softwareVersion)) { $softwareVersion = 'unbekannt'; }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Camera-RPi Verwaltung</title>
  <link rel="stylesheet" href="./style.css?v=camera-power-1">
</head>
<body>
<main>
  <header class="page-head">
    <div>
      <p class="eyebrow">Lokale Geräteverwaltung</p>
      <h1>Camera-RPi <small><?= e($softwareVersion) ?></small></h1>
      <p>Eine Kamera, mehrere unabhängige Zielserver.</p>
      <?php if ($poolSource !== ''): ?>
        <p>Gemeinsamer Bilderpool: eine Aufnahme für alle aktiven Ziele, höchstens zehn wartende Bilder je Ziel.
          Laufende Uploads bleiben geschützt. Kameraeinstellungen unter
          <a href="?edit=<?= e(rawurlencode($poolSource)) ?>"><?= e($poolSource) ?></a> bearbeiten.</p>
      <?php endif; ?>
    </div>
    <div class="actions">
      <a class="secondary" href="/camera-status/">Kamera &amp; Klima</a>
      <div class="identity">Angemeldet als <?= e((string)$_SERVER['REMOTE_USER']) ?></div>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="notice <?= e((string)$flash['type']) ?>" role="status"><?= e((string)$flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($loadError !== ''): ?>
    <div class="notice error" role="alert"><?= e($loadError) ?></div>
  <?php endif; ?>

  <?php if (function_exists('camera_power_view')) { require dirname(__DIR__).'/src/power-panel.php'; } ?>

  <section class="panel">
    <div class="section-title">
      <div>
        <p class="eyebrow"><?= $editServer ? 'Vorhandenes Ziel' : 'Neues Ziel' ?></p>
        <h2><?= $editServer ? e((string)$editServer['server_id']) . ' bearbeiten' : 'Server hinzufügen' ?></h2>
      </div>
      <?php if ($editServer): ?><a class="secondary" href="?new=1">Weiteren Server hinzufügen</a><?php elseif ($servers): ?><a class="secondary" href="./">Zurück zum vorhandenen Ziel</a><?php endif; ?>
    </div>
    <form method="post" autocomplete="off" class="server-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <label>
        Interner Name (Server-ID)
        <!-- HTML patterns use Unicode-sets syntax: escape the literal hyphen. -->
        <input name="server_id" required maxlength="48" pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?"
          title="1 bis 48 Zeichen: Kleinbuchstaben a–z, Zahlen und Bindestriche; kein Bindestrich am Anfang oder Ende."
          value="<?= e((string)($editServer['server_id'] ?? '')) ?>" <?= $editServer ? 'readonly' : '' ?>
          placeholder="zum-beispiel-prod">
        <small>Zum Beispiel steinhagen-prod. Keine URL; später nicht änderbar. Nur Kleinbuchstaben, Zahlen und Bindestriche, nicht am Anfang oder Ende.</small>
      </label>
      <label>
        Basisadresse des Servers
        <input type="url" name="server_base_url" required maxlength="255"
          value="<?= e((string)($editServer['server_base_url'] ?? '')) ?>"
          placeholder="https://server.example/webcam">
        <small>Ohne <code>/include/upload.php</code>; dieser Teil wird automatisch ergänzt.</small>
      </label>
      <label>
        Webcam-Kennung auf diesem Server
        <input name="camera_slug" required maxlength="64" pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?"
          title="1 bis 64 Zeichen: Kleinbuchstaben a–z, Zahlen und Bindestriche; kein Bindestrich am Anfang oder Ende."
          value="<?= e((string)($editServer['camera_slug'] ?? '')) ?>" placeholder="bielefeld">
      </label>
      <label>
        <?= $editServer ? 'Neuer Schlüssel (optional)' : 'Schlüssel' ?>
        <input type="password" name="key" minlength="4" maxlength="512"
          <?= $editServer ? '' : 'required' ?> autocomplete="new-password">
        <small><?= $editServer ? 'Leer lassen, um den vorhandenen Schlüssel unverändert zu behalten.' : 'Dieser Schlüssel gilt nur für diesen Zielserver und wird später nie angezeigt.' ?></small>
      </label>
      <label>
        Upload-Verfahren
        <select name="upload_mode">
          <option value="legacy" <?= (($editServer['upload_mode'] ?? 'legacy') === 'legacy') ? 'selected' : '' ?>>Legacy – ein vollständiger Upload</option>
          <option value="chunked" <?= (($editServer['upload_mode'] ?? '') === 'chunked') ? 'selected' : '' ?>>Chunked v1 – kleine, geprüfte Teile</option>
        </select>
        <small>Lokale Auswahl. Eine zentrale Legacy-/Chunked-Vorgabe hat ab der nächsten Serverabfrage Vorrang. Chunked fällt bei fehlender Serverunterstützung auf Legacy zurück.</small>
      </label>

      <label class="check"><input type="checkbox" name="enabled" <?= (($editServer['enabled'] ?? '1') === '1') ? 'checked' : '' ?>> Worker nach dem Speichern aktivieren</label>
      <button type="submit">Sicher speichern</button>
    </form>
  </section>

  <?php require dirname(__DIR__).'/src/source-panel.php'; ?>

  <section>
    <div class="section-title">
      <div><p class="eyebrow">Laufender Betrieb</p><h2>Zielserver</h2></div>
      <span class="count"><?= count($servers) ?> eingerichtet</span>
    </div>
    <div class="cards">
      <?php foreach ($servers as $server):
        $enabled = ($server['enabled'] ?? '0') === '1';
        $healthy = $enabled && ($server['active'] ?? '') === 'active' && !in_array(($server['state'] ?? ''), ['error', 'critical', 'invalid'], true);
      ?>
        <article class="server-card">
          <div class="card-head">
            <div><h3><?= e((string)$server['server_id']) ?></h3><p><?= e((string)$server['server_base_url']) ?></p></div>
            <span class="badge <?= $healthy ? 'ok' : ($enabled ? 'warn' : 'off') ?>"><?= $healthy ? 'läuft' : ($enabled ? 'prüfen' : 'aus') ?></span>
          </div>
          <dl>
            <div><dt>Webcam</dt><dd><?= e((string)$server['camera_slug']) ?></dd></div>
            <div><dt>Status</dt><dd><?= e((string)$server['state']) ?></dd></div>
            <div><dt>Upload</dt><dd><?= e((string)$server['upload_mode']) ?></dd></div>
            <div><dt>Wirksame Vorgabe</dt><dd><?= e((string)($server['effective_upload_mode'] ?? $server['upload_mode'])) ?> (<?= ($server['server_upload_mode'] ?? 'local') === 'local' ? 'lokal' : 'zentral' ?>)</dd></div>
            <div><dt>Aufnahme</dt><dd><?= e((string)($server['capture_backend'] ?? 'gphoto2')) ?></dd></div>
            <div><dt>Wartende Aufträge</dt><dd><?= e((string)$server['requests']) ?></dd></div>
            <div><dt>Wartende Bilder</dt><dd><?= e((string)$server['images']) ?></dd></div>
          </dl>
          <p class="message"><?= e((string)$server['message']) ?></p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="logs">
            <input type="hidden" name="server_id" value="<?= e((string)$server['server_id']) ?>">
            <button type="submit" class="secondary">Letzte 10 Log-Einträge</button>
          </form>
          <?php if (is_array($workerLogs) && ($workerLogs['server_id'] ?? '') === $server['server_id']): ?>
            <pre class="worker-log"><?= e(trim((string)($workerLogs['text'] ?? '')) ?: 'Keine Journal-Einträge vorhanden.') ?></pre>
          <?php endif; ?>
          <div class="actions">
            <a class="secondary" href="?edit=<?= rawurlencode((string)$server['server_id']) ?>">Bearbeiten</a>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="capture"><input type="hidden" name="server_id" value="<?= e((string)$server['server_id']) ?>"><button type="submit" <?= !$enabled || ($server['active'] ?? '') !== 'active' ? 'disabled' : '' ?>>Jetzt aufnehmen</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="validate"><input type="hidden" name="server_id" value="<?= e((string)$server['server_id']) ?>"><button class="secondary" type="submit">Prüfen</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="restart"><input type="hidden" name="server_id" value="<?= e((string)$server['server_id']) ?>"><button class="secondary" type="submit">Neu starten</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="set_enabled"><input type="hidden" name="server_id" value="<?= e((string)$server['server_id']) ?>"><input type="hidden" name="enabled_value" value="<?= $enabled ? 'false' : 'true' ?>"><button class="<?= $enabled ? 'danger' : '' ?>" type="submit"><?= $enabled ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$servers): ?><p class="empty">Noch kein Zielserver eingerichtet.</p><?php endif; ?>
    </div>
  </section>
  <?php if (($focusTarget['capture_backend'] ?? '') === 'gphoto2'): ?>
  <section class="panel camera-focus-panel" aria-labelledby="camera-focus-heading">
    <h2 id="camera-focus-heading">Kamerafokus</h2>
    <p>Ein Objektiv für alle Zielserver. − näher, + weiter; auto löst Autofokus aus.</p>
    <form method="post" class="focus-controls">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="focus">
      <fieldset><legend>gphoto2-Fokus</legend>
        <?php foreach (['--', '-', 'auto', '+', '++'] as $focusMode): ?>
          <button class="secondary" name="focus_mode" value="<?= e($focusMode) ?>" aria-label="Fokus <?= e($focusMode) ?>" <?= !$focusAvailable ? 'disabled' : '' ?>><?= e($focusMode) ?></button>
        <?php endforeach; ?>
        <p><?= $focusAvailable ? 'Änderungen werden vorgemerkt und unter der gemeinsamen Kamerasperre ausgeführt.' : 'Nicht verfügbar: Ein eindeutiges, aktives gphoto2-Kameraprofil mit unterstütztem Objektiv ist erforderlich. USB/V4L2 wird nicht unterstützt.' ?></p>
      </fieldset>
    </form>
  </section>
  <?php endif; ?>
  <?php require dirname(__DIR__) . '/src/climate-panel.php'; ?>
  <?php if (function_exists('privacy_view')) { require dirname(__DIR__).'/src/privacy-panel.php'; } ?>
  <?php if (function_exists('gpio_view')) { require dirname(__DIR__).'/src/gpio-panel.php'; } ?>
</main>
<script src="source.js" defer></script>
</body>
</html>
