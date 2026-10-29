<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Backup\BackupRunState;
use Webcam\Service\Storage\CaptureBackupScheduler;

require_once __DIR__ . '/Support/BackupTriggerFixture.php';
require_once __DIR__ . '/../bin/backup_daily.php';

final class CaptureBackupSchedulerTest extends TestCase
{
  private string $root;
  private App $app;
  private int $now;
  private array $launches = [];
  private CaptureBackupScheduler $scheduler;
  private array $processes = [];

  protected function tearDown(): void
  {
    foreach ($this->processes as $process) {
      if (is_resource($process)) {
        proc_close($process);
      }
    }
  }

  protected function setUp(): void
  {
    $this->root = TestTempDir::ensure() . '/backup-trigger-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0775, true);
    $cameras = [];
    foreach (['alpha', 'beta'] as $slug) {
      $cameras[] = ['id' => $slug, 'slug' => $slug, 'name' => $slug, 'title' => $slug,
        'mode' => 'active-capture', 'capture' => ['interval_seconds' => 600],
        'location' => [], 'status' => [], 'stream' => ['mode' => 'none'],
        'resolutions' => [], 'weather_commands' => [],
        'storage' => ['base_path' => $this->root . '/storage/' . $slug]];
    }
    file_put_contents($this->root . '/cameras.php', '<?php return ' . var_export($cameras, true) . ';');
    // Kein Wartungsplan aus dem globalen Bootstrap/Sample in diese Fixture.
    $previousMeta = $GLOBALS['webcam_cameras_meta'] ?? null;
    try {
      $GLOBALS['webcam_cameras_meta'] = [];
      $this->app = BackupTriggerFixture::app($this->root);
    } finally {
      if ($previousMeta === null) {
        unset($GLOBALS['webcam_cameras_meta']);
      } else {
        $GLOBALS['webcam_cameras_meta'] = $previousMeta;
      }
    }
    $this->now = time();
    $this->scheduler = new CaptureBackupScheduler($this->app, function (string $slug, string $token): bool {
      $this->launches[] = [$slug, $token];
      return true;
    }, fn(): int => $this->now);
  }

  public function testPerCameraAtomicCooldownIgnoresOldGlobalMarker(): void
  {
    mkdir($this->root . '/state/backup', 0775, true);
    touch($this->root . '/state/backup/backup_daily.lock');
    $this->schedule('alpha');
    $this->schedule('beta');
    $this->schedule('alpha');
    self::assertSame(['alpha', 'beta'], array_column($this->launches, 0));
    $this->now += 3599;
    $this->schedule('alpha');
    self::assertCount(2, $this->launches);
    $this->now++;
    $this->schedule('alpha');
    self::assertCount(3, $this->launches);
  }

  public function testFailedLaunchCanRetryAndCannotClearAnotherCamerasClaim(): void
  {
    $this->schedule('beta');
    $failing = new CaptureBackupScheduler($this->app, static fn(): bool => false);
    $failing->schedule($this->app->cameraOrFail('alpha'));
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertSame(['beta', 'alpha'], array_column($this->launches, 0));
  }

  public function testOldChildCannotClearNewReservation(): void
  {
    $this->schedule('alpha');
    $old = $this->launches[0][1];
    $this->now += 3600;
    $this->schedule('alpha');
    $this->scheduler->releaseClaim('alpha', $old);
    $this->schedule('alpha');
    self::assertCount(2, $this->launches);
    $this->scheduler->releaseClaim('alpha', $this->launches[1][1]);
    $this->schedule('alpha');
    self::assertCount(3, $this->launches);
  }

  public function testBusyExecutionReleasesClaimForNextCaptureWithoutDoingBackup(): void
  {
    $this->schedule('alpha');
    $runState = new BackupRunState($this->app);
    $lock = $runState->acquireLock();
    self::assertIsResource($lock);
    try {
      ob_start();
      $result = backupDailyMain(['backup_daily.php', '--slug=alpha', '--capture-trigger=' . $this->launches[0][1]], $this->app);
      $output = ob_get_clean();
      self::assertSame(0, $result);
      self::assertStringContainsString('another backup_daily process', $output);
      self::assertDirectoryDoesNotExist($this->root . '/backups');
    } finally {
      $runState->releaseLock($lock);
    }
    $this->schedule('alpha');
    self::assertCount(2, $this->launches);
  }

