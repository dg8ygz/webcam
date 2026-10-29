<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

/** Ein anderes Original belegt diesen Slot bereits; niemals still ersetzen. */
final class ImmutableImageSlotConflict extends \RuntimeException
{
}
