#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Service\Overlay\OverlayFileGuard;

/**
 * Entfernt verwaiste Overlay-Dateien unter cache/<slug>/overlays.
 *
 * Verwendete Dateien werden aus der aktuellen Kamera-Konfiguration gesammelt.
 * Standard: Dry-Run (nur Anzeige). Mit --delete werden ausschließlich nicht mehr
 * referenzierte Dateien und anschließend leere Overlay-Verzeichnisse gelöscht.
 */

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
  echo "Usage: php bin/cleanup_overlays.php [--delete] [--verbose]\n";
  echo "  --delete   Tatsächlich löschen (sonst nur Auflistung)\n";
  echo "  --verbose  Ausgabe je gelöschter Datei/Verzeichnis\n";
  exit(0);
}

$app = require __DIR__ . '/../bootstrap.php';

$delete = in_array('--delete', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$graceRaw = getenv('WEBCAM_OVERLAY_ORPHAN_GRACE_SECONDS');
$graceSeconds = is_string($graceRaw) && trim($graceRaw) !== '' ? (int) $graceRaw : 86400;
$graceSeconds = max(60, min(604800, $graceSeconds));

try {
  $overlayGuard = OverlayFileGuard::acquire($app, 30);
} catch (RuntimeException $exception) {
  fwrite(STDERR, 'ABBRUCH: ' . $exception->getMessage() . PHP_EOL);
  exit(1);
}
$scanStartedAt = time();

$cameras = $app->cameras()->all();
$slugs = array_map(static fn($c) => $c->slug(), $cameras);
$slugSet = array_fill_keys($slugs, true);

// Erwarte Dateien aus den aktuell gesetzten Overlay-Logos.
$usedFiles = [];
foreach ($cameras as $camera) {
  foreach ([$camera->overlayLogo(), $camera->overlayLogoDark()] as $overlay) {
    if (!$overlay || empty($overlay['url'])) {
      continue;
    }
    $url = (string) $overlay['url'];
    // Overlay-URLs: static /cache/<slug>/overlays/<file>, overlay.php?slug=..., oder legacy assets/overlays/<slug>/<file>.
    $slug = null;
    $file = null;
    if (str_contains($url, 'overlay.php')) {
      $parts = parse_url($url);
      if (isset($parts['query'])) {
        parse_str($parts['query'], $q);
        $slug = $q['slug'] ?? null;
        $file = $q['file'] ?? null;
      }
    } elseif (preg_match('#/cache/([a-z0-9-]+)/overlays/([^/?]+)$#', $url, $m)) {
      $slug = $m[1];
      $file = $m[2];
    } elseif (preg_match('#overlays/([a-z0-9-]+)/([^/?]+)$#', $url, $m)) {
      $slug = $m[1];
      $file = $m[2];
    }
    if ($slug && $file) {
      $path = $app->paths()->cachePath($slug . '/overlays/' . $file);
      $real = realpath($path);
      if ($real) {
        $usedFiles[$real] = true;
      }
    }
  }
}

$orphans = [];
$protected = [];
// bekannte Slugs
foreach ($slugSet as $slug => $_) {
  $dir = $app->paths()->cachePath($slug . '/overlays');
  if (!is_dir($dir)) {
    continue;
  }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $path = $item->getPathname();
    if ($item->isDir()) {
      continue;
    }
    $isUsed = isset($usedFiles[realpath($path) ?: $path]);
    if (!$isUsed) {
      if (OverlayFileGuard::recentlyPublished($path, $scanStartedAt, $graceSeconds)) {
        $protected[] = $path;
      } else {
        $orphans[] = $path;
      }
    }
  }
}
// unbekannte Slugs (Verzeichnisse ohne Kamera)
$cacheRoot = $app->paths()->cachePath();
foreach (glob($cacheRoot . '/*/overlays', GLOB_ONLYDIR) ?: [] as $dir) {
  $slug = basename(dirname($dir));
  if (isset($slugSet[$slug])) {
    continue;
  }
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $item) {
    if ($item->isDir()) {
      continue;
    }
    $path = $item->getPathname();
    if (OverlayFileGuard::recentlyPublished($path, $scanStartedAt, $graceSeconds)) {
      $protected[] = $path;
    } else {
      $orphans[] = $path;
    }
  }
}

if ($protected) {
  echo 'Vorlaeufig geschuetzte neue Uploads (' . count($protected) . ", Schutzfrist {$graceSeconds}s):\n";
  foreach ($protected as $path) {
    echo " - {$path}\n";
  }
}

if (!$orphans) {
  echo "Keine verwaisten Overlay-Dateien gefunden.\n";
  $overlayGuard->release();
  exit(0);
}

echo "Gefundene Orphans (" . count($orphans) . "):\n";
foreach ($orphans as $path) {
  echo " - {$path}\n";
  if ($delete) {
    if (is_dir($path)) {
      if (!rmdir($path)) {
        fwrite(STDERR, "Warnung: Verzeichnis konnte nicht gelöscht werden: {$path}\n");
      }
    } else {
      if (!unlink($path)) {
        fwrite(STDERR, "Warnung: Datei konnte nicht gelöscht werden: {$path}\n");
      }
    }
    if ($verbose) {
      echo "   gelöscht\n";
    }
  }
}

// Leere Kamera-Verzeichnisse entfernen, wenn delete aktiv
if ($delete) {
  echo "Cleanup abgeschlossen.\n";
} else {
  echo "Dry-Run beendet. Mit --delete löschen.\n";
}
$overlayGuard->release();
