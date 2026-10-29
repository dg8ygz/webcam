<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

use Webcam\Infrastructure\Filesystem\PathResolver;
use Webcam\Service\Capture\ReceivedCaptureSlot;

/** Testcam photo guard. Server time and accepted camera-DB slots are authoritative. */
final class UsbStreamWindow
{
  public function __construct(private readonly PathResolver $paths) {}

  /** Existing admitted viewers bypass the start guard; Pi capture locks preempt video. */
  public function status(string $slug, ?int $now = null, bool $continuing = false): array
  {
    $now ??= time();
    if ($slug !== 'test') {
      return ['ready' => true, 'message' => '', 'duration' => 1800];
    }
    if ($continuing) {
      return self::decision($now, false, true);
    }
    $slot = intdiv($now, 600) * 600;
    if ($now - $slot >= 540) {
      return self::decision($now, false);
    }
    $received = ReceivedCaptureSlot::contains($this->paths->cameraDbPath($slug . '/db.sqlite'), $slot);
    return self::decision($now, $received);
  }

  /** Pure boundary logic, also used by tests without touching a live camera DB. */
  public static function decision(int $now, bool $received, bool $continuing = false): array
  {
    $beforePhoto = $now % 600 >= 540;
    $ready = $continuing || (!$beforePhoto && $received);
    return ['ready' => $ready, 'duration' => 300, 'message' => $ready ? '' : ($beforePhoto
      ? 'In Kürze wird ein Bild gemacht. Der Stream startet danach automatisch und läuft 5 Minuten.'
      : 'Warte auf den Abschluss der Bildaufnahme. Der Stream startet danach automatisch und läuft 5 Minuten.')];
  }
}
