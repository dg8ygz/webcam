<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Webcam\App;

/**
 * Öffentliche Timelapse-Leseroute.
 *
 * Der Handler wählt eine begrenzte, gleichmäßig verteilte Folge vorhandener
 * Bilder. Er baut weder Videos noch Dateien und verändert keinen Archivzustand.
 */
final class PublicTimelapseHandler
{
  private readonly PublicTemporalRequest $request;

  public function __construct(private readonly App $app)
  {
    $this->request = new PublicTemporalRequest($app);
  }

  /**
   * Liefert eine begrenzte, gleichmäßig über den Zeitraum verteilte Bildfolge.
   * Die Route baut kein Video; sie wählt lediglich vorhandene Timeline-Bilder
   * und bevorzugt eine Variante nahe der gewünschten Maximalbreite.
   *
   * @param array<string,mixed> $query
   */
  public function handle(string $slug, array $query): void
  {
    try {
      $camera = $this->app->cameraOrFail($slug);
    } catch (RuntimeException) {
      \respond(404, ['detail' => 'Camera not found']);
      return;
    }

    $frameCount = $this->request->boundedInt($query, 'frame_count', 120, 5, 600);
    $maxWidth = isset($query['max_width']) && is_numeric($query['max_width'])
      ? (int) $query['max_width']
      : 1200;
    $durationHours = $this->request->boundedInt($query, 'duration_hours', 24, 1, 72);
    [$fromTimestamp, $toTimestamp] = $this->timelapseRange($query, $durationHours);

    try {
      $entries = $this->app->archiveBrowser()->timelapseEntries(
        $camera, $fromTimestamp, $toTimestamp, $frameCount
      );
    } catch (RuntimeException) {
      \respond(503, ['detail' => 'Timelapse index temporarily unavailable']);
      return;
    }
    if (!$entries) {
      \respond(200, [
        'frames' => [],
        'count' => 0,
        'from' => $fromTimestamp,
        'to' => $toTimestamp,
      ]);
      return;
    }

    $frames = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $timestamp = (int) ($entry['timestamp'] ?? 0);
      $url = $this->timelapseImageUrl($entry, $maxWidth);
      if ($timestamp <= 0 || $url === null) {
        continue;
      }
      $frames[] = [
        'timestamp' => $timestamp,
        'datetime_label' => $entry['datetime_label'] ?? date('d.m.Y H:i', $timestamp),
        'url' => $url,
      ];
    }

    \respond(200, [
      'frames' => $frames,
      'count' => count($frames),
      'from' => $fromTimestamp,
      'to' => $toTimestamp,
    ]);
  }

  /** @param array<string,mixed> $query @return array{0:int,1:int} */
  private function timelapseRange(array $query, int $durationHours): array
  {
    $from = isset($query['from']) && is_numeric($query['from']) ? (int) $query['from'] : null;
    $to = isset($query['to']) && is_numeric($query['to']) ? (int) $query['to'] : null;
    $date = isset($query['date']) ? trim((string) $query['date']) : '';

    if ($date !== '' && $from === null && $to === null) {
      $day = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone((string) $this->app->config('timezone', 'UTC'))
      );
      if ($day instanceof DateTimeImmutable) {
        $from = $day->getTimestamp();
        $to = $day->modify('+1 day')->getTimestamp() - 1;
      }
    }

    $to ??= time();
    $from ??= $to - ($durationHours * 3600);
    return $from <= $to ? [$from, $to] : [$to, $from];
  }

  /** Wählt erst eine Breitenvariante und fällt dann auf die alte Priorität zurück. */
  private function timelapseImageUrl(array $entry, int $maxWidth): ?string
  {
    $variants = is_array($entry['variants'] ?? null) ? $entry['variants'] : [];
    $bestUrl = null;
    $bestWidth = null;
    if ($maxWidth > 0) {
      foreach ($variants as $key => $info) {
        if (!is_array($info) || empty($info['url'])) {
          continue;
        }
        $width = is_numeric($key) ? (int) $key : null;
        if ($width === null && isset($info['width']) && is_numeric($info['width'])) {
          $width = (int) $info['width'];
        }
        if ($width !== null && $width <= $maxWidth && ($bestWidth === null || $width > $bestWidth)) {
          $bestWidth = $width;
          $bestUrl = (string) $info['url'];
        }
      }
    }
    if ($bestUrl !== null) {
      return $bestUrl;
    }

    foreach (['la', '1200', '720', '816', '640', 'full', 'lm', 'hu', 'sm'] as $key) {
      if (!empty($variants[$key]['url'])) {
        return (string) $variants[$key]['url'];
      }
    }
    return isset($entry['url']) ? (string) $entry['url'] : null;
  }
}
