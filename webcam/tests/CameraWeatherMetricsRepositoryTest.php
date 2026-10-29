<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\Infrastructure\Storage\CameraDataSchemaRepository;
use Webcam\Infrastructure\Storage\CameraWeatherMetricsRepository;
use Webcam\Service\Weather\WeatherMetrics;

final class CameraWeatherMetricsRepositoryTest extends TestCase
{
  private PDO $pdo;
  private CameraWeatherMetricsRepository $repository;

  protected function setUp(): void
  {
    $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $this->pdo->exec('PRAGMA foreign_keys=ON');
    (new CameraDataSchemaRepository())->ensure($this->pdo);
    $this->repository = new CameraWeatherMetricsRepository($this->pdo);
  }

  public function testParserKeepsUnitsRangesAndErrorExclusions(): void
  {
    self::assertSame(['temperature' => 12.5, 'pressure' => 1013.2, 'wind' => 4.2,
      'humidity' => 72.0, 'dewpoint' => -2.0], WeatherMetrics::extract("Temp: 12,5 C Press: 1013.2 mBar Wind: 4.2 km/h Hum: 72% DewPt: -2 °C"));
    self::assertSame([], WeatherMetrics::extract('Temp: 100 C Hum: 101%'));
    self::assertSame([], WeatherMetrics::extract("curl: operation timed out\nTemp: 12 C"));
    self::assertSame(['temperature' => 3.0], WeatherMetrics::extract('T: 3 C'));
  }

  public function testExplicitBackfillAndDailyAggregationAcrossDst(): void
  {
    $tz = new DateTimeZone('Europe/Berlin');
    $this->capture(1, '2026-03-29 00:30:00', 'Temp: 10 C', $tz);
    $this->capture(2, '2026-03-29 23:30:00', 'Temp: 20 C Hum: 80%', $tz);
    $this->capture(3, '2026-03-30 00:30:00', 'Temp: 30 C', $tz);
    self::assertNull($this->repository->aggregate(['2026-03-29'], $tz));
    $this->repository->install();
    self::assertNull($this->repository->aggregate(['2026-03-29'], $tz));
    self::assertSame(2, $this->repository->backfillBatch(2));
    $this->repository->install(); // Resume must not restart completed work.
    self::assertNull($this->repository->aggregate(['2026-03-29'], $tz));
    self::assertSame(1, $this->repository->backfillBatch(2));
    $result = $this->repository->aggregate(['2026-03-29', '2026-03-30'], $tz);
    self::assertSame(['sum' => 30.0, 'count' => 2], $result['daily']['temperature']['2026-03-29']);
    self::assertSame(3, $result['sample_counts']['temperature']);
    self::assertSame(30.0, $result['latest']['temperature']['value']);
    self::assertSame(80.0, $result['latest']['humidity']['value']);
  }

  public function testRawChangesInvalidateAndDeletesDoNotLeaveStaleValues(): void
  {
    $tz = new DateTimeZone('UTC');
    $this->capture(1, '2026-03-29 12:00:00', 'Temp: 10 C', $tz);
    $this->repository->install();
    $this->repository->backfillBatch();
    $this->pdo->exec("UPDATE weather_entries SET value='Temp: 20 C' WHERE capture_id=1");
    self::assertNull($this->repository->aggregate(['2026-03-29'], $tz));
    $this->repository->backfillBatch();
    self::assertSame(20.0, $this->repository->aggregate(['2026-03-29'], $tz)['latest']['temperature']['value']);
    $this->pdo->exec('DELETE FROM weather_entries WHERE capture_id=1');
    self::assertNull($this->repository->aggregate(['2026-03-29'], $tz));
    $this->repository->backfillBatch();
    self::assertSame([], $this->repository->aggregate(['2026-03-29'], $tz)['daily']);
    $this->pdo->exec('DELETE FROM captures WHERE id=1');
    self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM weather_measurements')->fetchColumn());
    self::assertFalse($this->pdo->query('PRAGMA foreign_key_check')->fetch());
  }

