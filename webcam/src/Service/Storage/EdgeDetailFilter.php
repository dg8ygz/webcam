<?php
declare(strict_types=1);
namespace Webcam\Service\Storage;

use GdImage;
use RuntimeException;
use Webcam\Infrastructure\Process\BoundedProcessRunner;

/**
 * Kantenerhaltende Detailglaettung mit ImageMagicks Kuwahara-Implementierung.
 * Keine Behauptung ueber den nicht veroeffentlichten Foto-Webcam-Filter.
 *
 * Kuwahara bevorzugt lokal homogene Nachbarschaften statt ueber jede Kante
 * hinweg zu mitteln. Eine begrenzte Arbeitsaufloesung entfernt zusaetzlich
 * Feinstruktur und haelt die Kosten auch bei DSLR-Bildern berechenbar.
 * Alle Bilddaten laufen durch Pipes; keine Shell, URLs oder Dateinamen aus
 * dem Browser. Bei Fehlern niemals das ungefilterte Bild als Erfolg liefern.
 */
final class EdgeDetailFilter
{
  public function apply(GdImage $image, int $strength, int $timeoutSeconds): GdImage
  {
    if (!is_executable('/usr/bin/magick')) { throw new RuntimeException('Kantenglaettung benoetigt ImageMagick 7 (/usr/bin/magick)'); }
    $w = imagesx($image); $h = imagesy($image);
    $scale = min(1.0, sqrt(500000 / ($w * $h)), 12 / $strength);
    $small = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BILINEAR_FIXED);
    if (!$small) throw new RuntimeException('Arbeitsbild fuer Kantenglaettung fehlt');
    ob_start();
    try {
      if (!imagepng($small, null, 1)) throw new RuntimeException('Arbeitsbild konnte nicht kodiert werden');
      $input = (string) ob_get_contents();
    } finally { ob_end_clean(); imagedestroy($small); }
    $radius = max(1, (int) round($strength * $scale));
    $result = (new BoundedProcessRunner())->run([
      '/usr/bin/magick', '-limit', 'thread', '1', '-limit', 'memory', '128MiB',
      '-limit', 'map', '128MiB', '-limit', 'disk', '0', '-limit', 'time', (string) $timeoutSeconds,
      'png:-', '-kuwahara', (string) $radius, '-strip', 'png:-',
    ], $timeoutSeconds, ['input' => $input, 'output_max_bytes' => 8 * 1024 * 1024, 'require_process_group' => true]);
    if (!$result['started'] || $result['timed_out'] || $result['exit_code'] !== 0) {
      throw new RuntimeException('Kantenglaettung fehlgeschlagen oder Zeitbudget erreicht');
    }
    $info = getimagesizefromstring($result['stdout']);
    if (!$info || $info[0] !== max(1, (int) round($w * $scale)) || $info[1] !== max(1, (int) round($h * $scale)) || $info[2] !== IMAGETYPE_PNG) {
      throw new RuntimeException('Kantenglaettung lieferte kein vollstaendiges Arbeitsbild');
    }
    $filtered = imagecreatefromstring($result['stdout']);
    if (!$filtered) throw new RuntimeException('Kantenglaettung ist nicht lesbar');
    try {
      $out = imagescale($filtered, $w, $h, IMG_BILINEAR_FIXED);
      if (!$out) throw new RuntimeException('Kantenglaettung konnte nicht vergroessert werden');
      return $out;
    } finally { imagedestroy($filtered); }
  }
}
