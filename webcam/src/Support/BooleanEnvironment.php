<?php
declare(strict_types=1);

namespace Webcam\Support;

use RuntimeException;

/** Explizite Schalter statt PHP-Truthiness; unbekannte Werte sind Konfigurationsfehler. */
final class BooleanEnvironment
{
  /** Leer/unset nutzt den Default; explizite Wahrheitswerte normieren, sonst Exception. */
  public static function read(string $name, bool $default = false): bool
  {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
      return $default;
    }

    return match (strtolower(trim($value))) {
      '1', 'true', 'yes', 'on' => true,
      '0', 'false', 'no', 'off' => false,
      // Den Rohwert nicht ausgeben: Auch versehentlich eingesetzte Secrets
      // sollen nicht ueber eine Konfigurationsfehlermeldung ins Log gelangen.
      default => throw new RuntimeException(
        $name . ' must be a boolean (1/0, true/false, yes/no, on/off).'
      ),
    };
  }
}
