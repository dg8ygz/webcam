<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Config;

/** Der gelesene Stand wurde inzwischen geändert; niemals still überschreiben. */
final class ConfigConflictException extends \RuntimeException {}
