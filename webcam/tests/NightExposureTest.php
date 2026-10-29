<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Domain\Camera;
use Webcam\Service\Capture\AutoIsoController;
use Webcam\Service\Capture\AutoIsoPolicy;
use Webcam\Service\Capture\NightExposurePolicy;

final class NightExposureTest extends TestCase
{
  private const LOCATION = ['latitude' => 52.0, 'longitude' => 8.4];
  private const NIGHT = 1791244800; // 2026-10-06 02:00 CEST

  private function camera(string $mode = 'auto', int $max = 2): Camera
  {
    return new Camera(['id' => 'ev', 'slug' => 'ev', 'name' => 'EV', 'title' => 'EV',
      'location' => self::LOCATION, 'storage' => [], 'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600, 'auto_iso' => ['mode' => $mode,
        'min_iso' => 100, 'max_iso' => 800, 'night_ev_max' => $max]]]);
  }

  private function exposure(float $bias = 0): array
  {
    return ['model' => 'Canon EOS 2000D', 'iso' => 200, 'seconds' => 15, 'bias' => $bias];
  }

  public function testNightDarknessBoundsAndBrightnessHysteresis(): void
  {
    foreach ([[0, 1.2, 1], [1, 1.9, 2], [2, 1.2, null], [1, 20, null],
      [2, 36, 1], [1, 36, 0], [0, 36, null], [-0.67, 1, null], [3, 1, 2]] as [$bias, $lum, $target]) {
      self::assertSame($target, NightExposurePolicy::decide(['night_ev_max' => 2],
        $this->exposure($bias), $lum, self::LOCATION, self::NIGHT)['target_ev']);
    }
    self::assertSame(1, NightExposurePolicy::decide(['night_ev_max' => 1],
      $this->exposure(), 1, self::LOCATION, self::NIGHT)['target_ev']);
    foreach ([null, NAN, INF, -1, 101] as $lum) {
      self::assertNull(NightExposurePolicy::decide(['night_ev_max' => 2],
        $this->exposure(), $lum, self::LOCATION, self::NIGHT)['target_ev']);
    }
    self::assertNull(NightExposurePolicy::decide([], $this->exposure(), 1, self::LOCATION, self::NIGHT)['target_ev']);
    self::assertNull(NightExposurePolicy::decide(['night_ev_max' => 2],
      ['model' => 'Canon EOS', 'seconds' => 15], 1, self::LOCATION, self::NIGHT)['target_ev']);
  }

  public function testSolarBoundaryAndUnknownLocationReturnToZero(): void
  {
    $sun = date_sun_info(self::NIGHT, 52, 8.4);
    self::assertTrue(NightExposurePolicy::isNight(self::LOCATION, $sun['civil_twilight_begin'] - 1));
    self::assertFalse(NightExposurePolicy::isNight(self::LOCATION, $sun['civil_twilight_begin']));
    self::assertFalse(NightExposurePolicy::isNight(self::LOCATION, $sun['civil_twilight_end'] - 1));
    self::assertTrue(NightExposurePolicy::isNight(self::LOCATION, $sun['civil_twilight_end']));
    foreach ([[], ['latitude' => null, 'longitude' => null], ['latitude' => 999, 'longitude' => 8]] as $location) {
      self::assertSame(0, NightExposurePolicy::decide(['night_ev_max' => 2],
        $this->exposure(2), null, $location, self::NIGHT)['target_ev']);
    }
  }

  public function testRealNightCaseDispatchAndMorningResetWithoutNewImage(): void
  {
    $q = new AutoIsoController(TestTempDir::ensure() . '/ev-' . bin2hex(random_bytes(4)));
    $cam = $this->camera(); $now = self::NIGHT;
    $q->poll($cam, $now - 1);
    $decision = $q->observe($cam, $this->exposure(), 1.2, $now, $now);
    self::assertNull($decision['target_iso']);
    self::assertSame(1, $decision['target_ev']);
    self::assertSame("command\n{$now}\nwebcam_isoexp 0 1\n", $q->poll($cam, $now));
    self::assertNull($q->poll($cam, $now + 1));
    $morning = date_sun_info($now, 52, 8.4)['civil_twilight_begin'];
    self::assertSame("command\n{$morning}\nwebcam_isoexp 0 0\n", $q->poll($cam, $morning));
    self::assertNull($q->poll($cam, $morning + 1));
    self::assertNotNull($q->poll($cam, $morning + 600)); // reset not acknowledged
    // A reset dispatch must not make a fresh upload from this slot stale.
    $q->observe($cam, $this->exposure(0), 15, $morning + 599, $morning + 601);
    self::assertNull($q->poll($cam, $morning + 1200));
    self::assertFalse($q->status($cam, $morning + 1200)['ev_engaged']);
  }

  public function testPendingNightIncreaseCannotCrossDawn(): void
  {
    $q = new AutoIsoController(TestTempDir::ensure() . '/ev-' . bin2hex(random_bytes(4)));
    $cam = $this->camera(); $dawn = date_sun_info(self::NIGHT, 52, 8.4)['civil_twilight_begin'];
    $q->poll($cam, $dawn - 2);
    $q->observe($cam, $this->exposure(), 1, $dawn - 1, $dawn - 1);
    self::assertNull($q->poll($cam, $dawn));
    self::assertSame('night_command_cancelled', $q->status($cam, $dawn)['state']);
  }

  public function testObserveNeverCommandsAndDisablingResetsPriorNightSetting(): void
  {
    $q = new AutoIsoController(TestTempDir::ensure() . '/ev-' . bin2hex(random_bytes(4)));
    $now = self::NIGHT;
    $q->poll($this->camera(), $now - 1);
    $q->observe($this->camera('observe'), $this->exposure(), 1, $now, $now);
    self::assertNull($q->poll($this->camera('observe'), $now));
    $q->observe($this->camera(), $this->exposure(), 1, $now + 1, $now + 1);
    self::assertNotNull($q->poll($this->camera(), $now + 1));
    self::assertSame('command' . "\n" . ($now + 2) . "\nwebcam_isoexp 0 0\n", $q->poll($this->camera('off'), $now + 2));
    $q->observe($this->camera('off'), $this->exposure(0), 1, $now + 3, $now + 3);
    self::assertNull($q->poll($this->camera('off'), $now + 603));
  }

  public function testConfigurationRejectsUnsupportedEv(): void
  {
    foreach ([-1, 3, '2', true, null, 1.5] as $value) {
      try { AutoIsoPolicy::normalize(['night_ev_max' => $value]); self::fail('Accepted invalid EV'); }
      catch (InvalidArgumentException $e) { self::assertStringContainsString('night_ev_max', $e->getMessage()); }
    }
  }
}
