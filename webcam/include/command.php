<?php
declare(strict_types=1);

// Leichtgewichtiger Poll-Endpunkt für externe Kamera-Helper:
// 1. Kamera und geheimen Schlüssel prüfen.
// 2. Wartungslease/Uploadmodus als Header liefern; Fokus hat Vorrang im Body.
// 3. Aufnahme-Slot sperren: manuellen Einmalauftrag oder fälligen Slot zustellen.
// 4. Nur hinter einer wait-Antwort fällige serverseitige Pull-Kameras prüfen.
// Der Schlüssel gehört bevorzugt in X-Command-Key/X-Upload-Key oder POST. Ein
// Query-Key wird während der Migration noch akzeptiert, aber sichtbar geloggt.

use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Capture\CaptureSourcePriority;
use Webcam\Service\Capture\PullSnapshotSlotPlanner;
use Webcam\Service\Capture\ManualCaptureQueue;
use Webcam\Service\Maintenance\MaintenanceTick;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap.php';

// ---------------------------------------------------------------------------
// Validate camera + key, then return command response.
// ---------------------------------------------------------------------------
$slug = $_POST['wc'] ?? ($_GET['wc'] ?? '');
$camera = $slug ? $app->cameras()->find($slug) : null;
if (!$camera) {
  http_response_code(404);
  header('Content-Type: text/plain');
  echo "not found\n";
  exit;
}

$expected = (string) ($camera->capture()['upload_key'] ?? '');
$key = '';
$keySource = 'missing';
if (isset($_SERVER['HTTP_X_COMMAND_KEY']) && is_string($_SERVER['HTTP_X_COMMAND_KEY'])) {
  $key = trim((string) $_SERVER['HTTP_X_COMMAND_KEY']);
  $keySource = 'header_command';
} elseif (isset($_SERVER['HTTP_X_UPLOAD_KEY']) && is_string($_SERVER['HTTP_X_UPLOAD_KEY'])) {
  $key = trim((string) $_SERVER['HTTP_X_UPLOAD_KEY']);
  $keySource = 'header_upload';
} elseif (isset($_POST['key']) && is_string($_POST['key'])) {
  $key = trim((string) $_POST['key']);
  $keySource = 'post';
} elseif (isset($_GET['key'])) {
  $key = trim((string) $_GET['key']);
  $keySource = 'query';
}
if ($expected === '' || !hash_equals($expected, $key)) {
  http_response_code(403);
  header('Content-Type: text/plain');
  $app->logger()->warning('command forbidden', ['camera' => $camera->slug(), 'key_source' => $keySource]);
  echo "forbidden\n";
  exit;
}
if ($keySource === 'query') {
  $app->logger()->warning('command query key accepted during migration', ['camera' => $camera->slug()]);
}

