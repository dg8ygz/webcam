<?php
declare(strict_types=1);

namespace Webcam\Service\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Kapselt die Datenbankarbeit des Admin-Capture-Editors.
 *
 * Der Service kennt keine Request-Parameter und erzeugt keine HTTP-Antworten.
 * Er liest Captures samt abhängigen Tabellen und ersetzt Änderungen unter dem
 * vorhandenen Kamera-Schreiblock in genau einer SQLite-Transaktion.
 * CapturePayloadNormalizer muss Eingaben vorher geprüft haben.
 *
 * Leseführung:
 * 1. preview() liefert eine kleine Liste für die Admin-Seitenleiste,
 * 2. detail() lädt einen ausgewählten Slot vollständig,
 * 3. update() ersetzt Hauptzeile und gesendete Kindlisten atomar,
 * 4. serializeCapture() baut das stabile JSON-Modell der Admin-API.
 */
final class CameraCaptureEditor
{
  public function __construct(private readonly App $app) {}

  /**
   * Lädt die jüngsten Captures einer Kamera für die kompakte DB-Vorschau.
   * Vollständige Varianten werden erst beim Öffnen eines Eintrags geladen.
   *
   * @return list<array<string,mixed>>
   */
  public function preview(string $slug, int $limit = 5): array
  {
    $camera = $this->app->cameras()->find($slug);
    if ($camera === null) {
      throw new RuntimeException("camera not found: {$slug}");
    }

    // Auch direkte Service-Aufrufe dürfen keine unbeschränkt große Vorschau
    // auslösen. Die HTTP-Route begrenzt ebenfalls, diese Grenze ist die zweite
    // Verteidigungsschicht an der Datenbank.
    $limit = max(1, min(50, $limit));
    $store = new CameraDataStore($this->app, $camera, null, true);
    $pdo = $store->pdo();
    $statement = $pdo->prepare(
      'SELECT id, slot_ts, captured_ts, relative_path, luminance, '
      . 'hash_sha256, likes_count, created_at '
      . 'FROM captures ORDER BY slot_ts DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $exifStatement = $pdo->prepare(
      'SELECT tag, value FROM exif_entries '
      . 'WHERE capture_id = :capture_id ORDER BY sort_order ASC, id ASC'
    );
    $weatherStatement = $pdo->prepare(
      'SELECT value FROM weather_entries '
      . 'WHERE capture_id = :capture_id ORDER BY position ASC, id ASC'
    );

    $entries = [];
    foreach ($rows as $row) {
      $slotTs = (int) ($row['slot_ts'] ?? 0);
      $capturedTs = isset($row['captured_ts']) ? (int) $row['captured_ts'] : null;
      $relativePath = (string) ($row['relative_path'] ?? '');

      $entries[] = [
        'slot_ts' => $slotTs,
        'captured_ts' => $capturedTs,
        'relative_path' => $relativePath,
        'luminance' => isset($row['luminance']) ? (float) $row['luminance'] : null,
        'hash' => isset($row['hash_sha256']) ? (string) $row['hash_sha256'] : null,
        'likes' => isset($row['likes_count']) ? (int) $row['likes_count'] : 0,
        'created_at' => isset($row['created_at']) ? (int) $row['created_at'] : null,
        'datetime' => $this->formatPreviewDate($slotTs, $timezone),
        'captured_at' => $this->formatPreviewDate($capturedTs, $timezone),
        'exif' => $this->loadPreviewExif($exifStatement, (int) $row['id']),
        'weather' => $this->loadPreviewWeather(
          $weatherStatement,
          (int) $row['id'],
          $camera,
          $relativePath
        ),
      ];
    }

    return $entries;
  }

