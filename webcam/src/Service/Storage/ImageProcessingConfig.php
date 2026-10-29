<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use InvalidArgumentException;

/** Ein gemeinsamer Vertrag fuer Admin, Vorschau und echte Bildaufnahme. */
final class ImageProcessingConfig
{
  public static function normalize(mixed $input): array
  {
    if ($input === null) { $input = []; }
    self::keys($input, ['enabled', 'crop', 'gamma', 'regions'], 'Bildbearbeitung');
    $enabled = $input['enabled'] ?? false;
    if (!is_bool($enabled)) { throw new InvalidArgumentException('enabled muss true oder false sein'); }
    $crop = $input['crop'] ?? [];
    self::keys($crop, ['top', 'right', 'bottom', 'left'], 'Beschnitt');
    foreach (['top', 'right', 'bottom', 'left'] as $key) {
      $crop[$key] = self::integer($crop[$key] ?? 0, 0, 8191, 'Beschnitt ' . $key);
    }
    $gamma = $input['gamma'] ?? 1;
    if ((!is_int($gamma) && !is_float($gamma)) || !is_finite((float) $gamma) || $gamma < 0.2 || $gamma > 3) {
      throw new InvalidArgumentException('Gamma muss zwischen 0.2 und 3 liegen');
    }
    $regions = $input['regions'] ?? [];
    if (!is_array($regions) || !array_is_list($regions) || count($regions) > 16) {
      throw new InvalidArgumentException('Maximal 16 Bildbereiche erlaubt');
    }
    foreach ($regions as &$r) {
      self::keys($r, ['type', 'x', 'y', 'width', 'height', 'dest_x', 'dest_y', 'opacity', 'feather', 'strength', 'color'], 'Bildbereich');
      if (!in_array($r['type'] ?? null, ['copy', 'blur', 'pixelate', 'scramble', 'mask', 'edge'], true)) {
        throw new InvalidArgumentException('Unbekannte Bereichsaktion');
      }
      foreach (['x', 'y', 'width', 'height'] as $key) {
        $r[$key] = self::integer($r[$key] ?? null, in_array($key, ['width', 'height']) ? 1 : 0, 8192, $key);
      }
      $r['dest_x'] = self::integer($r['dest_x'] ?? $r['x'], 0, 8192, 'Ziel X');
      $r['dest_y'] = self::integer($r['dest_y'] ?? $r['y'], 0, 8192, 'Ziel Y');
      $r['opacity'] = self::integer($r['opacity'] ?? 100, 1, 100, 'Deckkraft');
      $r['feather'] = self::integer($r['feather'] ?? 0, 0, 64, 'Weicher Rand');
      $r['strength'] = self::integer($r['strength'] ?? 12, 1, 64, 'Staerke');
      $r['color'] = $r['color'] ?? '#000000';
      if (!is_string($r['color']) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $r['color'])) {
        throw new InvalidArgumentException('Farbe muss #RRGGBB sein');
      }
      // Eine Abdeckung ist immer vollstaendig; kosmetische Effekte sind kein
      // verlaesslicher Sichtschutz. Masken erhalten deshalb keine Transparenz.
      if ($r['type'] === 'mask') { $r['opacity'] = 100; $r['feather'] = 0; }
    }
    unset($r);
    return ['enabled' => $enabled, 'crop' => $crop, 'gamma' => (float) $gamma, 'regions' => $regions];
  }

  private static function keys(mixed $value, array $allowed, string $label): void
  {
    if (!is_array($value) || array_diff(array_keys($value), $allowed)) {
      throw new InvalidArgumentException($label . ': ungueltige oder unbekannte Felder');
    }
  }

  private static function integer(mixed $v, int $min, int $max, string $name): int
  {
    if (!is_int($v) || $v < $min || $v > $max) {
      throw new InvalidArgumentException("$name muss eine ganze Zahl zwischen $min und $max sein");
    }
    return $v;
  }
}