  public function testQualityFlagsRemainDynamicWithoutRebuildingNumericValues(): void
  {
    $tz = new DateTimeZone('UTC');
    $now = time();
    for ($i = 1; $i <= 3; $i++) {
      $this->capture($i, gmdate('Y-m-d H:i:s', $now - 3600 + $i * 600), 'Temp: ' . $i . ' C', $tz);
    }
    $this->repository->install();
    $this->repository->backfillBatch();
    $this->pdo->exec('CREATE TABLE weather_quality_flags(capture_id INTEGER,flag TEXT)');
    $this->pdo->exec("INSERT INTO weather_quality_flags VALUES(2,'frozen_value')");
    $days = [gmdate('Y-m-d', $now - 3600), gmdate('Y-m-d', $now)];
    $days = array_values(array_unique($days));
    self::assertSame(1, $this->repository->aggregate($days, $tz)['sample_counts']['temperature']);
    $this->pdo->exec('DELETE FROM weather_quality_flags');
    self::assertSame(3, $this->repository->aggregate($days, $tz)['sample_counts']['temperature']);
  }

  public function testProjectionReplacementParticipatesInCallerTransaction(): void
  {
    $tz = new DateTimeZone('UTC');
    $this->capture(1, '2026-03-29 12:00:00', 'Temp: 10 C', $tz);
    $this->repository->install();
    $this->repository->backfillBatch();
    $this->pdo->beginTransaction();
    $this->pdo->exec("UPDATE weather_entries SET value='Temp: 99 C'");
    $this->repository->replace(1, ['Temp: 99 C']);
    $this->pdo->rollBack();
    self::assertSame(10.0, $this->repository->aggregate(['2026-03-29'], $tz)['latest']['temperature']['value']);
  }

  public function testLegacyOrphansAreNotInventedAsWeatherCapturesOrDeleted(): void
  {
    $this->pdo->exec('PRAGMA foreign_keys=OFF');
    $this->pdo->exec("INSERT INTO weather_entries(capture_id,key,value,position) VALUES(99,'line','Temp: 10 C',0)");
    $this->pdo->exec('PRAGMA foreign_keys=ON');
    $this->repository->install();
    self::assertSame(0, $this->repository->backfillBatch());
    self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM weather_entries')->fetchColumn());
    self::assertSame([], $this->repository->aggregate(['2026-03-29'], new DateTimeZone('UTC'))['daily']);
    self::assertFalse($this->pdo->query('PRAGMA foreign_key_check(weather_measurements)')->fetch());
  }

  public function testCliCreatesVerifiedPreMigrationBackupOnAnIsolatedDatabase(): void
  {
    $root = TestTempDir::ensure() . '/weather-migration-' . bin2hex(random_bytes(5));
    mkdir($root, 0700);
    mkdir($root . '/backups', 0700);
    $path = $root . '/db.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    (new CameraDataSchemaRepository())->ensure($pdo);
    $pdo->exec("INSERT INTO captures(id,slot_ts,relative_path,created_at) VALUES(1,1700000000,'fixture.jpg',1700000000)");
    $pdo->exec("INSERT INTO weather_entries(capture_id,key,value,position) VALUES(1,'line','Temp: 10 C',0)");
    $pdo = null;
    $result = (new \Webcam\Infrastructure\Process\BoundedProcessRunner())->run([
      PHP_BINARY, dirname(__DIR__) . '/bin/backfill_weather_metrics.php',
      '--db=' . $path, '--backup-dir=' . $root . '/backups', '--apply',
    ], 10, ['require_process_group' => true]);
    self::assertSame(0, $result['exit_code'], $result['stderr']);
    self::assertStringContainsString('Verified backup:', $result['stdout']);
    self::assertStringContainsString('OK: 1 weather captures', $result['stdout']);
    $pdo = new PDO('sqlite:' . $path);
    self::assertSame(10.0, (float) $pdo->query('SELECT temperature FROM weather_measurements')->fetchColumn());
    $backups = glob($root . '/backups/*.sqlite');
    self::assertCount(1, $backups);
    $backup = new PDO('sqlite:file:' . $backups[0] . '?mode=ro');
    self::assertSame('ok', $backup->query('PRAGMA quick_check')->fetchColumn());
    self::assertSame(1, (int) $backup->query('SELECT COUNT(*) FROM weather_entries')->fetchColumn());
    self::assertFalse($backup->query("SELECT 1 FROM sqlite_master WHERE name='weather_measurements'")->fetchColumn());
  }

  private function capture(int $id, string $date, string $line, DateTimeZone $tz): void
  {
    $stmt = $this->pdo->prepare('INSERT INTO captures(id,slot_ts,relative_path,created_at) VALUES(?,?,?,?)');
    $stmt->execute([$id, (new DateTimeImmutable($date, $tz))->getTimestamp(), 'fixture.jpg', time()]);
    $stmt = $this->pdo->prepare("INSERT INTO weather_entries(capture_id,key,value,position) VALUES(?,'line',?,0)");
    $stmt->execute([$id, $line]);
  }
}
