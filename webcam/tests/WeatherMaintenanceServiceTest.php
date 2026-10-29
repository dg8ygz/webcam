<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Weather\WeatherQualityAnalyzer;
use Webcam\Service\Weather\WeatherSidecarParser;

/**
 * Vertragstests für die aus den Wetter-CLIs gelösten Fachbausteine.
 *
 * Die Tests benötigen weder Archiv noch Produktivdatenbank. Sie halten die
 * beiden historischen Dateinamenformate sowie die wichtigsten Warnregeln fest.
 */
final class WeatherMaintenanceServiceTest extends TestCase
{
  public function testSidecarParserUnderstandsRollingAndArchivePaths(): void
  {
    $parser = new WeatherSidecarParser();
    $timezone = new DateTimeZone('Europe/Berlin');

    $rolling = $parser->pathMetadata(
      '/cache/camera/2026/08/31_1/20260831_235000_WX.txt',
      $timezone
    );
    $archive = $parser->pathMetadata(
      '/archive/camera/2026/08/31/2350_wx.txt',
      $timezone
    );

    self::assertNotNull($rolling);
    self::assertNotNull($archive);
    self::assertSame(2026, $rolling['year']);
    self::assertSame($rolling['slot_ts'], $archive['slot_ts']);
  }

  public function testSidecarParserRejectsImplausibleMeasurements(): void
  {
    $parser = new WeatherSidecarParser();

    self::assertTrue($parser->validate([
      'Temp: 18,4 C Press: 1012.3 hPa Wind: 4.0 m/s',
    ])['ok']);

    $invalid = $parser->validate(['Temp: 81 C Press: 1012 hPa']);
    self::assertFalse($invalid['ok']);
    self::assertStringContainsString('temperature', $invalid['reason']);
  }

  public function testQualityAnalyzerReportsMissingRangeAndFrozenValues(): void
  {
    $analyzer = new WeatherQualityAnalyzer();
    $rows = [
      ['capture_id' => 1, 'slot_ts' => 100, 'line' => '', 'metrics' => []],
      ['capture_id' => 2, 'slot_ts' => 200, 'line' => 'Temp: 55 C', 'metrics' => ['temperature' => 55.0]],
      ['capture_id' => 3, 'slot_ts' => 300, 'line' => 'Temp: 10 C', 'metrics' => ['temperature' => 10.0]],
      ['capture_id' => 4, 'slot_ts' => 400, 'line' => 'Temp: 10 C', 'metrics' => ['temperature' => 10.0]],
    ];

    $flags = $analyzer->evaluateWeatherQualityRows($rows, 2);
    $keys = array_map(
      static fn(array $flag): string => $flag['capture_id'] . ':' . $flag['flag'] . ':' . ($flag['metric'] ?? '-'),
      $flags
    );

    self::assertContains('1:missing_weather:-', $keys);
    self::assertContains('2:out_of_range:temperature', $keys);
    self::assertContains('3:frozen_value:temperature', $keys);
    self::assertContains('4:frozen_value:temperature', $keys);
  }

  public function testQualitySummaryIsStableAndSorted(): void
  {
    $summary = (new WeatherQualityAnalyzer())->summarizeWeatherQualityFlags([
      ['flag' => 'out_of_range', 'metric' => 'temperature'],
      ['flag' => 'missing_weather', 'metric' => null],
      ['flag' => 'out_of_range', 'metric' => 'temperature'],
    ]);

    self::assertSame('missing_weather=1 out_of_range:temperature=2', $summary);
  }
}
