<?php
declare(strict_types=1);

namespace Webcam\Service\Overlay;

use RuntimeException;
use Webcam\App;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;

/**
 * Prüft und veröffentlicht ein administrativ hochgeladenes Overlaybild.
 *
 * Die Klasse übernimmt den sicherheitsrelevanten Teil hinter dem Multipart-
 * Adapter. Sie kennt weder $_FILES noch HTTP-Antworten. Der Aufrufer muss zuvor
 * mit is_uploaded_file() belegen, dass der temporäre Pfad wirklich aus dem
 * aktuellen PHP-Upload stammt.
 *
 * Ablauf:
 * 1. Kamera und temporäre Quelldatei defensiv gegenprüfen,
 * 2. gemeldete und tatsächliche Dateigröße begrenzen,
 * 3. MIME-Typ aus dem Dateiinhalt ermitteln und Bildkopf lesen,
 * 4. einen ungefährlichen Zielnamen erzeugen,
 * 5. Publikation und Cleanup über den gemeinsamen Overlay-Lock koordinieren,
 * 6. atomar unter cache/<slug>/overlays/ veröffentlichen,
 * 7. ausschließlich die geschützte Auslieferungsroute overlay.php zurückgeben.
 */
final class OverlayUploadService
{
  private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

  private readonly int $maxBytes;

  public function __construct(
    private readonly App $app,
    ?int $maxBytes = null,
    private readonly int $lockTimeoutSeconds = 10
  ) {
    $configuredLimit = getenv('WEBCAM_OVERLAY_MAX_BYTES');
    $this->maxBytes = $maxBytes ?? (int) (
      is_string($configuredLimit) && $configuredLimit !== ''
        ? $configuredLimit
        : self::DEFAULT_MAX_BYTES
    );
  }

  /**
   * Speichert genau eine bereits vom HTTP-Adapter bestätigte Upload-Datei.
   *
   * publishedAt ist nur für reproduzierbare Tests vorgesehen. Im Betrieb wird
   * der aktuelle Unix-Zeitstempel verwendet, wie schon im bisherigen Upload.
   *
   * @return array{
   *   filename:string,
   *   url:string,
   *   relative_path:string,
   *   storage_path:string,
   *   width:int,
   *   height:int
   * }
   */
  public function store(
    string $slug,
    string $temporaryPath,
    string $originalName,
    int $reportedSize,
    ?int $publishedAt = null
  ): array {
    if ($this->app->cameras()->find($slug) === null) {
      throw new OverlayUploadException(
        OverlayUploadException::CAMERA_NOT_FOUND,
        'Camera not found'
      );
    }
    if ($temporaryPath === '' || !is_file($temporaryPath) || !is_readable($temporaryPath)) {
      throw new OverlayUploadException(
        OverlayUploadException::INVALID_SOURCE,
        'Invalid upload'
      );
    }

    // Nicht allein auf die vom Multipart-Parser gemeldete Größe vertrauen.
    // Der größere Wert aus Metadatum und realer Datei entscheidet das Limit.
    clearstatcache(true, $temporaryPath);
    $actualSize = filesize($temporaryPath);
    if ($actualSize === false) {
      throw new OverlayUploadException(
        OverlayUploadException::INVALID_SOURCE,
        'Invalid upload'
      );
    }
    if (max($reportedSize, (int) $actualSize) > $this->maxBytes) {
      throw new OverlayUploadException(
        OverlayUploadException::TOO_LARGE,
        'Overlay exceeds size limit'
      );
    }

    [$extension, $width, $height] = $this->inspectImage($temporaryPath, $slug);
    $filename = $this->buildFilename(
      $originalName,
      $extension,
      $publishedAt ?? time()
    );

    try {
      $guard = OverlayFileGuard::acquire($this->app, $this->lockTimeoutSeconds);
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('overlay operation lock unavailable', [
        'slug' => $slug,
        'error' => $exception->getMessage(),
      ]);
      throw new OverlayUploadException(
        OverlayUploadException::BUSY,
        'Overlay-Verarbeitung ist gerade belegt'
      );
    }

    try {
      $targetDirectory = $this->app->paths()->cachePath($slug . '/overlays');
      if (
        !is_dir($targetDirectory)
        && !mkdir($targetDirectory, 0775, true)
        && !is_dir($targetDirectory)
      ) {
        $this->app->logger()->warning('overlay dir create failed', [
          'dir' => $targetDirectory,
          'slug' => $slug,
        ]);
        throw new OverlayUploadException(
          OverlayUploadException::STORAGE_FAILED,
          'Failed to create overlay directory'
        );
      }

      $targetPath = $targetDirectory . '/' . $filename;
      if (!AtomicFileWriter::copy($temporaryPath, $targetPath, 0664)) {
        $this->app->logger()->warning('overlay atomic publish failed', [
          'path' => $targetPath,
          'slug' => $slug,
        ]);
        throw new OverlayUploadException(
          OverlayUploadException::STORAGE_FAILED,
          'Failed to store upload'
        );
      }
      $this->removeTemporaryFile($temporaryPath, $slug, 'overlay upload temp cleanup failed');

      $relativeStoragePath = 'cache/' . $slug . '/overlays/' . $filename;
      $publicUrl = 'overlay.php?slug=' . rawurlencode($slug)
        . '&file=' . rawurlencode($filename);

      return [
        'filename' => $filename,
        'url' => $publicUrl,
        'relative_path' => $publicUrl,
        'storage_path' => $relativeStoragePath,
        'width' => $width,
        'height' => $height,
      ];
    } finally {
      $guard->release();
    }
  }

