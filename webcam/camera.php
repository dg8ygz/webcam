<?php
declare(strict_types=1);

use Webcam\Service\View\CameraSnapshotPresentationBuilder;
use Webcam\Service\View\CameraPagePayloadBuilder;
use Webcam\Service\View\CameraTimelineCalendar;
use Webcam\Service\View\CameraTimelineNavigationBuilder;

// ---------------------------------------------------------------------------
// Page flow (high level):
// 1) Helpers + bootstrap
// 2) Input parsing (slug + selection)
// 3) Timeline/archive lookup
// 4) View-model assembly
// 5) Render + cache hints
// ---------------------------------------------------------------------------

$app = require __DIR__ . '/bootstrap.php';
$timelineCalendar = new CameraTimelineCalendar();
$timelineNavigationBuilder = new CameraTimelineNavigationBuilder($app, $timelineCalendar);
$snapshotPresentationBuilder = new CameraSnapshotPresentationBuilder($app);
$cameraPagePayloadBuilder = new CameraPagePayloadBuilder();

// ---------------------------------------------------------------------------
// HTTP headers + public CSRF cookie.
// ---------------------------------------------------------------------------
// Dynamic content – ensure browsers/proxies don't cache outdated renders (map height, timeline, etc.).
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}
// Muss vor einem möglichen Page-Cache-Treffer gesendet werden; das Layout wird
// bei einem Treffer nicht mehr ausgeführt.
webcam_ensure_public_csrf_cookie();
// lightweight profiling (request-triggered only in dev/test or from loopback; env override stays server-side)
$__wc_profileEnv = filter_var(getenv('WC_PROFILE'), FILTER_VALIDATE_BOOL);
$__wc_profileRequest = isset($_SERVER['HTTP_WC_PROFILE']) || (isset($_GET['profile']) && $_GET['profile'] === '1');
$__wc_profileEnvName = strtolower(trim((string) getenv('WEBCAM_ENV')));
$__wc_profileLoopback = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$__wc_profileDevAllowed = $__wc_profileLoopback
  || in_array($__wc_profileEnvName, ['dev', 'development', 'test'], true)
  || filter_var(getenv('WEBCAM_ALLOW_SAMPLE_CONFIG'), FILTER_VALIDATE_BOOL);
$__wc_profile = $__wc_profileEnv || ($__wc_profileRequest && $__wc_profileDevAllowed);
$__wc_t0 = microtime(true);
$__wc_marks = [];
$__wc_log = static function (string $label) use (&$__wc_t0, &$__wc_marks, $__wc_profile, $app): void {
  $t = microtime(true) - $__wc_t0;
  $line = sprintf('[WC_PROFILE_CLIENT] %.3fs %s', $t, $label);
  $mark = [$label, $t];
  $__wc_marks[] = $mark;
  if ($__wc_profile) {
    error_log($line);
    // inline for curl | grep WC_PROFILE_CLIENT
    echo "<!-- {$line} -->\n";
    try {
      $logFile = $app->paths()->statePath('wc_profile.log');
      if (file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND) === false) {
        error_log('camera.php: wc_profile log write failed');
      }
    } catch (Throwable $e) {
      // ignore logging failures
    }
  }
};
$__wc_log('bootstrap');


// Die beiden folgenden Stufen teilen bewusst denselben PHP-Scope. Die erste
// normalisiert den Request und behandelt den schnellen Best-Of-Pfad. Die
// zweite löst Timeline/Snapshot auf und erzeugt das fertige View-Modell.
$cameraPageRoot = __DIR__;
$cameraPageEntryFile = __FILE__;
$cameraPageResponseComplete = false;

require __DIR__ . '/src/Http/Public/camera/request.php';
if ($cameraPageResponseComplete) {
  return;
}

require __DIR__ . '/src/Http/Public/camera/presentation.php';
