<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;
use RuntimeException;

/**
 * Schreibt Captures, Varianten und EXIF in einer gemeinsamen Transaktion.
 *
 * Wetterzeilen werden während recordCapture über das Wetter-/Logo-Repository
 * ergänzt, damit eine Aufnahme vollständig oder gar nicht sichtbar wird.
 * Duplikat- und Reconcile-Änderungen verwenden denselben Kamera-Schreiblock.
 */
final class CameraCaptureWriter
{
  public function __construct(
    private readonly CameraDataConnection $connection,
    private readonly CameraWeatherLogoRepository $weatherLogo
  ) {}

  // -----------------------------------------------------------------------
  // Capture schreiben und vorhandenen Bildbestand abgleichen.
  // -----------------------------------------------------------------------

  /** Schreibt eine vollständige Aufnahme unter dem gemeinsamen Kamera-Lock. */
  public function recordCapture(array $data): int
  {
    return $this->connection->write(
      fn(CameraDataConnection $connection): int => $this->recordCaptureDirect($connection, $data),
      'record_capture'
    );
  }

  private function recordCaptureDirect(CameraDataConnection $connection, array $data): int
  {
    $pdo = $connection->pdo();
    $connection->beginWriteTransaction($pdo);
    try {
      $slotTimestamp = (int) ($data['slot_timestamp'] ?? time());
      $statement = $pdo->prepare(
        'INSERT INTO captures (
           slot_ts, captured_ts, relative_path, luminance, hash_sha256, is_duplicate, created_at
         ) VALUES (
           :slot_timestamp, :captured_timestamp, :relative_path, :luminance,
           :hash, :is_duplicate, :created_at
         )
         ON CONFLICT(slot_ts) DO UPDATE SET
           captured_ts = excluded.captured_ts,
           relative_path = excluded.relative_path,
           luminance = excluded.luminance,
           hash_sha256 = COALESCE(excluded.hash_sha256, captures.hash_sha256),
           is_duplicate = excluded.is_duplicate'
      );
      $statement->execute([
        ':slot_timestamp' => $slotTimestamp,
        ':captured_timestamp' => isset($data['captured_timestamp'])
          ? (int) $data['captured_timestamp']
          : null,
        ':relative_path' => (string) ($data['relative_path'] ?? ''),
        ':luminance' => isset($data['luminance']) ? (float) $data['luminance'] : null,
        ':hash' => $data['hash'] ?? null,
        ':is_duplicate' => !empty($data['is_duplicate']) ? 1 : 0,
        ':created_at' => time(),
      ]);

      $idStatement = $pdo->prepare('SELECT id FROM captures WHERE slot_ts = :slot_timestamp');
      $idStatement->execute([':slot_timestamp' => $slotTimestamp]);
      $captureId = (int) $idStatement->fetchColumn();
      if ($captureId <= 0) {
        throw new RuntimeException(
          'Unable to resolve capture id after upsert for slot ' . $slotTimestamp
        );
      }

      if (isset($data['deferred_recipe'])) {
        // Kein implizites CREATE TABLE im Upload: Migration vorher freigeben.
        $recipe = $data['deferred_recipe'];
        $insertRecipe = $pdo->prepare('INSERT INTO deferred_images
          (image_key, slot_ts, source_hash, source_bytes, recipe_json, width, height, created_at)
          VALUES (:image_key, :slot_ts, :source_hash, :source_bytes, :recipe_json, :width, :height, :created_at)');
        $insertRecipe->execute($recipe);
      }

      $this->replaceVariants($pdo, $captureId, $data['variants'] ?? []);
      $this->replaceExif($pdo, $captureId, $data['exif'] ?? []);
      if (array_key_exists('weather_lines', $data) && $data['weather_lines'] !== null) {
        $this->weatherLogo->replaceWeather($pdo, $captureId, $data['weather_lines']);
      }

      $pdo->commit();
      return $captureId;
    } catch (\Throwable $exception) {
      $connection->rollBackQuietly($pdo);
      throw $exception;
    }
  }

