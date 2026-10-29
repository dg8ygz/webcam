<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use GdImage;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/**
 * Erzeugt numerische und stabil benannte JPEG-Anzeigevarianten.
 *
 * Das Original wird nie verändert. Die optionale Nachtfilterung arbeitet nur
 * auf GD-Kopien und ist ausschließlich für Kameras mit internem Denoise-Tag
 * sowie niedriger Luminanz aktiv.
 */
final class ImageVariantGenerator
{
  private const NIGHT_DENOISE_TAG = '__night_denoise';
  private const NIGHT_DENOISE_LUMINANCE_THRESHOLD = 28.0;

  public function __construct(private readonly App $app) {}

  public function denoiseEnabled(Camera $camera, ?float $luminance): bool
  {
    return $luminance !== null
      && $luminance <= self::NIGHT_DENOISE_LUMINANCE_THRESHOLD
      && in_array(self::NIGHT_DENOISE_TAG, $camera->tags(), true);
  }

  /** @return array<int,string> Breite => absoluter Dateipfad */
  public function numeric(
    Camera $camera,
    string $directory,
    string $filename,
    GdImage $image,
    bool $denoise
  ): array {
    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
      return [];
    }
    $resolutions = $camera->resolutions() ?: $this->app->config('default_resolutions', []);
    sort($resolutions);
    $variants = [];
    foreach ($resolutions as $targetWidth) {
      if ($targetWidth >= $sourceWidth) {
        continue;
      }
      $path = $directory . DIRECTORY_SEPARATOR . $filename . '_' . $targetWidth . '.jpg';
      $this->writeResized($image, $sourceWidth, $sourceHeight, $targetWidth, $path, $denoise);
      $variants[$targetWidth] = $path;
    }
    return $variants;
  }

  /** @return array<string,string> */
  public function named(
    GdImage $image,
    string $directory,
    string $filename,
    string $originalPath,
    bool $denoise
  ): array {
    $paths = [];
    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    foreach (VariantProfiles::CANONICAL_WIDTHS as $suffix => $width) {
      $path = $directory . DIRECTORY_SEPARATOR . $filename . '_' . $suffix . '.jpg';
      $this->writeResized($image, $sourceWidth, $sourceHeight, $width, $path, $denoise);
      $paths[$suffix] = $path;
    }
    foreach (VariantProfiles::ORIGINAL_CLONES as $suffix) {
      $path = $directory . DIRECTORY_SEPARATOR . $filename . '_' . $suffix . '.jpg';
      if ($denoise) {
        $this->writeResized($image, $sourceWidth, $sourceHeight, $sourceWidth, $path, true);
      } else {
        if (!AtomicFileWriter::copy($originalPath, $path)) {
          $this->app->logger()->warning('named clone copy failed', ['source' => $originalPath, 'target' => $path]);
        }
        $this->applyOwnership($path);
      }
      $paths[$suffix] = $path;
    }
    return $paths;
  }

  /** Skaliert proportional und veröffentlicht die Datei erst nach JPEG-Prüfung. */
  public function writeResized(
    GdImage $image,
    int $sourceWidth,
    int $sourceHeight,
    int $targetWidth,
    string $destination,
    bool $denoise
  ): void {
    $output = $image;
    $destroyOutput = false;
    if ($targetWidth <= 0 || $targetWidth >= $sourceWidth) {
      if ($denoise && ($copy = imagecreatetruecolor($sourceWidth, $sourceHeight))) {
        imagecopy($copy, $image, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
        $this->reduceNightNoise($copy, $sourceWidth);
        $output = $copy;
        $destroyOutput = true;
      }
    } else {
      $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
      $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
      if ($canvas) {
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        if ($denoise) {
          $this->reduceNightNoise($canvas, $targetWidth);
        }
        $output = $canvas;
        $destroyOutput = true;
      }
    }
    $written = AtomicFileWriter::writeWithCallback(
      $destination,
      static fn($handle): bool => imagejpeg($output, $handle, 92),
      static function (string $path): bool {
        $info = @getimagesize($path);
        return is_array($info) && ($info[2] ?? null) === IMAGETYPE_JPEG;
      }
    );
    if ($destroyOutput) {
      imagedestroy($output);
    }
    if (!$written) {
      throw new RuntimeException("Unable to publish JPEG variant: {$destination}");
    }
    $this->applyOwnership($destination);
  }

  private function reduceNightNoise(GdImage $image, int $targetWidth): void
  {
    if (defined('IMG_FILTER_SELECTIVE_BLUR')) {
      @imagefilter($image, IMG_FILTER_SELECTIVE_BLUR);
      if ($targetWidth <= 400) {
        @imagefilter($image, IMG_FILTER_SELECTIVE_BLUR);
      }
    }
    if (defined('IMG_FILTER_SMOOTH')) {
      @imagefilter($image, IMG_FILTER_SMOOTH, $targetWidth <= 400 ? 4 : 2);
    }
  }

  private function applyOwnership(string $path): void
  {
    $group = $this->app->config('storage_owner_group');
    $user = $this->app->config('storage_owner_user');
    if (is_string($group) && $group !== '' && !chgrp($path, $group)) {
      $this->app->logger()->warning('chgrp failed', ['path' => $path, 'group' => $group]);
    }
    if (is_string($user) && $user !== '' && !chown($path, $user)) {
      $this->app->logger()->warning('chown failed', ['path' => $path, 'user' => $user]);
    }
  }
}
