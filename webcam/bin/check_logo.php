#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Erkennt das konfigurierte Overlay-Logo in einer Aufnahme.
 *
 * Usage:
 *   php bin/check_logo.php --slug=bielefeld [--path=relative/or/absolute.jpg] [--score-only]
 *
 * Ohne Pfad wird der neueste DB-Capture verwendet. Die Erkennung vergleicht das
 * Logo normalisiert mit den oberen Bildecken. Außer bei --score-only landet das
 * Ergebnis in capture_logo der aktiven Kamera-DB unter
 * /var/lib/webcam/camera-db/<slug>/db.sqlite.
 */

use Webcam\Service\Overlay\OverlayImageLimits;

$app = require __DIR__ . '/../bootstrap.php';

$args = $_SERVER['argv'];
array_shift($args);
$slug = null;
$pathArg = null;
$scoreOnly = false;
foreach ($args as $arg) {
  if (str_starts_with($arg, '--slug=')) {
    $slug = substr($arg, 7);
  } elseif (str_starts_with($arg, '--path=')) {
    $pathArg = substr($arg, 7);
  } elseif ($arg === '--score-only') {
    $scoreOnly = true;
  } elseif ($arg === '--help' || $arg === '-h') {
    echo "Usage: php bin/check_logo.php --slug=<slug> [--path=<file>] [--score-only]\n";
    exit(0);
  }
}
if (!$slug) {
  fwrite(STDERR, "Missing --slug\n");
  exit(1);
}

$camera = $app->cameras()->find($slug);
if (!$camera) {
  fwrite(STDERR, "Camera not found: {$slug}\n");
  exit(1);
}

// resolve capture path
$relativePath = null;
$absPath = null;
if ($pathArg) {
  $absPath = is_file($pathArg) ? realpath($pathArg) : $app->resolveCameraStoragePath($camera, $pathArg);
  $relativePath = str_starts_with((string)$pathArg, '/') ? ltrim(str_replace($app->paths()->storagePath(), '', (string)$absPath), '/') : $pathArg;
} else {
  // latest from DB
  try {
    $store = new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera, null, true);
    $pdo = $store->pdo();
    $stmt = $pdo->query('SELECT relative_path FROM captures WHERE is_duplicate = 0 ORDER BY slot_ts DESC LIMIT 1');
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
    if ($row && !empty($row['relative_path'])) {
      $relativePath = $row['relative_path'];
      $absPath = $app->resolveCameraStoragePath($camera, $relativePath);
    }
  } catch (\Throwable $e) {
    // ignore
  }
}
if (!$absPath || !is_file($absPath)) {
  fwrite(STDERR, "Capture file not found\n");
  exit(1);
}

// find logo asset (new location cache/<slug>/overlays, fallback legacy assets/overlays)
$logoDir = $app->paths()->cachePath($slug . '/overlays');
if (!is_dir($logoDir)) {
  $legacyDir = __DIR__ . '/../assets/overlays/' . $slug;
  if (is_dir($legacyDir)) {
    $logoDir = $legacyDir;
  }
}
$logoFile = null;
foreach (['png', 'jpg', 'jpeg'] as $ext) {
  $candidates = glob($logoDir . '/logo*.' . $ext) ?: [];
  if ($candidates) {
    $logoFile = $candidates[0];
    break;
  }
}
if (!$logoFile || !is_file($logoFile)) {
  fwrite(STDERR, "Logo file not found in {$logoDir}\n");
  exit(1);
}

// load images (GD)
// Auch alte/extern abgelegte Logos vor GD und pixelweisen PHP-Arrays pruefen.
try {
  OverlayImageLimits::assertAnalyzable($logoFile);
} catch (RuntimeException $exception) {
  fwrite(STDERR, $exception->getMessage() . "\n");
  exit(1);
}

$target = imagecreatefromjpeg($absPath);
if (!$target) {
  fwrite(STDERR, "Failed to load capture (must be JPEG)\n");
  exit(1);
}
$logo = (str_ends_with(strtolower($logoFile), '.png'))
  ? imagecreatefrompng($logoFile)
  : imagecreatefromjpeg($logoFile);
if (!$logo) {
  fwrite(STDERR, "Failed to load logo\n");
  exit(1);
}

$tw = imagesx($target);
$th = imagesy($target);
$lw = imagesx($logo);
$lh = imagesy($logo);

