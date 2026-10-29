<?php
declare(strict_types=1);

final class ArchiveApiTest extends WebcamHttpTestCase
{
  public function testTimelineByTimestamp(): void
  {
    $slug = $this->firstSlug();
    $detail = $this->getJson('/api/public/cameras/' . rawurlencode($slug));
    $iso = $detail['camera']['last_capture_at'] ?? null;
    $ts = TestFixtures::seedTimestamp() ?? time();
    if (is_string($iso) && $iso !== '') {
      $ts = (new DateTimeImmutable($iso))->getTimestamp();
    }
    $json = $this->getJson('/api/public/cameras/' . rawurlencode($slug) . '/timeline?ts=' . $ts . '&limit=5');
    $entries = $json['entries'] ?? null;
    $this->assertIsArray($entries);
    $this->assertNotEmpty($entries);
    $this->assertArrayHasKey('variants', $entries[0]);
    $this->assertArrayHasKey('likes_count', $entries[0]);
    $this->assertIsArray($json['years'] ?? null);
    $this->assertIsArray($json['months'] ?? null);
    $this->assertIsArray($json['days'] ?? null);
    $this->assertArrayHasKey('latest_global', $json);
  }

  public function testTimelineDayNavigationKeepsRequestedTimeAcrossDays(): void
  {
    /** @var \Webcam\App $app */
    $app = require __DIR__ . '/../bootstrap.php';

    foreach ($app->cameras()->all() as $camera) {
      $years = [];
      $yearsList = $app->archiveBrowser()->listYears($camera);
      foreach (['current', 'archive'] as $bucket) {
        foreach ($yearsList[$bucket] ?? [] as $year) {
          if (is_numeric($year)) {
            $years[] = (int) $year;
          }
        }
      }
      $years = array_values(array_unique($years));
      sort($years);
      if ($years === []) {
        continue;
      }

      $calendar = $app->archiveBrowser()->availableCalendar($camera, $years);
      $days = [];
      foreach ($years as $year) {
        foreach (($calendar['months'][$year] ?? []) as $month) {
          foreach (($calendar['days'][$year][$month] ?? []) as $day) {
            $days[] = ['year' => $year, 'month' => (int) $month, 'day' => (int) $day];
          }
        }
      }
      if (count($days) < 2) {
        continue;
      }

      for ($index = 1; $index < count($days); $index++) {
        $prev = $days[$index - 1];
        $current = $days[$index];
        $prevDate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $prev['year'], $prev['month'], $prev['day']));
        $currentDate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $current['year'], $current['month'], $current['day']));
        if ($prevDate->modify('+1 day')->format('Y-m-d') !== $currentDate->format('Y-m-d')) {
          continue;
        }

        $currentEntries = $app->archiveBrowser()->entriesForDay($camera, $current['year'], $current['month'], $current['day'])['entries'] ?? [];
        if ($currentEntries === []) {
          continue;
        }

        $probe = $currentEntries[(int) floor((count($currentEntries) - 1) / 2)] ?? null;
        $time = is_array($probe) ? (string) ($probe['time'] ?? '') : '';
        if ($time === '') {
          continue;
        }

        $expected = $app->archiveBrowser()->dayNearestEntry(
          $camera,
          $prev['year'],
          $prev['month'],
          $prev['day'],
          $time,
          'last'
        );
        if (!is_array($expected) || !isset($expected['time'], $expected['timestamp'])) {
          continue;
        }

        $json = $this->getJson(sprintf(
          '/api/public/cameras/%s/timeline?year=%04d&month=%02d&day=%02d&time=%s&limit=5',
          rawurlencode($camera->slug()),
          $current['year'],
          $current['month'],
          $current['day'],
          $time
        ));

        $prevEntry = $json['day_prev_entry'] ?? null;
        $this->assertIsArray($prevEntry);
        $this->assertSame($expected['time'], $prevEntry['time'] ?? null);
        $this->assertSame($expected['timestamp'], $prevEntry['timestamp'] ?? null);
        return;
      }
    }

    $this->markTestSkipped('No camera with adjacent day navigation candidate found.');
  }
}
