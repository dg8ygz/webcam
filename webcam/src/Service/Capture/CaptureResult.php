<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use Webcam\Domain\Camera;

/** Erfolg, Fehler oder noch ausstehende Quellveroeffentlichung eines Capture-Versuchs. */
final class CaptureResult
{
  public function __construct(
    public readonly Camera $camera,
    public readonly bool $successful,
    public readonly string $message,
    public readonly ?string $imagePath = null,
    public readonly ?int $timestamp = null,
    public readonly bool $pending = false,
  ) {}

  public static function success(Camera $camera, string $message, ?string $imagePath = null, ?int $timestamp = null): self
  {
    return new self($camera, true, $message, $imagePath, $timestamp);
  }

  public static function failure(Camera $camera, string $message): self
  {
    return new self($camera, false, $message, null, time());
  }

  /** Noch kein Bild: weder Erfolg vortaeuschen noch den Watchdog zaehlen lassen. */
  public static function awaitingSource(Camera $camera, int $slot): self
  {
    return new self($camera, false, 'Snapshot source not published yet', null, $slot, true);
  }
}
