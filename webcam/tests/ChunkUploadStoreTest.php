<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Webcam\App;
use Webcam\Service\Upload\ChunkUploadStore;
use Webcam\Service\Upload\UploadIngestor;

final class ChunkUploadStoreTest extends TestCase
{
  private string $root;
  private string $slug;
  private string $cameraConfig;

  protected function setUp(): void
  {
    $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR . 'webcam-chunk-store-' . bin2hex(random_bytes(6));
    $this->slug = 'chunk-upload-test-' . bin2hex(random_bytes(4));
    $this->assertTrue(mkdir($this->root, 0770, true));
    $this->cameraConfig = $this->root . '/cameras.php';
    $config = [[
      'id' => $this->slug,
      'slug' => $this->slug,
      'name' => 'Chunk Upload Test',
      'title' => 'Chunk Upload Test',
      'location' => ['latitude' => 0, 'longitude' => 0],
      'mode' => 'active-capture',
      'capture' => ['interval_seconds' => 600, 'upload_key' => 'unit-test-upload-key'],
      'storage' => ['current_dir' => 'current', 'archive_dir' => 'archive', 'best_of_dir' => 'bestof'],
      'status' => [],
      'stream' => ['mode' => 'none'],
      'resolutions' => [400],
      'weather_commands' => [],
    ]];
    file_put_contents($this->cameraConfig, '<?php return ' . var_export($config, true) . ';');
  }

  protected function tearDown(): void
  {
    $this->removePath($this->root);
    $projectCameraPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $this->slug;
    $this->removePath($projectCameraPath);
    $this->removePath(dirname(__DIR__) . '/cache/' . $this->slug);
  }

  public function testDedupeLocksStayBoundedAndKeepTheirInodes(): void
  {
    $app=$this->makeApp();
    $ingest=new UploadIngestor($app);
    $acquire=new ReflectionMethod($ingest,'acquireDedupeLock');
    $release=new ReflectionMethod($ingest,'releaseDedupeLock');
    for ($slot=1700000000; $slot<1700000200; $slot++) {
      $handle=$acquire->invoke($ingest,$this->slug,$slot);
      self::assertIsResource($handle);
      $release->invoke($ingest,$handle);
    }
    $files=glob($app->paths()->statePath('upload-dedupe/*.lock'));
    self::assertLessThanOrEqual(64,count($files));
    $first=$acquire->invoke($ingest,$this->slug,1700000000);
    $inode=fstat($first)['ino'];
    $release->invoke($ingest,$first);
    $again=$acquire->invoke($ingest,$this->slug,1700000000);
    self::assertSame($inode,fstat($again)['ino']);
    $release->invoke($ingest,$again);
  }

  public function testLateCaptureKeepsSlotButUsesPhysicalTimeForWeatherAndDatabase(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $ingest = new UploadIngestor($app);
    $captured = time() - 5;
    $slot = intdiv($captured - 3600, 600) * 600;
    $path = $this->root . '/physical.jpg';
    file_put_contents($path, $this->largeJpeg());
    $weather = json_encode(['source'=>'dht22','measured_at'=>$captured-10,'captured_at'=>$captured,
      'temperature_c'=>22.5,'humidity_percent'=>58.0]);
    $result = $ingest->ingestFile($camera,$path,$slot,$weather,$captured);
    self::assertSame(200,$result['status']);
    self::assertSame($slot,$result['payload']['slot_timestamp']);
    $db = (new \Webcam\Infrastructure\Storage\CameraDataStore($app,$camera))->pdo();
    self::assertSame($captured,(int)$db->query('SELECT captured_ts FROM captures')->fetchColumn());
    $original=$app->paths()->cachePath(substr($result['payload']['path'],6));
    self::assertStringContainsString('22.5',file_get_contents(substr($original,0,-4).'_WX.txt'));
    self::assertSame($captured,$app->statusService()->state($camera)['last_capture_at']);
    self::assertNull($ingest->parseCapturedAt(null));
    foreach (['bad', [], time()+120] as $bad) {
      try { $ingest->parseCapturedAt($bad); self::fail('Bad physical time accepted'); }
      catch (InvalidArgumentException) { self::assertTrue(true); }
    }
  }

