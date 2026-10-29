<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use GdImage;
use RuntimeException;

/**
 * Verbindlicher Datenschutzbeschnitt, Betreiberauftrag18.10.2026.
 * Ab30.03.2026 22:20 Europe/Berlin duerfen die unteren Fenster weder im
 * Original noch in Varianten erscheinen. Vor optionalen Bildaktionen anwenden:
 * Eine Kopieraktion darf den geschuetzten Bereich nicht ins Bild zurueckholen.
 * Ein bereits beschnittenes Bild wird nicht erneut gekuerzt.
 */
final class StrombergPrivacyCrop
{
  public const FIRST_SLOT = 1774902000;

  public static function required(string $slug, int $slot): bool
  {
    return $slug === 'stromberg' && $slot >= self::FIRST_SLOT;
  }

  public static function height(int $width, int $height): int
  {
    $safeHeight = (int) floor($width * 3700 / 6000);
    if ($safeHeight < 1) throw new RuntimeException('Privacy crop: invalid dimensions');
    if ($height <= (int) ceil($width * 3700 / 6000)) return $height;
    if (abs($height - $width * 2 / 3) > 1 || $width > 6000) {
      throw new RuntimeException('Privacy crop: unexpected image geometry; publication refused');
    }
    return $safeHeight;
  }

  /**
   * Der konfigurierte untere Beschnitt bezieht sich auf das urspruengliche
   * 3:2-Bild. Den bereits zwingend entfernten Streifen anrechnen, damit ein
   * eingetragener 300px-Beschnitt nicht insgesamt 600px entfernt.
   */
  public static function remainingProcessing(array $config, int $width): array
  {
    $mandatory = (int) round($width * 2 / 3) - (int) floor($width * 3700 / 6000);
    $config['crop']['bottom'] = max(0, $config['crop']['bottom'] - $mandatory);
    return $config;
  }

  /** Liefert eine neue Bildressource; der Aufrufer behaelt sein Eingangsbild. */
  public static function apply(GdImage $source): GdImage
  {
    $out = imagecrop($source, ['x' => 0, 'y' => 0, 'width' => imagesx($source),
      'height' => self::height(imagesx($source), imagesy($source))]);
    if (!$out) throw new RuntimeException('Privacy crop failed');
    return $out;
  }

  public static function jpeg(GdImage $image): string
  {
    ob_start();
    try {
      if (!imagejpeg($image, null, 92)) throw new RuntimeException('Privacy JPEG encoding failed');
      return (string) ob_get_contents();
    } finally { ob_end_clean(); }
  }
}
