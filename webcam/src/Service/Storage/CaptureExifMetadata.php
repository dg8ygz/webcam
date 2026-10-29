<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Stream\StreamState;

/**
 * Liest und normalisiert die öffentlichen EXIF-Metadaten eines Captures.
 *
 * Kamerahersteller liefern identische Werte unter verschiedenen Sections und
 * als Dezimalzahl, Bruchstring oder num/den-Array. Diese Klasse hält alle
 * Formatregeln an einer Stelle und ergänzt ausschließlich die Viewer-Anzahl –
 * niemals kurzlebige Stream-Identitäten oder IP-Adressen.
 */
final class CaptureExifMetadata
{
  public function __construct(private readonly App $app) {}

  /**
   * @param string|resource $imagePath JPEG-Datei oder privater seekbarer Eingangsstream.
   * @param array<string,mixed>|null $exposureValues Unrounded values for internal ISO feedback.
   * @return array<string,string> Public display values.
   */
  public function read(Camera $camera, mixed $imagePath, ?float $luminance, ?array &$exposureValues = null): array
  {
    $summary = [];
    $exposureValues = [];
    if (function_exists('exif_read_data')) {
      $exif = exif_read_data($imagePath, null, true);
      if (is_array($exif)) {
        $this->copyText($summary, 'Model', $this->first($exif, [['IFD0', 'Model'], ['0th', 'Model'], ['COMPUTED', 'Model']]));
        $this->copyFormatted($summary, 'Exposure Time', $this->exposure($this->first($exif, [['EXIF', 'ExposureTime'], ['SubIFD', 'ExposureTime']])));
        $this->copyFormatted($summary, 'FNumber', $this->fNumber($this->first($exif, [['EXIF', 'FNumber'], ['SubIFD', 'FNumber']])));
        $iso = $this->first($exif, [['EXIF', 'ISOSpeedRatings'], ['SubIFD', 'ISOSpeedRatings']]);
        if (is_array($iso)) {
          $iso = implode(', ', array_map('strval', $iso));
        }
        if ($iso !== null && $iso !== '') {
          $summary['ISO'] = (string) $iso;
        }
        // Keep unrounded values out of the public display strings. In particular,
        // 25.1 seconds must not become 25 before the feedback threshold check.
        $exposureValues = [
          'model' => $summary['Model'] ?? null,
          'iso' => is_numeric($iso) ? (float) $iso : null,
          'bias' => $this->rational($this->first($exif, [['EXIF', 'ExposureBiasValue'], ['SubIFD', 'ExposureBiasValue']])),
          'seconds' => $this->rational($this->first($exif, [['EXIF', 'ExposureTime'], ['SubIFD', 'ExposureTime']])),
        ];
        $this->copyFormatted($summary, 'Exposure Bias', $this->ev($this->first($exif, [['EXIF', 'ExposureBiasValue'], ['SubIFD', 'ExposureBiasValue']])));
        $this->copyFormatted($summary, 'Focal Length', $this->focalLength($this->first($exif, [['EXIF', 'FocalLength'], ['SubIFD', 'FocalLength']])));
        $this->copyFormatted($summary, 'MeasuredEV', $this->ev($this->first($exif, [['EXIF', 'BrightnessValue'], ['SubIFD', 'BrightnessValue']])));
        $this->copyText($summary, 'Lens Name', $this->first($exif, [['EXIF', 'LensModel'], ['EXIF', 'UndefinedTag:0xA434'], ['MakerNote', 'LensModel']]));
        $this->copyText($summary, 'Serial Number', $this->first($exif, [['EXIF', 'BodySerialNumber'], ['IFD0', 'SerialNumber'], ['MakerNote', 'SerialNumber']]));
      }
    }
    if ($luminance !== null) {
      $summary['Luminance'] = $this->number($luminance, 1) . ' %';
    }

    $summary = $this->ordered($summary);
    $summary['Stream viewers'] = (string) $this->viewerCount($camera);
    return $summary;
  }

  /** @param array<string,string> $summary */
  public function sidecar(array $summary): string
  {
    $lines = [];
    foreach ($summary as $label => $value) {
      $lines[] = $label . '|' . $value;
    }
    return implode(PHP_EOL, $lines) . PHP_EOL;
  }

  private function copyText(array &$summary, string $label, mixed $value): void
  {
    if (is_string($value) && trim($value) !== '') {
      $summary[$label] = trim($value);
    }
  }

  private function copyFormatted(array &$summary, string $label, ?string $value): void
  {
    if ($value !== null && $value !== '') {
      $summary[$label] = $value;
    }
  }

  private function exposure(mixed $value): ?string
  {
    $number = $this->rational($value);
    if ($number === null || $number <= 0) {
      return null;
    }
    if ($number >= 1) {
      return $this->number($number, $number >= 10 ? 0 : 1) . ' sec.';
    }
    $denominator = (int) round(1 / $number);
    return $denominator > 0 ? '1/' . $denominator . ' sec.' : $this->number($number, 4) . ' sec.';
  }

  private function fNumber(mixed $value): ?string
  {
    $number = $this->rational($value);
    return $number !== null && $number > 0 ? 'f/' . $this->number($number, 1) : null;
  }

  private function focalLength(mixed $value): ?string
  {
    $number = $this->rational($value);
    return $number !== null && $number > 0
      ? $this->number($number, $number >= 100 ? 0 : 1) . 'mm'
      : null;
  }

  private function ev(mixed $value): ?string
  {
    $number = $this->rational($value);
    return $number !== null ? $this->number($number, 1) . ' eV' : null;
  }

  /** Wandelt EXIF-Dezimal- und Bruchdarstellungen ohne Division durch null um. */
  private function rational(mixed $value): ?float
  {
    if (is_numeric($value)) {
      return (float) $value;
    }
    if (is_string($value) && str_contains(trim($value), '/')) {
      [$numerator, $denominator] = array_pad(explode('/', trim($value), 2), 2, '1');
      return is_numeric($numerator) && is_numeric($denominator) && (float) $denominator !== 0.0
        ? (float) $numerator / (float) $denominator : null;
    }
    if (is_array($value) && isset($value['num'], $value['den']) && (float) $value['den'] !== 0.0) {
      return (float) $value['num'] / (float) $value['den'];
    }
    return null;
  }

  private function number(float $value, int $precision): string
  {
    $formatted = number_format($value, $precision, '.', '');
    if ($precision > 0) $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
  }

  /** @param array<string,mixed> $exif @param list<array{0:string,1:string}> $candidates */
  private function first(array $exif, array $candidates): mixed
  {
    foreach ($candidates as [$section, $key]) {
      if (is_array($exif[$section] ?? null) && isset($exif[$section][$key]) && $exif[$section][$key] !== '') {
        return $exif[$section][$key];
      }
    }
    return null;
  }

  /** Hält die historische Reihenfolge der Sidecar-Zeilen stabil. */
  private function ordered(array $summary): array
  {
    $ordered = [];
    foreach (['Model', 'Exposure Time', 'FNumber', 'ISO', 'Exposure Bias', 'Focal Length', 'MeasuredEV', 'Lens Name', 'Serial Number', 'Luminance'] as $key) {
      if (isset($summary[$key])) {
        $ordered[$key] = $summary[$key];
        unset($summary[$key]);
      }
    }
    return $ordered + $summary;
  }

  private function viewerCount(Camera $camera): int
  {
    try {
      return (new StreamState($this->app))->viewerCount($camera->slug());
    } catch (\Throwable $exception) {
      $this->app->logger()->warning('stream viewer count unavailable', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return 0;
    }
  }
}
