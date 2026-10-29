<?php
declare(strict_types=1);

namespace Webcam\Http\Public;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Webcam\App;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Storage\PresentationFrames;
use Webcam\Service\Weather\DwdWeather;

/** Bounded public archive selections and centrally cached DWD data. */
final class PublicPresentationHandler
{
  public function __construct(private readonly App $app) {}

  public function handle(string $method, array $segments, array $query): bool
  {
    $multi = $segments === ['presentation', 'multiview'];
    $random = $segments === ['presentation', 'random'];
    $kind = $segments[2] ?? '';
    $single = count($segments) === 3 && $segments[0] === 'cameras'
      && in_array($kind, ['presentation', 'forecast', 'radar', 'radar-image', 'radar-legend'], true);
    if ($method !== 'GET' || (!$multi && !$single && !$random)) { return false; }
    try {
      $zone = new DateTimeZone((string) $this->app->config('timezone', 'Europe/Berlin'));
      if ($random) {
        header('Cache-Control: no-store');
        $slot = (new PresentationFrames($this->app))->randomFrame();
        \respond($slot ? 200 : 404, $slot
          ? ['camera' => $slot, 'timezone' => $zone->getName()]
          : ['detail' => 'Keine lesbare Archivaufnahme gefunden. Bitte erneut versuchen.']);
        return true;
      }
      if ($multi) {
        $slugs = array_unique(explode(',', self::value($query, 'cameras', '')));
        if (count($slugs) > 4 || !$slugs || in_array('', $slugs, true)) {
          throw new InvalidArgumentException('Bitte ein bis vier Kameras auswählen.');
        }
        $live = self::value($query, 'live', '0') === '1';
        $at = $live ? time() : $this->time($query, $zone);
        $slots = [];
        foreach ($slugs as $slug) {
          $camera = $this->app->cameras()->find($slug);
          if (!$camera) { throw new InvalidArgumentException('Unbekannte Kamera.'); }
          $store = new CameraDataStore($this->app, $camera, null, true);
          $store->pdo();
          $entry = $this->app->archiveBrowser()->nearestEntryFromDatabase(
            $camera, $at, $live ? $at - 7 * 86400 : $at - 600, min(time(), $live ? $at : $at + 600), $store, 1200
          );
          $frame = $entry ? PresentationFrames::frame($entry) : null;
          $slots[] = ['slug' => $slug, 'title' => $camera->title(), 'frame' => $frame,
            'offset_seconds' => $frame ? $frame['timestamp'] - $at : null];
        }
        \respond(200, ['at' => $at, 'timezone' => $zone->getName(), 'tolerance_seconds' => 600, 'cameras' => $slots]);
        return true;
      }
      $camera = $this->app->cameras()->find($segments[1]);
      if (!$camera) { \respond(404, ['detail' => 'Kamera nicht gefunden.']); return true; }
      if ($kind === 'presentation') {
        $date = PresentationFrames::date(self::value($query, 'date', date('Y-m-d')), $zone);
        $selection = (new PresentationFrames($this->app))->select($camera, $date,
          self::value($query, 'period', 'day'), self::value($query, 'clock', '12:00'));
        $selection['frames'] = array_values(array_filter(array_map([PresentationFrames::class, 'frame'], $selection['entries'])));
        unset($selection['entries']);
        \respond(200, $selection);
      } else {
        $weather = new DwdWeather($this->app);
        if ($kind === 'radar-image' || $kind === 'radar-legend') {
          $time = self::value($query, 'time', '0');
          if (!ctype_digit($time)) { throw new InvalidArgumentException('Ungültige Radarzeit.'); }
          $png = $kind === 'radar-legend' ? $weather->radarLegend() : $weather->radarImage($camera, (int) $time);
          header('Content-Type: image/png');
          header('Cache-Control: public, max-age=300');
          header('X-Content-Type-Options: nosniff');
          echo $png;
        } else {
          \respond(200, $kind === 'forecast' ? $weather->forecast($camera) : $weather->radar($camera));
        }
      }
    } catch (InvalidArgumentException $e) {
      \respond(400, ['detail' => $e->getMessage()]);
    } catch (\Throwable $e) {
      \log_internal_error($this->app->logger(), $e, ['route' => $segments]);
      header('Retry-After: 30');
      \respond(503, ['detail' => 'Daten derzeit nicht verfügbar. Bitte später erneut versuchen.']);
    }
    return true;
  }

  private function time(array $query, DateTimeZone $zone): int
  {
    $date = PresentationFrames::date(self::value($query, 'date', date('Y-m-d')), $zone);
    $clock = self::value($query, 'clock', '12:00');
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock)) {
      throw new InvalidArgumentException('Uhrzeit muss HH:MM sein.');
    }
    [$h, $m] = array_map('intval', explode(':', $clock));
    $at = $date->setTime($h, $m);
    if ($at->format('H:i') !== $clock || $at->getTimestamp() > time()) {
      throw new InvalidArgumentException('Zeit liegt in der Zukunft oder im Sommerzeit-Sprung.');
    }
    return $at->getTimestamp();
  }

  private static function value(array $query, string $name, string $default): string
  {
    $value = $query[$name] ?? $default;
    if (!is_string($value) || strlen($value) > 200) { throw new InvalidArgumentException('Ungültiger Parameter.'); }
    return $value;
  }
}
