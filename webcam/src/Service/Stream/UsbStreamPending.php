<?php
declare(strict_types=1);

namespace Webcam\Service\Stream;

/** Relay not listening yet, or explicitly waiting for its first playlist. */
final class UsbStreamPending extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('USB stream not ready');
  }
}