  /**
   * @param list<array{variant:string,relative_path:string,width?:int,height?:int,size?:int,mtime?:int}> $variants
   */
  private function replaceVariants(PDO $pdo, int $captureId, array $variants): void
  {
    $pdo->prepare('DELETE FROM variants WHERE capture_id = :id')->execute([':id' => $captureId]);
    if ($variants === []) {
      return;
    }

    $statement = $pdo->prepare(
      'INSERT INTO variants (
         capture_id, variant, width, height, relative_path, size_bytes, mtime
       ) VALUES (
         :capture_id, :variant, :width, :height, :relative_path, :size_bytes, :mtime
       )'
    );
    foreach ($variants as $variant) {
      $statement->execute([
        ':capture_id' => $captureId,
        ':variant' => (string) ($variant['variant'] ?? ''),
        ':width' => $variant['width'] ?? null,
        ':height' => $variant['height'] ?? null,
        ':relative_path' => (string) ($variant['relative_path'] ?? ''),
        ':size_bytes' => $variant['size'] ?? null,
        ':mtime' => $variant['mtime'] ?? null,
      ]);
    }
  }

  /** @param array<string,string> $exif */
  private function replaceExif(PDO $pdo, int $captureId, array $exif): void
  {
    $pdo->prepare('DELETE FROM exif_entries WHERE capture_id = :id')->execute([':id' => $captureId]);
    if ($exif === []) {
      return;
    }

    $statement = $pdo->prepare(
      'INSERT INTO exif_entries (capture_id, tag, value, sort_order)
       VALUES (:capture_id, :tag, :value, :sort_order)'
    );
    $sortOrder = 0;
    foreach ($exif as $tag => $value) {
      $statement->execute([
        ':capture_id' => $captureId,
        ':tag' => (string) $tag,
        ':value' => (string) $value,
        ':sort_order' => $sortOrder++,
      ]);
    }
  }

  /** Liefert den letzten verwendbaren Hash vor dem angegebenen Slot. */
  public function latestUsableHashBeforeSlot(int $slotTimestamp): ?string
  {
    return $this->connection->read(function (PDO $pdo) use ($slotTimestamp): ?string {
      $statement = $pdo->prepare(
        "SELECT hash_sha256 FROM captures
         WHERE slot_ts < :slot_timestamp
           AND is_duplicate = 0
           AND hash_sha256 IS NOT NULL
           AND hash_sha256 <> ''
         ORDER BY slot_ts DESC
         LIMIT 1"
      );
      $statement->execute([':slot_timestamp' => $slotTimestamp]);
      $hash = $statement->fetchColumn();
      return is_string($hash) && $hash !== '' ? $hash : null;
    });
  }

  public function setCaptureDuplicate(int $captureId, bool $duplicate): bool
  {
    return $this->setCaptureDuplicates([[$captureId, $duplicate]]) === 1;
  }

  /** @param list<array{0:int,1:bool}> $changes */
  public function setCaptureDuplicates(array $changes): int
  {
    if ($changes === []) {
      return 0;
    }

    return $this->connection->write(
      function (CameraDataConnection $connection) use ($changes): int {
        $pdo = $connection->pdo();
        $connection->beginWriteTransaction($pdo);
        try {
          $statement = $pdo->prepare(
            'UPDATE captures SET is_duplicate = :duplicate WHERE id = :id'
          );
          foreach ($changes as [$captureId, $duplicate]) {
            $statement->execute([
              ':duplicate' => $duplicate ? 1 : 0,
              ':id' => $captureId,
            ]);
            if ($statement->rowCount() <= 0) {
              throw new RuntimeException(
                "Capture not found while setting duplicate flag: {$captureId}"
              );
            }
          }
          $pdo->commit();
          return count($changes);
        } catch (\Throwable $exception) {
          $connection->rollBackQuietly($pdo);
          throw $exception;
        }
      },
      'set_capture_duplicates',
      false
    );
  }

  /**
   * @param list<array{0:int,1:bool}> $captureChanges
   * @param list<array{0:int,1:int,2:int}> $variantChanges
   * @return array{captures:int,variants:int}
   */
  public function reconcileCaptureImageState(array $captureChanges, array $variantChanges): array
  {
    if ($captureChanges === [] && $variantChanges === []) {
      return ['captures' => 0, 'variants' => 0];
    }

    return $this->connection->write(
      function (CameraDataConnection $connection) use ($captureChanges, $variantChanges): array {
        $pdo = $connection->pdo();
        $connection->beginWriteTransaction($pdo);
        try {
          $captureStatement = $pdo->prepare(
            'UPDATE captures SET is_duplicate = :duplicate WHERE id = :id'
          );
          foreach ($captureChanges as [$captureId, $duplicate]) {
            $captureStatement->execute([
              ':duplicate' => $duplicate ? 1 : 0,
              ':id' => $captureId,
            ]);
            if ($captureStatement->rowCount() <= 0) {
              throw new RuntimeException(
                "Capture not found while reconciling image state: {$captureId}"
              );
            }
          }

          $variantStatement = $pdo->prepare(
            'UPDATE variants SET size_bytes = :size_bytes, mtime = :mtime WHERE id = :id'
          );
          foreach ($variantChanges as [$variantId, $sizeBytes, $mtime]) {
            $variantStatement->execute([
              ':size_bytes' => max(0, $sizeBytes),
              ':mtime' => max(0, $mtime),
              ':id' => $variantId,
            ]);
            if ($variantStatement->rowCount() <= 0) {
              throw new RuntimeException(
                "Variant not found while reconciling image state: {$variantId}"
              );
            }
          }

          $pdo->commit();
          return [
            'captures' => count($captureChanges),
            'variants' => count($variantChanges),
          ];
        } catch (\Throwable $exception) {
          $connection->rollBackQuietly($pdo);
          throw $exception;
        }
      },
      'reconcile_capture_image_state',
      false
    );
  }
}