$interval = (int) ($camera->capture()['interval_seconds'] ?? 600);
// Coordinates belong to the camera's saved location, not the Pi's IP or an
// example location. Old helpers ignore this header; command frames unchanged.
header('X-Camera-Position: ' . \Webcam\Service\Capture\CameraPositionHeader::encode($camera->location(), date_default_timezone_get()));
// Planned privacy skips are not a failed camera. Accept reports only after
// key verification; they expire if the helper disappears. No live DB writes.
try {
  \Webcam\Service\Capture\CameraPrivacyState::forPaths($app->paths())->receive(
    $camera->slug(),'command',(string)($_SERVER['HTTP_X_CAMERA_PRIVACY'] ?? '')
  );
} catch (\Throwable) { $app->logger()->warning('camera privacy report invalid',['camera'=>$camera->slug()]); }
// Independent response header: opening/closing maintenance never consumes a capture slot.
try {
  $lease = (new \Webcam\Service\Maintenance\CameraTunnelLease($app->paths()->statePath('camera-tunnel')))->poll(
    $camera->slug(), (string) ($_SERVER['HTTP_X_CAMERA_TUNNEL_PROTOCOL'] ?? ''),
    (string) ($_SERVER['HTTP_X_CAMERA_TUNNEL_STATE'] ?? '')
  );
  if ($lease !== null) {
    header('X-Camera-Tunnel-Lease: ' . $lease);
    header('Cache-Control: no-store');
  }
} catch (\Throwable $error) {
  header('X-Camera-Tunnel-Lease: closed');
  $app->logger()->warning('camera tunnel dispatch unavailable', ['camera' => $camera->slug()]);
}
$helperUploadMode = $camera->capture()['helper_upload_mode'] ?? 'local';
if (in_array($helperUploadMode, ['local', 'legacy', 'chunked'], true)) {
  // Nur nach erfolgreicher Kamera-Authentifizierung; alter Drei-Zeilen-Vertrag bleibt erhalten.
  header('X-Camera-Upload-Mode: ' . $helperUploadMode);
}
$interval = $interval > 0 ? $interval : 600;
// Fokus wird nur explizit faehigen, authentifizierten Helpers zugestellt.
// Frueher Return: Ein Fokusauftrag verbraucht niemals einen Aufnahme-Slot.
try {
  $focus = (new \Webcam\Service\Capture\FocusQueue($app->paths()->statePath('focus')))->poll(
    $camera->slug(), (string) ($_SERVER['HTTP_X_CAMERA_FOCUS_PROTOCOL'] ?? '')
  );
  if ($focus !== null) {
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo $focus;
    exit;
  }
} catch (\Throwable $error) {
  $app->logger()->warning('focus dispatch unavailable', ['camera' => $camera->slug()]);
}
// Explicitly enabled ISO uses the existing authenticated ISOEXP contract.
// Never consume a capture slot.
try {
  $isoCommand = (new \Webcam\Service\Capture\AutoIsoController($app->paths()->statePath('auto-iso')))->poll(
    $camera
  );
  if ($isoCommand !== null) {
    $app->logger()->info('auto iso dispatched', ['camera' => $camera->slug()]);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo $isoCommand;
    exit;
  }
} catch (\Throwable $error) {
  $app->logger()->warning('auto iso dispatch unavailable', ['camera' => $camera->slug()]);
}
$now = time();
$automaticSlot = \Webcam\Service\Scheduler\CaptureSchedule::dueSlot($camera, $now, (string) $app->config('timezone', 'UTC'));
$slot = $automaticSlot ?? (int) floor($now / $interval) * $interval;

$stateDir = $app->paths()->statePath('command');
if (!is_dir($stateDir)) {
  if (!mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
    $app->logger()->warning('command state dir create failed', ['path' => $stateDir]);
  }
}
$stateFile = rtrim($stateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $camera->slug() . '.slot';

header('Content-Type: text/plain');

$response = "wait\n0\nwait\n";
$sourcePriority = new CaptureSourcePriority($app);

// Eine Pull-Kamera soll den alten Helper nicht weiter unnötig auslösen. Erst
// wenn der konfigurierte Pull-Fehlerzähler den Fallback freigibt, darf derselbe
// atomare Slotmechanismus wieder einen echten Capture-Befehl ausgeben.
if ($sourcePriority->shouldRequestExternalCapture($camera)) {
  $slotHandle = @fopen($stateFile, 'c+');
  if ($slotHandle === false) {
    $app->logger()->warning('command state file open failed', ['path' => $stateFile, 'camera' => $camera->slug()]);
  } elseif (!flock($slotHandle, LOCK_EX)) {
    $app->logger()->warning('command state file lock failed', ['path' => $stateFile, 'camera' => $camera->slug()]);
    fclose($slotHandle);
  } else {
    $rawLastSlot = stream_get_contents($slotHandle);
    $lastSlot = is_string($rawLastSlot) && trim($rawLastSlot) !== '' ? (int) trim($rawLastSlot) : null;
    $manual = false;
    // Der manuelle Auftrag wird erst nach erfolgreichem Slot-Schreiben verbraucht.
    // So erzeugt der nächste Poll nicht zusätzlich den regulären selben Slot.
    try {
      if ($camera->mode()->value !== 'pull-snapshot') {
        $manual = (new ManualCaptureQueue($app->paths()->statePath('manual-capture')))->consume(
          $camera->slug(),
          static function () use ($slotHandle, $slot): bool {
            return ftruncate($slotHandle, 0) && rewind($slotHandle)
              && fwrite($slotHandle, (string) $slot) === strlen((string) $slot) && fflush($slotHandle);
          }
        );
      }
    } catch (\Throwable $error) {
      $app->logger()->warning('manual capture dispatch failed', ['camera' => $camera->slug(), 'error' => $error->getMessage()]);
    }
    if ($manual) {
      $response = "command\n{$now}\nwebcam_capture\n";
    } elseif ($automaticSlot !== null && ($lastSlot === null || $lastSlot < $slot)) {
      // Ein anderer Pool-Client kann das Bild bereits an diesen Server
      // geliefert haben. Dann den Slot bestaetigen, nicht erneut ausloesen.
      // Der manuelle Zweig oben bleibt bewusst davon unabhaengig.
      $received = null;
      try {
        $received = \Webcam\Service\Capture\ReceivedCaptureSlot::contains(
          $app->paths()->cameraDbPath($camera->slug() . '/db.sqlite'), $slot
        );
      } catch (\Throwable $error) {
        $app->logger()->warning('command received-slot check failed', ['camera' => $camera->slug()]);
      }
      // null bedeutet Lesefehler, nicht fehlendes Bild: Marker unveraendert
      // lassen und beim naechsten Poll erneut pruefen. Lock umfasst Pruefung
      // und Marker, damit parallele Polls keine Doppelauftraege erzeugen.
      $writeOk = $received !== null && ftruncate($slotHandle, 0)
        && rewind($slotHandle)
        && fwrite($slotHandle, (string) $slot) !== false
        && fflush($slotHandle);
      if ($writeOk && !$received) {
        // Identitaet des automatischen Auftrags ist der Sollslot, nicht die
        // Pollzeit. Ein spaeterer Server/Poll darf dieselbe Poolaufnahme nicht
        // als neuen Auftrag ausloesen. Manuelle Auftraege behalten oben $now.
        $serial = (string) $slot;
        $response = "command\n{$serial}\nwebcam_capture\n";
      } elseif ($writeOk) {
        $app->logger()->info('command slot already received', ['camera' => $camera->slug(), 'slot' => $slot]);
      } elseif ($received !== null) {
        $app->logger()->warning('command state file write failed', ['path' => $stateFile, 'camera' => $camera->slug()]);
      }
    }
    flock($slotHandle, LOCK_UN);
    fclose($slotHandle);
  }
}

echo $response;

// Ensure the response is sent before any long-running pull-capture work.
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  if (function_exists('ob_get_level') && ob_get_level() > 0) {
    ob_flush();
  }
  flush();
}

