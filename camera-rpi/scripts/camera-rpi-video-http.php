<?php
declare(strict_types=1);

// Private loopback origin behind the video-only Chisel reverse forward.
// Never fall through to PHP's built-in static server: that exposes other files.
require_once is_file(__DIR__ . '/video-stream.php') ? __DIR__ . '/video-stream.php' : __DIR__ . '/lib/video-stream.php';
try {
  $c = CameraVideo::config((string) getenv('CAMERA_VIDEO_CONFIG'));
  if (!hash_equals('Bearer ' . $c['token'], (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    http_response_code(403); exit;
  }
  // Origin enforces the same window even when requested outside the public
  // viewer. The server also rejects cached segments at its reported deadline.
  $privacy=getenv('CAMERA_RPI_PRIVACY_CONFIG') ?: '/etc/camera-rpi/privacy.json';
  if (file_exists($privacy) || is_link($privacy)) {
    require_once __DIR__.'/camera-rpi-privacy.php';
    if (!(new PrivacyApp())->decision()['allowed']) { http_response_code(503); exit; }
  }
  $file = ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
  if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !CameraVideo::validFile($file)) { http_response_code(404); exit; }
  $path = '/run/camera-rpi-video/hls/' . $file;
  if (!is_file($path) || is_link($path) || filesize($path) > 4 * 1024 * 1024
    || ($file === 'index.m3u8' && filemtime($path) < time() - 8)) { http_response_code(503); exit; }
  header('Cache-Control: no-store');
  header('Content-Type: ' . ($file === 'index.m3u8' ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
  readfile($path);
} catch (Throwable $error) { http_response_code(503); }
