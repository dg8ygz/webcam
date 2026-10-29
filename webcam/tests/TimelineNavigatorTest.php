<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Service\Storage\TimelineNavigator;

final class TimelineNavigatorTest extends TestCase
{
  private TimelineNavigator $navigator;

  protected function setUp(): void
  {
    $this->navigator = new TimelineNavigator();
  }

  public function testSelectUsesClosestEntryWithinRequestedDay(): void
  {
    $timeline = ['entries' => [
      $this->entry('2026-08-15 11:50:00'),
      $this->entry('2026-08-15 12:20:00'),
      $this->entry('2026-08-16 12:00:00'),
    ]];

    $selected = $this->navigator->select($timeline, [
      'year' => 2026,
      'month' => 8,
      'day' => 15,
      'time' => '12:00',
    ], 'UTC');

    $this->assertNotNull($selected);
    $this->assertSame('115000', $selected['time']);
  }

  public function testSelectReducesIncompleteCriteriaAndUsesNewestMatch(): void
  {
    $timeline = ['entries' => [
      $this->entry('2025-07-01 10:00:00'),
      $this->entry('2026-07-01 10:00:00'),
      $this->entry('2026-07-02 10:00:00'),
    ]];

    $selected = $this->navigator->select($timeline, [
      'year' => 2026,
      'month' => 7,
    ], 'UTC');

    $this->assertNotNull($selected);
    $this->assertSame(2, $selected['day']);
  }

  public function testClosestEntryPrefersEarlierTimestampOnTie(): void
  {
    $target = strtotime('2026-08-15 12:00:00 UTC');
    $closest = $this->navigator->closestEntry([
      ['timestamp' => $target + 600, 'name' => 'later'],
      ['timestamp' => $target - 600, 'name' => 'earlier'],
    ], $target);

    $this->assertNotNull($closest);
    $this->assertSame('earlier', $closest['name']);
  }

  public function testTimeAndSingleDayRangeAreNormalizedInConfiguredTimezone(): void
  {
    $this->assertSame('061000', $this->navigator->normalizeTime('06:10'));
    $this->assertNull($this->navigator->normalizeTime('keine Zeit'));

    $timezone = new DateTimeZone('Europe/Berlin');
    $from = (new DateTimeImmutable('2026-08-15 00:00:00', $timezone))->getTimestamp();
    $to = (new DateTimeImmutable('2026-08-15 23:59:59', $timezone))->getTimestamp();

    $this->assertSame(
      ['year' => 2026, 'month' => 8, 'day' => 15],
      $this->navigator->singleDayRange(2026, $from, $to, 'Europe/Berlin')
    );
    $this->assertNull($this->navigator->singleDayRange(2025, $from, $to, 'Europe/Berlin'));
  }

  public function testNavigationEntryContainsLocalLabels(): void
  {
    $timestamp = strtotime('2026-08-15 10:10:00 UTC');
    $entry = $this->navigator->navigationEntry($timestamp, new DateTimeZone('Europe/Berlin'));

    $this->assertSame(2026, $entry['year']);
    $this->assertSame(8, $entry['month']);
    $this->assertSame(15, $entry['day']);
    $this->assertSame('121000', $entry['time']);
    $this->assertSame('12:10', $entry['time_label']);
  }

  /** @return array<string,int|string> */
  private function entry(string $utcDate): array
  {
    $timestamp = strtotime($utcDate . ' UTC');
    $date = new DateTimeImmutable('@' . $timestamp);
    return [
      'timestamp' => $timestamp,
      'year' => (int) $date->format('Y'),
      'month' => (int) $date->format('n'),
      'day' => (int) $date->format('j'),
      'time' => $date->format('His'),
    ];
  }
}
