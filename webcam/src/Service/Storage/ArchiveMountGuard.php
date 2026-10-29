<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use RuntimeException;
use Webcam\App;

/**
 * Verhindert Archiv- und Retention-Läufe gegen einen ausgefallenen Unter-Mount.
 *
 * Der Sentinel liegt ausschließlich auf dem tatsächlich eingebundenen Archiv.
 * Fehlt der Mount, zeigt storage/archive auf das leere lokale Mountverzeichnis;
 * dort fehlt der Sentinel und schreibende beziehungsweise löschende Jobs brechen
 * fail-closed ab.
 */
final class ArchiveMountGuard
{
  public const SENTINEL_FILENAME = '.webcam-archive-mounted';

  public function __construct(private readonly App $app) {}

  public function assertAvailable(string $operation): void
  {
    if (!$this->isRequired()) {
      return;
    }

    $archiveRoot = $this->app->paths()->archivePath();
    $sentinel = $this->app->paths()->archivePath(self::SENTINEL_FILENAME);
    clearstatcache(true, $archiveRoot);
    clearstatcache(true, $sentinel);

    if (
      !is_dir($archiveRoot)
      || is_link($sentinel)
      || !is_file($sentinel)
      || !is_readable($sentinel)
    ) {
      throw new RuntimeException(sprintf(
        'Archive mount sentinel missing or unreadable for %s: %s',
        $operation,
        $sentinel
      ));
    }
  }

  /**
   * Prueft die reale Schreibbarkeit des Archiv-Mounts mit einer kurzlebigen
   * Datei. Rechtebits beziehungsweise is_writable() reichen bei CIFS nicht aus,
   * weil Mountmodus, Serverrechte und Clientoptionen davon abweichen koennen.
   */
  public function assertWritable(string $operation): void
  {
    $this->assertAvailable($operation);

    $archiveRoot = $this->app->paths()->archivePath();
    if (!is_dir($archiveRoot)) {
      throw new RuntimeException(sprintf(
        'Archive is not writable for %s (archive directory missing): %s',
        $operation,
        $archiveRoot
      ));
    }

    $probePath = $archiveRoot . DIRECTORY_SEPARATOR
      . '.webcam-write-probe-' . bin2hex(random_bytes(8));
    $handle = @fopen($probePath, 'x+b');
    if ($handle === false) {
      throw new RuntimeException(sprintf(
        'Archive is not writable for %s (read-only mount or missing write permission): %s',
        $operation,
        $archiveRoot
      ));
    }

    $probeContents = "webcam archive write probe\n";
    $bytesWritten = @fwrite($handle, $probeContents);
    $flushed = $bytesWritten === strlen($probeContents) && @fflush($handle);
    fclose($handle);
    if (!$flushed) {
      @unlink($probePath);
      throw new RuntimeException(sprintf(
        'Archive is not writable for %s (write probe failed): %s',
        $operation,
        $archiveRoot
      ));
    }
    if (!@unlink($probePath)) {
      throw new RuntimeException(sprintf(
        'Archive write probe could not be removed for %s: %s',
        $operation,
        $probePath
      ));
    }
  }

  private function isRequired(): bool
  {
    return (bool) $this->app->config('archive_mount_sentinel_required', true);
  }
}
