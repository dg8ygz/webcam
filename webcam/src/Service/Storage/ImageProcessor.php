<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use GdImage;
use RuntimeException;
use InvalidArgumentException;

/**
 * Arbeitet ausschliesslich auf einer Bildkopie, ohne Shell. Kantenglaettung laeuft im begrenzten Hilfsprozess.
 * Bereiche verwenden Pixelkoordinaten des Eingangsbildes. Ihre Reihenfolge
 * ist verbindlich: Jede Aktion sieht das Ergebnis der vorherigen Aktion.
 * Beschnitt folgt zuletzt, damit Rechtecke beim Umordnen ihre Position halten.
 */
final class ImageProcessor
{
  public function apply(GdImage $source, array $input): GdImage
  {
    $c = ImageProcessingConfig::normalize($input);
    $w = imagesx($source); $h = imagesy($source);
    $out = $this->copy($source, 0, 0, $w, $h);
    try {
      if (!$c['enabled']) { return $out; }
      $crop = $c['crop'];
      if ($crop['left'] + $crop['right'] >= $w || $crop['top'] + $crop['bottom'] >= $h) {
        throw new InvalidArgumentException('Beschnitt entfernt das ganze Bild');
      }
      // Flächenbudget begrenzt wiederholte Vollbildfilter. Koordinaten nie
      // still abschneiden: Eine falsche Maske darf nicht unbemerkt durchgehen.
      $area = 0;
      foreach ($c['regions'] as $r) {
        $this->bounds($r['x'], $r['y'], $r['width'], $r['height'], $w, $h);
        if ($r['type'] === 'copy') { $this->bounds($r['dest_x'], $r['dest_y'], $r['width'], $r['height'], $w, $h); }
        $area += $r['width'] * $r['height'];
        if ($area > 80000000) { throw new InvalidArgumentException('Bildbereiche ueberschreiten das Flaechenbudget'); }
      }
      if ($c['gamma'] !== 1.0 && !imagegammacorrect($out, 1.0, $c['gamma'])) {
        throw new RuntimeException('Gamma-Korrektur fehlgeschlagen');
      }
      $deadline = microtime(true) + 20;
      foreach ($c['regions'] as $r) {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) throw new RuntimeException('Zeitbudget fuer Bildbereiche erreicht');
        $this->region($out, $r, $remaining);
      }
      if (array_sum($crop) > 0) {
        $cut = $this->copy($out, $crop['left'], $crop['top'], $w - $crop['left'] - $crop['right'], $h - $crop['top'] - $crop['bottom']);
        imagedestroy($out); $out = $cut;
      }
      return $out;
    } catch (\Throwable $e) {
      imagedestroy($out); throw $e;
    }
  }

  public function jpeg(GdImage $image, int $quality = 92): string
  {
    ob_start();
    try {
      if (!imagejpeg($image, null, $quality)) { throw new RuntimeException('JPEG-Erzeugung fehlgeschlagen'); }
      return (string) ob_get_contents();
    } finally { ob_end_clean(); }
  }

  private function bounds(int $x, int $y, int $w, int $h, int $iw, int $ih): void
  {
    if ($x + $w > $iw || $y + $h > $ih) { throw new InvalidArgumentException('Bildbereich liegt ausserhalb des Eingangsbildes'); }
  }

  private function copy(GdImage $src, int $x, int $y, int $w, int $h): GdImage
  {
    $image = imagecreatetruecolor($w, $h);
    if (!$image || !imagecopy($image, $src, 0, 0, $x, $y, $w, $h)) { throw new RuntimeException('Bildkopie fehlgeschlagen'); }
    return $image;
  }

  private function region(GdImage $out, array $r, int $remaining): void
  {
    $w = $r['width']; $h = $r['height'];
    $part = $this->copy($out, $r['x'], $r['y'], $w, $h);
    try {
      switch ($r['type']) {
        case 'edge':
          $filtered = (new EdgeDetailFilter())->apply($part, $r['strength'], $remaining);
          imagedestroy($part); $part = $filtered;
          break;
        case 'mask':
          if (!imagefilledrectangle($part, 0, 0, $w - 1, $h - 1, hexdec(substr($r['color'], 1)))) { throw new RuntimeException('Abdeckung fehlgeschlagen'); }
          break;
        case 'blur':
          // Kleine Arbeitsflaeche statt vieler teurer Filter ueber 24 Megapixel.
          $small = imagescale($part, max(1, (int) ceil($w / $r['strength'])), max(1, (int) ceil($h / $r['strength'])), IMG_BILINEAR_FIXED);
          if (!$small) { throw new RuntimeException('Filterverkleinerung fehlgeschlagen'); }
          try {
            if (!imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR) || !imagecopyresampled($part, $small, 0, 0, 0, 0, $w, $h, imagesx($small), imagesy($small))) { throw new RuntimeException('Weichzeichnen fehlgeschlagen'); }
          } finally { imagedestroy($small); }
          break;
        case 'pixelate':
          if (!imagefilter($part, IMG_FILTER_PIXELATE, $r['strength'], true)) { throw new RuntimeException('Verpixelung fehlgeschlagen'); }
          break;
        case 'scramble':
          // Gleich grosse Kacheln deterministisch permutieren. Wiederholte
          // Uploads bleiben identisch und werden nicht zu zufaelligen Bildern.
          $original = $this->copy($part, 0, 0, $w, $h);
          try {
            $tile = max($r['strength'], (int) ceil(max($w, $h) / 32));
            $cols = (int) ceil($w / $tile); $rows = (int) ceil($h / $tile);
            $order = range(0, $cols * $rows - 1);
            usort($order, static fn(int $a, int $b): int => strcmp(hash('sha256', 'tile:' . $a), hash('sha256', 'tile:' . $b)));
            foreach ($order as $to => $from) {
              $dx = ($to % $cols) * $tile; $dy = intdiv($to, $cols) * $tile;
              $sx = ($from % $cols) * $tile; $sy = intdiv($from, $cols) * $tile;
              if (!imagecopyresampled($part, $original, $dx, $dy, $sx, $sy, min($tile, $w - $dx), min($tile, $h - $dy), min($tile, $w - $sx), min($tile, $h - $sy))) { throw new RuntimeException('Durchmischen fehlgeschlagen'); }
            }
          } finally { imagedestroy($original); }
          break;
      }
      $x = $r['type'] === 'copy' ? $r['dest_x'] : $r['x'];
      $y = $r['type'] === 'copy' ? $r['dest_y'] : $r['y'];
      $feather = min($r['feather'], intdiv(min($w, $h), 2));
      // Jeder Pixel wird genau einmal eingemischt: Randringe plus Kern.
      // Wiederholtes Ueberblenden ganzer Rechtecke wuerde die Deckkraft erhoehen.
      for ($i = 0; $i < $feather; $i++) {
        $opacity = (int) round($r['opacity'] * ($i + 1) / ($feather + 1));
        $this->merge($out, $part, $x, $y, $i, $i, $w - 2*$i, 1, $opacity);
        $this->merge($out, $part, $x, $y, $i, $h - $i - 1, $w - 2*$i, 1, $opacity);
        if ($h - 2*$i > 2) {
          $this->merge($out, $part, $x, $y, $i, $i + 1, 1, $h - 2*$i - 2, $opacity);
          $this->merge($out, $part, $x, $y, $w - $i - 1, $i + 1, 1, $h - 2*$i - 2, $opacity);
        }
      }
      if ($w > 2*$feather && $h > 2*$feather) { $this->merge($out, $part, $x, $y, $feather, $feather, $w - 2*$feather, $h - 2*$feather, $r['opacity']); }
    } finally { imagedestroy($part); }
  }

  private function merge(GdImage $out, GdImage $part, int $x, int $y, int $sx, int $sy, int $w, int $h, int $opacity): void
  {
    if (!imagecopymerge($out, $part, $x + $sx, $y + $sy, $sx, $sy, $w, $h, $opacity)) { throw new RuntimeException('Bildbereich konnte nicht eingemischt werden'); }
  }
}
