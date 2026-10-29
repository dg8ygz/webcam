<?php
declare(strict_types=1);

$app = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/include/api_helpers.php';
require_once __DIR__ . '/include/session_rate_limit.php';
require_once __DIR__ . '/include/captcha_state.php';

check_rate_limit($app, 'captcha', [
  'limit' => getenv('WEBCAM_CAPTCHA_LIMIT') ?: 10,
  'window_seconds' => getenv('WEBCAM_CAPTCHA_WINDOW') ?: 60,
  'ttl_days' => getenv('WEBCAM_PUBLIC_API_TTL_DAYS') ?: 7,
]);

// Erzeugt eine kurze Rechenaufgabe und ein PNG im Speicher, legt nur Hash plus
// Ablaufzeit unter var/state/captcha ab und sendet ID/Bild als JSON. Abgelaufene
// Captchas werden bei dieser Gelegenheit begrenzt bereinigt; Klartextlösungen
// landen weder in Antwort noch State.
header('Content-Type: application/json');

if (!function_exists('imagecreatetruecolor')) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'Captcha kann nicht generiert werden (GD Erweiterung fehlt).',
  ]);
  return;
}

try {
  $captchaDir = $app->paths()->statePath('captcha');
} catch (RuntimeException $exception) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha-Pfad nicht konfiguriert.']);
  return;
}

if (!is_dir($captchaDir) && !mkdir($captchaDir, 0775, true) && !is_dir($captchaDir)) {
  $app->logger()->warning('captcha mkdir failed', ['dir' => $captchaDir]);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha-Verzeichnis konnte nicht erstellt werden.']);
  return;
}

$now = time();
$ttl = 600;
captcha_state_cleanup($captchaDir, $now, $ttl);

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
$max = strlen($alphabet) - 1;
try {
  for ($i = 0; $i < 4; $i++) {
    $code .= $alphabet[random_int(0, $max)];
  }
} catch (Throwable $exception) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha konnte nicht erzeugt werden.']);
  return;
}

try {
  $id = bin2hex(random_bytes(16));
} catch (Throwable $exception) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha konnte nicht initialisiert werden.']);
  return;
}

$entryPath = '';
try {
  $entryPath = captcha_state_store($captchaDir, $id, $code, $now);
} catch (Throwable $exception) {
  $app->logger()->warning('captcha write failed', ['error' => $exception->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha konnte nicht gespeichert werden.']);
  return;
}

$width = 150;
$height = 50;
$image = imagecreatetruecolor($width, $height);
$background = imagecolorallocate($image, 242, 246, 255);
imagefill($image, 0, 0, $background);

for ($i = 0; $i < 6; $i++) {
  $noiseColor = imagecolorallocate(
    $image,
    random_int(180, 210),
    random_int(200, 225),
    random_int(220, 240)
  );
  imageline(
    $image,
    random_int(0, $width),
    random_int(0, $height),
    random_int(0, $width),
    random_int(0, $height),
    $noiseColor
  );
}

$textColorPrimary = imagecolorallocate($image, 18, 44, 92);
$textShadowColor = imagecolorallocatealpha($image, 255, 255, 255, 40);
$fontFile = __DIR__ . '/assets/fonts/SourceCodePro-Semibold.ttf';
$useFont = is_file($fontFile) && function_exists('imagettftext');
$text = implode(' ', str_split($code));
if ($useFont) {
  $fontSize = 20;
  $angle = random_int(-8, 8);
  $bbox = imagettfbbox($fontSize, $angle, $fontFile, $text);
  $textWidth = (int) ($bbox[2] - $bbox[0]);
  $textHeight = (int) ($bbox[1] - $bbox[7]);
  $x = (int) (($width - $textWidth) / 2);
  $y = (int) (($height + $textHeight) / 2);
  imagettftext($image, $fontSize, $angle, $x + 1, $y + 1, $textShadowColor, $fontFile, $text);
  imagettftext($image, $fontSize, $angle, $x, $y, $textColorPrimary, $fontFile, $text);
} else {
  $font = 5;
  $textWidth = imagefontwidth($font) * strlen($text);
  $textHeight = imagefontheight($font);
  $x = (int) (($width - $textWidth) / 2);
  $y = (int) (($height - $textHeight) / 2);
  imagestring($image, $font, $x + 1, $y + 1, $text, $textShadowColor);
  imagestring($image, $font, $x, $y, $text, $textColorPrimary);
}

ob_start();
imagepng($image);
$pngData = ob_get_clean();
imagedestroy($image);

if ($pngData === false) {
  if (!unlink($entryPath)) {
    $app->logger()->warning('captcha cleanup failed', ['path' => $entryPath]);
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Captcha-Bild konnte nicht erzeugt werden.']);
  return;
}

$imageDataUrl = 'data:image/png;base64,' . base64_encode($pngData);

echo json_encode([
  'ok' => true,
  'captcha' => [
    'id' => $id,
    'image' => $imageDataUrl,
  ],
]);