  public function testRejectedWeatherKeepsImageAckButDoesNotReuseOldWeather(): void
  {
    $config = require $this->cameraConfig;
    $config[0]['weather_commands'] = ['printf "Temp: 8.9 C"; exit 2'];
    file_put_contents($this->cameraConfig, '<?php return ' . var_export($config, true) . ';');
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $app->statusService()->recordSuccess($camera, weatherData: 'Temp: 8.9 C');
    $now = time();
    $local = json_encode(['source' => 'dht22', 'measured_at' => $now,
      'captured_at' => $now, 'temperature_c' => 25, 'humidity_percent' => 60]);
    $path = $this->root . '/weather-failure.jpg';
    file_put_contents($path, $this->largeJpeg());
    $result = (new UploadIngestor($app))->ingestFile($camera, $path, $now, $local, $now);
    self::assertSame(200, $result['status']);
    self::assertTrue($result['payload']['ok']);
    self::assertFalse($result['payload']['duplicate']);
    $original = $app->paths()->cachePath(substr($result['payload']['path'], 6));
    self::assertFileExists($original);
    self::assertFileDoesNotExist(substr($original, 0, -4) . '_WX.txt');
    $db = (new \Webcam\Infrastructure\Storage\CameraDataStore($app, $camera))->pdo();
    self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM weather_entries')->fetchColumn());
    $state = $app->statusService()->state($camera);
    self::assertSame('', $state['last_weather_data']);
    self::assertNull($state['last_weather_path']);
    self::assertSame(0, $state['consecutive_failures']);
  }

  public function testDelayedUploadCannotReplaceCurrentOrStatus(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $ingest = new UploadIngestor($app);
    $slot = intdiv(time(), 600) * 600;
    $new = $this->largeJpeg();
    self::assertSame(200, $ingest->ingest($camera, $new, $slot)['status']);
    $current = (new \Webcam\Infrastructure\Filesystem\CurrentImageDirectory(dirname(__DIR__)))->path($this->slug);
    self::assertSame(200, $ingest->ingest($camera, $this->largeJpeg(), $slot - 600)['status']);
    self::assertSame(hash('sha256', $new), hash_file('sha256', $current . '/raw.jpg'));
    self::assertSame($slot, $app->statusService()->state($camera)['last_capture_at']);
    // An installation without a watermark still uses the existing DB head.
    unlink($app->paths()->statePath('current-publication/' . $this->slug . '.slot'));
    self::assertSame(200, $ingest->ingest($camera, $this->largeJpeg(), $slot - 1200)['status']);
    self::assertSame(hash('sha256', $new), hash_file('sha256', $current . '/raw.jpg'));
  }

  public function testCurrentPublicationFailureIsNotAcknowledgedAndRetryRepairs(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $ingest = new UploadIngestor($app);
    $slot = intdiv(time(), 600) * 600;
    $bytes = $this->largeJpeg();
    $current = (new \Webcam\Infrastructure\Filesystem\CurrentImageDirectory(dirname(__DIR__)))->prepare($this->slug);
    mkdir($current . '/raw.jpg');
    self::assertSame(500, $ingest->ingest($camera, $bytes, $slot)['status']);
    rmdir($current . '/raw.jpg');
    self::assertSame(200, $ingest->ingest($camera, $bytes, $slot)['status']);
    unlink($current . '/raw.jpg');
    mkdir($current . '/raw.jpg');
    self::assertSame(500, $ingest->ingest($camera, $bytes, $slot)['status']);
    rmdir($current . '/raw.jpg');
    self::assertSame(200, $ingest->ingest($camera, $bytes, $slot)['status']);
    self::assertSame(hash('sha256', $bytes), hash_file('sha256', $current . '/raw.jpg'));
  }

