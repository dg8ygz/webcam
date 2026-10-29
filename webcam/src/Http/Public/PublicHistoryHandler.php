<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use DateTimeZone;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/**
 * Öffentliche History-Route für repräsentative Tages-, Monats- und Jahresbilder.
 *
 * Die Klasse wählt lediglich vorhandene Archivtreffer. Sie verwendet während
 * einer Antwort dieselbe read-only Kamera-DB und überlässt Legacy-Fallbacks dem
 * ArchiveBrowser, statt selbst Dateisystempfade zu durchsuchen.
 */
final class PublicHistoryHandler
{
  private readonly PublicTemporalRequest $request;

  public function __construct(private readonly App $app)
  {
    $this->request = new PublicTemporalRequest($app);
  }

  /**
   * Liefert je nach Modus repräsentative Tages-, Monats- oder Jahresbilder.
   * Eine read-only Kamera-DB wird für die gesamte Liste wiederverwendet; der
   * ArchiveBrowser behält seinen kontrollierten Legacy-Dateisystemfallback.
   *
   * @param array<string,mixed> $query
   */
  public function handle(string $slug, array $query): void
  {
    $camera = $this->request->cameraOr404($slug);
    if (!$camera) {
      return;
    }
    $mode = strtolower((string) ($query['mode'] ?? 'days'));
    if (!in_array($mode, ['days', 'months', 'years'], true)) {
      $mode = 'days';
    }
    $timezone = new DateTimeZone((string) $this->app->config('timezone', 'UTC'));
    $reference = $this->request->referenceTimestamp($camera, $query, $timezone);
    $explicitReference = isset($query['ts']) && is_numeric($query['ts']);
    $selected = $this->request->explicitDate($query, $timezone);
    if ($selected !== null) {
      $reference = $selected;
      $explicitReference = true;
    }

    $limits = [
      'days' => $this->request->boundedInt($query, 'days', 30, 1, $explicitReference ? 400 : 60),
      'months' => $this->request->boundedInt($query, 'months', 13, 1, $explicitReference ? 24 : 13),
      'years' => $this->request->boundedInt($query, 'years', 30, 1, 30),
    ];
    $store = new CameraDataStore($this->app, $camera, null, true);
    set_time_limit(12);

    $items = match ($mode) {
      'months' => $this->historyMonths($camera, $store, $reference, $limits['months'], $timezone),
      'years' => $this->historyYears($camera, $store, $reference, $limits['years'], $timezone),
      default => $this->historyDays($camera, $store, $reference, $limits['days'], $timezone),
    };
    \respond(200, [
      'mode' => $mode,
      'count' => count($items),
      'items' => $items,
      'ref_ts' => $reference,
    ]);
  }

  /** @return list<array<string,mixed>> */
  private function historyDays(
    Camera $camera,
    CameraDataStore $store,
    int $reference,
    int $limit,
    DateTimeZone $timezone
  ): array {
    $items = [];
    $referenceDate = (new DateTimeImmutable('@' . $reference))->setTimezone($timezone);
    for ($index = 0; $index < $limit; $index++) {
      $target = HistoryCalendar::day($referenceDate, $index)->getTimestamp();
      $entry = \nearest_entry_for_timestamp(
        $this->app,
        $this->app->archiveBrowser(),
        $camera,
        $target,
        $timezone,
        0,
        $store
      );
      if ($entry) {
        $items[] = $this->serializeHistoryEntry($entry, $camera->slug(), $target);
      }
    }
    return $items;
  }

  /** @return list<array<string,mixed>> */
  private function historyMonths(
    Camera $camera,
    CameraDataStore $store,
    int $reference,
    int $limit,
    DateTimeZone $timezone
  ): array {
    $items = [];
    $referenceDate = (new DateTimeImmutable('@' . $reference))->setTimezone($timezone);
    for ($index = 0; $index < $limit; $index++) {
      $target = HistoryCalendar::month($referenceDate, $index);
      $entry = \nearest_entry_for_timestamp(
        $this->app,
        $this->app->archiveBrowser(),
        $camera,
        $target->getTimestamp(),
        $timezone,
        0,
        $store
      );
      if ($entry) {
        $items[] = $this->serializeHistoryEntry($entry, $camera->slug(), $target->getTimestamp());
      }
    }
    return $items;
  }

  /** @return list<array<string,mixed>> */
  private function historyYears(
    Camera $camera,
    CameraDataStore $store,
    int $reference,
    int $limit,
    DateTimeZone $timezone
  ): array {
    $referenceDate = (new DateTimeImmutable('@' . $reference))->setTimezone($timezone);
    $yearsMap = $this->app->archiveBrowser()->listYears($camera);
    $years = array_values(array_unique(array_map('intval', array_merge(
      $yearsMap['current'] ?? [],
      $yearsMap['archive'] ?? []
    ))));
    if (!$years) {
      $years = array_map('intval', array_keys($this->app->archiveBrowser()->availableMonths($camera, null)));
    }
    if (count($years) < $limit) {
      $currentYear = (int) $referenceDate->format('Y');
      $years = array_values(array_unique(array_merge(
        $years,
        range($currentYear, max(1970, $currentYear - $limit + 1))
      )));
    }
    rsort($years);

    $items = [];
    foreach (array_slice($years, 0, $limit) as $year) {
      $target = HistoryCalendar::year($referenceDate, $year);
      $targetTimestamp = $target->getTimestamp();
      $entry = $this->app->archiveBrowser()->nearestEntryFromCache($camera, $year, $targetTimestamp);
      $entry ??= \nearest_entry_for_timestamp(
        $this->app,
        $this->app->archiveBrowser(),
        $camera,
        $targetTimestamp,
        $timezone,
        7,
        $store
      );
      if ($entry) {
        $items[] = $this->serializeHistoryEntry($entry, $camera->slug(), $targetTimestamp);
      }
    }
    return $items;
  }

  /** Wählt dieselben Vorschaustufen wie die bisherige Public-Route. */
  private function serializeHistoryEntry(array $entry, string $slug, int $fallbackTimestamp): array
  {
    $variants = is_array($entry['variants'] ?? null) ? $entry['variants'] : [];
    $pick = static function (array $variants, string $preferred): ?array {
      if (isset($variants[$preferred]) && is_array($variants[$preferred])) {
        return $variants[$preferred];
      }
      foreach (['la', 'lm', 'sm', 'hu', 'best'] as $key) {
        if (isset($variants[$key]) && is_array($variants[$key])) {
          return $variants[$key];
        }
      }
      return null;
    };
    $thumbnail = $pick($variants, 'sm');
    $large = $pick($variants, 'la');
    return [
      'timestamp' => (int) ($entry['timestamp'] ?? $fallbackTimestamp),
      'date_label' => $entry['datetime_label'] ?? '',
      'time_label' => $entry['time_label'] ?? '',
      'url' => $large['url'] ?? ($entry['url'] ?? null),
      'thumb_url' => $thumbnail['url'] ?? ($entry['preview_url'] ?? $entry['url'] ?? null),
      'relative_path' => $large['relative_path'] ?? ($entry['relative_path'] ?? null),
      'link' => sprintf(
        '?cam=%s&year=%04d&month=%02d&day=%02d&time=%s',
        urlencode($slug),
        (int) ($entry['year'] ?? 0),
        (int) ($entry['month'] ?? 0),
        (int) ($entry['day'] ?? 0),
        (string) ($entry['time'] ?? '')
      ),
    ];
  }

}
