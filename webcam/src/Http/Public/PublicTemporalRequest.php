<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;

/**
 * Gemeinsame, rein lesende Regeln der öffentlichen Zeitrouten.
 *
 * Timeline und History müssen denselben Referenzzeitpunkt bestimmen; alle drei
 * Routen begrenzen numerische Querywerte. Diese kleine Hilfe verhindert, dass
 * sich die Regeln beim späteren Ändern unbemerkt auseinanderentwickeln. Sie
 * erzeugt selbst keine HTTP-Antworten und kennt keine Archivabfragen.
 */
final class PublicTemporalRequest
{
  public function __construct(private readonly App $app) {}

  /** @param array<string,mixed> $query */
  public function boundedInt(array $query, string $key, int $default, int $min, int $max): int
  {
    $value = isset($query[$key]) && is_numeric($query[$key])
      ? (int) $query[$key]
      : $default;
    return max($min, min($max, $value));
  }

  /** Liefert eine Kamera oder schreibt die einheitliche öffentliche 404-Antwort. */
  public function cameraOr404(string $slug): ?Camera
  {
    try {
      return $this->app->cameraOrFail($slug);
    } catch (RuntimeException) {
      \respond(404, ['detail' => 'Camera not found']);
      return null;
    }
  }

  /**
   * Liest nur ein vollständig angegebenes Kalenderdatum. Teilangaben sind
   * kein Fehler, sondern bedeuten für den Aufrufer „anderen Fallback nutzen“.
   *
   * @param array<string,mixed> $query
   */
  public function explicitDate(array $query, DateTimeZone $timezone): ?int
  {
    foreach (['year', 'month', 'day'] as $key) {
      if (!isset($query[$key]) || !is_numeric($query[$key])) {
        return null;
      }
    }
    $time = str_pad(
      (string) preg_replace('/[^0-9]/', '', (string) ($query['time'] ?? '000000')),
      6,
      '0',
      STR_PAD_LEFT
    );
    $date = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i:s',
      sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        (int) $query['year'],
        (int) $query['month'],
        (int) $query['day'],
        (int) substr($time, 0, 2),
        (int) substr($time, 2, 2),
        (int) substr($time, 4, 2)
      ),
      $timezone
    );
    return $date instanceof DateTimeImmutable ? $date->getTimestamp() : null;
  }

  /** Priorität: ts, vollständiges Datum, letzte Aufnahme, Status, Serverzeit. */
  public function referenceTimestamp(Camera $camera, array $query, DateTimeZone $timezone): int
  {
    if (isset($query['ts']) && is_numeric($query['ts'])) {
      return (int) $query['ts'];
    }
    $explicit = $this->explicitDate($query, $timezone);
    if ($explicit !== null) {
      return $explicit;
    }
    $overview = $this->app->cameraPresenter()->overviewData($camera);
    if (($overview['last_capture'] ?? null) instanceof DateTimeImmutable) {
      return $overview['last_capture']->getTimestamp();
    }
    $lastSuccess = $this->app->statusService()->state($camera)['last_success_at'] ?? null;
    return $lastSuccess ? (int) $lastSuccess : time();
  }
}
