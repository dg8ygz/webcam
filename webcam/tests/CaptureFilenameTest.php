<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\CaptureFilename;
use Webcam\Service\Storage\TimelineNavigator;

final class CaptureFilenameTest extends TestCase
{
  public function testBothHoursRoundTripWithoutSharingAName(): void
  {
    $tz = new DateTimeZone('Europe/Berlin');
    $first = (new DateTimeImmutable('2026-10-25T02:10:00+02:00'))->setTimezone($tz);
    $second = (new DateTimeImmutable('2026-10-25T02:10:00+01:00'))->setTimezone($tz);
    self::assertSame('20261025_021000', CaptureFilename::cache($first));
    self::assertSame('20261025_021000_utc1792890600', CaptureFilename::cache($second));
    self::assertSame('0210_utc1792890600', CaptureFilename::archiveFromCache(CaptureFilename::cache($second)));
    self::assertEquals($first, CaptureFilename::date('20261025021000', $tz));
    self::assertEquals($second, CaptureFilename::date('20261025021000', $tz, '1792890600'));
    self::assertNull(CaptureFilename::date('20261025022000', $tz, '1792890600'));
    self::assertNull(CaptureFilename::date('20260329023000', $tz));
  }

  public function testHalfHourFallbackAndFixedTimezone(): void
  {
    $tz = new DateTimeZone('Australia/Lord_Howe');
    foreach (['2026-04-05T01:45:00+11:00' => false, '2026-04-05T01:45:00+10:30' => true] as $iso => $repeated) {
      $d = (new DateTimeImmutable($iso))->setTimezone($tz);
      self::assertSame($repeated, CaptureFilename::suffix($d) !== '');
      self::assertEquals($d, CaptureFilename::date($d->format('YmdHis'), $tz, $repeated ? (string)$d->getTimestamp() : ''));
    }
    self::assertSame('', CaptureFilename::suffix(new DateTimeImmutable('2026-10-25T02:10:00+01:00')));
  }

  public function testExplicitTimelineTimestampWinsOverAmbiguousCivilTime(): void
  {
    $first = ['timestamp' => 1792887000, 'year' => 2026, 'month' => 10, 'day' => 25, 'time' => '021000'];
    $second = array_replace($first, ['timestamp' => 1792890600]);
    $navigator = new TimelineNavigator();
    foreach ([$first, $second] as $selected) {
      self::assertSame($selected, $navigator->select(['entries' => [$first, $second]], $selected, 'Europe/Berlin'));
    }
  }
}