  /**
   * Lädt einen Capture-Slot samt Varianten-, EXIF-, Wetter- und Logozeile.
   *
   * @return array<string,mixed>|null
   */
  public function detail(CameraDataStore $store, int $slotTs): ?array
  {
    $pdo = $store->pdo();
    $statement = $pdo->prepare('SELECT * FROM captures WHERE slot_ts = :slot LIMIT 1');
    $statement->execute([':slot' => $slotTs]);
    $capture = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($capture === null) {
      return null;
    }

    $captureId = (int) $capture['id'];
    $variants = $pdo->prepare(
      'SELECT variant,width,height,relative_path,size_bytes,mtime '
      . 'FROM variants WHERE capture_id = :capture_id ORDER BY variant'
    );
    $variants->execute([':capture_id' => $captureId]);
    $exif = $pdo->prepare(
      'SELECT tag,value,sort_order FROM exif_entries '
      . 'WHERE capture_id = :capture_id ORDER BY sort_order ASC, id ASC'
    );
    $exif->execute([':capture_id' => $captureId]);
    $weather = $pdo->prepare(
      'SELECT key,value,position FROM weather_entries '
      . 'WHERE capture_id = :capture_id ORDER BY position ASC, id ASC'
    );
    $weather->execute([':capture_id' => $captureId]);

    return [
      'capture' => $capture,
      'variants' => $variants->fetchAll(PDO::FETCH_ASSOC) ?: [],
      'exif' => $exif->fetchAll(PDO::FETCH_ASSOC) ?: [],
      'weather' => $weather->fetchAll(PDO::FETCH_ASSOC) ?: [],
      'logo' => $this->loadLogoResult($pdo, $captureId),
    ];
  }

