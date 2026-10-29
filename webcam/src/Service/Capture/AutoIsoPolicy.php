<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use InvalidArgumentException;

/** ISO feedback from the unmodified JPEG, never from rendered/gamma-corrected variants. */
final class AutoIsoPolicy
{
  public const STEPS = [50, 100, 200, 400, 800, 1600, 3200, 6400, 12800, 25600, 51200, 102400];

  public static function normalize(mixed $input): array
  {
    if ($input === null) $input = [];
    if (!is_array($input) || array_diff(array_keys($input), ['mode', 'min_iso', 'max_iso', 'night_ev_max'])) {
      throw new InvalidArgumentException('capture.auto_iso: ungueltige Felder');
    }
    $config = $input + ['mode' => 'off', 'min_iso' => 100, 'max_iso' => 800];
    if (!in_array($config['mode'], ['off', 'observe', 'auto'], true)) {
      throw new InvalidArgumentException('capture.auto_iso.mode: off, observe oder auto erwartet');
    }
    foreach (['min_iso', 'max_iso'] as $key) {
      if (!is_int($config[$key]) || !in_array($config[$key], self::STEPS, true)) {
        throw new InvalidArgumentException('capture.auto_iso.' . $key . ': gueltige ISO-Stufe erwartet');
      }
    }
    if ($config['min_iso'] > $config['max_iso']) {
      throw new InvalidArgumentException('capture.auto_iso: Minimum darf Maximum nicht uebersteigen');
    }
    if (isset($config['night_ev_max']) && (!is_int($config['night_ev_max']) || $config['night_ev_max'] < 0 || $config['night_ev_max'] > 2)) {
      throw new InvalidArgumentException('capture.auto_iso.night_ev_max: 0, 1 oder 2 erwartet');
    }
    if (array_key_exists('night_ev_max', $config) && $config['night_ev_max'] === null) {
      throw new InvalidArgumentException('capture.auto_iso.night_ev_max: Zahl erwartet');
    }
    return $config;
  }

  /** Raw EXIF values; thresholds deliberately retain the legacy hysteresis. */
  public static function decide(array $config, array $exposure, ?float $luminance): array
  {
    $config = self::normalize($config);
    $iso = $exposure['iso'] ?? null;
    $seconds = $exposure['seconds'] ?? null;
    $model = $exposure['model'] ?? null;
    if (!is_string($model) || !preg_match('/EOS|Nikon/i', $model)) {
      return ['reason' => 'unsupported_model', 'target_iso' => null];
    }
    if ((!is_int($iso) && !is_float($iso)) || !is_finite((float) $iso)
      || $iso != (int) $iso || !in_array((int) $iso, self::STEPS, true)
      || (!is_int($seconds) && !is_float($seconds)) || !is_finite((float) $seconds)
      || $seconds <= 0 || $seconds > 3600
      || $luminance === null || !is_finite($luminance) || $luminance < 0 || $luminance > 100) {
      return ['reason' => 'invalid_measurement', 'target_iso' => null];
    }
    $iso = (int) $iso;
    $target = $iso;
    $reason = 'hold';
    if ($seconds >= 25 && $luminance < 35) {
      $target = $iso * ($luminance < 10 ? 4 : 2);
      $reason = 'dark_long';
    } elseif ($seconds < 1 && $iso >= 400) {
      $target = intdiv($iso, 4);
      $reason = 'short';
    } elseif ($seconds < 10 || $luminance > 40) {
      $target = intdiv($iso, 2);
      $reason = 'bright_or_short';
    }
    $target = max($config['min_iso'], min($config['max_iso'], $target));
    if ($iso < $config['min_iso'] || $iso > $config['max_iso']) $reason = 'range';
    return ['reason' => $target === $iso ? 'hold' : $reason,
      'iso' => $iso, 'seconds' => (float) $seconds, 'luminance' => $luminance,
      'target_iso' => $target === $iso ? null : $target];
  }
}
