<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Storage\{DeferredImages, DeferredImageSchema, ImageStorage, ArchiveDatabaseReader, TimelineMapper, ArchiveEntryImageResolver, CacheRetentionManager};

require_once __DIR__ . '/../autoload.php';

final class DeferredImagesTest extends TestCase
{
  private string $root;
  private App $app;
  private Camera $camera;
  private array $config;
  private int $slot = 1792490400;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/deferred-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0700);
    mkdir($this->root . '/private', 0700);
    mkdir($this->root . '/backup', 0700);
    file_put_contents($this->root . '/private/.webcam-private-storage', 'isolated test');
    file_put_contents($this->root . '/backup/.webcam-private-storage', 'isolated test');
    mkdir($this->root . '/public', 0700);
    $data = ['id' => 'bielefeld', 'slug' => 'bielefeld', 'name' => 'Bielefeld', 'title' => 'Bielefeld', 'location' => ['latitude' => 52.0, 'longitude' => 8.5],
      'mode' => 'active-capture', 'capture' => ['interval_seconds' => 600, 'image_processing' => [
        'enabled' => true, 'crop' => ['bottom' => 30], 'gamma' => 1.0,
        'regions' => [['type' => 'mask', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'color' => '#000000']]]],
      'storage' => ['base_path' => $this->root . '/storage', 'archive_dir' => 'archive', 'current_dir' => 'current'],
      'resolutions' => [240, 400, 1600], 'weather_commands' => [], 'tags' => []];
    file_put_contents($this->root . '/cameras.php', '<?php return ' . var_export([$data], true) . ';');
    $this->config = ['timezone' => 'Europe/Berlin', 'camera_config_path' => $this->root . '/cameras.php',
      'storage_path' => $this->root . '/storage', 'archive_path' => $this->root . '/archive',
      'cache_path' => $this->root . '/public/cache', 'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/logs', 'info_path' => $this->root . '/info', 'camera_db_path' => $this->root . '/db',
      'current_project_root' => $this->root . '/public', 'deferred_image_root' => $this->root . '/private',
      'deferred_image_backup_root' => $this->root . '/backup', 'deferred_image_cameras' => ['bielefeld'], 'archive_mount_sentinel_required' => false];
    $this->app = new App($this->config);
    $this->camera = $this->app->cameraOrFail('bielefeld');
    $store = new CameraDataStore($this->app, $this->camera);
    $store->ensureWritableSchema();
    DeferredImageSchema::install($store->pdo());
    // Verhindert reale Backup-Hintergrundprozesse aus dem isolierten Capturetest.
    file_put_contents($this->app->paths()->statePath('backup_db.json'), json_encode([
      'ts' => time(), 'status' => 'ok', 'scope' => 'full',
    ]));
  }

  protected function tearDown(): void
  {
    unset($this->app);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($this->root);
  }

  private function jpeg(int $color = 0xff3300): string
  {
    $im = imagecreatetruecolor(1800, 300);
    imagefill($im, 0, 0, $color);
    ob_start(); imagejpeg($im, null, 95); $binary = ob_get_clean(); imagedestroy($im);
    return $binary;
  }

  private function store(?int $slot = null, ?string $binary = null): array
  {
    return (new ImageStorage($this->app))->storeSnapshot($this->camera, $binary ?? $this->jpeg(), ($slot ?? $this->slot) + 10, $slot ?? $this->slot);
  }

  private function path(array $result, string $variant): string
  {
    $parts = DeferredImages::parse($result['relative_path']);
    return DeferredImages::relative($this->camera, $parts[1], $variant);
  }

  public function testConflictingUploadIsExplicitAndNextSlotStillWorks(): void
  {
    $ingestor = new \Webcam\Service\Upload\UploadIngestor($this->app);
    $first = $this->jpeg();
    self::assertSame(200, $ingestor->ingest($this->camera, $first, $this->slot)['status']);
    $source = $this->app->deferredImages()->sourcePath($this->camera, hash('sha256', $first), $this->slot);
    $second = $this->jpeg(0x0033ff);
    $result = $ingestor->ingest($this->camera, $second, $this->slot + 39);
    self::assertSame(409, $result['status']);
    self::assertSame(['ok' => false, 'error' => 'immutable_slot_conflict',
      'timestamp' => $this->slot + 39, 'slot_timestamp' => $this->slot,
      'sha256' => hash('sha256', $second)], $result['payload']);
    self::assertSame($first, file_get_contents($source));
    self::assertSame(200, $ingestor->ingest($this->camera, $first, $this->slot)['status']);
    self::assertSame(200, $ingestor->ingest($this->camera, $second, $this->slot + 600)['status']);
  }

  public function testPrivateSourceCurrentPreviewsAndLazyFullImage(): void
  {
    $binary = $this->jpeg();
    $result = $this->store(null, $binary);
    $source = $this->app->deferredImages()->sourcePath($this->camera, hash('sha256', $binary), $this->slot);
    self::assertSame($binary, file_get_contents($source));
    self::assertSame(0600, fileperms($source) & 0777);
    $current = $this->root . '/public/cache/bielefeld/current/';
    foreach (['raw', 'full', 'hu', '240', '400', '1600', 'current'] as $variant) self::assertFileExists($current . $variant . '.jpg');
    self::assertSame([1800, 270], array_slice(getimagesize($current . 'raw.jpg'), 0, 2));
    $img = imagecreatefromjpeg($current . 'raw.jpg');
    $pixel = imagecolorat($img, 50, 50);
    foreach ([0, 8, 16] as $shift) self::assertLessThan(15, ($pixel >> $shift) & 255);
    imagedestroy($img);
    self::assertFileExists($result['named_variants']['lm']);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
    $full = $this->app->resolveCameraStoragePath($this->camera, $this->path($result, 'full'));
    self::assertSame([1800, 270], array_slice(getimagesize($full), 0, 2));
    touch($full, time() - 1000); clearstatcache(true, $full);
    $mtime = filemtime($full);
    self::assertSame($full, $this->app->resolveCameraStoragePath($this->camera, $this->path($result, 'full')));
    clearstatcache(true, $full); self::assertSame($mtime, filemtime($full), 'Second read must not regenerate');
    self::assertSame([], glob($this->root . '/private/bielefeld/work/*'));
  }

  public function testDatedLayoutAndPreservedConflictingOriginal(): void
  {
    $stamp = (new DateTimeImmutable('@'.$this->slot))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $expected = $this->root.'/private/bielefeld/'.$stamp->format('Y/m/d/Ymd_His');
    $result = $this->store();
    self::assertFileExists($expected.'.jpg');
    self::assertSame($expected.'_lm.jpg', $result['named_variants']['lm']);
    self::assertFileExists($expected.'_ex.txt');
    $before = hash_file('sha256', $expected.'.jpg');
    try {
      $other = $this->jpeg(0x00ff00);
      $this->app->deferredImages()->saveSource($this->camera, hash('sha256',$other),$this->slot,$other,null);
      self::fail('A different existing original must not be replaced');
    } catch (RuntimeException $e) {
      self::assertStringContainsString('hash mismatch',$e->getMessage());
    }
    self::assertSame($before,hash_file('sha256',$expected.'.jpg'));
  }

  public function testRepeatedLocalHourCannotOverwriteAnotherSlotEvenWithIdenticalPixels(): void
  {
    $first = (new DateTimeImmutable('2026-10-25T02:10:00+02:00'))->getTimestamp();
    $second = (new DateTimeImmutable('2026-10-25T02:10:00+01:00'))->getTimestamp();
    $this->store($first);
    $path = $this->root.'/private/bielefeld/2026/10/25/20261025_021000_lm.jpg';
    $before=hash_file('sha256',$path);
    $result=$this->store($second);
    self::assertSame($before,hash_file('sha256',$path));
    self::assertNotNull($this->app->deferredImages()->forSlot($this->camera,$second));
    self::assertStringEndsWith('20261025_021000_utc'.$second.'_lm.jpg',$result['named_variants']['lm']);
    self::assertFileExists($result['named_variants']['lm']);
  }

  public function testLegacyUrlsDistinguishBothFallbackHours(): void
  {
    $first = 1792887000;
    $second = 1792890600;
    $this->store($first);
    $this->store($second, $this->jpeg(0x0033ff));
    $resolver = $this->app->deferredImages();
    self::assertStringContainsString($resolver->forSlot($this->camera, $first)['image_key'],
      $resolver->fromLegacyPath('cache/bielefeld/2026/10/25/20261025_021000_lm.jpg'));
    self::assertStringContainsString($resolver->forSlot($this->camera, $second)['image_key'],
      $resolver->fromLegacyPath('cache/bielefeld/2026/10/25/20261025_021000_utc'.$second.'_lm.jpg'));
    self::assertNull($resolver->fromLegacyPath('cache/bielefeld/2026/10/25/20261025_022000_utc'.$second.'_lm.jpg'));
  }

  public function testWebrootWithoutRootPolicyIsRejected(): void
  {
    $root=$this->root.'/public/orgin';mkdir($root,0700);
    file_put_contents($root.'/.webcam-private-storage','marker is insufficient');
    $app=new App(array_replace($this->config,['deferred_image_root'=>$root]));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('outside public roots');
    $app->deferredImages()->root();
  }

  public function testCliWorkingDirectoryDoesNotBecomeForbiddenStorageRoot(): void
  {
    $config=$this->config;unset($config['current_project_root']);
    $cwd=getcwd();
    try {
      chdir('/');
      $app=new App($config);
      self::assertSame($this->root.'/private',$app->deferredImages()->root());
      self::assertSame($this->root.'/backup',$app->deferredImages()->backupRoot());
    } finally { chdir($cwd); }
  }

  public function testSymlinkInDatedSourcePathIsRejected(): void
  {
    mkdir($this->root.'/private/bielefeld',0700);
    symlink($this->root.'/backup',$this->root.'/private/bielefeld/2026');
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Symlink');
    $this->app->deferredImages()->sourcePath($this->camera,str_repeat('a',64),$this->slot);
  }

  public function testMountGuardRejectsMissingReadonlyForeignAndNestedMounts(): void
  {
    $root='/private/orgin';
    $expected=['mountpoint'=>$root,'source'=>'//nas/share/orgin','fstype'=>'cifs'];
    $valid="10 1 0:5 / $root rw,nosuid - cifs //nas/share/orgin rw,vers=3.1.1\n";
    $guard=\Webcam\Service\Storage\DeferredStorageGuard::class;
    self::assertTrue($guard::matchesMount($valid,$root,$expected));
    foreach ([
      '', str_replace(' rw,nosuid',' ro,nosuid',$valid),
      str_replace(' rw,vers',' ro,vers',$valid),
      str_replace('//nas/share/orgin','//other/share/orgin',$valid),
      str_replace(' - cifs ',' - ext4 ',$valid),
      str_replace(" $root rw", " /private rw",$valid),
    ] as $invalid) self::assertFalse($guard::matchesMount($invalid,$root,$expected));
    self::assertFalse($guard::matchesMount($valid."11 10 0:6 / $root/child rw - tmpfs tmpfs rw\n",$root.'/child',$expected));
    self::assertTrue($guard::matchesMount(str_replace($root,'/private/a\\040b',$valid),'/private/a b',array_replace($expected,['mountpoint'=>'/private/a b'])));
  }

  public function testTimelineOffersLargeVariantWithoutRenderingAndDayvideoUsesPreview(): void
  {
    $result = $this->store();
    $store = new CameraDataStore($this->app, $this->camera, null, true);
    $rows = $store->capturesForRange($this->slot, $this->slot, 10);
    $entry = (new ArchiveDatabaseReader($this->app, new TimelineMapper()))->entryFromRow($this->camera, $rows[0], new DateTimeZone('Europe/Berlin'));
    self::assertArrayHasKey('full', $entry['variants']);
    self::assertStringStartsWith('image.php?image=', $entry['variants']['full']['url']);
    $resolver = new ArchiveEntryImageResolver($this->root . '/public', fn($path) => $this->app->deferredImages()->available($this->camera, $path));
    self::assertSame('full', $resolver->resolve($entry, ['full'])['variant']);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
    $frames = (new \Webcam\Service\Dayvideo\DayvideoFrameSelector($this->app))->select([$entry], 10, 1200);
    self::assertCount(1, $frames); self::assertSame(1200, getimagesize($frames[0])[0]);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
  }

  public function testCalendarIncludesPrivateMonthsAlongsideExistingLegacyFolders(): void
  {
    $this->store();
    mkdir($this->root . '/public/cache/bielefeld/2026/04', 0700, true);
    $browser = new \Webcam\Service\Storage\ArchiveBrowser($this->app);
    self::assertContains('2026', $browser->listYears($this->camera)['current']);
    self::assertSame([4, 10], $browser->availableMonths($this->camera, [2026])[2026]);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
  }

  public function testRecipeSurvivesConfigurationChangeReplayAndDisablingPilot(): void
  {
    $result = $this->store();
    $data = (require $this->root . '/cameras.php')[0];
    $data['capture']['image_processing']['crop']['bottom'] = 60;
    $changed = new Camera($data);
    $replay = (new ImageStorage($this->app))->storeSnapshot($changed, $this->jpeg(), $this->slot + 200, $this->slot);
    self::assertTrue($replay['replay']); self::assertSame($result['relative_path'], $replay['relative_path']);
    self::assertSame(270, getimagesize($this->root . '/public/cache/bielefeld/current/raw.jpg')[1]);
    $disabled = new App(array_replace($this->config, ['deferred_image_cameras' => []]));
    self::assertFileExists($disabled->resolveCameraStoragePath($changed, $this->path($result, 'full')));
    $this->expectException(RuntimeException::class);
    (new ImageStorage($disabled))->storeSnapshot($changed, $this->jpeg(0x00ff00), $this->slot + 10, $this->slot);
  }

  public function testLateCaptureDoesNotReplaceCurrent(): void
  {
    $this->store($this->slot + 600);
    $current = $this->root . '/public/cache/bielefeld/current/raw.jpg';
    $hash = hash_file('sha256', $current);
    $late = $this->store($this->slot, $this->jpeg(0x00ff00));
    self::assertFalse($late['current_published']); self::assertSame($hash, hash_file('sha256', $current));
  }

  public function testRetentionOnlyReportsAndPreservesLegacyFiles(): void
  {
    $result = $this->store();
    $full = $this->app->resolveCameraStoragePath($this->camera, $this->path($result, 'full'));
    touch($full, time() - 31 * 86400);
    $legacy = $this->root . '/public/cache/bielefeld/old.jpg';
    file_put_contents($legacy, $this->jpeg()); touch($legacy, time() - 400 * 86400);
    $before = hash_file('sha256', $full);
    self::assertSame(1, $this->app->deferredImages()->retentionReport($this->camera, time())['files']);
    (new CacheRetentionManager($this->app))->run($this->camera, dirname($legacy), time());
    self::assertFileExists($legacy); self::assertSame($before, hash_file('sha256', $full));
  }

  public function testInvalidPathsUnknownVariantsAndDamagedRecipeFailClosed(): void
  {
    $result = $this->store();
    self::assertNull(DeferredImages::parse('derived/../sources/raw.jpg'));
    self::assertNull(DeferredImages::parse($this->path($result, 'full') . '?x=1'));
    self::assertFalse($this->app->deferredImages()->available($this->camera, $this->path($result, '1234')));
    $db = (new CameraDataStore($this->app, $this->camera))->pdo();
    $db->exec("UPDATE deferred_images SET recipe_json='{}'");
    $fresh = new App($this->config);
    self::assertFalse($fresh->deferredImages()->available($this->camera, $this->path($result, 'full')));
    $this->expectException(RuntimeException::class);
    $fresh->resolveCameraStoragePath($this->camera, $this->path($result, 'full'));
  }

  public function testMissingMigrationDoesNotPublishOrCreateSchema(): void
  {
    (new CameraDataStore($this->app, $this->camera))->pdo()->exec('DROP TABLE deferred_images');
    try { $this->store(); self::fail('Migration must be explicit'); }
    catch (RuntimeException $error) { self::assertStringContainsString('migration required', $error->getMessage()); }
    self::assertDirectoryDoesNotExist($this->root . '/public/cache/bielefeld/current');
    self::assertFalse(DeferredImageSchema::exists((new CameraDataStore($this->app, $this->camera))->pdo()));
  }

  public function testPublicPrivateRootIsRejected(): void
  {
    $app = new App(array_replace($this->config, ['deferred_image_root' => $this->root . '/public/cache']));
    $this->expectException(RuntimeException::class);
    $app->deferredImages()->root();
  }

  public function testOriginalLossNeverReturnsRawOrAnUnregisteredFallback(): void
  {
    $result = $this->store();
    $source = $this->app->deferredImages()->sourcePath($this->camera, hash('sha256', $this->jpeg()), $this->slot);
    rename($source, $source . '.offline');
    self::assertFalse((new DeferredImages($this->app))->available($this->camera, $this->path($result, 'full')));
    $this->expectException(RuntimeException::class);
    (new DeferredImages($this->app))->resolve($this->camera, $this->path($result, 'full'));
  }

  public function testCurrentPublicationFailureCanBeRepairedBySameUpload(): void
  {
    $current = $this->root . '/public/cache/bielefeld/current';
    mkdir($current, 0700, true);
    mkdir($current . '/raw.jpg'); // Verhindert atomare Dateipublikation auch als root.
    try { $this->store(); self::fail('Broken Current must not ACK'); }
    catch (RuntimeException $error) { self::assertStringContainsString('Current image publication failed', $error->getMessage()); }
    rmdir($current . '/raw.jpg');
    $result = $this->store();
    self::assertTrue($result['replay']); self::assertFileExists($current . '/raw.jpg');
    self::assertSame(1, (int) (new CameraDataStore($this->app, $this->camera))->pdo()->query('SELECT count(*) FROM captures')->fetchColumn());
  }

  public function testDuplicateFrameIsMarkedAndDoesNotAdvanceCurrent(): void
  {
    $this->store();
    $duplicate = $this->store($this->slot + 600);
    self::assertTrue($duplicate['duplicate']); self::assertFalse($duplicate['current_published']);
    self::assertFalse($this->app->deferredImages()->available($this->camera, $duplicate['relative_path']));
  }

  public function testBestOfKeepsDistinctTimestampedFiles(): void
  {
    $a = $this->store(); $b = $this->store($this->slot + 600, $this->jpeg(0x00ff00));
    $paths = [];
    foreach ([$a, $b] as $capture) {
      $relative = $this->path($capture, 'lm');
      $resolver = new \Webcam\Service\Interaction\BestOfSourceResolver($this->app);
      self::assertTrue($resolver->belongsToCamera($relative, 'bielefeld'));
      self::assertFalse($resolver->belongsToCamera($relative, 'stromberg'));
      $resolved = $resolver->resolveSource($this->camera, $relative);
      $result = (new \Webcam\Service\Interaction\BestOfLikeCoordinator($this->app))->execute($this->camera, $resolved['source_path'], fn() => ['ok' => true]);
      self::assertMatchesRegularExpression('/^20261020_\d{6}_lm\.jpg$/', $result['base_name']);
      $paths[] = $result['target_path'];
    }
    self::assertNotSame($paths[0], $paths[1]);
    foreach ($paths as $file) self::assertFileExists($file);
  }

  public function testPrivateBackupIsConsistentWithSnapshotAndRestorable(): void
  {
    $first = $this->store();
    $db = (new CameraDataStore($this->app, $this->camera))->pdo();
    $snapshot = $this->root . '/snapshot.sqlite';
    $db->exec('VACUUM INTO ' . $db->quote($snapshot));
    $this->store($this->slot + 600, $this->jpeg(0x00ff00));
    $backup = new \Webcam\Service\Backup\DeferredImageBackup($this->app);
    self::assertSame(1, $backup->backup($this->camera, $snapshot, '20261020_120000'));
    $dir = $this->root . '/backup/bielefeld';
    self::assertCount(1, glob($dir . '/sources/*.jpg'));
    self::assertSame($this->jpeg(), file_get_contents($dir . '/sources/' . hash('sha256', $this->jpeg()) . '.jpg'));
    $restoredDb = new PDO('sqlite:file:' . $dir . '/db-20261020_120000.sqlite?mode=ro');
    self::assertSame('ok', $restoredDb->query('PRAGMA quick_check')->fetchColumn());
    self::assertSame(1, (int) $restoredDb->query('SELECT count(*) FROM captures')->fetchColumn());
    $row = $restoredDb->query('SELECT * FROM deferred_images')->fetch(PDO::FETCH_ASSOC);
    self::assertSame($this->path($first, 'original'), DeferredImages::relative($this->camera, $row['image_key'], 'original'));
    $recipe = $this->app->deferredImages()->decodeRecipe($row);
    $raw = imagecreatefromjpeg($dir . '/sources/' . $row['source_hash'] . '.jpg');
    $processed = (new \Webcam\Service\Storage\ImageProcessor())->apply($raw, $recipe['processing']);
    self::assertSame(270, imagesy($processed)); imagedestroy($processed); imagedestroy($raw);
  }

  public function testDailyArchiveAcceptsPrivateContractWithoutGeneratingLargeCopies(): void
  {
    $this->store();
    $day = (new DateTimeImmutable('@' . $this->slot))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $result = (new \Webcam\Service\Storage\DailyArchiver($this->app))->archiveDay($this->camera, $day);
    self::assertFalse($result['missing']); self::assertSame(1, $result['deferred_verified']);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
  }

  public function testAuthenticatedPreviewStillUsesOriginalWhenFiltersWereDisabled(): void
  {
    $data = (require $this->root . '/cameras.php')[0];
    $data['capture']['image_processing']['enabled'] = false;
    $camera = new Camera($data);
    (new ImageStorage($this->app))->storeSnapshot($camera, $this->jpeg(), $this->slot, $this->slot);
    $preview = (new \Webcam\Service\Storage\ImageProcessingPreview($this->app))->render($camera, [
      'enabled' => true, 'crop' => ['bottom' => 100],
    ]);
    self::assertSame(300, $preview['height']); self::assertSame(200, $preview['result_height']);
  }

  public function testHttpDeliveryAndMethodValidation(): void
  {
    $capture = $this->store();
    file_put_contents($this->root . '/http-config.php', '<?php return ' . var_export($this->config, true) . ';');
    $router = $this->root . '/router.php';
    file_put_contents($router, '<?php require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . '; $app = new \\Webcam\\App(require __DIR__ . "/http-config.php"); require ' . var_export(dirname(__DIR__) . '/image.php', true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/http.log', 'a'], 2 => ['file', $this->root . '/http.log', 'a']], $pipes);
    self::assertIsResource($process);
    try {
      $ready = false;
      for ($i=0; $i<50; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $message, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(20000);
      }
      self::assertTrue($ready);
      $url = 'http://' . $address . '/' . DeferredImages::url($this->path($capture, 'full'));
      $body = file_get_contents($url);
      self::assertSame([1800, 270], array_slice(getimagesizefromstring($body), 0, 2));
      self::assertStringContainsString('200', $http_response_header[0]);
      self::assertContains('Content-Type: image/jpeg', $http_response_header);
      $again = file_get_contents($url); self::assertSame($body, $again);
      $date = (new DateTimeImmutable('@' . $this->slot))->setTimezone(new DateTimeZone('Europe/Berlin'));
      $legacy = 'cache/bielefeld/' . $date->format('Y/m/d/Ymd_His') . '_full.jpg';
      self::assertSame($body, file_get_contents('http://' . $address . '/image.php?legacy=' . rawurlencode($legacy)));
      file_get_contents($url, false, stream_context_create(['http' => ['method' => 'HEAD']]));
      self::assertStringContainsString('200', $http_response_header[0]);
      $bad = file_get_contents('http://' . $address . '/image.php?image=../../sources/raw.jpg', false, stream_context_create(['http' => ['ignore_errors' => true]]));
      self::assertStringContainsString('404', $http_response_header[0]); self::assertSame('', $bad);
      file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true]]));
      self::assertStringContainsString('405', $http_response_header[0]);
    } finally { proc_terminate($process); proc_close($process); }
  }

  public function testLegacyPathValidationAndMetadataPreviewDoesNotRender(): void
  {
    $capture = $this->store();
    $date = (new DateTimeImmutable('@' . $this->slot))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $legacy = 'bielefeld/' . $date->format('Y/m/d/Hi') . '_hu.jpg';
    self::assertSame($this->path($capture, 'hu'), $this->app->deferredImages()->fromLegacyPath($legacy));
    foreach (['../../sources/raw.jpg', str_replace('/10/', '/13/', $legacy), str_replace('bielefeld', 'stromberg', $legacy)] as $bad) {
      self::assertNull($this->app->deferredImages()->fromLegacyPath($bad));
    }
    (new \Webcam\Service\Admin\CameraCaptureEditor($this->app))->preview('bielefeld', 10);
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
  }

  public function testUploadAcknowledgesRecipeAndReplayWithoutSecondCapture(): void
  {
    $upload = new \Webcam\Service\Upload\UploadIngestor($this->app);
    $first = $upload->ingest($this->camera, $this->jpeg(), $this->slot);
    self::assertSame(200, $first['status']);
    self::assertTrue($first['payload']['ok']);
    self::assertStringStartsWith('derived/bielefeld/', $first['payload']['path']);
    $again = $upload->ingest($this->camera, $this->jpeg(), $this->slot);
    self::assertSame(200, $again['status']); self::assertTrue($again['payload']['duplicate']);
    self::assertSame($first['payload']['path'], $again['payload']['path']);
    $db = (new CameraDataStore($this->app, $this->camera, null, true))->pdo();
    self::assertSame(1, (int) $db->query('SELECT count(*) FROM captures')->fetchColumn());
    self::assertSame(1, (int) $db->query('SELECT count(*) FROM deferred_images')->fetchColumn());
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld/cache');
  }

  public function testTruncatedCacheIsRebuiltAndTamperedSourceIsRejected(): void
  {
    $capture = $this->store();
    $path = $this->app->deferredImages()->resolve($this->camera, $this->path($capture, 'full'));
    $bytes = file_get_contents($path);
    file_put_contents($path, substr($bytes, 0, -50));
    self::assertSame($path, $this->app->deferredImages()->resolve($this->camera, $this->path($capture, 'full')));
    self::assertSame($bytes, file_get_contents($path));
    $source = $this->app->deferredImages()->sourcePath($this->camera, hash('sha256', $this->jpeg()), $this->slot);
    file_put_contents($source, $this->jpeg(0x00ff00));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Private original changed');
    $this->app->deferredImages()->resolve($this->camera, $this->path($capture, '1600'));
  }

  public function testPublicMissBudgetDoesNotBlockCachedImages(): void
  {
    $capture = $this->store();
    $path = $this->app->deferredImages()->resolve($this->camera, $this->path($capture, 'full'), true);
    file_put_contents($this->root . '/private/render-budget.json', json_encode(['window' => intdiv(time(), 60), 'count' => 20]));
    self::assertSame($path, $this->app->deferredImages()->resolve($this->camera, $this->path($capture, 'full'), true));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('budget exhausted');
    $this->app->deferredImages()->resolve($this->camera, $this->path($capture, '1600'), true);
  }

  public function testMissingMountMarkerStopsUploadBeforeCreatingCapture(): void
  {
    unlink($this->root . '/private/.webcam-private-storage');
    try {
      $this->store();
      self::fail('An empty mountpoint must not accept originals');
    } catch (RuntimeException $error) {
      self::assertStringContainsString('mount marker missing', $error->getMessage());
    }
    $db = (new CameraDataStore($this->app, $this->camera, null, true))->pdo();
    self::assertSame(0, (int) $db->query('SELECT count(*) FROM captures')->fetchColumn());
    self::assertDirectoryDoesNotExist($this->root . '/private/bielefeld');
  }

  public function testConcurrentProcessReusesResultPublishedUnderLock(): void
  {
    $capture = $this->store();
    $relative = $this->path($capture, 'full');
    $lock = fopen($this->root . '/private/render.lock', 'c');
    flock($lock, LOCK_EX);
    $script = $this->root . '/concurrent.php';
    file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/autoload.php', true)
      . '; $app=new \Webcam\App(' . var_export($this->config, true) . ');'
      . 'file_put_contents(__DIR__ . "/ready", "1");'
      . '$path=$app->deferredImages()->resolve($app->cameraOrFail("bielefeld"),' . var_export($relative, true) . ');'
      . 'echo hash_file("sha256",$path);');
    $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/child.out', 'w'], 2 => ['file', $this->root . '/child.err', 'w']], $pipes);
    self::assertIsResource($process);
    try {
      for ($i=0; $i<100 && !is_file($this->root . '/ready'); $i++) usleep(20000);
      self::assertFileExists($this->root . '/ready');
      [$row, $recipe, $variant] = $this->app->deferredImages()->record($this->camera, $relative);
      $path = $this->app->deferredImages()->outputPath($this->camera, $row['image_key'], $variant, $recipe, $this->slot);
      // Simulate the first request finishing while the other is waiting.
      copy($this->root . '/public/cache/bielefeld/current/full.jpg', $path);
      touch($path, time()-500);
      clearstatcache(true, $path); $mtime = filemtime($path);
      flock($lock, LOCK_UN);
      for ($i=0; $i<200 && proc_get_status($process)['running']; $i++) usleep(20000);
      self::assertFalse(proc_get_status($process)['running']);
      self::assertSame(hash_file('sha256', $path), file_get_contents($this->root . '/child.out'));
      clearstatcache(true, $path); self::assertSame($mtime, filemtime($path));
    } finally {
      flock($lock, LOCK_UN); fclose($lock);
      if (proc_get_status($process)['running']) proc_terminate($process);
      proc_close($process);
    }
  }

}
