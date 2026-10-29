<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use InvalidArgumentException;

/**
 * Uebergibt RTSP-Quellen per ffconcat auf stdin statt als Prozessargument.
 * Dadurch bleibt die URL aus /proc/<pid>/cmdline und normaler ps-Ausgabe fern.
 */
final class RtspInput
{
  private const PROTOCOL_WHITELIST = 'file,pipe,rtp,udp,tcp,tls,crypto,rtsp,rtsps';

  /** @return list<string> */
  public static function ffmpegArguments(): array
  {
    return [
      '-f', 'concat',
      '-safe', '0',
      '-protocol_whitelist', self::PROTOCOL_WHITELIST,
      '-i', 'pipe:0',
    ];
  }

  public static function manifest(
    string $url,
    string $transport = 'tcp',
    ?string $timeoutOption = null,
    ?int $timeoutMicroseconds = null
  ): string {
    $url = trim($url);
    if (
      $url === ''
      || preg_match('#^rtsps?://#i', $url) !== 1
      || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
    ) {
      throw new InvalidArgumentException('Invalid RTSP input URL');
    }

    $transport = strtolower(trim($transport));
    if (!in_array($transport, ['tcp', 'udp', 'udp_multicast', 'http', 'https'], true)) {
      throw new InvalidArgumentException('Invalid RTSP transport');
    }

    $manifest = "ffconcat version 1.0\n"
      . "file '" . self::escapeConcatValue($url) . "'\n"
      . 'option rtsp_transport ' . $transport . "\n";

    if ($timeoutOption !== null && $timeoutMicroseconds !== null && $timeoutMicroseconds > 0) {
      $option = ltrim(strtolower(trim($timeoutOption)), '-');
      if (!in_array($option, ['timeout', 'stimeout'], true)) {
        throw new InvalidArgumentException('Invalid RTSP timeout option');
      }
      $manifest .= 'option ' . $option . ' ' . $timeoutMicroseconds . "\n";
    }

    return $manifest;
  }

  /** @param resource $pipe */
  public static function writeManifest($pipe, string $manifest): bool
  {
    $length = strlen($manifest);
    $written = 0;
    while ($written < $length) {
      $chunk = @fwrite($pipe, substr($manifest, $written));
      if (!is_int($chunk) || $chunk <= 0) {
        return false;
      }
      $written += $chunk;
    }
    return @fflush($pipe);
  }

  private static function escapeConcatValue(string $value): string
  {
    return str_replace(["\\", "'"], ["\\\\", "'\\''"], $value);
  }
}