  public function testSuccessOfBothCamerasSurvivesPartialStatusReplacement(): void
  {
    $this->writeStatus('alpha');
    $this->writeStatus('beta');
    self::assertSame(['beta'], json_decode(file_get_contents($this->root . '/state/backup_partial.json'), true)['ok_slugs']);
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertSame([], $this->launches);
    $this->now += 86400;
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertCount(2, $this->launches);
  }

  public function testFailedCameraStatusDoesNotSuppressBackup(): void
  {
    $this->writeStatus('alpha', false);
    $this->schedule('alpha');
    self::assertCount(1, $this->launches);
  }

  public function testHistoricalFullStatusStillCoversAllCameras(): void
  {
    file_put_contents($this->root . '/state/backup_db.json', json_encode(['ts' => $this->now, 'status' => 'ok']));
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertSame([], $this->launches);
  }

  public function testYesterdayBackupWaitsUntilScheduledFullBackupDeadline(): void
  {
    $this->withBackupPlan();
    $this->now = (new DateTimeImmutable('2026-09-08 03:25:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->writeStatus('alpha');
    $this->now = (new DateTimeImmutable('2026-09-09 00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->schedule('alpha');
    self::assertSame([], $this->launches);
    self::assertFileDoesNotExist($this->root . '/state/backup/capture-alpha.lock');
    // Noch nie gesicherte neue Kamera wartet nicht auf den Nachtlauf.
    $this->schedule('beta');
    self::assertSame(['beta'], array_column($this->launches, 0));
    $this->now += 3 * 3600 + 50 * 60 - 1;
    $this->schedule('alpha');
    self::assertCount(1, $this->launches);
    $this->now++;
    $this->schedule('alpha');
    self::assertSame(['beta', 'alpha'], array_column($this->launches, 0));
  }

  public function testSuccessfulScheduledFullBackupAvoidsFallbackAfterDeadline(): void
  {
    $this->withBackupPlan();
    $this->now = (new DateTimeImmutable('2026-09-09 03:25:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    file_put_contents($this->root . '/state/backup_db.json', json_encode([
      'ts' => $this->now, 'status' => 'ok', 'scope' => 'full', 'ok_slugs' => ['alpha', 'beta'],
    ]));
    $this->now += 3600;
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertSame([], $this->launches);
  }

  public function testFailedOrOlderBackupCannotWaitForSchedule(): void
  {
    $this->withBackupPlan();
    $this->now = (new DateTimeImmutable('2026-09-07 03:25:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->writeStatus('alpha');
    $this->now += 86400;
    $this->writeStatus('beta', false);
    $this->now = (new DateTimeImmutable('2026-09-09 00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->schedule('alpha');
    $this->schedule('beta');
    self::assertSame(['alpha', 'beta'], array_column($this->launches, 0));
  }

  public function testDisabledOrTargetedPlanKeepsImmediateFallback(): void
  {
    $this->now = (new DateTimeImmutable('2026-09-08 03:25:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->writeStatus('alpha');
    $this->writeStatus('beta');
    $this->now = (new DateTimeImmutable('2026-09-09 00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->withBackupPlan(['enabled' => false]);
    $this->schedule('alpha');
    $this->withBackupPlan(['arguments' => ['--slug=beta']]);
    $this->schedule('beta');
    self::assertSame(['alpha', 'beta'], array_column($this->launches, 0));
  }

  public function testEarlyPreviousDayBackupDoesNotHideMissedScheduledBackup(): void
  {
    $this->withBackupPlan();
    $this->now = (new DateTimeImmutable('2026-09-08 00:10:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->writeStatus('alpha');
    $this->now = (new DateTimeImmutable('2026-09-09 00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $this->schedule('alpha');
    self::assertCount(1, $this->launches);
  }

  public function testBackupDeadlineUsesLocalCalendarAcrossDstChanges(): void
  {
    $this->withBackupPlan();
    foreach (['2026-03-29', '2026-10-25'] as $day) {
      $midnight = new DateTimeImmutable($day, new DateTimeZone('Europe/Berlin'));
      $this->now = $midnight->modify('-1 day')->setTime(3, 25)->getTimestamp();
      $this->writeStatus('alpha');
      $this->now = $midnight->setTime(3, 49, 59)->getTimestamp();
      $before = count($this->launches);
      $this->schedule('alpha');
      self::assertCount($before, $this->launches);
      $this->now++;
      $this->schedule('alpha');
      self::assertCount($before + 1, $this->launches);
    }
  }

  private function withBackupPlan(array $overrides = []): void
  {
    $previous = $GLOBALS['webcam_cameras_meta'] ?? null;
    try {
      $GLOBALS['webcam_cameras_meta'] = ['maintenance_scripts' => ['backup_daily' => array_replace([
        'enabled' => true, 'time' => '03:20', 'path' => 'bin/backup_daily.php',
        'arguments' => ['--force'], 'timeout_seconds' => 1800,
      ], $overrides)]];
      $this->app = BackupTriggerFixture::app($this->root);
    } finally {
      if ($previous === null) {
        unset($GLOBALS['webcam_cameras_meta']);
      } else {
        $GLOBALS['webcam_cameras_meta'] = $previous;
      }
    }
    $this->scheduler = new CaptureBackupScheduler($this->app, function (string $slug, string $token): bool {
      $this->launches[] = [$slug, $token];
      return true;
    }, fn(): int => $this->now);
  }

  public function testEightParallelProcessesStartOnlyOneBackup(): void
  {
    mkdir($this->root . '/state/backup', 0775, true);
    $processes = [];
    for ($i = 0; $i < 8; $i++) {
      $processes[] = $this->worker();
    }
    foreach ($processes as $process) {
      self::assertSame(0, proc_close($process), (string) file_get_contents($this->root . '/worker-errors'));
    }
    $lines = file($this->root . '/launches', FILE_IGNORE_NEW_LINES);
    self::assertCount(1, $lines);
    self::assertMatchesRegularExpression('/^alpha:[a-f0-9]{32}$/D', $lines[0]);
  }

  public function testUploadLogWriterWaitsForLockAndPreservesCompleteLines(): void
  {
    $path = $this->root . '/log/upload.log';
    $handle = fopen($path, 'c+');
    flock($handle, LOCK_EX);
    $process = $this->worker('log');
    try {
      usleep(250000);
      self::assertTrue(proc_get_status($process)['running'], (string) file_get_contents($this->root . '/worker-errors'));
      self::assertSame('', file_get_contents($path));
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
    self::assertSame(0, proc_close($process));
    $workers = array_map(fn() => $this->worker('log'), range(1, 8));
    foreach ($workers as $worker) {
      self::assertSame(0, proc_close($worker));
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    self::assertCount(9, $lines);
    foreach ($lines as $line) {
      $payload = json_decode(substr($line, strpos($line, '] ') + 2), true, 512, JSON_THROW_ON_ERROR);
      self::assertSame(str_repeat('x', 65536), $payload['value']);
    }
    // Der zweite Schreiber desselben Logs muss denselben kooperativen Lock nutzen.
    $source = file_get_contents(__DIR__ . '/../include/log.php');
    self::assertStringContainsString('file_put_contents($dest, $line, FILE_APPEND | LOCK_EX)', $source);
  }

  /** @return resource */
  private function worker(string $mode = 'backup')
  {
    $process = proc_open(['/usr/bin/timeout', '10', PHP_BINARY, __DIR__ . '/Support/capture_backup_worker.php', $this->root, $mode], [
      0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->root . '/worker-errors', 'a'],
    ], $pipes);
    self::assertIsResource($process);
    $this->processes[] = $process;
    return $process;
  }

  private function schedule(string $slug): void
  {
    $this->scheduler->schedule($this->app->cameraOrFail($slug));
  }

  private function writeStatus(string $slug, bool $ok = true): void
  {
    $stamp = (new DateTimeImmutable('@' . $this->now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Ymd_His');
    (new BackupRunState($this->app))->writeStatus($stamp, true, [
      'total' => 1, 'failed' => $ok ? 0 : 1, 'target_base' => $this->root . '/backups',
      'ok_slugs' => $ok ? [$slug] : [], 'failed_slugs' => $ok ? [] : [$slug],
    ], false, [$slug]);
  }
}
