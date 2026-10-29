<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

/** Authenticated helper metadata only. Missing coordinates stay null; never
 * substitute the map's display defaults or infer a timezone from longitude.
 */
final class CameraPositionHeader
{
  /** Base64 JSON for X-Camera-Position after key validation; zero is a valid
   * coordinate, missing/out-of-range values remain null for the Pi's guard.
   */
  public static function encode(array $location, string $timezone): string
  {
    $position=['protocol'=>'camera-rpi-position-v1','latitude'=>null,'longitude'=>null,'timezone'=>$timezone];
    foreach (['latitude'=>90,'longitude'=>180] as $key=>$limit) {
      $v=$location[$key] ?? null;
      if ((is_int($v) || is_float($v)) && is_finite((float)$v) && abs($v)<=$limit) { $position[$key]=$v; }
    }
    return base64_encode(json_encode($position,JSON_THROW_ON_ERROR));
  }
}
