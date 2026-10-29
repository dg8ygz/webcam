<?php
declare(strict_types=1);

final class HttpServer
{
  private static bool $initialized = false;
  private static ?string $baseUrl = null;
  private static string $nonce = '';
  private static ?string $tempConfigDb = null;
  private static ?string $tempRateLimitDb = null;
  /** @var resource|null */
  private static $process = null;

  public static function ensure(): void
  {
    if (self::$initialized) {
      return;
    }
    self::$initialized = true;

    $root = realpath(__DIR__ . '/../..');
    if ($root !== false) {
      $configDb = self::resolveConfigDbPath($root);
      $snapshot = self::snapshotConfigDb($configDb);
      if ($snapshot !== null) {
        self::$tempConfigDb = $snapshot;
        putenv('WEBCAM_CONFIG_DB=' . $snapshot);
      } else {
        throw new RuntimeException('Test configuration snapshot failed; refusing original DB');
      }
    }
    putenv('WEBCAM_ENV=test');
    // HTTP-Tests dürfen weder die produktive Rate-Limit-DB füllen noch von
    // Treffern eines früheren Testlaufs abhängen. Jeder PHPUnit-Prozess erhält
    // deshalb eine eigene, beim Shutdown wieder entfernte SQLite-Datei.
    $rateLimitDb = tempnam(self::tempDir(), 'webcam-rate-limit-test-');
    if ($rateLimitDb !== false) {
      @unlink($rateLimitDb);
      self::$tempRateLimitDb = $rateLimitDb . '.sqlite';
      putenv('WEBCAM_PUBLIC_RATE_DB=' . self::$tempRateLimitDb);
    }
    // Der lokale PHP-Testserver ist der unmittelbare Proxy. So können Tests
    // über X-Forwarded-For mehrere echte Client-IP-Budgets simulieren.
    putenv('WEBCAM_TRUSTED_PROXIES=' . (getenv('WEBCAM_TRUSTED_PROXIES') ?: '127.0.0.1,::1'));
    putenv('WEBCAM_MAINTENANCE_TICK_DISABLE=1');
    putenv('WEBCAM_ALLOW_PROD_WRITE=0');
    putenv('WEBCAM_HISTORY_API_LIMIT=' . (getenv('WEBCAM_HISTORY_API_LIMIT') ?: '5'));
    putenv('WEBCAM_CAPTCHA_LIMIT=' . (getenv('WEBCAM_CAPTCHA_LIMIT') ?: '3'));
    putenv('WEBCAM_ALLOW_SAMPLE_CONFIG=' . (getenv('WEBCAM_ALLOW_SAMPLE_CONFIG') ?: '1'));
    putenv('WEBCAM_ADMIN_API_TOKEN=' . (getenv('WEBCAM_ADMIN_API_TOKEN') ?: 'unittest-token'));
    register_shutdown_function([self::class, 'stop']);

    $envBase = getenv('WEBCAM_BASE_URL');
    if (is_string($envBase) && trim($envBase) !== '') {
      self::$nonce = (string) (getenv('WEBCAM_TEST_SERVER_NONCE') ?: '');
      if (!self::ping(rtrim($envBase, '/')) && getenv('WEBCAM_TEST_EXTERNAL_SERVER') !== '1') {
        throw new RuntimeException('External test server requires WEBCAM_TEST_EXTERNAL_SERVER=1');
      }
      self::$baseUrl = rtrim($envBase, '/');
      return;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) { throw new RuntimeException('Cannot reserve test port'); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) { throw new RuntimeException('Cannot identify test port'); }
    $defaultBase = 'http://' . $address;
    self::$nonce = bin2hex(random_bytes(24));
    putenv('WEBCAM_TEST_SERVER_NONCE=' . self::$nonce);

    if ($root === false) {
      return;
    }
    $router = $root . '/tests/router.php';
    if (!is_file($router)) {
      return;
    }

    // Als Argumentliste starten, damit proc_terminate wirklich den PHP-Server
    // und nicht nur eine vorgeschaltete /bin/sh beendet. Sonst kann ein alter
    // Testserver mit bereits gelöschter Temp-DB den nächsten Lauf abfangen.
    $cmd = [PHP_BINARY, '-S', $address, $router];
    $logDir = $root . '/var/log';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
      error_log('HttpServer: unable to create log dir ' . $logDir);
    }
    $logFile = $logDir . '/phpunit-server.log';

    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['file', $logFile, 'a'],
      2 => ['file', $logFile, 'a'],
    ];
    self::$process = proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource(self::$process)) {
      self::$process = null;
      return;
    }

    // A cold PHP/router bootstrap from the SMB workspace can exceed three
    // seconds even though the server is healthy. This deadline is test-only.
    if (!self::waitForReady($defaultBase, 15.0)) {
      self::stopProcess();
      throw new RuntimeException('Own HTTP fixture did not become ready');
    }

    self::$baseUrl = $defaultBase;
    putenv('WEBCAM_BASE_URL=' . $defaultBase);
  }

  public static function baseUrl(): ?string
  {
    return self::$baseUrl;
  }

  public static function stop(): void
  {
    self::stopProcess();

    if (self::$tempConfigDb !== null) {
      foreach ([self::$tempConfigDb, self::$tempConfigDb . '-shm', self::$tempConfigDb . '-wal'] as $path) {
        if (is_file($path) && !@unlink($path)) {
          error_log('HttpServer: temp config cleanup failed ' . $path);
        }
      }
      self::$tempConfigDb = null;
    }

    if (self::$tempRateLimitDb !== null) {
      foreach ([self::$tempRateLimitDb, self::$tempRateLimitDb . '-shm', self::$tempRateLimitDb . '-wal'] as $path) {
        if (is_file($path) && !@unlink($path)) {
          error_log('HttpServer: rate-limit DB cleanup failed ' . $path);
        }
      }
      self::$tempRateLimitDb = null;
    }
  }

  private static function stopProcess(): void
  {
    if (is_resource(self::$process)) {
      if (!proc_terminate(self::$process)) {
        error_log('HttpServer: proc_terminate failed');
      }
      proc_close(self::$process); // SIGTERM is expected for this owned fixture.
      self::$process = null;
    }
  }

  private static function waitForReady(string $baseUrl, float $timeout): bool
  {
    $start = microtime(true);
    while ((microtime(true) - $start) < $timeout) {
      if (self::ping($baseUrl)) {
        return true;
      }
      usleep(100000);
    }
    return false;
  }

  private static function ping(string $baseUrl): bool
  {
    $context = stream_context_create(['http'=>['timeout'=>0.4, 'ignore_errors'=>true, 'follow_location'=>0]]);
    $body = @file_get_contents($baseUrl . '/__phpunit_identity', false, $context);
    return self::$nonce !== '' && is_string($body) && hash_equals(self::$nonce, $body);
  }

  private static function resolveConfigDbPath(string $root): string
  {
    $configDb = getenv('WEBCAM_CONFIG_DB');
    if (!is_string($configDb) || trim($configDb) === '') {
      return $root . '/database/webcams.sqlite';
    }
    if (!str_starts_with($configDb, '/')) {
      return $root . '/' . ltrim($configDb, '/');
    }
    return $configDb;
  }

  private static function snapshotConfigDb(string $sourcePath): ?string
  {
    if (!is_file($sourcePath)) {
      error_log('HttpServer: config DB missing ' . $sourcePath);
      return null;
    }

    $tmpBase = tempnam(self::tempDir(), 'webcam-config-test-');
    if ($tmpBase === false) {
      error_log('HttpServer: tempnam failed for config DB snapshot');
      return null;
    }
    @unlink($tmpBase);
    $tmp = $tmpBase . '.sqlite';

    $sqlite3 = trim((string) shell_exec('command -v sqlite3 2>/dev/null'));
    if ($sqlite3 !== '') {
      $command = sprintf(
        '%s %s %s 2>/dev/null',
        escapeshellcmd($sqlite3),
        escapeshellarg($sourcePath),
        escapeshellarg('.backup ' . $tmp)
      );
      exec($command, $output, $exitCode);
      if ($exitCode === 0 && is_file($tmp) && filesize($tmp) !== 0) {
        self::seedUploadKeys($tmp);
        return $tmp;
      }
      @unlink($tmp);
    }

    if (!copy($sourcePath, $tmp) || !is_file($tmp) || filesize($tmp) === 0) {
      @unlink($tmp);
      error_log('HttpServer: config DB snapshot copy failed ' . $sourcePath);
      return null;
    }

    self::seedUploadKeys($tmp);
    return $tmp;
  }

  private static function seedUploadKeys(string $database): void
  {
    try {
      $pdo = new PDO('sqlite:' . $database);
      // Der HTTP-Testserver arbeitet mit einer Sicherung der eingecheckten
      // Sample-DB. Binäre Sample-Dateien können älter als schema.config.sql
      // sein; deshalb werden additive Testmigrationen vor dem ersten
      // schreibenden API-Test nachvollzogen.
      $columns = $pdo->query('PRAGMA table_info(capture_rules)')->fetchAll(PDO::FETCH_ASSOC);
      $columnNames = array_map(
        static fn(array $column): string => (string) ($column['name'] ?? ''),
        is_array($columns) ? $columns : []
      );
      if (!in_array('active_upload_fallback_failures', $columnNames, true)) {
        $pdo->exec(
          'ALTER TABLE capture_rules '
          . 'ADD COLUMN active_upload_fallback_failures INTEGER NOT NULL DEFAULT 0'
        );
      }
      $pdo->exec(
        "INSERT INTO capture_rules (camera_id, interval_seconds, upload_key)
         SELECT id, 600, 'phpunit-upload-key-0000000000000000'
           FROM cameras
          WHERE NOT EXISTS (
            SELECT 1 FROM capture_rules WHERE capture_rules.camera_id = cameras.id
          )"
      );
      $pdo->exec(
        "UPDATE capture_rules
            SET upload_key = 'phpunit-upload-key-0000000000000000'
          WHERE upload_key IS NULL OR trim(upload_key) = ''"
      );
    } catch (Throwable $error) {
      error_log('HttpServer: unable to seed test upload keys: ' . $error->getMessage());
    }
  }

  private static function tempDir(): string
  {
    $env = getenv('TMPDIR');
    if (is_string($env) && $env !== '' && is_dir($env) && is_writable($env)) {
      return $env;
    }
    if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
      return '/dev/shm';
    }
    return sys_get_temp_dir();
  }
}
