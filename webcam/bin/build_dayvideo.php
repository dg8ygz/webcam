#!/usr/bin/env php
<?php
declare(strict_types=1);

use Webcam\Domain\Camera;

$app = require __DIR__ . '/../bootstrap.php';

// Sicherer Offline-Weg für öffentliche Tagesvideos: prüft Kamera/Datum, liest
// Frames aus der Timeline und erzeugt das MP4 im Cache. Dadurch muss der
// öffentliche API-Request selbst keinen ffmpeg-Build erlauben.
$args = getopt('', [
  'slug:',
  'date:',
  'fps::',
  'max-width::',
  'refresh',
  'help',
]);

if (isset($args['help'])) {
  echo "Usage: WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite php bin/build_dayvideo.php --slug=<camera> --date=YYYY-MM-DD [--fps=5] [--max-width=1200] [--refresh]\n";
  echo "Builds the cached public dayvideo MP4 without enabling ffmpeg in the web request path.\n";
  exit(0);
}

$slug = trim((string) ($args['slug'] ?? ''));
$dateParam = trim((string) ($args['date'] ?? ''));
if ($slug === '' || $dateParam === '') {
  fwrite(STDERR, "Missing required --slug or --date. Use --help for usage.\n");
  exit(2);
}

try {
  $camera = $app->cameraOrFail($slug);
} catch (RuntimeException $exception) {
  fwrite(STDERR, $exception->getMessage() . "\n");
  exit(2);
}

$tz = new DateTimeZone((string) $app->config('timezone', 'UTC'));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateParam, $tz);
if (!$date) {
  fwrite(STDERR, "Invalid --date, expected YYYY-MM-DD: {$dateParam}\n");
  exit(2);
}

$fps = isset($args['fps']) && is_numeric($args['fps']) ? (int) $args['fps'] : 5;
$fps = max(1, min(60, $fps));
$maxWidth = isset($args['max-width']) && is_numeric($args['max-width']) ? (int) $args['max-width'] : 1200;
$maxWidth = max(320, min(2000, $maxWidth));
$refresh = isset($args['refresh']);

$dateDir = $date->format('Y/m/d');
$videoRel = sprintf('cache/%s/videos/%s/day_w%d_fps%d.mp4', $camera->slug(), $dateDir, $maxWidth, $fps);
$videoPath = $app->paths()->cachePath($camera->slug() . '/videos/' . $dateDir . '/day_w' . $maxWidth . '_fps' . $fps . '.mp4');

if (!$refresh && is_file($videoPath) && filesize($videoPath) > 1024) {
  echo "[OK] already exists: {$videoRel}\n";
  exit(0);
}

$fromTs = $date->setTime(0, 0, 0)->getTimestamp();
$toTs = $date->setTime(23, 59, 59)->getTimestamp();
$timeline = $app->archiveBrowser()->timeline($camera, null, $fromTs, $toTs);
$entries = $timeline['entries'] ?? [];
if (!$entries) {
  fwrite(STDERR, "[FAIL] no entries for {$camera->slug()} on {$dateParam}\n");
  exit(1);
}

$frames = sampleDayvideoFrames($entries, 800);
$docroot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$imageFiles = [];
foreach ($frames as $frame) {
  $candidate = pickDayvideoVariant($frame, $maxWidth);
  if ($candidate === null) {
    continue;
  }
  $abs = resolveDayvideoFile($docroot, $candidate);
  if ($abs !== null && is_file($abs)) {
    $imageFiles[] = $abs;
  }
}

$imageFiles = array_values(array_unique($imageFiles));
$frameCount = count($imageFiles);
if ($frameCount < max(2, (int) ceil($fps * 2))) {
  fwrite(STDERR, "[FAIL] too few local frames for {$camera->slug()} {$dateParam}: {$frameCount}\n");
  exit(1);
}

$ffmpeg = getenv('WEBCAM_FFMPEG_PATH') ?: 'ffmpeg';
$ffmpegPath = trim((string) shell_exec('command -v ' . escapeshellarg($ffmpeg)));
if ($ffmpegPath === '') {
  fwrite(STDERR, "[FAIL] ffmpeg not available; set WEBCAM_FFMPEG_PATH if needed\n");
  exit(1);
}

$videoDir = dirname($videoPath);
if (!is_dir($videoDir) && !mkdir($videoDir, 0775, true) && !is_dir($videoDir)) {
  fwrite(STDERR, "[FAIL] cannot create video directory: {$videoDir}\n");
  exit(1);
}