  /**
   * Aktualisiert einen Capture und seine gesendeten Kindlisten atomar.
   *
   * @param array<string,mixed> $payload Von CapturePayloadNormalizer geprüft
   * @return array<string,mixed>
   */
  public function update(CameraDataStore $store, int $slotTs, array $payload): array
  {
    return $store->withWriteLock(function () use ($store, $slotTs, $payload): array {
      $pdo = null;
      try {
        $pdo = $store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        $captureId = $this->captureIdForSlot($pdo, $slotTs);
        $this->updateCaptureColumns($pdo, $slotTs, $payload);

        // Nur tatsächlich gesendete Listen werden ersetzt. Alle drei Schritte
        // liegen in derselben Transaktion wie die Capture-Hauptzeile.
        if (array_key_exists('variants', $payload)) {
          $this->replaceVariants($pdo, $captureId, $payload['variants']);
        }
        if (array_key_exists('exif', $payload)) {
          $this->replaceExif($pdo, $captureId, $payload['exif']);
        }
        if (array_key_exists('weather', $payload)) {
          $this->replaceWeather($pdo, $captureId, $payload['weather']);
        }

        $pdo->commit();
        $fresh = $this->detail($store, $slotTs);
        if ($fresh === null) {
          throw new RuntimeException('Capture vanished after update');
        }
        return $fresh;
      } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $exception;
      }
    });
  }

  /**
   * Übersetzt eine interne Capture-Zeile in das stabile Admin-JSON-Modell.
   *
   * @param array<string,mixed> $capture
   * @return array<string,mixed>
   */
  public function serializeCapture(array $capture, DateTimeZone $timezone): array
  {
    $slotTs = (int) ($capture['slot_ts'] ?? 0);
    $capturedTs = isset($capture['captured_ts']) ? (int) $capture['captured_ts'] : null;
    $slotDate = $slotTs > 0
      ? (new DateTimeImmutable('@' . $slotTs))->setTimezone($timezone)
      : null;
    $capturedDate = $capturedTs !== null && $capturedTs > 0
      ? (new DateTimeImmutable('@' . $capturedTs))->setTimezone($timezone)
      : null;

    return [
      'id' => (int) ($capture['id'] ?? 0),
      'slot_ts' => $slotTs,
      'captured_ts' => $capturedTs,
      'relative_path' => (string) ($capture['relative_path'] ?? ''),
      'luminance' => isset($capture['luminance']) ? (float) $capture['luminance'] : null,
      'hash_sha256' => $capture['hash_sha256'] ?? null,
      'likes_count' => isset($capture['likes_count']) ? (int) $capture['likes_count'] : 0,
      'created_at' => isset($capture['created_at']) ? (int) $capture['created_at'] : null,
      'datetime' => $slotDate?->format(DATE_ATOM),
      'captured_at' => $capturedDate?->format(DATE_ATOM),
    ];
  }

  /** @return list<array{tag:string,value:string}> */
  private function loadPreviewExif(\PDOStatement $statement, int $captureId): array
  {
    $statement->execute([':capture_id' => $captureId]);
    $entries = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $entries[] = [
        'tag' => (string) ($row['tag'] ?? ''),
        'value' => (string) ($row['value'] ?? ''),
      ];
    }
    return $entries;
  }

  private function loadPreviewWeather(
    \PDOStatement $statement,
    int $captureId,
    \Webcam\Domain\Camera $camera,
    string $relativePath
  ): string {
    $statement->execute([':capture_id' => $captureId]);
    $lines = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $line = (string) ($row['value'] ?? '');
      if ($line !== '') {
        $lines[] = $line;
      }
    }
    if ($lines !== []) {
      return implode(' | ', $lines);
    }

    // Ältere Datenbanken enthalten nicht immer weather_entries. Dann darf die
    // read-only Vorschau das vorhandene Sidecar lesen, ohne einen neuen Cache
    // oder einen stillen Datenbank-Fallback einzuführen.
    if ($relativePath === '' || \Webcam\Service\Storage\DeferredImages::isPath($relativePath)) {
      // Pilotaufnahmen speichern Wetter in SQLite. Eine reine Metadaten-
      // Vorschau darf keine grosse Archivansicht rendern.
      return '';
    }
    $imagePath = $this->app->resolveCameraStoragePath($camera, $relativePath);
    $weatherPath = is_string($imagePath)
      ? preg_replace('/\.jpg$/i', '_WX.txt', $imagePath)
      : null;
    if (!is_string($weatherPath) || !is_file($weatherPath)) {
      return '';
    }

    $contents = file_get_contents($weatherPath);
    if (!is_string($contents)) {
      return '';
    }
    $sidecarLines = array_values(array_filter(
      array_map('trim', preg_split('/\r?\n/', $contents) ?: []),
      static fn(string $line): bool => $line !== ''
    ));
    return implode(' | ', $sidecarLines);
  }

  /** @return array{detected:bool,score:?float,checked_at:?int}|null */
  private function loadLogoResult(PDO $pdo, int $captureId): ?array
  {
    try {
      $statement = $pdo->prepare(
        'SELECT detected, score, checked_at FROM capture_logo WHERE capture_id = :capture_id'
      );
      $statement->execute([':capture_id' => $captureId]);
      $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $exception) {
      // Alte Kamera-DBs dürfen die später ergänzte Logo-Tabelle noch nicht
      // besitzen. Andere SQL-Fehler bleiben sichtbar und werden weitergeworfen.
      $message = strtolower($exception->getMessage());
      if (!(str_contains($message, 'no such table') && str_contains($message, 'capture_logo'))) {
        throw $exception;
      }
      return null;
    }

    if ($row === null) {
      return null;
    }
    return [
      'detected' => (int) ($row['detected'] ?? 0) === 1,
      'score' => isset($row['score']) ? (float) $row['score'] : null,
      'checked_at' => isset($row['checked_at']) ? (int) $row['checked_at'] : null,
    ];
  }

  private function captureIdForSlot(PDO $pdo, int $slotTs): int
  {
    $statement = $pdo->prepare('SELECT id FROM captures WHERE slot_ts = :slot LIMIT 1');
    $statement->execute([':slot' => $slotTs]);
    $captureId = $statement->fetchColumn();
    if ($captureId === false) {
      throw new RuntimeException('Capture not found');
    }
    return (int) $captureId;
  }

  /** @param array<string,mixed> $payload */
  private function updateCaptureColumns(PDO $pdo, int $slotTs, array $payload): void
  {
    $fields = [];
    $parameters = [':slot' => $slotTs];
    foreach ([
      'captured_ts' => 'captured_ts',
      'relative_path' => 'relative_path',
      'luminance' => 'luminance',
      'hash_sha256' => 'hash_sha256',
      'likes_count' => 'likes_count',
    ] as $inputKey => $column) {
      if (!array_key_exists($inputKey, $payload)) {
        continue;
      }
      $fields[] = "{$column} = :{$inputKey}";
      $parameters[':' . $inputKey] = $payload[$inputKey];
    }

    if ($fields === []) {
      return;
    }
    $statement = $pdo->prepare(
      'UPDATE captures SET ' . implode(', ', $fields) . ' WHERE slot_ts = :slot'
    );
    $statement->execute($parameters);
  }

  private function replaceVariants(PDO $pdo, int $captureId, mixed $variants): void
  {
    $pdo->prepare('DELETE FROM variants WHERE capture_id = :capture_id')
      ->execute([':capture_id' => $captureId]);
    $insert = $pdo->prepare(
      'INSERT INTO variants '
      . '(capture_id, variant, width, height, relative_path, size_bytes, mtime) '
      . 'VALUES (:capture_id,:variant,:width,:height,:path,:size,:mtime)'
    );
    foreach (is_array($variants) ? $variants : [] as $variant) {
      $insert->execute([
        ':capture_id' => $captureId,
        ':variant' => (string) ($variant['variant'] ?? ''),
        ':width' => isset($variant['width']) ? (int) $variant['width'] : null,
        ':height' => isset($variant['height']) ? (int) $variant['height'] : null,
        ':path' => (string) ($variant['relative_path'] ?? ''),
        ':size' => isset($variant['size_bytes']) ? (int) $variant['size_bytes'] : null,
        ':mtime' => isset($variant['mtime']) ? (int) $variant['mtime'] : null,
      ]);
    }
  }

  private function replaceExif(PDO $pdo, int $captureId, mixed $exif): void
  {
    $pdo->prepare('DELETE FROM exif_entries WHERE capture_id = :capture_id')
      ->execute([':capture_id' => $captureId]);
    $insert = $pdo->prepare(
      'INSERT INTO exif_entries (capture_id, tag, value, sort_order) '
      . 'VALUES (:capture_id,:tag,:value,:sort_order)'
    );
    foreach (is_array($exif) ? $exif : [] as $order => $entry) {
      $insert->execute([
        ':capture_id' => $captureId,
        ':tag' => (string) ($entry['tag'] ?? ''),
        ':value' => (string) ($entry['value'] ?? ''),
        ':sort_order' => isset($entry['sort_order']) ? (int) $entry['sort_order'] : (int) $order,
      ]);
    }
  }

  private function replaceWeather(PDO $pdo, int $captureId, mixed $weather): void
  {
    $pdo->prepare('DELETE FROM weather_entries WHERE capture_id = :capture_id')
      ->execute([':capture_id' => $captureId]);
    $insert = $pdo->prepare(
      'INSERT INTO weather_entries (capture_id, key, value, position) '
      . 'VALUES (:capture_id,:key,:value,:position)'
    );
    foreach (is_array($weather) ? $weather : [] as $position => $entry) {
      $insert->execute([
        ':capture_id' => $captureId,
        ':key' => (string) ($entry['key'] ?? ''),
        ':value' => (string) ($entry['value'] ?? ''),
        ':position' => isset($entry['position']) ? (int) $entry['position'] : (int) $position,
      ]);
    }
  }

  private function formatPreviewDate(?int $timestamp, DateTimeZone $timezone): string
  {
    if ($timestamp === null || $timestamp <= 0) {
      return '';
    }
    return (new DateTimeImmutable('@' . $timestamp))
      ->setTimezone($timezone)
      ->format('d.m.Y H:i');
  }
}
