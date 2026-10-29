<?php
declare(strict_types=1);
require_once __DIR__.'/camera-source.php';

/** Transport failures can retry within an existing grant, never extend it. */
final class CameraVideoPollException extends RuntimeException
{
  public readonly bool $retryable;
  public function __construct(int $curlError, int $httpStatus)
  {
    $this->retryable=in_array($curlError,[5,6,7,28,52,55,56],true) || ($curlError===0 && $httpStatus>=500 && $httpStatus<=599);
    parent::__construct('Video-Poll fehlgeschlagen (curl '.$curlError.', HTTP '.$httpStatus.')');
  }
}

/** Validated, deliberately narrow USB streaming profile; never accepts argv from the server. */
final class CameraVideo
{
  public static function config(string $path): array
  {
    $raw = file_get_contents($path, false, null, 0, 8193);
    $c = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    $fields = ['control_url', 'camera_slug', 'key_file', 'tunnel_url', 'fingerprint', 'auth', 'port', 'token', 'device', 'input_fps'];
    if (!is_array($c) || count(array_diff(array_keys($c), $fields)) || count(array_diff($fields, array_keys($c)))) {
      throw new RuntimeException('Video-Konfiguration: unerwartete/fehlende Felder');
    }
    foreach (['control_url', 'tunnel_url'] as $name) {
      if (!is_string($c[$name]) || !preg_match('~^https://[a-zA-Z0-9.-]+(?::443)?/[a-zA-Z0-9/_.-]*$~D', $c[$name])) {
        throw new RuntimeException('Video braucht HTTPS ohne URL-Zugangsdaten');
      }
    }
    foreach (['camera_slug' => '/^[a-z0-9][a-z0-9-]{0,63}$/D',
      'key_file' => '~^/etc/camera-rpi/keys/[a-z0-9-]+\.key$~D',
      'fingerprint' => '~^[a-zA-Z0-9+/]{43}=$~D',
      'auth' => '/^[a-z0-9-]+:[a-zA-Z0-9_-]{32,128}$/D',
      'token' => '/^[a-f0-9]{64}$/D', 'device' => '~^/dev/video[0-9]+$~D'] as $name => $pattern) {
      if (!is_string($c[$name]) || !preg_match($pattern, $c[$name])) { throw new RuntimeException('Video-Feld ungueltig: ' . $name); }
    }
    if (!is_int($c['port']) || $c['port'] < 23000 || $c['port'] > 23999
      || !is_int($c['input_fps']) || $c['input_fps'] < 1 || $c['input_fps'] > 30) {
      throw new RuntimeException('Video-Port/Bildrate ungueltig');
    }
    $c['source_profile']=CameraSource::load();
    return $c;
  }

  /** Lease lifetime is converted to monotonic time; server data cannot change local limits. */
  public static function lease(string $json): int
  {
    $c = strlen($json) <= 1024 ? json_decode($json, true) : null;
    if (!is_array($c) || ($c['protocol'] ?? '') !== 'camera-rpi-video-v1'
      || !is_bool($c['active'] ?? null) || !is_int($c['ttl'] ?? null)
      || $c['ttl'] < 0 || $c['ttl'] > 30 || (!$c['active'] && $c['ttl'] !== 0)) {
      throw new RuntimeException('Ungueltige Video-Freigabe');
    }
    return $c['active'] ? $c['ttl'] : 0;
  }

  /** Preserve only the unused part of an authenticated, finite grant. */
  public static function expiryAfterFailure(float $expires, float $now, Throwable $error): float
  {
    return $error instanceof CameraVideoPollException && $error->retryable && $expires>$now ? $expires : 0.0;
  }

  /** An outage backs off to five minutes; healthy polling stays unchanged.
   * A still-valid video grant needs frequent renewal; failures never extend it.
   */
  public static function pollDelay(int $failures, float $expires, float $now): int
  {
    if ($expires > $now) { return $failures > 0 ? 2 : 10; }
    if ($failures <= 0) { return 10; }
    if ($failures === 1) { return 60; }
    return (int) min(300, 60 * (2 ** min(3, $failures - 1)));
  }

  /** 32-bit-safe monotonic clock, independent of system time corrections. */
  public static function clock(): float { $t = hrtime(); return $t[0] + $t[1] / 1e9; }

  public static function validFile(string $file): bool
  {
    return $file === 'index.m3u8' || preg_match('/^seg_[a-f0-9]{16}_[0-9]{6,12}\.ts$/D', $file) === 1;
  }

  /** Fixed 1080p/3fps H.264 profile; CRF25 is NOT JPEG quality/qscale25. */
  public static function encoder(array $c, string $dir, string $generation): array
  {
    if (!preg_match('/^[a-f0-9]{16}$/D', $generation)) { throw new RuntimeException('Invalid generation'); }
    $source=$c['source_profile']??null;
    if (($source['source']??'')==='gphoto2') { throw new RuntimeException('HLS ist fuer gphoto2 nicht eingerichtet'); }
    $input=($source['source']??'')==='rtsp' ? CameraSource::rtspArgs() :
      ['-f', 'v4l2', '-input_format', 'mjpeg', '-framerate', (string) ($source['video_input_fps']??$c['input_fps']),
      '-video_size', ($source['video_width']??'1920').'x'.($source['video_height']??'1080'), '-i', $source['video_device']??$c['device']];
    return ['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'warning',
      ...$input, '-an', '-filter_threads', '2', '-vf', 'fps=3,scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2',
      '-c:v', 'libx264', '-preset', ($source['source']??'')==='rtsp' ? 'ultrafast' : 'veryfast', '-threads', '2', '-pix_fmt', 'yuv420p',
      '-crf', '25', '-maxrate', '1500k', '-bufsize', '3000k', '-g', '6', '-keyint_min', '6',
      '-sc_threshold', '0', '-f', 'hls', '-hls_time', '2', '-hls_list_size', '6', '-hls_start_number_source', 'epoch',
      '-hls_delete_threshold', '1', '-hls_flags', 'delete_segments+temp_file+independent_segments',
      '-hls_segment_filename', $dir . '/seg_' . $generation . '_%06d.ts', $dir . '/index.m3u8'];
  }

  /** Bounded, HTTPS-verified video poll. No capture/SSH maintenance side effects. */
  public static function poll(array $c): int
  {
    $key = trim((string) file_get_contents($c['key_file'], false, null, 0, 513));
    if ($key === '' || strlen($key) > 512 || preg_match('/[\x00-\x20\x7f]/', $key)) {
      throw new RuntimeException('Video-Key ungueltig');
    }
    $body = '';
    $curl = curl_init($c['control_url']);
    $headers=['X-Command-Key: ' . $key];
    if (class_exists('PrivacyApp')) { $headers[]='X-Camera-Privacy: '.(new PrivacyApp())->report(); }
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['wc' => $c['camera_slug']],
      CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 5000,
      CURLOPT_WRITEFUNCTION => static function ($h, string $part) use (&$body): int {
        if (strlen($body) + strlen($part) > 1024) { return 0; }
        $body .= $part; return strlen($part);
      }]);
    try { $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $errno = curl_errno($curl); }
    finally { curl_close($curl); unset($key); }
    if ($ok === false || $status !== 200) { throw new CameraVideoPollException($errno,$status); }
    return self::lease($body);
  }
}
