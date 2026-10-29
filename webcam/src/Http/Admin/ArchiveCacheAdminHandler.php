<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

use Closure;
use RuntimeException;
use Webcam\App;
use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Infrastructure\Process\BoundedProcessRunner;

/**
 * HTTP-Handler für das ausdrücklich optionale Legacy-Archivcache-Werkzeug.
 *
 * Der normale Archiv- und Timeline-Betrieb verwendet die Kamera-SQLite und
 * benötigt diesen Cache nicht. Deshalb bleiben Aktivierung und Rebuild hinter
 * einem separaten Admin-Schalter. Der zentrale Router prüft davor bereits
 * Authentifizierung und CSRF.
 *
 * Leseführung:
 * 1. Status lesen oder Aktivierung in der Config-SQLite speichern,
 * 2. vor einem Rebuild Freigabe, Konfiguration und laufenden Lock prüfen,
 * 3. aus validierten Einzelwerten eine feste Argumentliste ohne Shell bauen,
 * 4. den Prozess mit Zeit- und Ausgabegrenze ausführen,
 * 5. Timeout und Werkzeugfehler sichtbar in HTTP und Log übersetzen.
 */
final class ArchiveCacheAdminHandler
{
  private const PROCESS_OUTPUT_MAX_BYTES = 1048576;

  private readonly Closure $processRunner;

  /**
   * `processRunner` ist ausschließlich eine Testnaht. Der Produktivpfad nutzt
   * immer BoundedProcessRunner mit eigener Prozessgruppe und harter Begrenzung.
   *
   * @param null|callable(list<string>,string,int):array{
   *   exit_code:int,
   *   stdout:string,
   *   stderr:string,
   *   timed_out:bool,
   *   timeout_seconds:int
   * } $processRunner
   */
  public function __construct(
    private readonly App $app,
    private readonly ConfigStore $configStore,
    private readonly string $projectRoot,
    private readonly bool $legacyArchiveCacheAllowed,
    private readonly bool $testRequest,
    ?callable $processRunner = null
  ) {
    $this->processRunner = $processRunner !== null
      ? Closure::fromCallable($processRunner)
      : Closure::fromCallable([$this, 'runBoundedProcess']);
  }

  /** @param list<string> $segments */
  public function supports(array $segments): bool
  {
    return ($segments[0] ?? null) === 'archive-cache';
  }

  /**
   * @param list<string> $segments
   * @param array<string,mixed> $query
   * @param callable():array<string,mixed> $readJson
   */
  public function handle(
    string $method,
    array $segments,
    array $query,
    callable $readJson
  ): AdminRouteResult {
    if (!$this->supports($segments)) {
      return $this->result(404, ['detail' => 'Not found']);
    }

    if ($segments === ['archive-cache'] && $method === 'GET') {
      return $this->status($query);
    }
    if ($segments === ['archive-cache'] && $method === 'PUT') {
      return $this->updateSettings($readJson);
    }
    if ($segments === ['archive-cache', 'rebuild'] && $method === 'POST') {
      return $this->rebuild($readJson);
    }

    return $this->result(404, ['detail' => 'Not found']);
  }