// helper to convert to grayscale arrays
$toGray = static function ($im, int $x0, int $y0, int $w, int $h): array {
  $data = [];
  for ($y = 0; $y < $h; $y++) {
    $row = [];
    for ($x = 0; $x < $w; $x++) {
      $rgba = imagecolorat($im, $x0 + $x, $y0 + $y);
      $r = ($rgba >> 16) & 0xFF;
      $g = ($rgba >> 8) & 0xFF;
      $b = $rgba & 0xFF;
      $a = ($rgba & 0x7F000000) >> 24; // 0..127
      $alpha = 1.0 - ($a / 127.0);
      $gray = (0.299 * $r + 0.587 * $g + 0.114 * $b) * $alpha;
      $row[] = $gray;
    }
    $data[] = $row;
  }
  return $data;
};

$toRGB = static function ($im, int $x0, int $y0, int $w, int $h): array {
  $rA = $gA = $bA = [];
  for ($y = 0; $y < $h; $y++) {
    $rRow = $gRow = $bRow = [];
    for ($x = 0; $x < $w; $x++) {
      $rgba = imagecolorat($im, $x0 + $x, $y0 + $y);
      $r = ($rgba >> 16) & 0xFF;
      $g = ($rgba >> 8) & 0xFF;
      $b = $rgba & 0xFF;
      $a = ($rgba & 0x7F000000) >> 24;
      $alpha = 1.0 - ($a / 127.0);
      $rRow[] = $r * $alpha;
      $gRow[] = $g * $alpha;
      $bRow[] = $b * $alpha;
    }
    $rA[] = $rRow;
    $gA[] = $gRow;
    $bA[] = $bRow;
  }
  return ['r' => $rA, 'g' => $gA, 'b' => $bA];
};

// simple box blur for robustness
$blur3 = static function (array $m): array {
  $h = count($m); $w = count($m[0] ?? []);
  $out = [];
  for ($y = 0; $y < $h; $y++) {
    $row = [];
    for ($x = 0; $x < $w; $x++) {
      $sum = 0; $cnt = 0;
      for ($dy = -1; $dy <= 1; $dy++) {
        for ($dx = -1; $dx <= 1; $dx++) {
          $yy = $y + $dy; $xx = $x + $dx;
          if ($yy >= 0 && $yy < $h && $xx >= 0 && $xx < $w) {
            $sum += $m[$yy][$xx];
            $cnt++;
          }
        }
      }
      $row[] = $cnt ? $sum / $cnt : 0;
    }
    $out[] = $row;
  }
  return $out;
};

// precompute grayscale logo at multiple scales
$scales = [0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];
$logoVariants = [];
foreach ($scales as $scale) {
  $newW = max(1, (int) round($lw * $scale));
  $newH = max(1, (int) round($lh * $scale));
  $resized = imagecreatetruecolor($newW, $newH);
  imagealphablending($resized, false);
  imagesavealpha($resized, true);
  imagecopyresampled($resized, $logo, 0, 0, 0, 0, $newW, $newH, $lw, $lh);
  $gray = $toGray($resized, 0, 0, $newW, $newH);
  $rgb = $toRGB($resized, 0, 0, $newW, $newH);
  $logoVariants[] = [
    'w' => $newW,
    'h' => $newH,
    'gray' => $gray,
    'gray_blur' => $blur3($gray),
    'rgb' => $rgb,
    'rgb_blur' => [
      'r' => $blur3($rgb['r']),
      'g' => $blur3($rgb['g']),
      'b' => $blur3($rgb['b']),
    ],
  ];
  imagedestroy($resized);
}

$ncc = static function (array $tpl, array $patch): float {
  $h = count($tpl); $w = count($tpl[0] ?? []);
  if ($h === 0 || $w === 0) return 0.0;
  $sumT = $sumP = $sumT2 = $sumP2 = $sumTP = 0.0;
  for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
      $t = $tpl[$y][$x];
      $p = $patch[$y][$x];
      $sumT += $t; $sumP += $p;
      $sumT2 += $t * $t; $sumP2 += $p * $p;
      $sumTP += $t * $p;
    }
  }
  $n = $w * $h;
  $num = $sumTP - ($sumT * $sumP / $n);
  $den = sqrt(max(1e-9, ($sumT2 - ($sumT * $sumT / $n)) * ($sumP2 - ($sumP * $sumP / $n))));
  return $den > 0 ? $num / $den : 0.0;
};

