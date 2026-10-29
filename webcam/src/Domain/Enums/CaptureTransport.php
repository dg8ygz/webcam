<?php
declare(strict_types=1);

namespace Webcam\Domain\Enums;

/** Erlaubte technische Transportwege zum Auslösen oder Abrufen eines Captures. */
enum CaptureTransport: string
{
  case HttpGet = 'http-get';
  case Rtsp = 'rtsp';
}
