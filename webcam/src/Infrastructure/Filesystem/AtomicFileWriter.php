<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Filesystem;

/**
 * Veröffentlicht vollständige Dateien atomar im jeweiligen Zielverzeichnis.
 *
 * Temp-Datei und Ziel liegen bewusst auf demselben Dateisystem. Leser sehen
 * dadurch entweder die bisherige oder die vollständig geschriebene neue Datei,
 * niemals den Zwischenstand eines copy(), fwrite() oder GD-Exports.
 */
final class AtomicFileWriter
{
  /** @param null|callable(string):bool $validator */
  public static function write(
    string $destination,
    string $contents,
    ?callable $validator = null,
    ?int $mode = null
  ): bool
  {
    return self::writeWithCallback(
      $destination,
      static function ($handle) use ($contents): bool {
        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
          $chunk = substr($contents, $written, min(1048576, $length - $written));
          $chunkLength = strlen($chunk);
          $chunkWritten = 0;
          while ($chunkWritten < $chunkLength) {
            $result = fwrite($handle, substr($chunk, $chunkWritten));
            if ($result === false || $result === 0) {
              return false;
            }
            $chunkWritten += $result;
            $written += $result;
          }
        }
        return true;
      },
      $validator,
      null,
      $mode
    );
  }

  public static function copy(string $source, string $destination, ?int $mode = null): bool
  {
    if (!is_file($source) || !is_readable($source)) {
      return false;
    }

    $resolvedSource = realpath($source);
    $resolvedDestination = realpath($destination);
    if ($resolvedSource !== false && $resolvedDestination !== false && $resolvedSource === $resolvedDestination) {
      return true;
    }

    $sourceHandle = @fopen($source, 'rb');
    if (!is_resource($sourceHandle)) {
      return false;
    }

    try {
      $sourceStat = fstat($sourceHandle);
      if (!is_array($sourceStat)) {
        return false;
      }
      $sourceSize = (int) ($sourceStat['size'] ?? -1);
      if ($sourceSize < 0) {
        return false;
      }
      $sourceMtime = isset($sourceStat['mtime']) ? (int) $sourceStat['mtime'] : null;
      $sourceMode = $mode ?? (isset($sourceStat['mode']) ? ((int) $sourceStat['mode'] & 0777) : null);

      return self::writeWithCallback(
        $destination,
        static function ($destinationHandle) use ($sourceHandle, $sourceSize): bool {
          $copied = stream_copy_to_stream($sourceHandle, $destinationHandle);
          if ($copied === false || $copied !== $sourceSize) {
            return false;
          }
          $finalSourceStat = fstat($sourceHandle);
          return is_array($finalSourceStat)
            && (int) ($finalSourceStat['size'] ?? -1) === $sourceSize;
        },
        static function (string $temporary) use ($sourceSize): bool {
          clearstatcache(true, $temporary);
          $temporarySize = filesize($temporary);
          return $temporarySize !== false && $temporarySize === $sourceSize;
        },
        $sourceMtime,
        $sourceMode
      );
    } finally {
      fclose($sourceHandle);
    }
  }

  /**
   * @param callable(resource):bool $writer
   * @param null|callable(string):bool $validator
   */
  public static function writeWithCallback(
    string $destination,
    callable $writer,
    ?callable $validator = null,
    ?int $mtime = null,
    ?int $mode = null
  ): bool {
    $directory = dirname($destination);
    if (!is_dir($directory) || !is_writable($directory)) {
      return false;
    }

    try {
      $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($destination)
        . '.tmp-' . bin2hex(random_bytes(8));
    } catch (\Throwable) {
      return false;
    }

    $handle = @fopen($temporary, 'x+b');
    if (!is_resource($handle)) {
      return false;
    }

    $published = false;
    try {
      if ($writer($handle) !== true || !fflush($handle)) {
        return false;
      }

      if (!fclose($handle)) {
        $handle = null;
        return false;
      }
      $handle = null;

      clearstatcache(true, $temporary);
      if ($validator !== null && $validator($temporary) !== true) {
        return false;
      }

      $existingMode = is_file($destination) ? @fileperms($destination) : false;
      $publishMode = is_int($existingMode) ? ($existingMode & 0777) : ($mode ?? 0664);
      @chmod($temporary, $publishMode);
      if ($mtime !== null && !@touch($temporary, $mtime)) {
        return false;
      }
      if (!@rename($temporary, $destination)) {
        return false;
      }

      $published = true;
      return true;
    } finally {
      if (is_resource($handle)) {
        fclose($handle);
      }
      if (!$published && is_file($temporary)) {
        @unlink($temporary);
      }
    }
  }
}
