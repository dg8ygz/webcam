<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/** Bounded, read-only selections for solar timelapses and seasonal films. */
final class PresentationFrames
{
  public const MODES = ['day', 'sunrise', 'sunset', 'night', 'month', 'year'];

  public function __construct(private readonly App $app) {}

  /** Random camera/time, with bounded retries for missing files; never scan archives. */
  public function randomFrame(): ?array
  {
    $cameras = $this->app->cameras()->listed();
    shuffle($cameras);
    foreach (array_slice($cameras, 0, 8) as $camera) {
      try {
        $store = new CameraDataStore($this->app, $camera, null, true);
        $bounds = $store->captureTimeBounds(time());
        if ($bounds === null) { continue; }
        [$from, $to] = $bounds;
        for ($attempt = 0; $attempt < 3; $attempt++) {
          $entry = $this->app->archiveBrowser()->nearestEntryFromDatabase(
            $camera, random_int($from, $to), $from, $to, $store, 1200
          );
          $frame = $entry ? self::frame($entry) : null;
          if ($frame !== null) {
            return ['slug' => $camera->slug(), 'title' => $camera->title(), 'frame' => $frame];
          }
        }
      } catch (\Throwable $e) {
        $this->app->logger()->warning('Random slideshow archive unavailable', ['camera' => $camera->slug(), 'error' => $e->getMessage()]);
      }
    }
    return null;
  }

  public static function date(string $value, DateTimeZone $zone): DateTimeImmutable
  {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 2000 || (int) $date->format('Y') > 2100) {
      throw new InvalidArgumentException('Datum muss zwischen 2000 und 2100 liegen.');
    }
    return $date;
  }

  /** Night belongs to the selected evening and ends on the following morning. */
  public static function range(DateTimeImmutable $day, string $mode, array $location): array
  {
    if (!in_array($mode, self::MODES, true)) { throw new InvalidArgumentException('Unbekannte Zeitraffer-Auswahl.'); }
    if ($mode === 'day') { return [$day->getTimestamp(), $day->modify('+1 day')->getTimestamp() - 1]; }
    if ($mode === 'month' || $mode === 'year') {
      $start = $mode === 'month' ? $day->modify('first day of this month') : $day->setDate((int) $day->format('Y'), 1, 1);
      return [$start->getTimestamp(), $start->modify($mode === 'month' ? '+1 month' : '+1 year')->getTimestamp() - 1];
    }
    $lat = $location['latitude'] ?? null;
    $lon = $location['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
      throw new InvalidArgumentException('Für diese Auswahl fehlen gültige Standortkoordinaten.');
    }
    $sun = date_sun_info($day->setTime(12, 0)->getTimestamp(), (float) $lat, (float) $lon);
    $next = date_sun_info($day->modify('+1 day')->setTime(12, 0)->getTimestamp(), (float) $lat, (float) $lon);
    $key = $mode === 'sunrise' ? 'sunrise' : 'sunset';
    if (!is_int($sun[$key]) || ($mode === 'night' && !is_int($next['sunrise']))) {
      throw new InvalidArgumentException('Kein Sonnenauf- oder -untergang für diesen Tag verfügbar.');
    }
    return match ($mode) {
      'sunrise' => [$sun['sunrise'] - 3600, $sun['sunrise'] + 5400],
      'sunset' => [$sun['sunset'] - 5400, $sun['sunset'] + 3600],
      'night' => [$sun['sunset'], $next['sunrise']],
    };
  }

  /** At most 300 hydrated captures; year/month never hydrate a whole archive. */
  public function select(Camera $camera, DateTimeImmutable $date, string $mode, string $clock = '12:00', int $limit = 300, int $width = 1200): array
  {
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock)) {
      throw new InvalidArgumentException('Uhrzeit muss HH:MM sein.');
    }
    $limit = max(2, min(300, $limit));
    [$from, $to] = self::range($date, $mode, $camera->location());
    $to = min($to, time());
    $entries = []; $missing = 0; $targets = 0;
    if ($from <= $to && in_array($mode, ['month', 'year'], true)) {
      $store = new CameraDataStore($this->app, $camera, null, true);
      $store->pdo(); // Fail visibly if the index is unavailable; never scan directories.
      $days = []; $nonexistentTimes = [];
      [$hour, $minute] = array_map('intval', explode(':', $clock));
      $day = (new DateTimeImmutable('@' . $from))->setTimezone($date->getTimezone());
      while ($day->getTimestamp() <= $to) {
        $localTarget = $day->setTime($hour, $minute);
        $target = $localTarget->getTimestamp();
        if ($localTarget->format('H:i') !== $clock) { $nonexistentTimes[$target] = true; }
        if ($target <= $to) { $days[] = $target; }
        $day = $day->modify('+1 day');
      }
      $availableDays = count($days);
      if ($availableDays > $limit) {
        $days = array_map(static fn(int $i): int => $days[(int) floor($i * ($availableDays - 1) / ($limit - 1))], range(0, $limit - 1));
      }
      $targets = count($days);
      foreach ($days as $target) {
        if (isset($nonexistentTimes[$target])) { $missing++; continue; }
        $midnight = (new DateTimeImmutable('@' . $target))->setTimezone($date->getTimezone())->setTime(0, 0);
        $entry = $this->app->archiveBrowser()->nearestEntryFromDatabase($camera, $target,
          max($midnight->getTimestamp(), $target - 1800),
          min($to, $target + 1800, $midnight->modify('+1 day')->getTimestamp() - 1), $store, $width);
        if ($entry === null) { $missing++; } else { $entries[] = $entry; }
      }
    } elseif ($from <= $to) {
      $entries = $this->app->archiveBrowser()->timelapseEntries($camera, $from, $to, $limit);
    }
    return ['entries' => $entries, 'from' => $from, 'to' => $to, 'mode' => $mode,
      'clock' => $clock, 'timezone' => $date->getTimezone()->getName(),
      'missing_days' => $missing, 'sampled_days' => $targets, 'limit' => $limit];
  }

  public static function frame(array $entry, int $width = 1200): ?array
  {
    $variants = $entry['variants'] ?? []; $url = null; $best = 0;
    foreach ($variants as $key => $value) {
      $w = is_numeric($key) ? (int) $key : (int) ($value['width'] ?? 0);
      if ($w > $best && $w <= $width && !empty($value['url'])) { $best = $w; $url = $value['url']; }
    }
    $url ??= $entry['url'] ?? $variants['full']['url'] ?? null;
    if (!is_string($url) || !($entry['timestamp'] ?? 0)) { return null; }
    return ['url' => $url, 'timestamp' => (int) $entry['timestamp'],
      'datetime_label' => $entry['datetime_label'] ?? date('d.m.Y H:i', (int) $entry['timestamp'])];
  }
}