$bestScore = -1.0;
$bestPos = null;
$bestScoreBlur = -1.0;
$bestPosBlur = null;
$bestScoreColor = -1.0;
$bestPosColor = null;
$bestScoreColorBlur = -1.0;
$bestPosColorBlur = null;
$paddings = range(0, 200, 20);
foreach ($logoVariants as $variant) {
  $lwScaled = $variant['w'];
  $lhScaled = $variant['h'];
  $logoGray = $variant['gray'];
  $logoGrayBlur = $variant['gray_blur'];
  $logoRGB = $variant['rgb'];
  $logoRGBBlur = $variant['rgb_blur'];
  foreach (['left', 'right'] as $side) {
    foreach (['top', 'bottom'] as $vert) {
      foreach ($paddings as $pad) {
        $x0 = $side === 'left' ? $pad : max(0, $tw - $lwScaled - $pad);
        $y0 = $vert === 'top' ? $pad : max(0, $th - $lhScaled - $pad);
        if ($x0 < 0 || $y0 < 0 || $x0 + $lwScaled > $tw || $y0 + $lhScaled > $th) {
          continue;
        }
        $patch = $toGray($target, $x0, $y0, $lwScaled, $lhScaled);
        $score = $ncc($logoGray, $patch);
        if ($score > $bestScore) {
          $bestScore = $score;
          $bestPos = [$x0, $y0, $side . '-' . $vert, $lwScaled, $lhScaled];
        }
        $scoreBlur = $ncc($logoGrayBlur, $blur3($patch));
        if ($scoreBlur > $bestScoreBlur) {
          $bestScoreBlur = $scoreBlur;
          $bestPosBlur = [$x0, $y0, $side . '-' . $vert, $lwScaled, $lhScaled];
        }
        // color channels
        $patchRGB = $toRGB($target, $x0, $y0, $lwScaled, $lhScaled);
        $scoreColor = (
          $ncc($logoRGB['r'], $patchRGB['r']) +
          $ncc($logoRGB['g'], $patchRGB['g']) +
          $ncc($logoRGB['b'], $patchRGB['b'])
        ) / 3;
        if ($scoreColor > $bestScoreColor) {
          $bestScoreColor = $scoreColor;
          $bestPosColor = [$x0, $y0, $side . '-' . $vert, $lwScaled, $lhScaled];
        }
        $scoreColorBlur = (
          $ncc($logoRGBBlur['r'], $blur3($patchRGB['r'])) +
          $ncc($logoRGBBlur['g'], $blur3($patchRGB['g'])) +
          $ncc($logoRGBBlur['b'], $blur3($patchRGB['b']))
        ) / 3;
        if ($scoreColorBlur > $bestScoreColorBlur) {
          $bestScoreColorBlur = $scoreColorBlur;
          $bestPosColorBlur = [$x0, $y0, $side . '-' . $vert, $lwScaled, $lhScaled];
        }
      }
    }
  }
}

$finalScore = max($bestScore, $bestScoreBlur, $bestScoreColor, $bestScoreColorBlur);
$finalPos = $finalScore === $bestScore ? $bestPos
  : ($finalScore === $bestScoreBlur ? $bestPosBlur
    : ($finalScore === $bestScoreColor ? $bestPosColor : $bestPosColorBlur));
$detected = $finalScore >= 0.5;
echo json_encode([
  'slug' => $slug,
  'path' => $relativePath,
  'score' => round($finalScore, 4),
  'detected' => $detected,
  'pos' => $finalPos,
], JSON_PRETTY_PRINT) . PHP_EOL;

if ($scoreOnly) {
  exit(0);
}

// persist in camera DB (table capture_logo)
try {
  $readStore = new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera, null, true);
  $cid = $readStore->findCaptureIdByAnyPath($relativePath) ?? $readStore->findCaptureIdByRelativePath($relativePath);
  if ($cid !== null) {
    $writeStore = new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera);
    $writeStore->upsertLogoStatus($cid, $detected, (float) $bestScore, time());
  }
} catch (\Throwable $e) {
  fwrite(STDERR, "Warning: could not persist result: {$e->getMessage()}\n");
}
