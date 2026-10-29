<?php
declare(strict_types=1);

// camera_store.php wird auch von eigenständigen CLI-Werkzeugen direkt geladen.
// Deshalb den Klassenlader hier selbst sicherstellen, statt bootstrap.php
// vorauszusetzen.
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../src/Service/Maintenance/MaintenanceSchedule.php';

// Zentrale, prozedurale SQLite-Zugriffsschicht für die Kamera-Konfiguration.
// Sie schützt insbesondere davor, die anonymisierte Repo-Beispieldatenbank
// unbemerkt produktiv zu verwenden, trennt Lese- und Schreibverbindungen und
// erzwingt auf Schreibpfaden den für Network-Mounts sicheren DELETE-Journalmodus.

if (!function_exists('webcam_camera_store_json_encode')) {
  /**
   * JSON-Encode mit Fehlerwurf statt stillem false.
   *
   * @param mixed $value
   */
  function webcam_camera_store_json_encode(mixed $value): string
  {
    try {
      return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\Throwable $exception) {
      throw new RuntimeException('Failed to encode JSON payload for camera store: ' . $exception->getMessage(), 0, $exception);
    }
  }
}


if (!function_exists('webcam_camera_store_load')) {
  function webcam_camera_store_normalize_db_path(string $path): string
  {
    $path = trim($path);
    if ($path === '') {
      throw new RuntimeException('camera_config_db path missing');
    }

    if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
      $path = __DIR__ . '/../' . ltrim($path, '/');
    }

    return $path;
  }

  /** Erlaubt --help-Ausgaben, ohne dafür eine produktive DB vorauszusetzen. */
  function webcam_camera_store_is_help_request(): bool
  {
    $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    if (!$isCli) {
      return false;
    }

    $argv = $_SERVER['argv'] ?? [];
    return in_array('--help', $argv, true)
      || in_array('-h', $argv, true)
      || in_array('help', $argv, true);
  }

  /**
   * Verhindert die unbemerkte Nutzung der anonymisierten Repo-DB.
   * Nur ein explizit wahrer WEBCAM_ALLOW_SAMPLE_CONFIG-Wert erlaubt sie in Dev/Test.
   */
  function webcam_camera_store_assert_db_path_allowed(string $path): void
  {
    $resolvedPath = realpath($path) ?: $path;
    $repoConfigDb = realpath(__DIR__ . '/../database/webcams.sqlite') ?: (__DIR__ . '/../database/webcams.sqlite');
    $usingRepoConfigDb = $resolvedPath === $repoConfigDb;
    if (!$usingRepoConfigDb) {
      return;
    }

    $allowSampleConfig = \Webcam\Support\BooleanEnvironment::read('WEBCAM_ALLOW_SAMPLE_CONFIG');
    $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    $isHelpRequest = webcam_camera_store_is_help_request();

    if (!$allowSampleConfig) {
      if ($isCli && !$isHelpRequest) {
        throw new RuntimeException(
          'WEBCAM_CONFIG_DB is not set. Refusing to use the repository sample DB for CLI runs. '
          . 'Set WEBCAM_CONFIG_DB to the live/test DB or WEBCAM_ALLOW_SAMPLE_CONFIG=1 for explicit sample-db runs.'
        );
      }

      if (!$isCli) {
        throw new RuntimeException(
          'WEBCAM_CONFIG_DB is not set to a live config DB. Refusing to use the repository sample DB for web requests. '
          . 'Set WEBCAM_CONFIG_DB to the live DB or WEBCAM_ALLOW_SAMPLE_CONFIG=1 for dev/test.'
        );
      }
    }

    if ($allowSampleConfig && $isCli && !$isHelpRequest && !($GLOBALS['webcam_sample_config_warning_emitted'] ?? false)) {
      $GLOBALS['webcam_sample_config_warning_emitted'] = true;
      $warning = 'WARNING: CLI is using the repository sample config DB '
        . $repoConfigDb
        . ' because WEBCAM_ALLOW_SAMPLE_CONFIG is enabled.';
      if (defined('STDERR')) {
        fwrite(STDERR, $warning . PHP_EOL);
      } else {
        error_log($warning);
      }
    }
  }

  /** Lädt das vollständige normalisierte Config-Dokument über eine Read-only-Verbindung. */
  function webcam_camera_store_load(): array
  {
    return webcam_camera_store_with_retry(
      false,
      static fn(PDO $pdo): array => (new \Webcam\Infrastructure\Config\ConfigDocumentHydrator())->hydrate($pdo)
    );
  }

  /** Speichert ein vollständiges Dokument atomar und erhöht optional dessen Version. */
  function webcam_camera_store_save(array $document, bool $bumpVersion = true): array
  {
    return webcam_camera_store_with_retry(
      true,
      static fn(PDO $pdo): array => (new \Webcam\Infrastructure\Config\ConfigDocumentPersister())
        ->persist($pdo, $document, $bumpVersion)
    );
  }

  /** Löst WEBCAM_CONFIG_DB auf und prüft Existenz sowie Sample-DB-Grenze. */
  function webcam_camera_store_db_path(): string
  {
    $options = $GLOBALS['webcam_camera_config_options'] ?? [];
    $path = $options['db_path'] ?? null;
    if (!is_string($path) || trim($path) === '') {
      $path = getenv('WEBCAM_CONFIG_DB');
    }
    if (!is_string($path) || trim($path) === '') {
      $path = __DIR__ . '/../database/webcams.sqlite';
    }

    $path = webcam_camera_store_normalize_db_path($path);
    webcam_camera_store_assert_db_path_allowed($path);

    return $path;
  }

  function webcam_camera_store_table_exists(PDO $pdo, string $table): bool
  {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) {
      return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name');
    $stmt->execute([':type' => 'table', ':name' => $table]);
    $exists = $stmt->fetchColumn() !== false;
    $cache[$key] = $exists;
    return $exists;
  }

  function webcam_camera_store_column_exists(PDO $pdo, string $table, string $column): bool
  {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
      return $cache[$key];
    }
    $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
    $stmt->execute();
    $exists = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      if (($row['name'] ?? '') === $column) {
        $exists = true;
        break;
      }
    }
    $cache[$key] = $exists;
    return $exists;
  }

  function webcam_camera_store_log_missing_table(string $table): void
  {
    static $logged = [];
    if (isset($logged[$table])) {
      return;
    }
    $logged[$table] = true;
    $dbPath = webcam_camera_store_db_path();
    $msg = "camera_store: missing {$table} table in config DB {$dbPath}. Apply migration to enable it.";
    error_log($msg);
    if (defined('STDERR')) {
      fwrite(STDERR, $msg . PHP_EOL);
    }
  }

  function webcam_camera_store_log_missing_column(string $table, string $column): void
  {
    $key = $table . '.' . $column;
    static $logged = [];
    if (isset($logged[$key])) {
      return;
    }
    $logged[$key] = true;
    $dbPath = webcam_camera_store_db_path();
    $msg = "camera_store: missing {$key} in config DB {$dbPath}. Apply migration to enable it.";
    error_log($msg);
    if (defined('STDERR')) {
      fwrite(STDERR, $msg . PHP_EOL);
    }
  }

  /** Schließt gecachte PDO-Verbindungen, etwa nach einem Schreib- oder Mountfehler. */
  function webcam_camera_store_reset_connections(): void
  {
    \Webcam\Infrastructure\Config\ConfigDatabaseConnection::reset();
  }

  /**
   * Führt eine Config-DB-Operation mit kurzer Wiederholung bei SQLite-Busy bzw.
   * austauschbaren CIFS-Verbindungen aus. Nicht temporäre Fehler laufen sofort hoch.
   *
   * @template T
   * @param callable(PDO):T $fn
   * @return T
   */
  function webcam_camera_store_with_retry(bool $write, callable $fn)
  {
    return (new \Webcam\Infrastructure\Config\ConfigDatabaseConnection())->run($write, $fn);
  }

  /**
   * Prevent accidental overwrite of the live config DB with test fixtures.
   *
   * Emits a loud warning and aborts if:
   *  - the target DB path is the project default (database/webcams.sqlite) and
   *  - the payload looks like the test fixture (only "testcam") or a test env is detected.
   */
  function webcam_camera_store_guard_write(PDO $pdo, array $document): void
  {
    // For SQLite we rely on configured path instead of PDO attributes.
    $dbPath = webcam_camera_store_db_path();
    $realPath = realpath($dbPath);
    if ($realPath === false) {
      $realPath = $dbPath;
    }

    $isProjectDb = str_contains($realPath, '/webcam/database/webcams.sqlite');
    $isTestEnv = getenv('PHPUNIT') !== false
      || getenv('WEBCAM_ENV') === 'test'
      || getenv('NODE_ENV') === 'test';

    $cameras = $document['cameras'] ?? [];
    $isTestFixture = is_array($cameras) && count($cameras) === 1 && isset($cameras['testcam']);

    if ($isProjectDb && ($isTestEnv || $isTestFixture) && getenv('WEBCAM_ALLOW_PROD_WRITE') !== '1') {
      $msg = "WARNING !!! Database will be overwritten with Test-Data\n"
        . "Refusing to write to {$realPath} in test context. Set WEBCAM_ALLOW_PROD_WRITE=1 to override.";
      // loud stderr output for CLI
      if (defined('STDERR')) {
        fwrite(STDERR, $msg . PHP_EOL);
      }
      throw new RuntimeException($msg);
    }
  }

}
