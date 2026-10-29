<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;

require_once __DIR__ . '/../bin/backup_daily.php';

final class BackupDailyScriptTest extends TestCase
{
  public function testSqliteBackupRejectsEmptySource(): void
  {
    $dir = sys_get_temp_dir() . '/webcam-backup-test-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($dir, 0775, true) || is_dir($dir));

    $source = $dir . '/empty.sqlite';
    $dest = $dir . '/backup.sqlite.bak';
    file_put_contents($source, '');

    try {
      $this->assertFalse(backupSqliteDatabase($source, $dest, 'test-empty'));
      $this->assertFileDoesNotExist($dest);
      $this->assertFileDoesNotExist($dest . '.tmp');
    } finally {
      @unlink($source);
      @rmdir($dir);
    }
  }

  public function testSqliteBackupCreatesVerifiedSnapshot(): void
  {
    $dir = sys_get_temp_dir() . '/webcam-backup-sqlite-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($dir, 0775, true) || is_dir($dir));

    $source = $dir . '/source.sqlite';
    $dest = $dir . '/backup.sqlite.bak';
    $db = new SQLite3($source);
    $db->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec("INSERT INTO sample (value) VALUES ('snapshot-ok')");
    $db->close();

    $previousStaging = getenv('WEBCAM_SQLITE_BACKUP_TMPDIR');
    putenv('WEBCAM_SQLITE_BACKUP_TMPDIR=' . $dir);
    try {
      $this->assertTrue(backupSqliteDatabase($source, $dest, 'test-snapshot'));
      $this->assertFileExists($dest);
      $this->assertSame(0660, fileperms($dest) & 0777);
      $backup = new SQLite3($dest, SQLITE3_OPEN_READONLY);
      $this->assertSame('ok', $backup->querySingle('PRAGMA quick_check(1)'));
      $this->assertSame('snapshot-ok', $backup->querySingle('SELECT value FROM sample'));
      $backup->close();
    } finally {
      $previousStaging === false
        ? putenv('WEBCAM_SQLITE_BACKUP_TMPDIR')
        : putenv('WEBCAM_SQLITE_BACKUP_TMPDIR=' . $previousStaging);
      @unlink($source);
      @unlink($dest);
      @unlink($dest . '.tmp');
      @rmdir($dir);
    }
  }

  public function testSqliteBackupDoesNotFallBackWhenConfiguredStagingIsMissing(): void
  {
    $dir = sys_get_temp_dir() . '/webcam-backup-missing-stage-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($dir, 0775, true) || is_dir($dir));
    $source = $dir . '/source.sqlite';
    $dest = $dir . '/backup.sqlite.bak';
    $db = new SQLite3($source);
    $db->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY)');
    $db->close();

    $previousStaging = getenv('WEBCAM_SQLITE_BACKUP_TMPDIR');
    putenv('WEBCAM_SQLITE_BACKUP_TMPDIR=' . $dir . '/does-not-exist');
    try {
      $this->assertFalse(backupSqliteDatabase($source, $dest, 'test-missing-stage'));
      $this->assertFileDoesNotExist($dest);
    } finally {
      $previousStaging === false
        ? putenv('WEBCAM_SQLITE_BACKUP_TMPDIR')
        : putenv('WEBCAM_SQLITE_BACKUP_TMPDIR=' . $previousStaging);
      @unlink($source);
      @unlink($dest);
      @rmdir($dir);
    }
  }

  public function testPruneBackupsIgnoresWalAndShmSidecars(): void
  {
    $dir = sys_get_temp_dir() . '/webcam-backup-prune-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($dir, 0775, true) || is_dir($dir));

    $old = $dir . '/db.sqlite.bak-20260410_031001';
    $new = $dir . '/db.sqlite.bak-20260411_031001';
    $wal = $dir . '/db.sqlite.bak-20260411_031001-wal';
    $shm = $dir . '/db.sqlite.bak-20260411_031001-shm';
    file_put_contents($old, 'old');
    file_put_contents($new, 'new');
    file_put_contents($wal, 'wal');
    file_put_contents($shm, 'shm');
    touch($old, time() - 20);
    touch($new, time() - 10);
    touch($wal, time() - 5);
    touch($shm, time() - 5);

    try {
      pruneBackups($dir, 'db.sqlite.bak-', 1);

      $this->assertFileDoesNotExist($old);
      $this->assertFileExists($new);
      $this->assertFileExists($wal);
      $this->assertFileExists($shm);
    } finally {
      @unlink($old);
      @unlink($new);
      @unlink($wal);
      @unlink($shm);
      @rmdir($dir);
    }
  }

  public function testSidecarFailureMakesFullBackupStatusPartial(): void
  {
    $dir = sys_get_temp_dir() . '/webcam-backup-status-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($dir, 0775, true) || is_dir($dir));
    $cameraConfig = $dir . '/cameras.php';
    file_put_contents($cameraConfig, '<?php return [];');
    $app = new App([
      'timezone' => 'UTC',
      'camera_config_path' => $cameraConfig,
      'storage_path' => $dir . '/storage',
      'archive_path' => $dir . '/archive',
      'state_path' => $dir . '/state',
      'log_path' => $dir . '/log',
      'info_path' => $dir . '/info',
      'cache_path' => $dir . '/cache',
      'camera_db_path' => $dir . '/camera-db',
    ]);

    try {
      writeBackupStatus(
        $app,
        '20260726_032000',
        true,
        [
          'total' => 1,
          'failed' => 0,
          'target_base' => $dir . '/backups',
          'ok_slugs' => ['demo'],
          'failed_slugs' => [],
        ],
        true,
        [],
        [
          'total' => 2,
          'copied' => 1,
          'unchanged' => 0,
          'failed' => 1,
          'target_base' => $dir . '/backups/sidecars',
          'dates' => ['2026-07-25'],
          'ok_slugs' => [],
          'failed_slugs' => ['demo'],
        ]
      );

      $state = json_decode((string) file_get_contents($dir . '/state/backup_db.json'), true);
      $this->assertIsArray($state);
      $this->assertSame('partial', $state['status']);
      $this->assertSame(1, $state['failed']);
      $this->assertSame([], $state['ok_slugs']);
      $this->assertSame(['demo'], $state['failed_slugs']);
      $this->assertSame(1, $state['sidecars']['failed']);
      $this->assertStringContainsString('sidecars copied=1 unchanged=0 failed=1', $state['note']);
      $this->assertStringContainsString('sidecar_target_base=' . $dir . '/backups/sidecars', $state['note']);
    } finally {
      $this->removeTree($dir);
    }
  }

  private function removeTree(string $path): void
  {
    if (!file_exists($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->removeTree($path . '/' . $entry);
    }
    @rmdir($path);
  }
}
