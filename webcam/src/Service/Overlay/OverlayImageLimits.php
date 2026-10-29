<?php
declare(strict_types=1);

namespace Webcam\Service\Overlay;

use RuntimeException;

/** Headerbasierte Ressourcenpruefung vor Publikation oder GD-Dekodierung. */
final class OverlayImageLimits
{
  public const MAX_DIMENSION = 4096;
  public const MAX_PIXELS = 8 * 1024 * 1024;
  // check_logo baut viele PHP-Pixelarrays, nicht nur ein GD-Bitmap.
  public const ANALYSIS_MAX_DIMENSION = 512;
  public const ANALYSIS_MAX_PIXELS = 512 * 512;

  /** Upload- oder strengere Pixelarray-Analysegrenze ohne Bitmap-Allokation pruefen. */
  public static function allows(int $width, int $height, bool $analysis = false): bool
  {
    $dimension = $analysis ? self::ANALYSIS_MAX_DIMENSION : self::MAX_DIMENSION;
    $pixels = $analysis ? self::ANALYSIS_MAX_PIXELS : self::MAX_PIXELS;
    return $width > 0 && $height > 0
      && $width <= $dimension && $height <= $dimension
      // Division statt Multiplikation: auch manipulierte Header ohne Overflow.
      && $width <= intdiv($pixels, $height);
  }

  /** CLI-Altlogo vor GD pruefen; nicht heimlich skalieren oder die Datei veraendern. */
  public static function assertAnalyzable(string $path): void
  {
    $size = getimagesize($path);
    if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
      throw new RuntimeException('Logo must be a PNG or JPEG image');
    }
    if (!self::allows($size[0], $size[1], true)) {
      throw new RuntimeException('Logo analysis limit: 512 px per side and 262144 pixels; use a smaller analysis logo');
    }
  }
}
