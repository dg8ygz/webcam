<?php
declare(strict_types=1);

namespace Webcam\Service\Overlay;

use RuntimeException;

/**
 * Fachfehler des Overlay-Uploads ohne Abhängigkeit von HTTP-Statuscodes.
 *
 * Der Service benennt nur den technischen Grund. Der aufrufende HTTP-Adapter
 * entscheidet anschließend, ob daraus beispielsweise 413, 415 oder 503 wird.
 */
final class OverlayUploadException extends RuntimeException
{
  public const CAMERA_NOT_FOUND = 'camera_not_found';
  public const INVALID_SOURCE = 'invalid_source';
  public const TOO_LARGE = 'too_large';
  public const UNSUPPORTED_MEDIA = 'unsupported_media';
  public const BUSY = 'busy';
  public const STORAGE_FAILED = 'storage_failed';

  public function __construct(
    public readonly string $reason,
    string $message
  ) {
    parent::__construct($message);
  }
}
