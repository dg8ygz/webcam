<?php
declare(strict_types=1);

namespace Webcam\Domain\Enums;

/** Legt fest, wer eine Aufnahme auslöst bzw. das Bild zum Server transportiert. */
enum CameraMode: string
{
  case ActiveCapture = 'active-capture';
  case PullSnapshot = 'pull-snapshot';
  case PassiveUpload = 'passive-upload';
}
