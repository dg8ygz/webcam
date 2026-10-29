<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use RuntimeException;
use Webcam\App;

/**
 * Gemeinsamer Tages-Lock direkt auf dem Archiv-Mount.
 *
 * Bei zwei Installationen oder Hosts zeigt derselbe SMB-Pfad auf dieselbe
 * Lockdatei. Der Lock ergaenzt die Betriebsregel mit genau einem autorisierten
 * Schreiber, ersetzt sie aber nicht.
 */
final class ArchiveDayLock
{
  /** @var resource|null */
  private $handle;

  private function __construct($handle, private readonly string $path)
  {
    $this->handle = $handle;
  }

  public static function acquire(App $app, DateTimeImmutable $day): self
  {
    (new ArchiveMountGuard($app))->assertAvailable('archive day lock');

    $enabled = (bool) $app->config('archive_day_lock_enabled', true);
    if (!$enabled) {
      return new self(null, 'disabled');
    }

    $root = $app->paths()->archivePath('.webcam-archive-locks');
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
      throw new RuntimeException("Unable to create shared archive lock directory: {$root}");
    }

    $path = $root . DIRECTORY_SEPARATOR . $day->format('Y-m-d') . '.lock';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
      throw new RuntimeException("Unable to open shared archive day lock: {$path}");
    }

    $timeout = max(0, min(300, (int) $app->config('archive_day_lock_timeout_seconds', 30)));
    $deadline = microtime(true) + $timeout;
    do {
      if (@flock($handle, LOCK_EX | LOCK_NB)) {
        self::writeOwner($handle, $day);
        return new self($handle, $path);
      }
      if ($timeout === 0 || microtime(true) >= $deadline) {
        fclose($handle);
        throw new RuntimeException("Shared archive day already locked: {$path}");
      }
      usleep(100000);
    } while (true);
  }

  public function release(): void
  {
    if (!is_resource($this->handle)) {
      return;
    }
    flock($this->handle, LOCK_UN);
    fclose($this->handle);
    $this->handle = null;
  }

  public function path(): string
  {
    return $this->path;
  }

  public function __destruct()
  {
    $this->release();
  }

  /** @param resource $handle */
  private static function writeOwner($handle, DateTimeImmutable $day): void
  {
    $owner = [
      'day' => $day->format('Y-m-d'),
      'host' => gethostname() ?: 'unknown',
      'pid' => getmypid(),
      'acquired_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    ];
    $encoded = json_encode($owner, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $encoded) === false || !fflush($handle)) {
      flock($handle, LOCK_UN);
      fclose($handle);
      throw new RuntimeException('Unable to publish shared archive day lock owner');
    }
  }
}