// Do not run long pull-snapshot work behind a real command response. On mod_php
// flush() does not reliably close the client connection, and the helper has a
// short command.php timeout before it starts webcam_capture.
if (str_starts_with($response, "command\n")) {
  exit;
}

// Nach wait-Antworten zuerst den leichten internen Wartungs-Zeitgeber pruefen.
// Faellige lange Jobs starten abgekoppelt; der Kamera-Helper wartet nicht darauf.
MaintenanceTick::trigger($app);

// Danach opportunistisch faellige Pull-Snapshot-Captures ausfuehren (kein Cron).
tick_pull_snapshots($app);
exit;

/**
 * Opportunistischer Pull-Tick hinter einer wait-Antwort.
 *
 * Ein globaler flock bleibt von der Fälligkeitsprüfung bis zum Ende aller
 * Captures gehalten. Dadurch können parallele Kamera-Polls keine stale Due-Liste
 * doppelt abarbeiten. Der letzte slot_ts aus der Kamera-DB ist die Zeitquelle.
 */
function tick_pull_snapshots(App $app): void
{
  $tickHandle = null;
  $tickLocked = false;
  try {
    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }
    $stateDir = $app->paths()->statePath('pull');
    if (!is_dir($stateDir)) {
      if (!mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
        $app->logger()->warning('pull state dir create failed', ['path' => $stateDir]);
      }
    }
    $lock = rtrim($stateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tick.lock';
    $now = time();

    $tickHandle = @fopen($lock, 'c+');
    if ($tickHandle === false) {
      $app->logger()->warning('pull tick lock open failed', ['path' => $lock]);
      return;
    }
    if (!flock($tickHandle, LOCK_EX | LOCK_NB)) {
      return;
    }
    $tickLocked = true;

    rewind($tickHandle);
    $rawLastTick = stream_get_contents($tickHandle);
    $lastTick = is_string($rawLastTick) && trim($rawLastTick) !== '' ? (int) trim($rawLastTick) : 0;
    if ($lastTick > 0 && ($now - $lastTick) < 20) {
      return; // throttle to max once per ~20s
    }
    $writeOk = ftruncate($tickHandle, 0)
      && rewind($tickHandle)
      && fwrite($tickHandle, (string) $now) !== false
      && fflush($tickHandle);
    if (!$writeOk) {
      $app->logger()->warning('pull tick lock write failed', ['path' => $lock]);
    }

    $due = [];
    $manualQueue = new ManualCaptureQueue($app->paths()->statePath('manual-capture'));
    foreach ($app->cameras()->all() as $cam) {
      if ($cam->mode()->value !== \Webcam\Domain\Enums\CameraMode::PullSnapshot->value) {
        continue;
      }
      $interval = max(60, (int) ($cam->capture()['interval_seconds'] ?? 600));
      $lastSlot = latest_pull_snapshot_slot($app, $cam);
      $nextDue = $lastSlot > 0 ? $lastSlot + $interval : 0;
      $manual = false;
      try {
        $manual = $manualQueue->pending($cam->slug());
      } catch (\Throwable $error) {
        $app->logger()->warning('manual pull state unavailable', ['camera' => $cam->slug(), 'error' => $error->getMessage()]);
      }
      if ($manual || $nextDue === 0 || $nextDue <= $now) {
        $due[] = [
          'camera' => $cam,
          'manual' => $manual,
          'next_due' => $nextDue,
          'interval' => $interval,
          'target_slot' => PullSnapshotSlotPlanner::targetSlot($nextDue, $interval, $now),
        ];
      }
    }

    if (!$due) {
      return;
    }

    usort(
      $due,
      static fn(array $a, array $b): int => $a['next_due'] <=> $b['next_due']
    );

    foreach ($due as $entry) {
      /** @var \Webcam\Domain\Camera $cam */
      $cam = $entry['camera'];
      $nextDue = (int) $entry['next_due'];
      $interval = (int) $entry['interval'];
      $targetSlot = isset($entry['target_slot']) ? (int) $entry['target_slot'] : null;
      $camLock = rtrim($stateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cam->slug() . '.lock';
      $lockHandle = @fopen($camLock, 'c');
      if ($lockHandle === false) {
        $app->logger()->warning('pull camera lock open failed', ['path' => $camLock, 'camera' => $cam->slug()]);
        continue;
      }
      if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        continue;
      }
      ftruncate($lockHandle, 0);
      fwrite($lockHandle, (string) $now);
      try {
        if ($nextDue > 0 && $targetSlot === null) {
          $app->logger()->warning('pull overdue slot too old for catch-up', [
            'camera' => $cam->slug(),
            'next_due' => $nextDue,
            'selected_at' => $now,
            'capture_at' => time(),
            'interval' => $interval,
          ]);
        }
        if ($entry['manual'] ?? false) {
          $manualQueue->consume($cam->slug(), static function (int $capturedAt) use ($app, $cam): bool {
            $result = $app->captureManager()->capture($cam, true);
            $app->logger()->info('manual pull capture completed', ['camera' => $cam->slug(), 'successful' => $result->successful]);
            return true;
          });
        } else {
          $app->captureManager()->capture($cam, slotTimestamp: $targetSlot);
        }
      } catch (\Throwable $error) {
        $app->logger()->warning('pull camera failed', ['camera' => $cam->slug(), 'error' => $error->getMessage()]);
      } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
      }
    }
  } catch (\Throwable $e) {
    $app->logger()->warning('pull tick failed', ['error' => $e->getMessage()]);
  } finally {
    if ($tickHandle !== null) {
      if ($tickLocked) {
        flock($tickHandle, LOCK_UN);
      }
      fclose($tickHandle);
    }
  }
}

/**
 * Liest den jüngsten Capture-Slot read-only.
 *
 * Pull-Captures können erst Minuten nach ihrem Sollslot fertig sein. Würde man
 * mit der Abschlusszeit planen, driftet der Takt; deshalb ist slot_ts die Quelle.
 */
function latest_pull_snapshot_slot(App $app, Camera $camera): int
{
  $dbPath = $app->paths()->cameraDbPath($camera->slug() . '/db.sqlite');
  if (!is_file($dbPath)) {
    $app->logger()->warning('pull slot db missing', ['camera' => $camera->slug(), 'path' => $dbPath]);
    return 0;
  }

  try {
    $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_TIMEOUT => 2,
    ]);
    $value = $pdo->query('SELECT MAX(slot_ts) FROM captures')->fetchColumn();
    return ($value !== false && $value !== null) ? max(0, (int) $value) : 0;
  } catch (\Throwable $e) {
    $app->logger()->warning('pull slot db read failed', [
      'camera' => $camera->slug(),
      'path' => $dbPath,
      'error' => $e->getMessage(),
    ]);
    return 0;
  }
}
