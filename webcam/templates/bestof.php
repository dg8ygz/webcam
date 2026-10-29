<?php
declare(strict_types=1);

/** @var \Webcam\App $app */
/** @var array<string,mixed> $bestOfPage */
/** @var list<\Webcam\Domain\Camera> $cameraOptions */

$basePathValue = trim((string) $app->config('base_url', ''), '/');
$relativePrefix = webcam_document_relative_prefix($basePathValue);
$meta = $app->camerasMeta();
$heroText = (string) ($meta['overview_hero'] ?? $app->config('overview_hero', ''));
?>

<?php require __DIR__ . '/overview_header.php'; ?>
<?php
$globalBestOfPage = $bestOfPage;
$globalBestOfPerPage = $perPage;
$globalSelectedYear = $selectedYear;
$globalSelectedCamera = $selectedCamera;
require __DIR__ . '/global_bestof_gallery.php';
?>
