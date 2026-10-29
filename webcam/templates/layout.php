<?php
declare(strict_types=1);

// Gemeinsamer HTML-Rahmen aller gerenderten Seiten: relative Assetpfade,
// Seitentitel, Navigation sowie lokale CSS/JS-Dateien. Hier wird außerdem das
// öffentliche Double-Submit-CSRF-Cookie für Like-/Best-Of-Aktionen gesetzt.

/** @var string $content */
/** @var \Webcam\App|null $app */
$app ??= null;
$pageTitle ??= 'Webcams';
$basePathValue = trim((string) ($app?->config('base_url', '') ?? ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);

$assetsPath = trim((string) ($app?->config('assets_url', 'assets') ?? 'assets'), '/');
if ($assetsPath === '') {
  $assetsPath = 'assets';
}
$assetsPrefix = $relativePrefix . ($assetsPath !== '' ? $assetsPath . '/' : '');

// JS liest dieses Double-Submit-Cookie für Like-/Best-Of-Aktionen.
webcam_ensure_public_csrf_cookie();

$styles = $styles ?? [];
$scripts = $scripts ?? [];
$defaultStyles = [
  'css/app.css',
];
$defaultScripts = ['js/theme.js', 'js/app.js'];
if (!empty($enableLeaflet)) {
  $defaultStyles[] = 'vendor/leaflet/leaflet.css';
  $defaultScripts[] = 'vendor/leaflet/leaflet.js';
}
$styles = array_merge($defaultStyles, $styles);
$scripts = array_merge($defaultScripts, $scripts);

$projectVersion = trim((string) @file_get_contents(__DIR__ . '/../VERSION'));
$projectIteration = trim((string) @file_get_contents(__DIR__ . '/../ITERATION'));
$projectVersion = $projectVersion !== '' ? $projectVersion : 'unbekannt';
$projectIteration = ctype_digit($projectIteration) ? $projectIteration : 'unbekannt';

/**
 * Baut eine kurze, aber robuste Versionskennung fuer lokale CSS-/JS-Dateien.
 *
 * Nur `filemtime()` war fuer schnelle Rollouts zu grob: Werden mehrere
 * Fassungen innerhalb derselben Sekunde geschrieben, kann der Browser trotz
 * normalem Reload noch eine alte Datei unter derselben URL verwenden. Die
 * Kombination aus Projektiteration, Aenderungszeit und Dateigroesse wechselt
 * auch bei solchen Auslagerungen praktisch immer, ohne fuer jeden Seitenaufruf
 * den gesamten Inhalt aller Assets erneut hashen zu muessen.
 */
$assetRevisionForPath = static function (string $path) use ($projectIteration): string {
  clearstatcache(true, $path);
  $modifiedAt = filemtime($path);
  $size = filesize($path);
  $iteration = ctype_digit($projectIteration) ? $projectIteration : '0';
  return sprintf(
    '%s-%d-%d',
    $iteration,
    $modifiedAt !== false ? $modifiedAt : 0,
    $size !== false ? $size : 0
  );
};

$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
$bodyClassAttr = $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass) . '"' : '';
$mainClass = isset($mainClass) && is_string($mainClass) && $mainClass !== ''
  ? trim($mainClass)
  : 'main-content';
$mainStyleAttr = '';
if (isset($mainStyle) && is_string($mainStyle) && trim($mainStyle) !== '') {
  $mainStyleAttr = ' style="' . htmlspecialchars(trim($mainStyle), ENT_QUOTES) . '"';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($relativePrefix) ?>assets/favicon.png">
  <?php foreach ($styles as $href): ?>
    <?php
      $isExternal = preg_match('#^(?:[a-z]+:)?//#i', $href);
      $finalHref = $isExternal ? $href : $assetsPrefix . ltrim($href, '/');
      if (!$isExternal) {
        $assetsFsBase = realpath(__DIR__ . '/../' . $assetsPath);
        $fsPath = $assetsFsBase ? realpath($assetsFsBase . '/' . ltrim($href, '/')) : false;
        if ($fsPath && is_file($fsPath)) {
          $ver = $assetRevisionForPath($fsPath);
          $finalHref .= (str_contains($finalHref, '?') ? '&' : '?') . 'v=' . $ver;
        }
      }
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($finalHref) ?>">
  <?php endforeach; ?>
</head>
<body<?= $bodyClassAttr ?>>
  <div class="app-shell">
    <?php $wrapperClass = 'layout-grid'; ?>
    <?php $wrapperStyleAttr = ''; ?>
    <?php if (($mainClass ?? '') && str_contains((string)$mainClass, 'main-content--full')): ?>
      <?php $wrapperClass .= ' layout-grid--full'; ?>
      <?php $wrapperStyleAttr = ' style="padding:0"'; ?>
    <?php endif; ?>
    <div class="<?= htmlspecialchars($wrapperClass) ?>"<?= $wrapperStyleAttr ?> data-layout-grid>
      <main class="<?= htmlspecialchars($mainClass) ?>"<?= $mainStyleAttr ?>>
        <?= $content ?>
      </main>
    </div>

    <footer class="site-footer">
      <div>
        <strong>webcam.example.invalid</strong> – Amateurfunk &amp; Infrastrukturprojekte in OWL
        · Version <?= htmlspecialchars($projectVersion) ?> · Iteration <?= htmlspecialchars($projectIteration) ?>
      </div>
      <div>
        Letztes Update: <?= date('d.m.Y H:i') ?>
      </div>
    </footer>
  </div>

  <?php foreach ($scripts as $src): ?>
    <?php
      $finalSrc = (preg_match('#^(?:[a-z]+:)?//#i', $src))
        ? $src
        : $assetsPrefix . ltrim($src, '/');
      if (!preg_match('#^(?:[a-z]+:)?//#i', $src)) {
        $assetsFsBase = realpath(__DIR__ . '/../' . $assetsPath);
        $fsPath = $assetsFsBase ? realpath($assetsFsBase . '/' . ltrim($src, '/')) : false;
        if ($fsPath && is_file($fsPath)) {
          $ver = $assetRevisionForPath($fsPath);
          $finalSrc .= (str_contains($finalSrc, '?') ? '&' : '?') . 'v=' . $ver;
        }
      }
    ?>
    <script src="<?= htmlspecialchars($finalSrc) ?>"></script>
  <?php endforeach; ?>
  <?php if (!empty($inlineScript)): ?>
    <script>
    <?= $inlineScript ?>
    </script>
  <?php endif; ?>
</body>
</html>
