<?php
declare(strict_types=1);

namespace Webcam\Service\Weather;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Filesystem\AtomicFileWriter;
use Webcam\Infrastructure\Http\HttpClient;

/** Fixed DWD sources, shared bounded caches; no user-supplied upstream URLs. */
final class DwdWeather
{
  private const WMS = 'https://maps.dwd.de/geoserver/ows';
  private readonly \Closure $download;
  private string $directory;

  public function __construct(App $app, ?\Closure $download = null)
  {
    $this->directory = $app->paths()->statePath('external-weather');
    $this->download = $download ?? static function (string $url, int $limit): string {
      $response = (new HttpClient())->get($url, ['timeout' => 20, 'max_response_bytes' => $limit]);
      if ($response->status !== 200) { throw new RuntimeException('DWD antwortet derzeit nicht erfolgreich.'); }
      return $response->body;
    };
  }

  public function forecast(Camera $camera): array
  {
    [$lat, $lon] = $this->coordinates($camera);
    $catalog = $this->remember('stations', 604800, 2592000, function (): array {
      $text = ($this->download)('https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication&nn=16102', 1048576);
      return self::stations($text);
    });
    $nearest = null; $distance = INF;
    foreach ($catalog['data'] as $station) {
      $a = sin(deg2rad($station['lat'] - $lat) / 2) ** 2
        + cos(deg2rad($lat)) * cos(deg2rad($station['lat'])) * sin(deg2rad($station['lon'] - $lon) / 2) ** 2;
      $km = 12742 * asin(sqrt(min(1, max(0, $a))));
      if ($km < $distance) { $distance = $km; $nearest = $station; }
    }
    if ($nearest === null || $distance > 150) { throw new RuntimeException('Kein passender DWD-Vorhersageort in der Nähe.'); }
    $id = $nearest['id'];
    $result = $this->remember('forecast-' . $id, 1800, 43200, function () use ($id): array {
      $bytes = ($this->download)('https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/' . $id . '/kml/MOSMIX_L_LATEST_' . $id . '.kmz', 2097152);
      $tmp = tempnam($this->directory, 'mosmix-');
      if ($tmp === false) { throw new RuntimeException('Vorhersage kann nicht zwischengespeichert werden.'); }
      try {
        if (file_put_contents($tmp, $bytes) !== strlen($bytes)) { throw new RuntimeException('DWD-Zwischenspeicher nicht beschreibbar.'); }
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) { throw new RuntimeException('Ungültiges DWD-Datenpaket.'); }
        try {
          $xml = null;
          for ($i = 0; $i < min(8, $zip->numFiles); $i++) {
            $info = $zip->statIndex($i);
            if ($info && str_ends_with($info['name'], '.kml') && $info['size'] <= 8388608) { $xml = $zip->getFromIndex($i); break; }
          }
          if (!is_string($xml)) { throw new RuntimeException('DWD-Vorhersage fehlt.'); }
          return self::parseForecast($xml);
        } finally { $zip->close(); }
      } finally { unlink($tmp); }
    });
    $result['data']['hours'] = array_values(array_filter($result['data']['hours'], static fn(array $h): bool => $h['timestamp'] >= time()));
    if ($result['data']['hours'] === []) { throw new RuntimeException('DWD-Vorhersage ist abgelaufen.'); }
    $result['station'] = $nearest + ['distance_km' => round($distance, 1)];
    $result['source'] = 'Deutscher Wetterdienst (DWD), MOSMIX';
    return $result;
  }

  public static function stations(string $text): array
  {
    // The published catalogue includes Latin-1 station names.
    if (preg_match('//u', $text) !== 1) {
      $converted = iconv('ISO-8859-1', 'UTF-8', $text);
      if ($converted === false) { throw new RuntimeException('Ungültige DWD-Stationsnamen.'); }
      $text = $converted;
    }
    $stations = [];
    foreach (explode("\n", $text) as $line) {
      if (!preg_match('/^([A-Z0-9]{5})\s+\S+\s+(.+?)\s+(-?\d+\.\d{2})\s+(-?\d+\.\d{2})\s+-?\d+\s*$/D', trim($line), $m)) { continue; }
      // DWD catalogue uses degrees.minutes, not decimal degrees.
      $degrees = static function (string $v): float {
        [$d, $minutes] = explode('.', ltrim($v, '-'));
        if ((int) $minutes > 60) { throw new RuntimeException('Ungültige DWD-Koordinaten.'); }
        return ((int) $d + (int) $minutes / 60) * (str_starts_with($v, '-') ? -1 : 1);
      };
      $stations[] = ['id' => $m[1], 'name' => trim($m[2]), 'lat' => $degrees($m[3]), 'lon' => $degrees($m[4])];
    }
    if (!$stations) { throw new RuntimeException('DWD-Stationsliste fehlt.'); }
    return $stations;
  }

  public static function parseForecast(string $text): array
  {
    $xml = self::xml($text);
    $times = $xml->xpath('//*[local-name()="ForecastTimeSteps"]/*[local-name()="TimeStep"]') ?: [];
    $issue = (string) (($xml->xpath('//*[local-name()="IssueTime"]') ?: [])[0] ?? '');
    $fields = [];
    foreach ($xml->xpath('//*[local-name()="Forecast"]') ?: [] as $forecast) {
      $attrs = $forecast->attributes('https://opendata.dwd.de/weather/lib/pointforecast_dwd_extension_V1_0.xsd');
      $key = (string) ($attrs['elementName'] ?? '');
      if (!in_array($key, ['TTT', 'FF', 'FX1', 'DD', 'N', 'RR1c', 'ww'], true)) { continue; }
      $values = (string) (($forecast->xpath('./*[local-name()="value"]') ?: [])[0] ?? '');
      $fields[$key] = preg_split('/\s+/', trim($values));
    }
    $hours = [];
    foreach (array_slice($times, 0, 240) as $index => $time) {
      $ts = strtotime((string) $time);
      if (!$ts) { continue; }
      $hour = ['timestamp' => $ts];
      foreach (['TTT' => 'temperature', 'FF' => 'wind', 'FX1' => 'gust', 'DD' => 'wind_direction', 'N' => 'cloud', 'RR1c' => 'rain', 'ww' => 'weather_code'] as $key => $label) {
        $value = $fields[$key][$index] ?? null;
        $hour[$label] = is_numeric($value) ? round(((float) $value - ($key === 'TTT' ? 273.15 : 0)) * (in_array($key, ['FF', 'FX1'], true) ? 3.6 : 1), 1) : null;
      }
      $hours[] = $hour;
    }
    if (!$hours || !strtotime($issue) || !isset($fields['TTT'])) { throw new RuntimeException('Unvollständige DWD-Vorhersage.'); }
    return ['issued_at' => strtotime($issue), 'hours' => $hours];
  }

  public function radar(Camera $camera): array
  {
    [$lat, $lon] = $this->coordinates($camera);
    $result = $this->remember('radar-time', 300, 1800, function (): array {
      $xml = self::xml(($this->download)(self::WMS . '?service=WMS&version=1.3.0&request=GetCapabilities', 2097152));
      foreach ($xml->xpath('//*[local-name()="Layer"]') ?: [] as $layer) {
        $name = (string) (($layer->xpath('./*[local-name()="Name"]') ?: [])[0] ?? '');
        if ($name !== 'dwd:RADOLAN-RY') { continue; }
        foreach ($layer->xpath('./*[local-name()="Dimension"]') ?: [] as $dim) {
          if ((string) $dim['name'] !== 'time') { continue; }
          $latest = strtotime((string) $dim['default']);
          if ($latest && abs(time() - $latest) < 7200) {
            return ['latest' => $latest, 'times' => range($latest - 7200, $latest, 300)];
          }
        }
      }
      throw new RuntimeException('Keine aktuellen DWD-Radarzeiten verfügbar.');
    });
    $result['bounds'] = [[max(-85, $lat - 1), max(-180, $lon - 1.5)], [min(85, $lat + 1), min(180, $lon + 1.5)]];
    $result['source'] = 'Deutscher Wetterdienst (DWD), RADOLAN-RY';
    return $result;
  }

  public function radarImage(Camera $camera, int $timestamp): string
  {
    $meta = $this->radar($camera);
    if (!in_array($timestamp, $meta['data']['times'], true)) { throw new \InvalidArgumentException('Radarzeit außerhalb der letzten zwei Stunden.'); }
    $bounds = $meta['bounds'];
    $key = 'radar-' . $camera->slug() . '-' . $timestamp;
    // Coordinates/configuration changes must not reuse the wrong map extent.
    $key .= '-mercator-' . substr(hash('sha256', json_encode($bounds)), 0, 8);
    $result = $this->remember($key, 86400, 86400, function () use ($bounds, $timestamp): array {
      // Leaflet's base map is Web Mercator; request the overlay in the same CRS.
      $project = static fn(array $point): array => [
        6378137 * deg2rad($point[1]), 6378137 * log(tan(M_PI / 4 + deg2rad($point[0]) / 2)),
      ];
      $params = ['SERVICE' => 'WMS', 'VERSION' => '1.1.1', 'REQUEST' => 'GetMap',
        'LAYERS' => 'dwd:RADOLAN-RY', 'STYLES' => '', 'SRS' => 'EPSG:3857',
        'BBOX' => implode(',', [...$project($bounds[0]), ...$project($bounds[1])]),
        'WIDTH' => 800, 'HEIGHT' => 600, 'FORMAT' => 'image/png', 'TRANSPARENT' => 'TRUE',
        'TIME' => gmdate('Y-m-d\TH:i:s\Z', $timestamp)];
      $png = ($this->download)(self::WMS . '?' . http_build_query($params), 2097152);
      if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) { throw new RuntimeException('DWD-Radarbild nicht verfügbar.'); }
      return ['png' => base64_encode($png)];
    });
    // Only this camera's fixed-size regional frames; keep a bounded short history.
    $files = glob($this->directory . '/radar-' . $camera->slug() . '-*.json') ?: [];
    foreach ($files as $file) { if (filemtime($file) < time() - 10800) { @unlink($file); } }
    return (string) base64_decode($result['data']['png'], true);
  }

  private function coordinates(Camera $camera): array
  {
    $location = $camera->location();
    $lat = $location['latitude'] ?? null; $lon = $location['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 85 || abs((float) $lon) > 180) {
      throw new \InvalidArgumentException('Gültige Kamerakoordinaten fehlen.');
    }
    return [(float) $lat, (float) $lon];
  }

  public function radarLegend(): string
  {
    $result = $this->remember('radar-legend', 86400, 604800, function (): array {
      $png = ($this->download)(self::WMS . '?SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&FORMAT=image/png&LAYER=dwd%3ARADOLAN-RY', 262144);
      if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) { throw new RuntimeException('DWD-Legende nicht verfügbar.'); }
      return ['png' => base64_encode($png)];
    });
    return (string) base64_decode($result['data']['png'], true);
  }

  private static function xml(string $text): \SimpleXMLElement
  {
    if (stripos($text, '<!DOCTYPE') !== false) { throw new RuntimeException('Ungültiges DWD-XML.'); }
    $previous = libxml_use_internal_errors(true);
    try {
      $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
      if ($xml === false) { throw new RuntimeException('DWD-XML kann nicht gelesen werden.'); }
      return $xml;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
  }

  /** Persistent cache is exclusively for external data, never an archive index. */
  private function remember(string $key, int $ttl, int $maxAge, callable $load): array
  {
    if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
      throw new RuntimeException('Wetter-Zwischenspeicher nicht verfügbar.');
    }
    $path = $this->directory . '/' . $key . '.json';
    $read = static function () use ($path): ?array {
      $value = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
      return is_array($value) && isset($value['fetched_at'], $value['data']) ? $value : null;
    };
    $value = $read();
    if ($value && time() - $value['fetched_at'] < $ttl) { return $value + ['stale' => false]; }
    // Fixed striped locks: timestamped radar images must not create endless inodes.
    $lock = fopen($this->directory . '/fetch-' . (hexdec(substr(hash('sha256', $key), 0, 2)) % 16) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
      if (is_resource($lock)) { fclose($lock); }
      if ($value && time() - $value['fetched_at'] < $maxAge) { return $value + ['stale' => true]; }
      throw new RuntimeException('Wetterdaten werden gerade geladen. Bitte kurz erneut versuchen.');
    }
    try {
      $latest = $read();
      if ($latest && time() - $latest['fetched_at'] < $ttl) { return $latest + ['stale' => false]; }
      $fresh = ['fetched_at' => time(), 'data' => $load()];
      if (!AtomicFileWriter::write($path, json_encode($fresh, JSON_THROW_ON_ERROR), null, 0600)) {
        throw new RuntimeException('Wetter-Zwischenspeicher nicht beschreibbar.');
      }
      return $fresh + ['stale' => false];
    } catch (\Throwable $e) {
      if ($value && time() - $value['fetched_at'] < $maxAge) { return $value + ['stale' => true]; }
      throw $e;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }
}
