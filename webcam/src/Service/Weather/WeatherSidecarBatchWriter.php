<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/** Schreibt validierte Wetterzeilen batchweise unter dem Kamera-DB-Lock. */
final class WeatherSidecarBatchWriter
{
  /** @var resource */
  private $lock;
  private readonly PDOStatement $delete;
  private readonly PDOStatement $insert;

  public function __construct(private readonly PDO $pdo, string $dbPath)
  {
    $lockPath = $dbPath . '.write.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
      throw new RuntimeException('Unable to open camera DB write lock: ' . $lockPath);
    }
    // Kamera-DB und Lock teilen den geschützten 2770-Root. Alle vorgesehenen
    // Schreiber sind dessen Gruppe zugeordnet; andere lokale Benutzer dürfen
    // den Lock nicht verändern.
    @chmod($lockPath, 0660);
    $this->lock = $lock;
    $this->delete = $pdo->prepare('DELETE FROM weather_entries WHERE capture_id = :capture_id');
    $this->insert = $pdo->prepare('INSERT INTO weather_entries (capture_id, key, value, position) VALUES (:capture_id, :key, :value, :position)');
  }

  /** @param list<array{capture_id:int,lines:list<string>}> $items */
  public function replace(array $items): int
  {
    if ($items === []) {
      return 0;
    }

    // Dateilock und SQLite-Transaktion umfassen immer denselben vollständigen
    // Batch. Bei SQLITE_BUSY wird beides aufgeräumt, bevor der nächste Versuch
    // mit begrenztem Backoff beginnt.
    for ($attempt = 1; ; $attempt++) {
      $locked = false;
      try {
        flock($this->lock, LOCK_EX);
        $locked = true;
        $this->pdo->beginTransaction();
        foreach ($items as $item) {
          $this->delete->execute([':capture_id' => $item['capture_id']]);
          foreach (array_values($item['lines']) as $position => $line) {
            $this->insert->execute([':capture_id' => $item['capture_id'], ':key' => 'line', ':value' => $line, ':position' => $position]);
          }
        }
        $this->pdo->commit();
        flock($this->lock, LOCK_UN);
        return count($items);
      } catch (PDOException $exception) {
        $this->rollbackAndUnlock($locked);
        if (!$this->isBusy($exception) || $attempt >= 20) {
          throw $exception;
        }
        usleep(min(250000 * $attempt, 1500000));
      } catch (Throwable $exception) {
        $this->rollbackAndUnlock($locked);
        throw $exception;
      }
    }
  }

  public function close(): void
  {
    if (is_resource($this->lock)) {
      fclose($this->lock);
    }
  }

  private function rollbackAndUnlock(bool $locked): void
  {
    if ($this->pdo->inTransaction()) {
      $this->pdo->rollBack();
    }
    if ($locked && is_resource($this->lock)) {
      flock($this->lock, LOCK_UN);
    }
  }

  private function isBusy(PDOException $exception): bool
  {
    $code = is_array($exception->errorInfo) && is_numeric($exception->errorInfo[1] ?? null)
      ? (int) $exception->errorInfo[1]
      : null;
    $message = strtolower($exception->getMessage());
    return in_array($code, [5, 6, 15], true)
      || str_contains($message, 'database is locked')
      || str_contains($message, 'database locked')
      || str_contains($message, 'busy')
      || str_contains($message, 'locking protocol');
  }
}