  public function testInvalidFramedJpegIsPermanentContentFailure(): void
  {
    $app=$this->makeApp();
    $result=(new UploadIngestor($app))->ingest($app->cameraOrFail($this->slug), "\xff\xd8".str_repeat('X',16380)."\xff\xd9", time());
    self::assertSame(422,$result['status']);
    self::assertSame(['ok'=>false,'error'=>'invalid_image'],$result['payload']);
  }

  public function testCaptureTimestampPreservesLegacyDayAndRejectsMalformedInput(): void
  {
    $app = $this->makeApp(['timezone' => 'Europe/Berlin']);
    $ingest = new UploadIngestor($app);
    $local = new DateTimeImmutable('2026-10-08 23:10:00', new DateTimeZone($app->config('timezone','UTC')));
    self::assertSame($local->getTimestamp(), $ingest->parseTimestamp('20261008_2310'));
    self::assertSame(1791493800, $ingest->parseTimestamp('1791493800'));
    self::assertSame(1791493800, $ingest->parseTimestamp('20261008_211000'));
    foreach (['', 'yesterday', '20260230_2300', '1e9', -1, [], '20261008_2500', "20261008_2310\0"] as $bad) {
      try { $ingest->parseTimestamp($bad); self::fail('Malformed timestamp accepted'); }
      catch (InvalidArgumentException) { self::assertTrue(true); }
    }
  }

  public function testDuplicateRetryRestoresLostOrCorruptOriginalAndKeepsMetadata(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $ingest = new UploadIngestor($app);
    $binary = $this->largeJpeg();
    $stamp = intdiv(time(), 600) * 600;
    $first = $ingest->ingest($camera, $binary, $stamp);
    self::assertSame(200, $first['status']);
    $path = $app->paths()->cachePath(substr($first['payload']['path'], 6));
    $sidecar = substr($path, 0, -4) . '_ex.txt';
    file_put_contents($sidecar, "HistoricalExtra|preserve-me\n");
    $uploadFile = $this->root . '/retry.jpg';
    file_put_contents($uploadFile, $binary);
    foreach (['missing', 'corrupt', 'file-retry'] as $case) {
      if ($case === 'missing') { unlink($path); }
      else { file_put_contents($path, str_repeat('x', strlen($binary))); }
      $retry = $case === 'file-retry' ? $ingest->ingestFile($camera, $uploadFile, $stamp)
        : $ingest->ingest($camera, $binary, $stamp);
      self::assertSame(200, $retry['status']);
      self::assertTrue($retry['payload']['duplicate']);
      self::assertSame(hash('sha256', $binary), hash_file('sha256', $path));
      self::assertSame("HistoricalExtra|preserve-me\n", file_get_contents($sidecar));
    }
    unlink($path);
    symlink($uploadFile, $path);
    $blocked = $ingest->ingestFile($camera, $uploadFile, $stamp);
    self::assertSame(503, $blocked['status']);
    self::assertFalse($blocked['payload']['ok']);
    self::assertTrue(is_link($path), 'A retry must not replace an unexpected symlink');
    unlink($path);
    copy($uploadFile, $path);
    $date = (new DateTimeImmutable('@'.$stamp))->setTimezone(new DateTimeZone($app->config('timezone','UTC')));
    $archive = $app->paths()->archivePath($this->slug . '/' . $date->format('Y/m/d/Hi') . '_hu.jpg');
    mkdir(dirname($archive), 0700, true);
    copy($path, $archive);
    unlink($path);
    self::assertSame(200, $ingest->ingest($camera, $binary, $stamp)['status']);
    self::assertFileDoesNotExist($path, 'Archived original must not repopulate cache');
  }

