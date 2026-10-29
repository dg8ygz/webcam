<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Service\Maintenance\MaintenanceCommand;
use Webcam\Service\Maintenance\MaintenanceLog;
use Webcam\Service\Maintenance\MaintenanceSchedule;

/**
 * HTTP-Handler für globale Admin-Einstellungen und deren Wartungsübersicht.
 *
 * Dieser Handler besitzt ausschließlich die Routen `/config`, `/config/*` und
 * `/scripts`. Der zentrale Einstiegspunkt hat den Request zu diesem Zeitpunkt
 * bereits authentifiziert und bei Änderungen den CSRF-Nachweis geprüft.
 *
 * Leseführung:
 * 1. Methode und Pfad einer globalen Einstellung zuordnen,
 * 2. den JSON-Body erst in dem tatsächlich passenden PUT-Zweig lesen,
 * 3. Zahlen, Texte und Wartungskommandos vor dem Speichern validieren,
 * 4. das Config-Dokument pro Request höchstens einmal laden,
 * 5. ein neutrales AdminRouteResult ohne Header oder Prozessende liefern.
 *
 * Die Klasse führt keinen zweiten Konfigurationscache ein. `documentCache` hält
 * nur das Dokument während dieses einen Handler-Aufrufs konsistent und wird
 * nach jedem erfolgreichen ConfigStore-Schreibvorgang ersetzt.
 */
final class GlobalConfigAdminHandler
{
  /** @var array<string,mixed>|null */
  private ?array $documentCache = null;

  public function __construct(
    private readonly App $app,
    private readonly ConfigStore $configStore,
    private readonly string $projectRoot
  ) {}

  /** @param list<string> $segments */
  public function supports(array $segments): bool
  {
    return ($segments[0] ?? null) === 'config' || $segments === ['scripts'];
  }

