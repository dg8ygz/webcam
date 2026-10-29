<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

/** Absolute whole-EV values; requires a verified value-based ISOEXP helper. */
final class NightExposurePolicy
{
  public static function isNight(array $location, int $now): bool
  {
    $lat = $location['latitude'] ?? null;
    $lon = $location['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon) || !is_finite((float) $lat)
      || !is_finite((float) $lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) return false;
    $sun = date_sun_info($now, (float) $lat, (float) $lon);
    // Unknown/polar dates never authorize positive compensation.
    $begin = $sun['civil_twilight_begin'] ?? null;
    $end = $sun['civil_twilight_end'] ?? null;
    return is_int($begin) && is_int($end) && ($now < $begin || $now >= $end);
  }

  public static function decide(array $config, array $exposure, ?float $lum, array $location, int $now): array
  {
    $result = ['target_ev' => null, 'ev_reason' => 'disabled'];
    $max = $config['night_ev_max'] ?? 0;
    if ($max === 0) return $result;
    $bias = $exposure['bias'] ?? null;
    if ((!is_int($bias) && !is_float($bias)) || !is_finite((float) $bias) || abs($bias) > 5
      || !preg_match('/EOS/i', (string) ($exposure['model'] ?? ''))) {
      return ['target_ev' => null, 'ev_reason' => 'invalid_bias_or_model'];
    }
    $result = ['target_ev' => null, 'ev_reason' => 'hold', 'bias' => (float) $bias];
    if (!self::isNight($location, $now)) {
      return array_replace($result, ['target_ev' => abs($bias) > 0.05 ? 0 : null, 'ev_reason' => 'day_zero']);
    }
    if ($lum === null || !is_finite($lum) || $lum < 0 || $lum > 100) return $result;
    if ($bias > $max) return array_replace($result, ['target_ev' => $max, 'ev_reason' => 'limit']);
    if ($bias < -0.05) return $result; // preserve a deliberate manual underexposure at night
    if ($lum > 35 && $bias > 0.05) {
      return array_replace($result, ['target_ev' => max(0, (int) ceil($bias - 1)), 'ev_reason' => 'night_bright']);
    }
    $seconds = $exposure['seconds'] ?? null;
    if (is_numeric($seconds) && is_finite((float) $seconds) && $seconds >= 10 && $seconds <= 3600
      && $lum < 10 && $bias < $max - 0.05) {
      return array_replace($result, ['target_ev' => min($max, (int) floor($bias + 1.05)), 'ev_reason' => 'night_dark']);
    }
    return $result;
  }
}
