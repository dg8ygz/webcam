<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

/**
 * Kompatible Capture-Fassade für CameraDataStore.
 *
 * Schreibvorgänge liegen in CameraCaptureWriter; sämtliche Pfad-, Kalender-
 * und Timeline-Abfragen in CameraCaptureReader. Bestehende Aufrufer behalten
 * dadurch ihre öffentliche API, ohne beide Verantwortungen erneut zu mischen.
 */
final class CameraCaptureRepository
{
  private readonly CameraCaptureWriter $writer;
  private readonly CameraCaptureReader $reader;

  public function __construct(
    CameraDataConnection $connection,
    CameraWeatherLogoRepository $weatherLogo
  ) {
    $this->writer = new CameraCaptureWriter($connection, $weatherLogo);
    $this->reader = new CameraCaptureReader($connection, $weatherLogo);
  }

  public function recordCapture(array $data): int
  {
    return $this->writer->recordCapture($data);
  }

  public function latestUsableHashBeforeSlot(int $slotTimestamp): ?string
  {
    return $this->writer->latestUsableHashBeforeSlot($slotTimestamp);
  }

  public function setCaptureDuplicate(int $captureId, bool $duplicate): bool
  {
    return $this->writer->setCaptureDuplicate($captureId, $duplicate);
  }

  public function setCaptureDuplicates(array $changes): int
  {
    return $this->writer->setCaptureDuplicates($changes);
  }

  /** @return array{captures:int,variants:int} */
  public function reconcileCaptureImageState(array $captureChanges, array $variantChanges): array
  {
    return $this->writer->reconcileCaptureImageState($captureChanges, $variantChanges);
  }

  public function findCaptureIdByRelativePath(string $relativePath): ?int
  {
    return $this->reader->findCaptureIdByRelativePath($relativePath);
  }

  public function findCaptureIdByAnyPath(string $relativePath): ?int
  {
    return $this->reader->findCaptureIdByAnyPath($relativePath);
  }

  public function relativePathByCaptureId(int $captureId): ?string
  {
    return $this->reader->relativePathByCaptureId($captureId);
  }

  public function findCaptureIdBySlot(int $slotTimestamp): ?int
  {
    return $this->reader->findCaptureIdBySlot($slotTimestamp);
  }

  public function normalizeRelativePath(string $path): string
  {
    return $this->reader->normalizeRelativePath($path);
  }

  /** @return list<int> */
  public function years(): array
  {
    return $this->reader->years();
  }

  /** @return list<array<string,mixed>> */
  public function capturesForRange(int $fromTimestamp, int $toTimestamp, ?int $maxRows = null): array
  {
    return $this->reader->capturesForRange($fromTimestamp, $toTimestamp, $maxRows);
  }

  public function rangeContainsOnlyDuplicateCaptures(int $fromTimestamp, int $toTimestamp): bool
  {
    return $this->reader->rangeContainsOnlyDuplicateCaptures($fromTimestamp, $toTimestamp);
  }

  public function sampledCapturesForRange(int $from, int $to, int $limit): array
  {
    return $this->reader->sampledCapturesForRange($from, $to, $limit);
  }

  /** @return list<int> */
  public function duplicateCaptureSlotsForRange(int $fromTimestamp, int $toTimestamp): array
  {
    return $this->reader->duplicateCaptureSlotsForRange($fromTimestamp, $toTimestamp);
  }

  public function nearestCaptureSlot(int $targetTimestamp, int $startTimestamp, int $endTimestamp): ?int
  {
    return $this->reader->nearestCaptureSlot($targetTimestamp, $startTimestamp, $endTimestamp);
  }

  public function captureTimeBounds(int $until): ?array
  {
    return $this->reader->captureTimeBounds($until);
  }
}