  /**
   * @param list<string> $segments
   * @param callable():array<string,mixed> $readJson
   */
  public function handle(
    string $method,
    array $segments,
    callable $readJson
  ): AdminRouteResult {
    if (!$this->supports($segments)) {
      return $this->result(404, ['detail' => 'Not found']);
    }

    // Das vollständige Dokument wird nur lesend ausgeliefert. Schreibzugriffe
    // verwenden immer die engeren Unterrouten mit eigener Feldvalidierung.
    if ($segments === ['config'] && $method === 'GET') {
      return $this->result(200, $this->loadDocument());
    }

    if ($segments === ['config', 'timeline']) {
      return $this->timeline($method, $readJson);
    }
    if ($segments === ['config', 'runtime']) {
      return $this->runtime($method, $readJson);
    }
    if ($segments === ['config', 'maintenance-scripts']) {
      return $this->maintenanceScripts($method, $readJson);
    }
    if ($segments === ['scripts'] && $method === 'GET') {
      return $this->scriptsWorkspace();
    }
    if ($segments === ['config', 'overview-hero']) {
      return $this->overviewHero($method, $readJson);
    }
    if ($segments === ['config', 'overview-map']) {
      return $this->overviewMap($method, $readJson);
    }
    if ($segments === ['config', 'public-rate-limit']) {
      return $this->publicRateLimit($method, $readJson);
    }

    return $this->result(404, ['detail' => 'Not found']);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function timeline(string $method, callable $readJson): AdminRouteResult
  {
    if ($method === 'GET') {
      $document = $this->loadDocument();
      return $this->result(200, $document['timeline'] ?? []);
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $body = $readJson();
    $maxEntries = isset($body['max_entries']) && is_numeric($body['max_entries'])
      ? (int) $body['max_entries']
      : null;
    if ($maxEntries === null || $maxEntries < 50 || $maxEntries > 5000) {
      return $this->result(400, [
        'detail' => 'max_entries must be between 50 and 5000',
      ]);
    }

    $document = $this->loadDocument();
    $document['timeline'] = ['max_entries' => $maxEntries];
    $saved = $this->saveDocument($document, 'timeline:update');
    return $this->result(200, $saved['timeline']);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function runtime(string $method, callable $readJson): AdminRouteResult
  {
    if ($method === 'GET') {
      try {
        $document = $this->loadDocument();
        return $this->result(200, $this->normalizeRuntimeSettings($document['runtime'] ?? []));
      } catch (InvalidArgumentException $exception) {
        return $this->result(400, ['detail' => $exception->getMessage()]);
      }
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    try {
      $settings = $this->normalizeRuntimeSettings($readJson());
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }
    $document = $this->loadDocument();
    $document['runtime'] = $settings;
    $saved = $this->saveDocument($document, 'runtime:update');
    return $this->result(200, $this->normalizeRuntimeSettings($saved['runtime'] ?? []));
  }

  /** @param callable():array<string,mixed> $readJson */
  private function maintenanceScripts(
    string $method,
    callable $readJson
  ): AdminRouteResult {
    if ($method === 'GET') {
      $document = $this->loadDocument();
      return $this->result(200, MaintenanceSchedule::normalize(
        $document['maintenance_scripts'] ?? null
      ));
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    // Neben der Schema-Prüfung wird jedes Kommando einmal aufgebaut. So kann
    // kein fehlender oder aus bin/ herausführender Pfad gespeichert werden.
    try {
      $settings = MaintenanceSchedule::validateUpdate($readJson());
      foreach ($settings as $task => $taskSettings) {
        MaintenanceCommand::build($this->projectRoot, $task, $taskSettings);
      }
    } catch (InvalidArgumentException | RuntimeException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    $document = $this->loadDocument();
    $document['maintenance_scripts'] = $settings;
    $saved = $this->saveDocument($document, 'maintenance-scripts:update');
    return $this->result(200, MaintenanceSchedule::normalize(
      $saved['maintenance_scripts'] ?? null
    ));
  }

  private function scriptsWorkspace(): AdminRouteResult
  {
    $document = $this->loadDocument();
    $settings = MaintenanceSchedule::normalize($document['maintenance_scripts'] ?? null);
    return $this->result(200, [
      'settings' => $settings,
      'definitions' => $this->maintenanceScriptDefinitions($settings),
      'status' => $this->loadMaintenanceScriptStatus($settings),
      'trigger' => $this->loadMaintenanceTriggerStatus(),
    ]);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function overviewHero(string $method, callable $readJson): AdminRouteResult
  {
    if ($method === 'GET') {
      $document = $this->loadDocument();
      return $this->result(200, [
        'overview_hero' => $document['overview_hero'] ?? '',
      ]);
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $body = $readJson();
    $text = isset($body['overview_hero']) ? trim((string) $body['overview_hero']) : '';
    if ($text === '') {
      return $this->result(400, ['detail' => 'overview_hero must not be empty']);
    }

    $document = $this->loadDocument();
    $document['overview_hero'] = $text;
    $saved = $this->saveDocument($document, 'overview_hero:update');
    return $this->result(200, ['overview_hero' => $saved['overview_hero']]);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function overviewMap(string $method, callable $readJson): AdminRouteResult
  {
    if ($method === 'GET') {
      $document = $this->loadDocument();
      return $this->result(200, $document['overview_map'] ?? []);
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $body = $readJson();
    $latitude = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : null;
    $longitude = isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : null;
    $zoom = isset($body['zoom']) && is_numeric($body['zoom']) ? (int) $body['zoom'] : null;
    $height = isset($body['height_px']) && is_numeric($body['height_px'])
      ? (int) $body['height_px']
      : null;

    if ($latitude === null || $latitude < -90 || $latitude > 90) {
      return $this->result(400, ['detail' => 'lat must be between -90 and 90']);
    }
    if ($longitude === null || $longitude < -180 || $longitude > 180) {
      return $this->result(400, ['detail' => 'lng must be between -180 and 180']);
    }
    if ($zoom === null || $zoom < 1 || $zoom > 18) {
      return $this->result(400, ['detail' => 'zoom must be between 1 and 18']);
    }
    if ($height === null || $height < 120 || $height > 800) {
      return $this->result(400, ['detail' => 'height_px must be between 120 and 800']);
    }

    $document = $this->loadDocument();
    $document['overview_map'] = [
      'lat' => $latitude,
      'lng' => $longitude,
      'zoom' => $zoom,
      'height_px' => $height,
    ];
    $saved = $this->saveDocument($document, 'overview_map:update');
    return $this->result(200, $saved['overview_map']);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function publicRateLimit(string $method, callable $readJson): AdminRouteResult
  {
    if ($method === 'GET') {
      $document = $this->loadDocument();
      return $this->result(200, $document['public_rate_limit'] ?? []);
    }
    if ($method !== 'PUT') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $body = $readJson();
    $limit = isset($body['limit']) && is_numeric($body['limit']) ? (int) $body['limit'] : null;
    $window = isset($body['window_seconds']) && is_numeric($body['window_seconds'])
      ? (int) $body['window_seconds']
      : null;
    $ttlDays = isset($body['ttl_days']) && is_numeric($body['ttl_days'])
      ? (int) $body['ttl_days']
      : null;

    if ($limit === null || $limit < 1) {
      return $this->result(400, ['detail' => 'limit must be >= 1']);
    }
    if ($window === null || $window < 5) {
      return $this->result(400, ['detail' => 'window_seconds must be >= 5']);
    }
    if ($ttlDays === null || $ttlDays < 1) {
      return $this->result(400, ['detail' => 'ttl_days must be >= 1']);
    }

    $document = $this->loadDocument();
    $document['public_rate_limit'] = [
      'limit' => $limit,
      'window_seconds' => $window,
      'ttl_days' => $ttlDays,
    ];
    $saved = $this->saveDocument($document, 'public-rate-limit:update');
    return $this->result(200, $saved['public_rate_limit']);
  }

  /**
   * Normalisiert nur die globalen, extern wirksamen Runtime-Grenzen.
   * Ungültige Werte werden sichtbar abgelehnt und nicht still korrigiert.
   *
   * @return array{weather_command_timeout_seconds:int}
   */
  private function normalizeRuntimeSettings(mixed $settings): array
  {
    $settings = is_array($settings) ? $settings : [];
    $timeout = isset($settings['weather_command_timeout_seconds'])
      && is_numeric($settings['weather_command_timeout_seconds'])
      ? (int) $settings['weather_command_timeout_seconds']
      : 20;
    if ($timeout < 1 || $timeout > 300) {
      throw new InvalidArgumentException(
        'weather_command_timeout_seconds must be between 1 and 300'
      );
    }
    return ['weather_command_timeout_seconds' => $timeout];
  }

  /**
   * @param array<string,array<string,mixed>> $settings
   * @return array<string,array{label:string,description:string,builtin:bool}>
   */
  private function maintenanceScriptDefinitions(array $settings): array
  {
    $definitions = [];
    foreach ($settings as $task => $taskSettings) {
      $definitions[$task] = [
        'label' => (string) ($taskSettings['label'] ?? $task),
        'description' => (string) ($taskSettings['description'] ?? ''),
        'builtin' => MaintenanceSchedule::isBuiltin((string) $task),
      ];
    }
    return $definitions;
  }

  /**
   * Liest je Aufgabe den letzten Scheduler-State. Defekte Einzeldateien werden
   * protokolliert und nur für diese Aufgabe als `state_error` sichtbar gemacht.
   *
   * @param array<string,array<string,mixed>> $settings
   * @return array<string,array<string,mixed>|null>
   */
  private function loadMaintenanceScriptStatus(array $settings): array
  {
    $result = [];
    foreach ($settings as $task => $taskSettings) {
      $result[$task] = null;
      try {
        $path = $this->app->paths()->statePath('maintenance-scheduler/' . $task . '.json');
        if (!is_file($path)) {
          continue;
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
          continue;
        }
        if (isset($decoded['task']) && (string) $decoded['task'] !== (string) $task) {
          throw new RuntimeException('maintenance scheduler state belongs to another task');
        }

        $logEntries = MaintenanceLog::storedEntries($decoded['log_entries'] ?? null);
        if ($logEntries === [] && ($decoded['status'] ?? null) === 'error') {
          $logEntries = MaintenanceLog::legacyTaskEntries(
            $this->app->paths()->logPath('maintenance_scheduler.log'),
            (string) $task,
            (string) ($taskSettings['label'] ?? $task),
            isset($decoded['started_at']) ? (string) $decoded['started_at'] : null
          );
        }
        $result[$task] = [
          'status' => (string) ($decoded['status'] ?? 'unknown'),
          'scheduled_day' => isset($decoded['scheduled_day'])
            ? (string) $decoded['scheduled_day']
            : null,
          'started_at' => isset($decoded['started_at']) ? (string) $decoded['started_at'] : null,
          'finished_at' => isset($decoded['finished_at']) ? (string) $decoded['finished_at'] : null,
          'exit_code' => isset($decoded['exit_code']) ? (int) $decoded['exit_code'] : null,
          'log_entries' => $logEntries,
        ];
      } catch (Throwable $exception) {
        $this->app->logger()->warning('maintenance scheduler state unreadable', [
          'task' => $task,
          'error' => $exception->getMessage(),
        ]);
        $result[$task] = [
          'status' => 'state_error',
          'scheduled_day' => null,
          'started_at' => null,
          'finished_at' => null,
          'exit_code' => null,
          'log_entries' => [],
        ];
      }
    }
    return $result;
  }

  /** @return array{mode:string,last_tick_at:?string} */
  private function loadMaintenanceTriggerStatus(): array
  {
    $lastTickAt = null;
    $path = $this->app->paths()->statePath('maintenance-scheduler/tick.lock');
    if (is_file($path)) {
      $raw = file_get_contents($path);
      $timestamp = is_string($raw) && trim($raw) !== '' ? (int) trim($raw) : 0;
      if ($timestamp > 0) {
        $lastTickAt = (new DateTimeImmutable('@' . $timestamp))
          ->setTimezone(new DateTimeZone((string) $this->app->config('timezone', 'UTC')))
          ->format(DATE_ATOM);
      }
    }
    return [
      'mode' => 'camera_poll',
      'last_tick_at' => $lastTickAt,
    ];
  }

  /** @return array<string,mixed> */
  private function loadDocument(): array
  {
    if ($this->documentCache === null) {
      $this->documentCache = $this->configStore->load();
    }
    return $this->documentCache;
  }

  /**
   * @param array<string,mixed> $document
   * @return array<string,mixed>
   */
  private function saveDocument(array $document, string $action): array
  {
    $this->documentCache = $this->configStore->save(
      $document,
      actor: 'api',
      action: $action
    );
    return $this->documentCache;
  }

  /** @param array<string,mixed>|null $payload */
  private function result(int $status, ?array $payload): AdminRouteResult
  {
    return new AdminRouteResult($status, $payload);
  }
}
