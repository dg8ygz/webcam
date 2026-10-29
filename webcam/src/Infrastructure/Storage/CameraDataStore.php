<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Rückwärtskompatible Fassade für die Metadatenbank genau einer Kamera.
 *
 * Die fachlichen SQL-Blöcke liegen in getrennten, kommentierten Repositories:
 *
 * - `CameraDataSchemaRepository`: Schema und additive Migrationen,
 * - `CameraCaptureRepository`: Captures, Varianten, EXIF und Timeline-Lesen,
 * - `CameraLikeBestOfRepository`: Likes und DB-basiertes Best-Of,
 * - `CameraWeatherLogoRepository`: Wetterqualität und Logo-Erkennung.
 *
 * `CameraDataConnection` hält dagegen die eine gemeinsame Verbindung, Busy-
 * Retry, Schreiblock und CIFS-Staging. Bestehende Aufrufer verwenden weiterhin
 * diese Fassade; dadurch ist die Entzerrung kein gleichzeitiger API-Umbau.
 */
final class CameraDataStore
{
  private CameraDataSchemaRepository $schema;
  private CameraDataConnection $connection;
  private CameraCaptureRepository $captures;
  private CameraLikeBestOfRepository $likes;
  private CameraWeatherLogoRepository $weatherLogo;

  public function __construct(
    private readonly App $app,
    private readonly Camera $camera,
    private readonly ?string $dbPathOverride = null,
    private readonly ?bool $readOnlyOverride = null
  ) {
    $schema = new CameraDataSchemaRepository();
    $connection = new CameraDataConnection(
      $app,
      $camera,
      $schema,
      $dbPathOverride,
      $readOnlyOverride
    );
    $this->compose($schema, $connection);
  }

  /** Verdrahtet alle Repositories auf dieselbe Connection und dasselbe Schema. */
  private function compose(
    CameraDataSchemaRepository $schema,
    CameraDataConnection $connection
  ): void {
    $this->schema = $schema;
    $this->connection = $connection;
    $this->weatherLogo = new CameraWeatherLogoRepository($connection, $schema);
    $this->captures = new CameraCaptureRepository($connection, $this->weatherLogo);
    $this->likes = new CameraLikeBestOfRepository($connection);
  }

  /** Erzeugt nur für den kontrollierten Staging-Ablauf eine Fassade zur Kopie. */
  private static function forConnection(
    App $app,
    Camera $camera,
    CameraDataSchemaRepository $schema,
    CameraDataConnection $connection
  ): self {
    $store = new self($app, $camera);
    $store->compose($schema, $connection);
    return $store;
  }

  // -----------------------------------------------------------------------
  // Verbindung und öffentlicher Lebenszyklus.
  // -----------------------------------------------------------------------

  public function pdo(): PDO
  {
    return $this->connection->pdo();
  }

  public function dbPath(): string
  {
    return $this->connection->dbPath();
  }

  public function ensureWritableSchema(): void
  {
    $this->connection->ensureWritableSchema();
  }

  public function close(): void
  {
    $this->connection->close();
  }

  public function __destruct()
  {
    $this->close();
  }

  // -----------------------------------------------------------------------
  // Capture-Fassade.
  // -----------------------------------------------------------------------

  public function recordCapture(array $data): int
  {
    return $this->captures->recordCapture($data);
  }

  public function latestUsableHashBeforeSlot(int $slotTimestamp): ?string
  {
    return $this->captures->latestUsableHashBeforeSlot($slotTimestamp);
  }

  public function setCaptureDuplicate(int $captureId, bool $duplicate): bool
  {
    return $this->captures->setCaptureDuplicate($captureId, $duplicate);
  }

  /** @param list<array{0:int,1:bool}> $changes */
  public function setCaptureDuplicates(array $changes): int
  {
    return $this->captures->setCaptureDuplicates($changes);
  }

  /**
   * @param list<array{0:int,1:bool}> $captureChanges
   * @param list<array{0:int,1:int,2:int}> $variantChanges
   * @return array{captures:int,variants:int}
   */
  public function reconcileCaptureImageState(array $captureChanges, array $variantChanges): array
  {
    return $this->captures->reconcileCaptureImageState($captureChanges, $variantChanges);
  }

  public function findCaptureIdByRelativePath(string $relativePath): ?int
  {
    return $this->captures->findCaptureIdByRelativePath($relativePath);
  }

