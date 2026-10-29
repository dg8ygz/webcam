<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Webcam\Service\Weather\LocalSensorWeather;

final class LocalSensorWeatherTest extends TestCase
{
  public function testConfiguredWeatherCommandsAlwaysWin(): void
  {
    $camera = new \Webcam\Domain\Camera(['id' => 'test', 'slug' => 'test', 'name' => 'test', 'title' => 'test',
      'location' => [], 'mode' => 'active-capture', 'capture' => [], 'storage' => [], 'weather_commands' => ['false']]);
    $stamp = time();
    $raw = json_encode(['source' => 'dht22', 'measured_at' => $stamp, 'captured_at' => $stamp, 'temperature_c' => 23.3, 'humidity_percent' => 61.5]);
    self::assertNull(LocalSensorWeather::store($camera, $raw, $stamp, '/must-not-write', 'test'));
  }
  public function testPlausibilityCaptureBindingAndDelayedUpload(): void
  {
    $data = ['source' => 'dht22', 'measured_at' => 1800000000, 'captured_at' => 1800000030, 'temperature_c' => 23.3, 'humidity_percent' => 61.5];
    self::assertSame($data, LocalSensorWeather::parse(json_encode($data), 1800000030, 1800080000));
    foreach (['temperature_c' => '23.3', 'humidity_percent' => 101, 'source' => 'command', 'measured_at' => 1799999800, 'captured_at' => 1800000040, 'extra' => 'bad'] as $key => $value) {
      self::assertNull(LocalSensorWeather::parse(json_encode(array_replace($data, [$key => $value])), 1800000030, 1800000000), $key);
    }
    foreach ([null, [], str_repeat('x', 513), '{}', 'null'] as $raw) self::assertNull(LocalSensorWeather::parse($raw, 1800000030));
  }
}
