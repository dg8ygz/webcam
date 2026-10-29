<?php
declare(strict_types=1);

// View-spezifische Verlaufsdaten werden erst nach den gemeinsamen Renderern
// aufgebaut. Der teurere Archivzugriff läuft nur für die History-Ansicht;
// alle anderen Kameraansichten erhalten das leere JSON-Array.

$dayHistoryJson = '[]';
// History-Serie nur dann aufbauen, wenn der Verlauf wirklich angezeigt wird.
if ($viewMode === 'history') {
  $cameraObject = $app->cameras()->find((string) ($camera['slug'] ?? ''));
  if ($snapshot && isset($snapshot['time']) && $cameraObject) {
    if (!empty($isArchiveSelection)) {
      $historySeries = [];
    } else {
      $historySeries = $app->archiveBrowser()->recentTimeslotEntries(
        $cameraObject,
        $timeline,
        (string) $snapshot['time'],
        30,
        isset($snapshot['timestamp']) ? (int) $snapshot['timestamp'] : null
      );
    }
    $encodedHistory = json_encode($historySeries ?: [], JSON_UNESCAPED_SLASHES);
    if ($encodedHistory !== false) {
      $dayHistoryJson = $encodedHistory;
    }
  }
}

  $overlayToneCurrent = is_string($overlay['tone'] ?? null) ? $overlay['tone'] : 'dark';
?>