  /**
   * Bestimmt Format und Abmessungen ausschließlich aus dem Dateiinhalt.
   * Ein Dateiname mit .png macht aus Text oder einem defekten Bild kein Overlay.
   *
   * @return array{0:string,1:int,2:int} Erweiterung, Breite, Höhe
   */
  private function inspectImage(string $path, string $slug): array
  {
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo !== false ? finfo_file($fileInfo, $path) : false;
    if ($fileInfo !== false) {
      finfo_close($fileInfo);
    }

    $allowedMimeTypes = [
      'image/png' => ['extension' => '.png', 'image_type' => IMAGETYPE_PNG],
      'image/jpeg' => ['extension' => '.jpg', 'image_type' => IMAGETYPE_JPEG],
      'image/jpg' => ['extension' => '.jpg', 'image_type' => IMAGETYPE_JPEG],
    ];
    $format = is_string($mimeType) ? ($allowedMimeTypes[$mimeType] ?? null) : null;
    $dimensions = is_array($format) ? @getimagesize($path) : false;
    if (
      !is_array($format)
      || $dimensions === false
      || (int) ($dimensions[0] ?? 0) <= 0
      || (int) ($dimensions[1] ?? 0) <= 0
      || (int) ($dimensions[2] ?? 0) !== $format['image_type']
    ) {
      // Wie bisher wird ein inhaltlich abgelehnter Upload sofort entfernt.
      $this->removeTemporaryFile($path, $slug, 'overlay temp cleanup failed');
      throw new OverlayUploadException(
        OverlayUploadException::UNSUPPORTED_MEDIA,
        'Unsupported media type'
      );
    }

    if (!OverlayImageLimits::allows((int) $dimensions[0], (int) $dimensions[1])) {
      $this->removeTemporaryFile($path, $slug, 'overlay temp cleanup failed');
      throw new OverlayUploadException(
        OverlayUploadException::TOO_LARGE,
        'Overlay exceeds dimension limit: 4096 px per side and 8388608 pixels'
      );
    }

    return [
      $format['extension'],
      (int) $dimensions[0],
      (int) $dimensions[1],
    ];
  }

  /** Erzeugt aus dem Clientnamen nur ASCII-Buchstaben, Zahlen, _ und -. */
  private function buildFilename(string $originalName, string $extension, int $timestamp): string
  {
    $stem = preg_replace(
      '/[^a-zA-Z0-9_-]+/',
      '-',
      pathinfo($originalName, PATHINFO_FILENAME)
    );
    if (!is_string($stem) || $stem === '') {
      $stem = 'overlay';
    }
    return $stem . '-' . $timestamp . $extension;
  }

  /** Entfernt nur den konkreten temporären Uploadpfad und protokolliert Fehler. */
  private function removeTemporaryFile(string $path, string $slug, string $message): void
  {
    if (!is_file($path)) {
      return;
    }
    if (!unlink($path)) {
      $this->app->logger()->warning($message, [
        'path' => $path,
        'slug' => $slug,
      ]);
    }
  }
}
