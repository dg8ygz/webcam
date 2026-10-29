<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Weather\DwdWeather;

final class DwdWeatherTest extends TestCase
{
  public function testCatalogueConvertsDegreesMinutesAndLatinNames(): void
  {
    $stations = DwdWeather::stations("01001 ENJA JAN MAYEN 70.56 -8.40 10\n37531 ---- GORI 41.60 44.07 132\n12345 ---- CAF\xC9 52.00 8.30 20");
    self::assertEqualsWithDelta(70.933333, $stations[0]['lat'], .00001);
    self::assertEqualsWithDelta(-8.666667, $stations[0]['lon'], .00001);
    self::assertSame(42.0, $stations[1]['lat']);
    self::assertSame('CAFÉ', $stations[2]['name']);
  }

  public function testNamespacedForecastConvertsUnitsAndPreservesMissingValues(): void
  {
    $data = DwdWeather::parseForecast($this->xml());
    self::assertSame(10.0, $data['hours'][0]['temperature']);
    self::assertSame(36.0, $data['hours'][0]['wind']);
    self::assertNull($data['hours'][1]['temperature']);
    self::assertNull($data['hours'][0]['rain']);
  }

  public function testXmlExternalEntityIsRejected(): void
  {
    $this->expectException(RuntimeException::class);
    DwdWeather::parseForecast('<!DOCTYPE kml [<!ENTITY x SYSTEM "file:///etc/passwd">]><kml>&x;</kml>');
  }

  public function testForecastCacheAvoidsRepeatFetchAndMarksBoundedStaleFallback(): void
  {
    $root = TestTempDir::ensure() . '/dwd-' . bin2hex(random_bytes(4)); mkdir($root);
    file_put_contents($root . '/cameras.php', '<?php return [];');
    $app = new App(['camera_config_path' => $root . '/cameras.php', 'state_path' => $root, 'log_path' => $root . '/logs', 'timezone' => 'Europe/Berlin']);
    $camera = new Camera(['id' => 'a', 'slug' => 'a', 'name' => 'A', 'title' => 'A', 'mode' => 'active-capture',
      'location' => ['latitude' => 52, 'longitude' => 8.5], 'capture' => [], 'storage' => []]);
    $zipPath = $root . '/fixture.kmz'; $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('forecast.kml', $this->xml()); $zip->close();
    $calls = [];
    $service = new DwdWeather($app, static function (string $url, int $limit) use (&$calls, $zipPath): string {
      $calls[] = $url;
      return str_contains($url, 'stationskatalog') ? '12345 ---- FIXTURE 52.00 8.30 100' : file_get_contents($zipPath);
    });
    self::assertFalse($service->forecast($camera)['stale']);
    self::assertSame(0.0, $service->forecast($camera)['station']['distance_km']);
    self::assertCount(2, $calls);
    $path = $root . '/external-weather/forecast-12345.json';
    $cache = json_decode(file_get_contents($path), true); $cache['fetched_at'] = time() - 3600; file_put_contents($path, json_encode($cache));
    $offline = new DwdWeather($app, static fn() => throw new RuntimeException('offline'));
    self::assertTrue($offline->forecast($camera)['stale']);
    $cache['fetched_at'] = time() - 43201; file_put_contents($path, json_encode($cache));
    $this->expectException(RuntimeException::class);
    $offline->forecast($camera);
  }

  private function xml(): string
  {
    $issue = gmdate('Y-m-d\TH:i:s\Z'); $next = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    return '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:dwd="https://opendata.dwd.de/weather/lib/pointforecast_dwd_extension_V1_0.xsd"><Document><ExtendedData>'
      . '<dwd:IssueTime>' . $issue . '</dwd:IssueTime><dwd:ForecastTimeSteps><dwd:TimeStep>' . $issue . '</dwd:TimeStep><dwd:TimeStep>' . $next . '</dwd:TimeStep></dwd:ForecastTimeSteps>'
      . '<dwd:Forecast dwd:elementName="TTT"><dwd:value>283.15 -</dwd:value></dwd:Forecast><dwd:Forecast dwd:elementName="FF"><dwd:value>10 5</dwd:value></dwd:Forecast>'
      . '</ExtendedData></Document></kml>';
  }
}
