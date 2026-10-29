<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Domain\Camera;
use Webcam\Service\Capture\AutoIsoPolicy;
use Webcam\Service\Capture\AutoIsoController;
use Webcam\Service\Admin\CameraConfigNormalizer;

final class AutoIsoTest extends TestCase
{
  private function camera(string $mode = 'auto', int $max = 400, string $source = 'active-capture'): Camera
  {
    return new Camera(['id' => 'iso', 'slug' => 'iso', 'name' => 'ISO', 'title' => 'ISO',
      'location' => [], 'storage' => [], 'mode' => $source,
      'capture' => ['interval_seconds' => 600, 'auto_iso' => ['mode' => $mode, 'min_iso' => 100, 'max_iso' => $max]]]);
  }

  private function queue(): AutoIsoController
  {
    return new AutoIsoController(TestTempDir::ensure() . '/iso-' . bin2hex(random_bytes(4)));
  }

  public function testHysteresisExtremesAndHardLimits(): void
  {
    foreach ([
      [100, 30, 30, 200], [100, 30, 5, 400], [200, 30, 5, 400], [400, 30, 5, null],
      [400, 19.9, 30, null], [400, 9.999, 30, 200], [400, 10, 30, null], [400, 0.5, 30, 100], [100, 0.5, 30, null],
      [200, 24.999, 34, null], [200, 25, 34, 400], [200, 25.1, 34, 400], [200, 30, 35, null],
      [200, 20, 40, null], [200, 20, 40.1, 100], [800, 22, 36, 400],
    ] as [$iso, $seconds, $lum, $target]) {
      $result = AutoIsoPolicy::decide(['max_iso' => 400], ['model' => 'Canon EOS 1100D', 'iso' => $iso, 'seconds' => $seconds], $lum);
      self::assertSame($target, $result['target_iso'], json_encode([$iso, $seconds, $lum]));
    }
    self::assertSame(1600, AutoIsoPolicy::decide(['max_iso' => 1600], ['model' => 'Nikon', 'iso' => 800, 'seconds' => 30], 5)['target_iso']);
  }

  public function testSteinhagenTwentyFiveSecondImageRequestsEightHundred(): void
  {
    $q = $this->queue(); $cam = $this->camera('auto', 800); $now = 1791141000;
    $q->poll($cam, $now - 1);
    $result = $q->observe($cam,
      ['model' => 'Canon EOS 2000D', 'iso' => 400, 'seconds' => 25], 9.6, $now, $now);
    self::assertSame('queued', $result['state']);
    self::assertSame(800, $result['target_iso']);
    self::assertSame("command\n" . ($now + 1) . "\nwebcam_isoexp 800\n", $q->poll($cam, $now + 1));
    self::assertNull($q->poll($cam, $now + 2));
  }

  public function testThirteenSecondFollowupHoldsEightHundredWithoutOscillation(): void
  {
    $q = $this->queue(); $cam = $this->camera('auto', 800); $now = 1791141000;
    $q->poll($cam, $now - 1);
    $q->observe($cam, ['model' => 'Canon EOS 2000D', 'iso' => 400, 'seconds' => 25], 9.6, $now, $now);
    self::assertNotNull($q->poll($cam, $now + 1));
    $q->poll($cam, $now + 599);
    $result = $q->observe($cam,
      ['model' => 'Canon EOS 2000D', 'iso' => 800, 'seconds' => 13], 9.6, $now + 600, $now + 600);
    self::assertSame('hold', $result['state']);
    self::assertNull($result['target_iso']);
    self::assertNull($q->poll($cam, $now + 601));
    $exposure = ['model' => 'Canon EOS 2000D', 'iso' => 800, 'seconds' => 13];
    self::assertSame(400, AutoIsoPolicy::decide(['max_iso' => 800], $exposure, 40.1)['target_iso']);
    $exposure['seconds'] = 9.999;
    self::assertSame(400, AutoIsoPolicy::decide(['max_iso' => 800], $exposure, 9.6)['target_iso']);
    $exposure['seconds'] = 10;
    self::assertNull(AutoIsoPolicy::decide(['max_iso' => 800], $exposure, 9.6)['target_iso']);
  }