  /** @param array<string,mixed> $query */
  private function status(array $query): AdminRouteResult
  {
    $slug = is_string($query['slug'] ?? null) ? trim($query['slug']) : '';
    $camera = null;
    if ($slug !== '') {
      $camera = $this->app->cameras()->find($slug);
      if ($camera === null) {
        return $this->result(404, ['detail' => 'Camera not found']);
      }
    }

    $status = $this->app->archiveBrowser()->cacheStatus($camera);
    $status['admin_allowed'] = $this->legacyArchiveCacheAllowed;
    return $this->result(200, $status);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function updateSettings(callable $readJson): AdminRouteResult
  {
    if (!$this->legacyArchiveCacheAllowed) {
      return $this->result(403, ['detail' => 'Legacy archive-cache admin disabled']);
    }

    $body = $readJson();
    $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    $document = $this->configStore->load();
    $document['archive_cache'] = [
      'enabled' => $enabled,
      'last_built_at' => $enabled
        ? ($document['archive_cache']['last_built_at'] ?? null)
        : null,
    ];
    $saved = $this->configStore->save(
      $document,
      actor: 'api',
      action: 'archive-cache:update'
    );
    return $this->result(200, $saved['archive_cache']);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function rebuild(callable $readJson): AdminRouteResult
  {
    if (!$this->legacyArchiveCacheAllowed) {
      return $this->result(403, ['detail' => 'Legacy archive-cache admin disabled']);
    }

    // Außerhalb isolierter Tests muss das Legacy-Werkzeug zusätzlich in der
    // aktiven Konfiguration eingeschaltet sein. Die Testfreigabe umgeht nur
    // diese Betriebsoption, niemals Authentifizierung oder CSRF im Router.
    $settings = $this->app->camerasMeta()['archive_cache'] ?? [];
    if (!$this->testRequest && empty($settings['enabled'])) {
      return $this->result(409, ['detail' => 'Archive cache disabled']);
    }

    $status = $this->app->archiveBrowser()->cacheStatus();
    if ($this->isRebuildRunning($status)) {
      return $this->result(409, [
        'detail' => 'Rebuild already running',
        'state' => $status['rebuild_state'] ?? null,
      ]);
    }

    $body = $readJson();
    $slug = isset($body['slug']) && is_string($body['slug'])
      ? trim($body['slug'])
      : null;
    $year = isset($body['year']) && is_numeric($body['year'])
      ? (int) $body['year']
      : null;
    $dryRun = !empty($body['dry_run']);

    // Jedes Argument bleibt ein eigenes Arrayelement. Weder Slug noch Jahr
    // werden jemals durch eine Shell ausgewertet.
    $command = ['php', $this->projectRoot . '/bin/archive_cache_rebuild.php'];
    if ($slug !== null && $slug !== '') {
      $command[] = '--slug=' . $slug;
    }
    if ($year !== null) {
      $command[] = '--year=' . $year;
    }
    if ($dryRun) {
      $command[] = '--dry-run';
    }
    $command[] = '--verbose';

    $runner = $this->processRunner;
    $result = $runner($command, $this->projectRoot, $this->processTimeoutSeconds());
    if ($result['timed_out']) {
      $this->app->logger()->error('legacy archive cache rebuild timed out', [
        'slug' => $slug,
        'year' => $year,
        'timeout_seconds' => $result['timeout_seconds'],
      ]);
      return $this->result(504, ['detail' => 'Rebuild timed out']);
    }
    if ($result['exit_code'] !== 0) {
      $this->app->logger()->error('legacy archive cache rebuild failed', [
        'slug' => $slug,
        'year' => $year,
        'exit_code' => $result['exit_code'],
        'stderr' => $result['stderr'],
      ]);
      return $this->result(500, ['detail' => 'Rebuild failed']);
    }

    $cameraForStatus = $slug !== null && $slug !== ''
      ? $this->app->cameras()->find($slug)
      : null;
    $status = $this->app->archiveBrowser()->cacheStatus($cameraForStatus);
    return $this->result(200, [
      'status' => 'ok',
      'stdout' => $result['stdout'],
      'config_updated' => false,
      'last_built_at' => $status['last_built_at'] ?? null,
    ]);
  }

  /**
   * Ein bloß vorhandenes Lockfile bedeutet noch keinen laufenden Prozess. Erst
   * ein tatsächlich gehaltener flock oder der aktive State sperrt den Rebuild.
   *
   * @param array<string,mixed> $status
   */
  private function isRebuildRunning(array $status): bool
  {
    $state = is_array($status['rebuild_state'] ?? null) ? $status['rebuild_state'] : [];
    if (($state['status'] ?? null) === 'running') {
      return true;
    }

    $lockPath = $this->app->paths()->statePath('archive-cache/rebuild.lock');
    if (!is_file($lockPath)) {
      return false;
    }
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false) {
      return true;
    }

    $locked = !@flock($lockHandle, LOCK_EX | LOCK_NB);
    if (!$locked) {
      @flock($lockHandle, LOCK_UN);
    }
    fclose($lockHandle);
    return $locked;
  }

  /** Liest den Prozess-Timeout und hält ihn zwischen 30 Sekunden und 2 Stunden. */
  private function processTimeoutSeconds(): int
  {
    $value = getenv('WEBCAM_ADMIN_PROCESS_TIMEOUT_SECONDS');
    $seconds = is_string($value) && is_numeric($value) ? (int) $value : 1800;
    return max(30, min(7200, $seconds));
  }

  /**
   * Produktiver Prozessadapter: keine Shell, maximal 1 MiB Diagnoseausgabe und
   * zwingend eine eigene Prozessgruppe, damit Timeouts auch Kinder beenden.
   *
   * @param list<string> $command
   * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,timeout_seconds:int}
   */
  private function runBoundedProcess(
    array $command,
    string $workingDirectory,
    int $timeoutSeconds
  ): array {
    $result = (new BoundedProcessRunner())->run($command, $timeoutSeconds, [
      'cwd' => $workingDirectory,
      'output_max_bytes' => self::PROCESS_OUTPUT_MAX_BYTES,
      'output_retention' => 'tail',
      'require_process_group' => true,
    ]);
    if (!$result['started']) {
      throw new RuntimeException('Failed to start admin process');
    }

    return [
      'exit_code' => $result['exit_code'],
      'stdout' => $result['stdout'],
      'stderr' => $result['stderr'],
      'timed_out' => $result['timed_out'],
      'timeout_seconds' => $result['timeout_seconds'],
    ];
  }

  /** @param array<string,mixed>|null $payload */
  private function result(int $status, ?array $payload): AdminRouteResult
  {
    return new AdminRouteResult($status, $payload);
  }
}
