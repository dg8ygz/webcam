<?php
declare(strict_types=1);

namespace Webcam\Infrastructure\Storage;

use PDO;

/**
 * Bündelt Like-Transaktionen und die daraus abgeleiteten Best-Of-Abfragen.
 *
 * Leseführung:
 * 1. `recordLike()` prüft Sessionduplikat und Zeitfenster in einer Transaktion.
 * 2. Kleine Zählerabfragen versorgen Timeline und Bildpräsentation.
 * 3. `topLiked()` liefert ausschließlich positive, verwendbare Captures und
 *    wählt dafür die beste verfügbare Anzeigevariante.
 */
final class CameraLikeBestOfRepository
{
  public function __construct(private readonly CameraDataConnection $connection) {}

  /**
   * @return array{ok:bool,code?:string,message?:string,likes_count?:int}
   */
  public function recordLike(
    int $captureId,
    string $sessionId,
    int $windowSeconds,
    int $maxPerWindow
  ): array {
    return $this->connection->write(
      fn(CameraDataConnection $connection): array => $this->recordLikeDirect(
        $connection,
        $captureId,
        $sessionId,
        $windowSeconds,
        $maxPerWindow
      ),
      'record_like'
    );
  }

  /** Führt die komplette Like-Entscheidung innerhalb einer SQLite-Transaktion aus. */
  private function recordLikeDirect(
    CameraDataConnection $connection,
    int $captureId,
    string $sessionId,
    int $windowSeconds,
    int $maxPerWindow
  ): array {
    $pdo = $connection->pdo();
    $now = time();
    $windowStart = $now - $windowSeconds;

    $connection->beginWriteTransaction($pdo);
    try {
      // Ein Bild darf von derselben Session dauerhaft nur einmal geliked werden.
      $duplicate = $pdo->prepare(
        'SELECT id FROM likes_log WHERE capture_id = :capture_id AND session_id = :session_id LIMIT 1'
      );
      $duplicate->execute([
        ':capture_id' => $captureId,
        ':session_id' => $sessionId,
      ]);
      if ($duplicate->fetchColumn()) {
        $pdo->rollBack();
        return [
          'ok' => false,
          'code' => 'duplicate_image',
          'message' => 'Dieses Bild wurde bereits geliked',
        ];
      }

      // Das bewegliche Zeitfenster gilt pro Kamera und Session.
      $countStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM likes_log
         WHERE session_id = :session_id
           AND camera_slug = :camera_slug
           AND created_at >= :window_start'
      );
      $countStatement->execute([
        ':session_id' => $sessionId,
        ':camera_slug' => $connection->cameraSlug(),
        ':window_start' => $windowStart,
      ]);
      if ((int) $countStatement->fetchColumn() >= $maxPerWindow) {
        $pdo->rollBack();
        return [
          'ok' => false,
          'code' => 'rate_limited',
          'message' => 'Likes-Limit erreicht',
        ];
      }

      $insert = $pdo->prepare(
        'INSERT INTO likes_log (capture_id, camera_slug, session_id, created_at, hour_bucket)
         VALUES (:capture_id, :camera_slug, :session_id, :created_at, :hour_bucket)'
      );
      $insert->execute([
        ':capture_id' => $captureId,
        ':camera_slug' => $connection->cameraSlug(),
        ':session_id' => $sessionId,
        ':created_at' => $now,
        ':hour_bucket' => intdiv($now, 3600),
      ]);

      $update = $pdo->prepare(
        'UPDATE captures SET likes_count = likes_count + 1 WHERE id = :capture_id'
      );
      $update->execute([':capture_id' => $captureId]);
      $count = $pdo->prepare('SELECT likes_count FROM captures WHERE id = :capture_id');
      $count->execute([':capture_id' => $captureId]);
      $likesCount = (int) $count->fetchColumn();

      $pdo->commit();
      return ['ok' => true, 'likes_count' => $likesCount];
    } catch (\Throwable $exception) {
      $connection->rollBackQuietly($pdo);
      throw $exception;
    }
  }

  public function likesCountById(int $captureId): ?int
  {
    return $this->connection->read(function (PDO $pdo) use ($captureId): ?int {
      $statement = $pdo->prepare('SELECT likes_count FROM captures WHERE id = :id');
      $statement->execute([':id' => $captureId]);
      $value = $statement->fetchColumn();
      return $value === false ? null : (int) $value;
    });
  }

  /** @return array<int,int> Map slot_ts => likes_count, nur positive Werte. */
  public function likesByRange(int $fromTimestamp, int $toTimestamp): array
  {
    return $this->connection->read(function (PDO $pdo) use ($fromTimestamp, $toTimestamp): array {
      $statement = $pdo->prepare(
        'SELECT slot_ts, likes_count FROM captures
         WHERE slot_ts BETWEEN :from_timestamp AND :to_timestamp
           AND likes_count > 0
           AND is_duplicate = 0'
      );
      $statement->execute([
        ':from_timestamp' => $fromTimestamp,
        ':to_timestamp' => $toTimestamp,
      ]);

      $result = [];
      foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $slotTimestamp = (int) ($row['slot_ts'] ?? 0);
        if ($slotTimestamp > 0) {
          $result[$slotTimestamp] = (int) ($row['likes_count'] ?? 0);
        }
      }
      return $result;
    });
  }

  /** @return list<array{relative_path:string,thumb_rel:string,likes:int,slot_ts:int}> */
  public function topLiked(int $limit = 20): array
  {
    return $this->connection->read(function (PDO $pdo) use ($limit): array {
      $statement = $pdo->prepare(
        "SELECT c.slot_ts,
                c.relative_path,
                COALESCE(c.likes_count, 0) AS likes,
                (SELECT v.relative_path
                   FROM variants v
                  WHERE v.capture_id = c.id
                    AND v.variant IN ('la', 'lm', 'full', 'sm', 'hu')
                  ORDER BY CASE v.variant
                    WHEN 'la' THEN 1
                    WHEN 'lm' THEN 2
                    WHEN 'full' THEN 3
                    WHEN 'hu' THEN 4
                    WHEN 'sm' THEN 5
                    ELSE 6 END
                  LIMIT 1) AS variant_rel
           FROM captures c
          WHERE COALESCE(c.likes_count, 0) > 0
            AND c.is_duplicate = 0
          ORDER BY likes DESC, slot_ts DESC
          LIMIT :limit"
      );
      $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
      $statement->execute();

      $result = [];
      foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $relativePath = (string) ($row['variant_rel'] ?? $row['relative_path'] ?? '');
        if ($relativePath === '') {
          continue;
        }
        $result[] = [
          'relative_path' => $relativePath,
          'thumb_rel' => $relativePath,
          'likes' => (int) ($row['likes'] ?? 0),
          'slot_ts' => (int) ($row['slot_ts'] ?? 0),
        ];
      }
      return $result;
    });
  }
}
