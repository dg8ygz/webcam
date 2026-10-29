<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use DateTimeZone;
use PDO;
use Throwable;

/** Koordiniert den read-only Dry-Run oder batchweisen Wetter-Sidecar-Import. */
final class WeatherSidecarImporter
{
  public function __construct(private readonly WeatherSidecarParser $parser) {}

  /**
   * @param array<int,bool> $years @param array<int,bool> $months
   * @return array{stats:array<string,int>,years:list<int>}
   */
  public function run(string $base, string $dbPath, DateTimeZone $timezone, array $years, array $months, bool $write, int $batchSize, bool $verbose): array
  {
    // Das Capture-Statement bleibt während des gesamten Scans vorbereitet.
    // Einen Writer (und damit den Lock-Inode) gibt es nur im Schreibmodus.
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $select = $pdo->prepare('SELECT id FROM captures WHERE slot_ts = :slot_ts');
    $writer = $write ? new WeatherSidecarBatchWriter($pdo, $dbPath) : null;
    $stats = ['files' => 0, 'imported' => 0, 'dry_run_importable' => 0, 'missing_capture' => 0, 'empty' => 0, 'implausible' => 0, 'unreadable' => 0, 'bad_path' => 0];
    $yearsSeen = [];
    $pending = [];
    try {
      foreach ($this->parser->files($base, $years, $months) as $file) {
        $stats['files']++;

        // Pfad und Inhalt werden vor jeder Datenbankzuordnung validiert. Ein
        // defektes Sidecar darf dadurch keinen vorhandenen Wetterwert ersetzen.
        $metadata = $this->parser->pathMetadata($file, $timezone);
        if ($metadata === null) {
          $stats['bad_path']++;
          continue;
        }
        $yearsSeen[$metadata['year']] = true;
        $lines = $this->parser->lines($file);
        if ($lines === null) {
          $stats['unreadable']++;
          continue;
        }
        if ($lines === []) {
          $stats['empty']++;
          continue;
        }
        $validation = $this->parser->validate($lines);
        if (!$validation['ok']) {
          $stats['implausible']++;
          if ($verbose) {
            printf("[SKIP] implausible file=%s reason=%s\n", $file, $validation['reason']);
          }
          continue;
        }

        // Sidecars ohne Capture bleiben unangetastet und sichtbar in der
        // Statistik. So erfindet der Import keine historischen Bildzeilen.
        $select->execute([':slot_ts' => $metadata['slot_ts']]);
        $captureId = $select->fetchColumn();
        if ($captureId === false || (int) $captureId <= 0) {
          $stats['missing_capture']++;
          continue;
        }
        if (!$write) {
          $stats['dry_run_importable']++;
        } else {
          $pending[] = ['capture_id' => (int) $captureId, 'lines' => $lines];
          if (count($pending) >= $batchSize) {
            $stats['imported'] += $writer->replace($pending);
            $pending = [];
          }
        }
        if ($verbose && $stats['files'] % 10000 === 0) {
          printf("[INFO] files=%d importable=%d imported=%d missing_capture=%d\n", $stats['files'], $stats['dry_run_importable'], $stats['imported'], $stats['missing_capture']);
        }
      }
      if ($writer !== null && $pending !== []) {
        $stats['imported'] += $writer->replace($pending);
      }
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    } finally {
      $writer?->close();
    }
    ksort($yearsSeen);
    return ['stats' => $stats, 'years' => array_map('intval', array_keys($yearsSeen))];
  }
}
