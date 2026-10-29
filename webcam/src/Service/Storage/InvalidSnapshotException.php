<?php
declare(strict_types=1);
namespace Webcam\Service\Storage;
/** Permanent invalid image content, distinct from storage/transport failures. */
final class InvalidSnapshotException extends \RuntimeException {}