  public function testFinalizeAssemblesMultiplePartsAndUsesSharedIngest(): void
  {
    $app = $this->makeApp();
    $camera = $app->cameraOrFail($this->slug);
    $store = new ChunkUploadStore($app, new UploadIngestor($app));
    $binary = $this->largeJpeg();
    $chunkSize = ChunkUploadStore::MIN_CHUNK_BYTES;
    $totalChunks = (int) ceil(strlen($binary) / $chunkSize);
    $this->assertGreaterThan(1, $totalChunks, 'Fixture must exercise more than one part.');
    $timestamp = intdiv(time() - 3600, 600) * 600;
    $capturedAt = time() - 5;
    $hash = hash('sha256', $binary);
    $uploadId = substr(hash('sha256', $this->slug . ':' . $timestamp . ':' . $hash), 0, 32);
    $metadata = [
      'upload_id' => $uploadId,
      'timestamp' => $timestamp,
      'total_size' => strlen($binary),
      'chunk_size' => $chunkSize,
      'total_chunks' => $totalChunks,
      'sha256' => $hash,
      'captured_at' => $capturedAt,
    ];
    $uploadDir = $app->paths()->statePath('upload-chunks/' . $this->slug . '/' . $uploadId);
    $this->assertTrue(mkdir($uploadDir, 0770, true));
    file_put_contents($uploadDir . '/manifest.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    for ($index = 0; $index < $totalChunks; $index++) {
      file_put_contents(
        $uploadDir . '/' . sprintf('%06d.part', $index),
        substr($binary, $index * $chunkSize, $chunkSize)
      );
    }

    $lockPath = (new ReflectionMethod($store, 'lockPath'))->invoke($store, dirname($uploadDir), $uploadId);
    $waitingHandle = fopen($lockPath, 'c');
    $inode = fstat($waitingHandle)['ino'];
    $result = $store->finalize($camera, [
      'upload_id' => $uploadId,
      'now' => (string) $timestamp,
      'captured_at' => (string) $capturedAt,
      'total_size' => (string) strlen($binary),
      'chunk_size' => (string) $chunkSize,
      'total_chunks' => (string) $totalChunks,
      'sha256' => $hash,
      'local_weather' => json_encode(['source' => 'dht22', 'measured_at' => $capturedAt, 'captured_at' => $capturedAt,
        'temperature_c' => 23.3, 'humidity_percent' => 61.5]),
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['payload']['ok'] ?? false);
    $this->assertSame(ChunkUploadStore::PROTOCOL, $result['payload']['protocol'] ?? null);
    $this->assertSame($totalChunks, $result['payload']['chunks'] ?? null);
    $this->assertDirectoryDoesNotExist($uploadDir);
    $this->assertSame($inode, stat($lockPath)['ino'], 'Finalization must retain the lock inode.');
    fclose($waitingHandle);
    $relative = $result['payload']['path'] ?? null;
    $this->assertIsString($relative);
    $this->assertFileExists($app->paths()->cachePath(substr($relative, strlen('cache/'))));
    $original = $app->paths()->cachePath(substr($relative, strlen('cache/')));
    $weather = dirname($original) . '/' . pathinfo($original, PATHINFO_FILENAME) . '_WX.txt';
    $this->assertFileExists($weather);
    $this->assertStringContainsString('Temp: 23.3 C', file_get_contents($weather));
    $this->assertStringContainsString('Humidity: 61.5 %', file_get_contents($weather));
  }

  public function testConfiguredUploadLimitCannotExceedAbsoluteSafetyLimit(): void
  {
    $previous = getenv('WEBCAM_UPLOAD_MAX_BYTES');
    putenv('WEBCAM_UPLOAD_MAX_BYTES');
    try {
      $app = $this->makeApp([
        'upload_max_bytes' => UploadIngestor::ABSOLUTE_MAX_UPLOAD_BYTES * 4,
      ]);
      $ingestor = new UploadIngestor($app);

      $this->assertSame(UploadIngestor::ABSOLUTE_MAX_UPLOAD_BYTES, $ingestor->maxUploadBytes());
    } finally {
      if ($previous === false) {
        putenv('WEBCAM_UPLOAD_MAX_BYTES');
      } else {
        putenv('WEBCAM_UPLOAD_MAX_BYTES=' . $previous);
      }
    }
  }

  public function testBusyStableLocksFailPromptlyAndCleanupDoesNotReplaceThem(): void
  {
    $app = $this->makeApp();
    $store = new ChunkUploadStore($app, new UploadIngestor($app));
    $lockMethod = new ReflectionMethod($store, 'lockUpload');
    $unlock = new ReflectionMethod($store, 'unlock');
    $cleanup = new ReflectionMethod($store, 'cleanupStale');
    $id = str_repeat('a', 32);
    $dir = $this->root . '/' . $id;
    mkdir($dir);
    touch($dir, time() - 86400);
    $lock = $lockMethod->invoke($store, $this->root, $id);
    $inode = fstat($lock)['ino'];
    try {
      $start = microtime(true);
      $this->assertNull($lockMethod->invoke($store, $this->root, $id));
      $this->assertLessThan(0.5, microtime(true) - $start);
      $cleanup->invoke($store, $this->root);
      $this->assertDirectoryExists($dir);
    } finally {
      $unlock->invoke($store, $lock);
    }
    $cleanup->invoke($store, $this->root);
    $this->assertDirectoryDoesNotExist($dir);
    $lock = $lockMethod->invoke($store, $this->root, $id);
    $this->assertSame($inode, fstat($lock)['ino']);
    $unlock->invoke($store, $lock);
    $paths = [];
    $pathMethod = new ReflectionMethod($store, 'lockPath');
    for ($i = 0; $i < 256; $i++) {
      $paths[] = $pathMethod->invoke($store, $this->root, sprintf('%02x', $i) . str_repeat('0', 30));
    }
    $this->assertCount(64, array_unique($paths));
  }

  public function testUnavailableDedupeLockFailsClosed(): void
  {
    $app = $this->makeApp();
    $blockedLockDirectory = $app->paths()->statePath('upload-dedupe');
    $this->assertNotFalse(file_put_contents($blockedLockDirectory, 'not a directory'));
    $camera = $app->cameraOrFail($this->slug);

    $result = (new UploadIngestor($app))->ingest(
      $camera,
      $this->largeJpeg(),
      intdiv(time(), 600) * 600
    );

    $this->assertSame(503, $result['status']);
    $this->assertSame('upload lock unavailable', $result['payload']['error'] ?? null);
  }

  /** @param array<string,mixed> $overrides */
  private function makeApp(array $overrides = []): App
  {
    $config = [
      'timezone' => 'UTC',
      'camera_config_path' => $this->cameraConfig,
      'storage_path' => $this->root . '/storage',
      'archive_path' => $this->root . '/storage/archive',
      'state_path' => $this->root . '/state',
      'log_path' => $this->root . '/log',
      'info_path' => $this->root . '/info',
      'cache_path' => $this->root . '/cache',
      'camera_db_path' => $this->root . '/camera-db',
      'cache_retention_days' => 0,
      'upload_max_bytes' => 25 * 1024 * 1024,
      'storage_owner_user' => '',
      'storage_owner_group' => '',
      'base_url' => '',
    ];
    return new App($overrides + $config);
  }

  private function largeJpeg(): string
  {
    $image = imagecreatetruecolor(640, 480);
    $this->assertInstanceOf(GdImage::class, $image);
    for ($y = 0; $y < 480; $y += 3) {
      for ($x = 0; $x < 640; $x += 3) {
        $color = random_int(0, 0xffffff);
        imagefilledrectangle($image, $x, $y, min(639, $x + 2), min(479, $y + 2), $color);
      }
    }
    ob_start();
    imagejpeg($image, null, 95);
    $binary = ob_get_clean();
    imagedestroy($image);
    $this->assertIsString($binary);
    return $binary;
  }

  private function removePath(string $path): void
  {
    if (is_dir($path) && !is_link($path)) {
      foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
          $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
      }
      @rmdir($path);
      return;
    }
    @unlink($path);
  }
}
