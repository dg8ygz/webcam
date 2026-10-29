<?php
declare(strict_types=1);

namespace Webcam\Service\Security;

use PDO;
use PDOException;
use Throwable;
use Webcam\App;

/** SQLite-Limiter mit gehashten, vom jeweiligen HTTP-Adapter bestimmten Subjekten. */
final class PublicRateLimiter
{
  /** @var list<string>|null */
  private readonly ?array $databaseCandidates;

  /**
   * @param list<string>|null $databaseCandidates Nur für isolierte Tests; im
   *   Betrieb werden der lokale Sollpfad und der State-Fallback verwendet.
   */
  public function __construct(private readonly App $app, ?array $databaseCandidates = null)
  {
    $this->databaseCandidates = $databaseCandidates;
  }

  /**
   * Zählt Prüfung und Zugriff in derselben Transaktion. Auch abgelehnte Zugriffe
   * erhöhen den Bucket; Storagefehler werden dem HTTP-Adapter weitergegeben.
   * Nur Lock-Konflikte erhalten einen begrenzten Retry, niemals einen Freipass.
   *
   * @param array{limit?:mixed,window_seconds?:mixed,ttl_days?:mixed} $settings
   * @return array{allowed:bool,limit:int,remaining:int,retry_after:int}
   */
  public function consume(string $scope, string $subject, array $settings): array
  {
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $scope)) {
      throw new \InvalidArgumentException('Invalid rate-limit scope');
    }
    if ($subject === '') {
      throw new \InvalidArgumentException('Rate-limit subject is empty');
    }

    $limit = max(1, (int) ($settings['limit'] ?? 120));
    $window = max(5, (int) ($settings['window_seconds'] ?? 60));
    $ttlDays = max(1, (int) ($settings['ttl_days'] ?? 7));
    $subjectHash = hash('sha256', $scope . "\0" . $subject);
    $dbPath = $this->databasePath();
    $dir = dirname($dbPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new \RuntimeException('Rate-limit database directory unavailable');
    }

    $now = time();
    $bucket = (int) floor($now / $window);
    $expiresAt = max(($bucket + 1) * $window, $now + $ttlDays * 86400);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
      $pdo = null;
      try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_TIMEOUT => 5]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->beginTransaction();
        $this->ensureSchema($pdo, max($expiresAt, $now + 7 * 86400));
        if ($this->shouldPrune($pdo, $now)) {
          $pdo->prepare('DELETE FROM hits WHERE expires_at <= :now')->execute([':now' => $now]);
          $this->markPruned($pdo, $now);
        }
        $pdo->prepare('INSERT INTO hits (sid, bucket, count, expires_at) VALUES (:sid, :bucket, 1, :expires_at)
          ON CONFLICT(sid, bucket) DO UPDATE SET count = count + 1,
            expires_at = MAX(hits.expires_at, excluded.expires_at)')
          ->execute([':sid' => $subjectHash, ':bucket' => $bucket, ':expires_at' => $expiresAt]);
        $countStmt = $pdo->prepare('SELECT count FROM hits WHERE sid = :sid AND bucket = :bucket');
        $countStmt->execute([':sid' => $subjectHash, ':bucket' => $bucket]);
        $count = (int) $countStmt->fetchColumn();
        $pdo->commit();
        $retryAfter = max(1, ($bucket + 1) * $window - $now);
        return [
          'allowed' => $count <= $limit,
          'limit' => $limit,
          'remaining' => max(0, $limit - $count),
          'retry_after' => $retryAfter,
        ];
      } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
          try {
            $pdo->rollBack();
          } catch (Throwable) {
            // Best effort only.
          }
        }
        if ($this->isBusy($exception) && $attempt < 3) {
          usleep(150000 * $attempt);
          continue;
        }
        throw $exception;
      }
    }

    throw new \RuntimeException('Rate-limit attempts exhausted');
  }

  private function databasePath(): string
  {
    $override = getenv('WEBCAM_PUBLIC_RATE_DB');
    if (is_string($override) && trim($override) !== '') {
      return trim($override);
    }
    $candidates = $this->databaseCandidates ?? [
      '/var/lib/webcam/ratelimit_public.sqlite',
      $this->app->paths()->statePath('ratelimit_public.sqlite'),
    ];
    foreach ($candidates as $candidate) {
      if (is_string($candidate) && $candidate !== '' && $this->pathUsable($candidate)) {
        return $candidate;
      }
    }
    // Rate-Limits dürfen bei einem Storage-/Rechtefehler nicht unbemerkt
    // deaktiviert werden. Eine klare Exception erzeugt den vorgesehenen
    // fail-closed API-Fehler und vermeidet den früheren ungültigen Arrayindex.
    throw new \RuntimeException('No usable public rate-limit database path');
  }

  private function pathUsable(string $path): bool
  {
    if (is_file($path)) {
      return is_writable($path);
    }
    $dir = dirname($path);
    return (is_dir($dir) || is_link($dir)) && is_writable($dir);
  }

  /** Migriert innerhalb der Consume-Transaktion, ohne alte Zähler zurückzusetzen. */
  private function ensureSchema(PDO $pdo, int $legacyExpiresAt): void
  {
    $pdo->exec('CREATE TABLE IF NOT EXISTS hits (
      sid TEXT NOT NULL, bucket INTEGER NOT NULL, count INTEGER NOT NULL DEFAULT 0,
      expires_at INTEGER NOT NULL DEFAULT 0,
      PRIMARY KEY (sid, bucket))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $columns = $pdo->query('PRAGMA table_info(hits)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('expires_at', $columns, true)) {
      $pdo->exec('ALTER TABLE hits ADD COLUMN expires_at INTEGER NOT NULL DEFAULT 0');
    }
    // Alte Buckets besitzen keine rekonstruierbare Zeiteinheit. Nicht löschen
    // oder neu zählen: mindestens eine volle bisherige Standard-TTL erhalten.
    $pdo->prepare('UPDATE hits SET expires_at = :expiry WHERE expires_at = 0')
      ->execute([':expiry' => $legacyExpiresAt]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits_expiry ON hits(expires_at)');
  }

  /** Teilt das 30-Minuten-Aufräumbudget über die DB statt über einzelne Worker. */
  private function shouldPrune(PDO $pdo, int $now): bool
  {
    $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => 'last_pruned_at']);
    $value = $stmt->fetchColumn();
    return $value === false || !is_numeric($value) || ((int) $value + 1800) <= $now;
  }

  /** Wird mit dem DELETE gemeinsam committed oder bei einem Fehler zurückgerollt. */
  private function markPruned(PDO $pdo, int $now): void
  {
    $pdo->prepare('INSERT INTO meta (key, value) VALUES (:key, :value)
      ON CONFLICT(key) DO UPDATE SET value = excluded.value')
      ->execute([':key' => 'last_pruned_at', ':value' => (string) $now]);
  }

  private function isBusy(Throwable $exception): bool
  {
    if ($exception instanceof PDOException) {
      $info = $exception->errorInfo;
      if (is_array($info) && isset($info[1]) && is_numeric($info[1]) && in_array((int) $info[1], [5, 6, 15], true)) {
        return true;
      }
    }
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'database is locked')
      || str_contains($message, 'database locked')
      || str_contains($message, 'busy')
      || str_contains($message, 'locking protocol');
  }
}