  public function testMissingAndInvalidValuesNeverGenerateCommands(): void
  {
    $base = ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30];
    foreach ([[], ['model' => 'USB'], ['iso' => 0], ['iso' => 125], ['iso' => NAN], ['iso' => '100;id'],
      ['seconds' => 0], ['seconds' => INF], ['seconds' => -1], ['seconds' => null]] as $bad) {
      $input = $bad === [] ? [] : array_replace($base, $bad);
      self::assertNull(AutoIsoPolicy::decide([], $input, 5)['target_iso']);
    }
    foreach ([null, NAN, INF, -1.0, 101.0] as $lum) self::assertNull(AutoIsoPolicy::decide([], $base, $lum)['target_iso']);
  }

  public function testCooldownDispatchesStrombergDecisionAtDeadlineExactlyOnce(): void
  {
    $q = $this->queue(); $cam = $this->camera('auto', 800);
    $dispatch = 1791150088; // 23:41:28: first ISO400 command
    $q->poll($cam, $dispatch - 2);
    $q->observe($cam, ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30], 1.4, $dispatch - 1, $dispatch - 1);
    self::assertStringContainsString('webcam_isoexp 400', $q->poll($cam, $dispatch));
    $q->poll($cam, $dispatch + 580);
    $result = $q->observe($cam, ['model' => 'Canon EOS', 'iso' => 400, 'seconds' => 25], 5.1,
      $dispatch + 580, $dispatch + 587); // next upload arrives 13 seconds early
    self::assertSame('cooldown', $result['state']);
    self::assertSame(800, $result['target_iso']);
    self::assertNull($q->poll($cam, $dispatch + 588));
    self::assertNull($q->poll($cam, $dispatch + 599));
    self::assertSame("command\n" . ($dispatch + 600) . "\nwebcam_isoexp 800\n", $q->poll($cam, $dispatch + 600));
    self::assertNull($q->poll($cam, $dispatch + 601));
  }

  public function testDeferredDecisionStillExpiresOrIsCancelledOrReplaced(): void
  {
    $cam = $this->camera('auto', 800); $now = 1791150088;
    foreach (['off', 'observe', 'changed', 'pull', 'expired', 'new-hold', 'new-target'] as $case) {
      $q = $this->queue(); $q->poll($cam, $now - 2);
      $q->observe($cam, ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30], 5, $now - 1, $now - 1);
      self::assertNotNull($q->poll($cam, $now));
      self::assertSame('cooldown', $q->observe($cam,
        ['model' => 'Canon EOS', 'iso' => 400, 'seconds' => 25], 5, $now + 500, $now + 501)['state']);
      if (str_starts_with($case, 'new-')) {
        $q->observe($cam, ['model' => 'Canon EOS', 'iso' => 400, 'seconds' => 13],
          $case === 'new-hold' ? 5 : 45, $now + 502, $now + 503);
      }
      $pollCam = match ($case) {
        'off' => $this->camera('off', 800), 'observe' => $this->camera('observe', 800),
        'changed' => $this->camera('auto', 400), 'pull' => $this->camera('auto', 800, 'pull-snapshot'),
        default => $cam,
      };
      // Cancellation must happen even before the cooldown ends.
      self::assertNull($q->poll($pollCam, $now + ($case === 'expired' ? 1400 : 599)), $case);
      if ($case === 'new-target') {
        self::assertStringContainsString('webcam_isoexp 200', $q->poll($cam, $now + 600));
      } else {
        self::assertNull($q->poll($cam, $now + 1401), $case);
      }
    }
  }

  public function testConfigurationRoundTripAndValidation(): void
  {
    $normalizer = new CameraConfigNormalizer();
    self::assertSame(['mode' => 'off', 'min_iso' => 100, 'max_iso' => 800],
      $normalizer->normalizePayload([], 'iso', [])['capture']['auto_iso']);
    self::assertSame(800, AutoIsoPolicy::decide([], ['model' => 'Canon EOS', 'iso' => 400, 'seconds' => 30], 5)['target_iso']);
    self::assertNull(AutoIsoPolicy::decide([], ['model' => 'Canon EOS', 'iso' => 800, 'seconds' => 30], 5)['target_iso']);
    $camera = ['capture' => ['upload_key' => 'unchanged']];
    $payload = ['capture' => ['auto_iso' => ['mode' => 'observe', 'min_iso' => 200, 'max_iso' => 800]]];
    $updated = $normalizer->normalizePayload($normalizer->applySectionUpdate($camera, $payload, 'capture'), 'iso', []);
    self::assertSame($payload['capture']['auto_iso'], $updated['capture']['auto_iso']);
    self::assertSame('unchanged', $updated['capture']['upload_key']);
    foreach ([false, 'auto', ['mode' => true], ['max_iso' => 199], ['max_iso' => '400'],
      ['min_iso' => 800, 'max_iso' => 400], ['command' => 'id']] as $bad) {
      try { AutoIsoPolicy::normalize($bad); self::fail('Invalid config accepted'); }
      catch (InvalidArgumentException $expected) { self::assertStringContainsString('auto_iso', $expected->getMessage()); }
    }
  }

  public function testObservationAuthenticatedPollAndAtMostOnceDispatch(): void
  {
    $q = $this->queue(); $cam = $this->camera(); $now = 1791100000;
    $exif = ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30];
    self::assertNull($q->observe($this->camera('off'), $exif, 5, $now, $now));
    self::assertSame('observed', $q->observe($this->camera('observe'), $exif, 5, $now, $now)['state']);
    self::assertNull($q->poll($this->camera('observe'), $now));
    self::assertSame('queued', $q->observe($cam, $exif, 5, $now + 1, $now + 1)['state']);
    self::assertSame("command\n" . ($now + 2) . "\nwebcam_isoexp 400\n", $q->poll($cam, $now + 2));
    self::assertSame('dispatched', $q->status($cam, $now + 2)['state']);
    self::assertNull($q->poll($cam, $now + 3));
    self::assertNull($q->observe($cam, $exif, 5, $now + 1, $now + 4));
    self::assertSame('cooldown', $q->observe($cam, $exif, 5, $now + 5, $now + 5)['state']);
    self::assertSame('queued', $q->observe($cam, $exif, 5, $now + 605, $now + 605)['state']);
    self::assertNull($q->poll($this->camera('off'), $now + 606));
    self::assertSame('cancelled', $q->status($cam, $now + 606)['state']);
  }

  public function testOldImagesExpirationDisabledAndChangedSettings(): void
  {
    $cam = $this->camera(); $now = 1791100000; $exif = ['model' => 'Canon EOS', 'iso' => 100, 'seconds' => 30];
    foreach (['old', 'changed', 'observe', 'pull'] as $case) {
      $q = $this->queue(); $q->poll($cam, $now);
      self::assertNull($q->observe($cam, $exif, 5, $now - 900, $now));
      self::assertNull($q->observe($cam, $exif, 5, $now + 1, $now));
      $q->observe($cam, $exif, 5, $now, $now);
      $pollCam = match ($case) {
        'changed' => $this->camera('auto', 800), 'observe' => $this->camera('observe'),
        'pull' => $this->camera('auto', 400, 'pull-snapshot'), default => $cam,
      };
      self::assertNull($q->poll($pollCam, $now + ($case === 'old' ? 900 : 1)));
      self::assertNull($q->poll($cam, $now + 901));
    }
    $q = $this->queue();
    self::assertSame('helper_unavailable', $q->observe($cam, $exif, 5, $now, $now)['state']);
    self::assertNull($q->poll($cam, $now + 1)); // no retroactive command
    self::assertNull($q->observe($this->camera('auto', 400, 'pull-snapshot'), $exif, 5, $now + 2, $now + 2));
  }

  public function testLockConflictAndTamperedQueueFailClosed(): void
  {
    $dir = TestTempDir::ensure() . '/iso-corrupt-' . bin2hex(random_bytes(4)); mkdir($dir);
    $q = new AutoIsoController($dir); $file = $dir . '/iso.json';
    $h = fopen($file, 'c+'); flock($h, LOCK_EX);
    try { $q->poll($this->camera()); self::fail('Lock ignored'); }
    catch (RuntimeException $e) { self::assertStringContainsString('belegt', $e->getMessage()); }
    finally { flock($h, LOCK_UN); fclose($h); }
    file_put_contents($file, '{"pending":{"iso":"400;id"}}');
    $this->expectException(RuntimeException::class);
    $q->poll($this->camera());
  }
}