  public function findCaptureIdByAnyPath(string $relativePath): ?int
  {
    return $this->captures->findCaptureIdByAnyPath($relativePath);
  }

  public function relativePathByCaptureId(int $captureId): ?string
  {
    return $this->captures->relativePathByCaptureId($captureId);
  }

  public function findCaptureIdBySlot(int $slotTimestamp): ?int
  {
    return $this->captures->findCaptureIdBySlot($slotTimestamp);
  }

  public function normalizeRelativePath(string $path): string
  {
    return $this->captures->normalizeRelativePath($path);
  }

  /** @return list<int> */
  public function years(): array
  {
    return $this->captures->years();
  }

  /** @return list<array<string,mixed>> */
  public function capturesForRange(int $fromTs, int $toTs, ?int $maxRows = null): array
  {
    return $this->captures->capturesForRange($fromTs, $toTs, $maxRows);
  }

  public function sampledCapturesForRange(int $from, int $to, int $limit): array
  {
    return $this->captures->sampledCapturesForRange($from, $to, $limit);
  }

  public function rangeContainsOnlyDuplicateCaptures(int $fromTs, int $toTs): bool
  {
    return $this->captures->rangeContainsOnlyDuplicateCaptures($fromTs, $toTs);
  }

  /** @return list<int> */
  public function duplicateCaptureSlotsForRange(int $fromTs, int $toTs): array
  {
    return $this->captures->duplicateCaptureSlotsForRange($fromTs, $toTs);
  }

  public function nearestCaptureSlot(int $targetTs, int $startTs, int $endTs): ?int
  {
    return $this->captures->nearestCaptureSlot($targetTs, $startTs, $endTs);
  }

  public function captureTimeBounds(int $until): ?array
  {
    return $this->captures->captureTimeBounds($until);
  }

  // -----------------------------------------------------------------------
  // Like-/Best-Of-Fassade.
  // -----------------------------------------------------------------------

  public function recordLike(
    int $captureId,
    string $sessionId,
    int $windowSeconds,
    int $maxPerWindow
  ): array {
    return $this->likes->recordLike($captureId, $sessionId, $windowSeconds, $maxPerWindow);
  }

  public function likesCountById(int $captureId): ?int
  {
    return $this->likes->likesCountById($captureId);
  }

  /** @return array<int,int> */
  public function likesByRange(int $fromTs, int $toTs): array
  {
    return $this->likes->likesByRange($fromTs, $toTs);
  }

  /** @return list<array{relative_path:string,thumb_rel:string,likes:int,slot_ts:int}> */
  public function topLiked(int $limit = 20): array
  {
    return $this->likes->topLiked($limit);
  }

  // -----------------------------------------------------------------------
  // Wetter-/Logo-Fassade.
  // -----------------------------------------------------------------------

  /** @param list<string> $lines */
  public function upsertWeatherForSlot(int $slotTimestamp, array $lines): bool
  {
    return $this->weatherLogo->upsertWeatherForSlot($slotTimestamp, $lines);
  }

  /** @return array{detected:bool,score:?float}|null */
  public function logoStatusById(int $captureId): ?array
  {
    return $this->weatherLogo->logoStatusById($captureId);
  }

  public function upsertLogoStatus(
    int $captureId,
    bool $detected,
    ?float $score,
    ?int $checkedAt = null
  ): bool {
    return $this->weatherLogo->upsertLogoStatus($captureId, $detected, $score, $checkedAt);
  }

  // -----------------------------------------------------------------------
  // Gemeinsame Lock-/Staging-Grenze für bestehende Batch-Aufrufer und Tests.
  // -----------------------------------------------------------------------

  /** @template T @param callable():T $callback @return T */
  public function withWriteLock(callable $callback)
  {
    return $this->connection->withWriteLock($callback);
  }

  /**
   * Kompatibilitätsgrenze für den getesteten Staging-Ablauf. Fachrepositories
   * verwenden direkt `CameraDataConnection::write()`.
   *
   * @template T
   * @param callable(self):T $mutator
   * @return T
   */
  private function withStagedWrite(callable $mutator, string $operation)
  {
    return $this->connection->withStagedWrite(
      function (CameraDataConnection $connection) use ($mutator) {
        $stagingStore = self::forConnection($this->app, $this->camera, $this->schema, $connection);
        return $mutator($stagingStore);
      },
      $operation
    );
  }

}
