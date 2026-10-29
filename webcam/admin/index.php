<?php
declare(strict_types=1);

/** @var \Webcam\App $app */
$app = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/admin_auth.php';

// Die Admin-Seite enthält nur das geschützte HTML-/JS-Grundgerüst. Konfiguration
// und Status werden anschließend über admin/api.php geladen; ohne gültigen Token
// wird vor jeder HTML-Ausgabe abgebrochen.
if (!adminIsAuthorized($app)) {
  adminSendAuthChallenge();
  http_response_code(401);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Admin authentication required.\n";
  exit;
}

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);

// Assets liegen eine Ebene oberhalb von /admin (assets/…)
$assetsPath = trim((string) $app->config('assets_url', 'assets'), '/');
if ($assetsPath === '') {
  $assetsPath = 'assets';
}
$assetsPrefix = $relativePrefix . ($assetsPath !== '' ? $assetsPath . '/' : '');
$assetsDataPrefix = rtrim($assetsPrefix, '/');

// Die Admin-JavaScript-Dateien bilden eine zusammengehörige Laufzeitversion.
// Die Iteration im Query-String verhindert, dass ein Browser nach einem
// Rollout eine alte admin.js mit einer neuen Fachkomponente kombiniert.
$iterationValue = is_readable(__DIR__ . '/../ITERATION')
  ? trim((string) file_get_contents(__DIR__ . '/../ITERATION'))
  : '';
$adminIteration = preg_match('/^\d+$/', $iterationValue) === 1
  ? $iterationValue
  : '';
$adminAssetRevision = $adminIteration !== ''
  ? $adminIteration
  : (string) max(
    (int) filemtime(__DIR__ . '/../assets/css/admin.css'),
    (int) filemtime(__DIR__ . '/../assets/js/admin.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-status.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-maintenance.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-archive-cache.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-settings.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-capture-editor.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-map-preview.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-overlay-editor.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-label-editor.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-form-validation.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-camera-editor.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-image-processing.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-camera-payload.js'),
    (int) filemtime(__DIR__ . '/../assets/js/admin-page.js'),
  );

// Admin-API-Basis: aktuelles Verzeichnis (admin/) – api.php liegt hier.
// JS hängt /api.php/<route> selbst an.
$apiBaseUrl = '.';

$cameraUrl = $relativePrefix . 'camera/';

// CSRF-Token per Cookie (double-submit)
$csrfCookieName = 'admin_csrf';
$csrfToken = isset($_COOKIE[$csrfCookieName]) && is_string($_COOKIE[$csrfCookieName]) && preg_match('/^[a-f0-9]{32,64}$/', $_COOKIE[$csrfCookieName])
  ? $_COOKIE[$csrfCookieName]
  : bin2hex(random_bytes(32));
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie($csrfCookieName, $csrfToken, [
  'expires' => 0,
  'path' => '/',
  'secure' => $secure,
  'httponly' => false, // JS muss den Cookie lesen, daher nicht HttpOnly
  'samesite' => 'Lax',
]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Webcam Admin</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($relativePrefix . 'assets/favicon.png') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetsPrefix . 'vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetsPrefix . 'vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetsPrefix . 'css/admin.css?v=' . $adminAssetRevision) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetsPrefix . 'vendor/leaflet/leaflet.css') ?>">
</head>
<body>
  <header class="admin-header">
    <h1>Webcam Konfiguration</h1>
    <nav>
      <a href="<?= htmlspecialchars($relativePrefix) ?>">Zurück zur Übersicht</a>
    </nav>
  </header>

  <main id="admin-app"
        data-api-base="<?= htmlspecialchars($apiBaseUrl) ?>"
        data-assets-base="<?= htmlspecialchars($assetsDataPrefix) ?>"
        data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
        data-camera-url="<?= htmlspecialchars($cameraUrl) ?>"
        data-iteration="<?= htmlspecialchars($adminIteration) ?>"
        data-wc-test="<?= isset($_GET['test']) && $_GET['test'] === '1' ? '1' : '0' ?>">
    <noscript>
      <p>Für das Admin-Interface muss JavaScript aktiviert sein.</p>
    </noscript>
  </main>

  <template id="camera-row-template">
    <tr>
      <td class="cell-slug"></td>
      <td class="cell-title"></td>
      <td class="cell-mode"></td>
      <td class="cell-interval"></td>
      <td class="cell-actions">
        <button type="button" class="btn edit">Bearbeiten</button>
        <button type="button" class="btn danger delete">Löschen</button>
      </td>
    </tr>
  </template>
  <script src="<?= htmlspecialchars($assetsPrefix . 'vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'vendor/leaflet/leaflet.js') ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'vendor/tinymce/js/tinymce/tinymce.min.js') ?>"></script>
  <script>
    // Workaround: Simple module loader fallback for Playwright/basic contexts
    window.__ADMIN_CONFIG__ = {
      apiBase: "<?= htmlspecialchars($apiBaseUrl) ?>",
      assetsBase: "<?= htmlspecialchars($assetsDataPrefix) ?>",
      cameraUrl: "<?= htmlspecialchars($cameraUrl) ?>",
      wcTest: "<?= isset($_GET['test']) && $_GET['test'] === '1' ? '1' : '0' ?>",
      csrfToken: "<?= htmlspecialchars($csrfToken) ?>",
    };
  </script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-status.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-maintenance.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-archive-cache.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-settings.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-capture-editor.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-map-preview.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-overlay-editor.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-label-editor.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-form-validation.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-image-processing.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-camera-editor.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-camera-payload.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin-page.js?v=' . $adminAssetRevision) ?>"></script>
  <script src="<?= htmlspecialchars($assetsPrefix . 'js/admin.js?v=' . $adminAssetRevision) ?>" type="module"></script>
</body>
</html>
