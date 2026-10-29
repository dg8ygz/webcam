<?php
declare(strict_types=1);

namespace Webcam\Service\Interaction;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Fachlogik für sessionbasierte Likes.
 *
 * Der Service löst Bildreferenzen auf, lässt CameraDataStore Duplikat und
 * Zeitfenster atomar prüfen und synchronisiert anschließend die lesbare
 * EXIF-Sidecar. Best-Of-Dateikopien gehören nicht in diese Klasse.
 */
final class LikeService
{
  public function __construct(private readonly App $app) {}

  /** Verwendet/erzeugt eine anonyme Session-ID; IP-Adressen werden nicht gespeichert. */
  public function ensureSessionId(): string
  {
    $cookieName = 'webcam_sid';
    $current = isset($_COOKIE[$cookieName]) ? (string) $_COOKIE[$cookieName] : '';
    if ($current !== '' && preg_match('/^[a-f0-9]{32,64}$/', $current)) {
      return $current;
    }
    $sid = bin2hex(random_bytes(16));
    $params = [
      'expires' => time() + (86400 * 30),
      'path' => '/',
      'httponly' => true,
      'samesite' => 'Lax',
      'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
    setcookie($cookieName, $sid, $params);
    $_COOKIE[$cookieName] = $sid;
    return $sid;
  }

  /**
   * Löst Pfad oder Slot auf einen Capture auf und übergibt Duplikat-/Fensterregeln
   * atomar an CameraDataStore. Nach Erfolg wird nur die zugehörige EXIF-Sidecar
   * mit dem neuen Zähler synchronisiert.
   *
   * @param array{relative_path?:string,slot_ts?:int} $selection
   * @return array{ok:bool,code?:string,message?:string,likes_count?:int}
   */
  public function like(Camera $camera, string $sessionId, array $selection): array
  {
    $store = new CameraDataStore($this->app, $camera, null, true);
    $captureId = null;
    $resolvedRel = null;
    $likesCount = null;

    try {
      if (!empty($selection['relative_path'])) {
        $resolvedRel = $store->normalizeRelativePath((string) $selection['relative_path']);
        $captureId = $store->findCaptureIdByRelativePath($resolvedRel) ?? $store->findCaptureIdByAnyPath($resolvedRel);
      } elseif (!empty($selection['slot_ts'])) {
        $captureId = $store->findCaptureIdBySlot((int) $selection['slot_ts']);
        if ($captureId !== null) {
          $resolvedRel = $store->relativePathByCaptureId($captureId);
        }
      }
    } catch (\PDOException $exception) {
      // Datenbank nicht initialisiert (z.B. read-only mirror ohne Schema)
      if (str_contains(strtolower($exception->getMessage()), 'no such table')) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'Aufnahme-Datenbank nicht initialisiert'];
      }
      throw $exception;
    }

    if ($captureId === null) {
      return ['ok' => false, 'code' => 'not_found', 'message' => 'Aufnahme nicht bekannt'];
    }

    $windowSeconds = (int) $this->app->config('likes_window_seconds', 3600);
    $maxPerWindow = (int) $this->app->config('likes_max_per_window', 20);
    if ($windowSeconds <= 0) {
      $windowSeconds = 3600;
    }
    if ($maxPerWindow <= 0) {
      $maxPerWindow = 20;
    }

    $writeStore = new CameraDataStore($this->app, $camera);
    $result = $writeStore->recordLike($captureId, $sessionId, $windowSeconds, $maxPerWindow);
    if ($result['ok'] ?? false) {
      $likesCount = $result['likes_count'] ?? null;
      $result['relative_path'] = $resolvedRel;
      if ($resolvedRel && $likesCount !== null) {
        try {
          $this->updateExifLikes($camera, $resolvedRel, (int) $likesCount);
        } catch (\Throwable $exception) {
          // Die DB ist zu diesem Zeitpunkt bereits festgeschrieben. Sidecars sind
          // Komfortdaten und dürfen den erfolgreichen Like nicht als Fehler melden.
          $this->app->logger()->warning('like exif update failed', [
            'camera' => $camera->slug(),
            'path' => $resolvedRel,
            'error' => $exception->getMessage(),
          ]);
        }
      }
    }
    return $result;
  }

  /** Öffentlicher Reparaturweg zum Synchronisieren eines bekannten Like-Zählers. */
  public function updateExifLikesForPath(Camera $camera, string $relativePath, int $likes): void
  {
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
      return;
    }
    $this->updateExifLikes($camera, $relativePath, $likes);
  }

  private function updateExifLikes(Camera $camera, string $relativePath, int $likes): void
  {
    // Pilot-Likes liegen vollstaendig in SQLite; kein Rendern fuer eine Sidecar.
    if (\Webcam\Service\Storage\DeferredImages::isPath($relativePath)) return;
    $app = $this->app;
    $abs = $app->resolveCameraStoragePath($camera, $relativePath);
    if ($abs === null) {
      return;
    }
    $this->writeLikesSidecar($abs, $likes, $camera->slug());

    // Falls ein Variantensuffix (_la/_lm/_full/_sm/_hu) verwendet wird, auch die Basisdatei aktualisieren
    if (preg_match('/_(la|lm|full|sm|hu)(\.jpe?g)$/i', $relativePath)) {
      $baseRel = preg_replace('/_(la|lm|full|sm|hu)(\.jpe?g)$/i', '$2', $relativePath);
      if ($baseRel && $baseRel !== $relativePath) {
        $baseAbs = $app->resolveCameraStoragePath($camera, $baseRel);
        if ($baseAbs !== null) {
          $this->writeLikesSidecar($baseAbs, $likes, $camera->slug());
        }
      }
    }
  }

  /**
   * EXIF-Sidecars sind nur ein Komfort-Overlay. Ein Fehler darf den Like nicht
   * zurückrollen, muss aber sauber im Log landen.
   */
  // Ersetzt genau die Likes-Zeile oder hängt sie an und veröffentlicht die Sidecar
  // über eine temporäre Datei, damit parallele Leser vollständigen Text erhalten.
  private function writeLikesSidecar(string $absoluteJpegPath, int $likes, ?string $cameraSlug = null): void
  {
    $exifPath = preg_replace('/\\.(jpe?g)$/i', '_ex.txt', $absoluteJpegPath);
    if (!$exifPath) {
      return;
    }
    $lines = [];
    if (is_file($exifPath)) {
      $raw = file($exifPath, FILE_IGNORE_NEW_LINES) ?: [];
      foreach ($raw as $line) {
        if (preg_match('/^likes\\s*:/i', (string) $line)) {
          continue; // alten Likes-Eintrag entfernen
        }
        if (trim((string) $line) === '') {
          continue;
        }
        $lines[] = $line;
      }
    }
    $lines[] = 'Likes: ' . $likes;
    if (!AtomicFileWriter::write($exifPath, implode(PHP_EOL, $lines) . PHP_EOL)) {
      $context = ['path' => $exifPath];
      if (is_string($cameraSlug) && $cameraSlug !== '') {
        $context['camera'] = $cameraSlug;
      }
      $this->app->logger()->warning('like exif write failed', $context);
    }
  }
}
