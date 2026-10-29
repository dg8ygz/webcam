<?php
declare(strict_types=1);

namespace Webcam\Domain\Enums;

/** Beschreibt, ob und in welcher Form eine Kamera einen Live-Stream anbietet. */
enum CameraStreamMode: string
{
  case None = 'none';
  case Hls = 'hls';
  case Rtsp = 'rtsp';
  case Mjpeg = 'mjpeg';
}