$listFile = tempnam(sys_get_temp_dir(), 'dayvideo_cli_');
if ($listFile === false) {
  fwrite(STDERR, "[FAIL] cannot create temporary ffmpeg list file\n");
  exit(1);
}

$fh = fopen($listFile, 'wb');
if ($fh === false) {
  @unlink($listFile);
  fwrite(STDERR, "[FAIL] cannot write temporary ffmpeg list file\n");
  exit(1);
}

$duration = 1 / $fps;
foreach ($imageFiles as $file) {
  fwrite($fh, "file '" . str_replace("'", "'\\''", $file) . "'\n");
  fwrite($fh, "duration {$duration}\n");
}
fwrite($fh, "file '" . str_replace("'", "'\\''", end($imageFiles)) . "'\n");
fclose($fh);

$tmpVideo = $videoPath . '.tmp-' . bin2hex(random_bytes(8)) . '.mp4';
$vf = '-vf ' . escapeshellarg('scale=min(iw\,' . $maxWidth . '):-2');
$cmd = sprintf(
  '%s -y -safe 0 -f concat -i %s -vsync vfr %s -c:v libx264 -pix_fmt yuv420p -movflags +faststart %s 2>&1',
  escapeshellarg($ffmpegPath),
  escapeshellarg($listFile),
  $vf,
  escapeshellarg($tmpVideo)
);

echo "[INFO] building {$videoRel} from {$frameCount} frames ({$fps} fps, max {$maxWidth}px)\n";
exec($cmd, $output, $code);
@unlink($listFile);

if ($code !== 0 || !is_file($tmpVideo) || filesize($tmpVideo) <= 1024) {
  @unlink($tmpVideo);
  $tail = array_slice(array_values(array_filter($output, static fn($line): bool => is_string($line) && trim($line) !== '')), -12);
  fwrite(STDERR, "[FAIL] ffmpeg failed with code {$code}\n");
  if ($tail) {
    fwrite(STDERR, implode("\n", $tail) . "\n");
  }
  exit(1);
}

if (!rename($tmpVideo, $videoPath)) {
  @unlink($tmpVideo);
  fwrite(STDERR, "[FAIL] cannot move finished video into place: {$videoPath}\n");
  exit(1);
}

echo "[OK] {$videoRel}\n";
exit(0);

/**
 * @param list<array<string,mixed>> $entries
 * @return list<array<string,mixed>>
 */
function sampleDayvideoFrames(array $entries, int $maxFrames): array
{
  $total = count($entries);
  if ($total <= $maxFrames) {
    return array_values($entries);
  }
  $step = $total / $maxFrames;
  $sampled = [];
  for ($i = 0; $i < $maxFrames; $i++) {
    $idx = min((int) floor($i * $step), $total - 1);
    $sampled[] = $entries[$idx];
  }
  return $sampled;
}

/**
 * @param array<string,mixed> $entry
 */
function pickDayvideoVariant(array $entry, int $maxWidth): ?string
{
  $variants = is_array($entry['variants'] ?? null) ? $entry['variants'] : [];
  $chosen = null;
  $bestDiff = null;
  foreach ($variants as $key => $info) {
    if (!is_array($info)) {
      continue;
    }
    $url = $info['url'] ?? null;
    if (!is_string($url) || $url === '') {
      continue;
    }
    $width = is_numeric($key) ? (int) $key : (isset($info['width']) && is_numeric($info['width']) ? (int) $info['width'] : null);
    if ($width === null) {
      continue;
    }
    $diff = $width - $maxWidth;
    if ($diff <= 0 && ($bestDiff === null || $diff > $bestDiff)) {
      $bestDiff = $diff;
      $chosen = $url;
    }
  }
  if ($chosen === null && isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== '') {
    $chosen = $entry['url'];
  }
  return $chosen;
}

function resolveDayvideoFile(string $docroot, string $candidate): ?string
{
  $path = parse_url($candidate, PHP_URL_PATH);
  $rel = ltrim($path !== null && $path !== false ? $path : $candidate, '/');
  if ($rel === '' || str_contains($rel, "\0")) {
    return null;
  }
  if (preg_match('#(?:^|/)\.\.(?:/|$)#', $rel)) {
    return null;
  }

  $docrootName = basename($docroot);
  if (str_starts_with($rel, $docrootName . '/')) {
    $rel = substr($rel, strlen($docrootName) + 1);
  }

  return rtrim($docroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}
