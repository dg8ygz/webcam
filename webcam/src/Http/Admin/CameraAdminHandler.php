<?php
declare(strict_types=1);

namespace Webcam\Http\Admin;

use Closure;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Config\ConfigStore;
use Webcam\Infrastructure\Storage\CameraDataStore;
use Webcam\Service\Admin\CameraCaptureEditor;
use Webcam\Service\Admin\CameraCaptureQueryService;
use Webcam\Service\Admin\CameraConfigNormalizer;
use Webcam\Service\Admin\CameraRuntimeAccessInspector;
use Webcam\Service\Admin\CapturePayloadNormalizer;
use Webcam\Service\Interaction\LikeService;
use Webcam\Service\Overlay\OverlayUploadException;
use Webcam\Service\Overlay\OverlayUploadService;

/**
 * HTTP-Handler für alle Admin-Routen unter /cameras.
 *
 * `admin/api.php` übernimmt weiterhin Authentifizierung, CSRF und die echte
 * Ausgabe. Diese Klasse bündelt danach die zusammengehörige Kameraarbeit:
 *
 * 1. URL und Methode einer Kameraaktion zuordnen,
 * 2. JSON- oder Multipart-Eingaben an die zuständigen Normalizer geben,
 * 3. ConfigStore beziehungsweise Kamera-SQLite aufrufen,
 * 4. Fachfehler in den bisherigen HTTP-Vertrag übersetzen,
 * 5. ein neutrales AdminRouteResult zurückgeben.
 *
 * Die eigentlichen Regeln bleiben in den bereits ausgelagerten Services. Der
 * Handler erfindet keine Fallbackdaten und führt keinen zusätzlichen Cache ein.
 */
final class CameraAdminHandler
{
  private readonly CameraConfigNormalizer $cameraConfigNormalizer;
  private readonly CapturePayloadNormalizer $capturePayloadNormalizer;
  private readonly CameraCaptureEditor $cameraCaptureEditor;
  private readonly CameraCaptureQueryService $cameraCaptureQuery;
  private readonly OverlayUploadService $overlayUploadService;
  private readonly CameraRuntimeAccessInspector $runtimeAccessInspector;
  private readonly Closure $uploadedFileValidator;

  /**
   * Der optionale Upload-Prüfer ist nur eine Testnaht. Im Betrieb wird immer
   * PHPs `is_uploaded_file()` benutzt, damit beliebige lokale Pfade nicht als
   * administrativer Multipart-Upload ausgegeben werden können.
   */
  public function __construct(
    private readonly App $app,
    private readonly ConfigStore $configStore,
    ?callable $uploadedFileValidator = null,
    ?CameraRuntimeAccessInspector $runtimeAccessInspector = null
  ) {
    $this->cameraConfigNormalizer = new CameraConfigNormalizer();
    $this->capturePayloadNormalizer = new CapturePayloadNormalizer();
    $this->cameraCaptureEditor = new CameraCaptureEditor($app);
    $this->cameraCaptureQuery = new CameraCaptureQueryService(
      $this->cameraCaptureEditor,
      new DateTimeZone((string) $app->config('timezone', 'UTC'))
    );
    $this->overlayUploadService = new OverlayUploadService($app);
    $projectRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    $configDatabase = (string) $app->config('camera_config_db', '');
    $syncCommand = sprintf(
      '/usr/local/sbin/webcam-sync-apache-runtime-paths --apply --project-root %s --config-db %s',
      escapeshellarg($projectRoot),
      escapeshellarg($configDatabase)
    );
    $this->runtimeAccessInspector = $runtimeAccessInspector
      ?? new CameraRuntimeAccessInspector(
        $projectRoot,
        provisionCommand: $syncCommand
      );
    $this->uploadedFileValidator = $uploadedFileValidator !== null
      ? Closure::fromCallable($uploadedFileValidator)
      : static fn(string $path): bool => is_uploaded_file($path);
  }

  private function autoIsoStatuses(array $cameras): array
  {
    $result = [];
    $controller = new \Webcam\Service\Capture\AutoIsoController($this->app->paths()->statePath('auto-iso'));
    foreach ($cameras as $slug => $config) {
      try {
        $camera = $this->app->cameras()->find((string) $slug);
        if ($camera !== null) $result[$slug] = $controller->status($camera);
      } catch (\Throwable $error) {
        $result[$slug] = ['state' => 'unavailable', 'poll_recent' => false];
      }
    }
    return $result;
  }

  /** @param list<string> $segments */
  public function supports(array $segments): bool
  {
    return ($segments[0] ?? null) === 'cameras';
  }

  /**
   * Verarbeitet genau eine bereits authentifizierte Kamera-Admin-Anfrage.
   *
   * `readJson` wird nur bei einer Route aufgerufen, die wirklich einen
   * JSON-Body benötigt. So bleibt der zentrale Parser in admin/api.php und ein
   * Multipart-Upload wird nicht versehentlich als JSON gelesen.
   *
   * @param list<string> $segments
   * @param array<string,mixed> $query
   * @param array<string,mixed> $files
   * @param callable():array<string,mixed> $readJson
   */
  public function handle(
    string $method,
    array $segments,
    array $query,
    array $files,
    callable $readJson
  ): AdminRouteResult {
    if (!$this->supports($segments)) {
      return $this->result(404, ['detail' => 'Not found']);
    }

    // Sammlung: Liste lesen oder eine neue Kamera anlegen.
    if ($segments === ['cameras']) {
      if ($method === 'GET') {
        $document = $this->configStore->load();
        $cameras = is_array($document['cameras'] ?? null)
          ? $document['cameras']
          : [];
        return $this->result(200, [
          'cameras' => $cameras,
          // Betriebszustand bleibt getrennt von der gespeicherten Kamera-
          // Konfiguration. Das Admin-Panel kann dadurch fehlende systemd-
          // Freigaben anzeigen, ohne Laufzeitwerte zurück in SQLite zu senden.
          'runtime' => [
            'current_paths' => $this->runtimeAccessInspector->inspectAll($cameras),
            'auto_iso' => $this->autoIsoStatuses($cameras),
          ],
        ]);
      }
      if ($method === 'POST') {
        return $this->createCamera($readJson());
      }
      return $this->result(404, ['detail' => 'Not found']);
    }

    $slug = (string) ($segments[1] ?? '');
    if ($slug === '') {
      return $this->result(404, ['detail' => 'Not found']);
    }

    $document = $this->configStore->load();
    $cameraExists = isset($document['cameras'][$slug]);
    $camera = $cameraExists ? $this->app->cameras()->find($slug) : null;

    if (count($segments) === 3 && $segments[2] === 'image-preview' && $method === 'POST') {
      if ($camera === null) return $this->result(404, ['detail' => 'Camera not found']);
      $input = $readJson();
      if (!is_array($input) || array_keys($input) !== ['image_processing'] || !is_array($input['image_processing'])) {
        return $this->result(400, ['detail' => 'Nur image_processing ist erlaubt']);
      }
      try {
        return $this->result(200, (new \Webcam\Service\Storage\ImageProcessingPreview($this->app))->render($camera, $input['image_processing']));
      } catch (\InvalidArgumentException $error) {
        return $this->result(422, ['detail' => $error->getMessage()]);
      } catch (\RuntimeException $error) {
        return $this->result(503, ['detail' => 'Vorschau derzeit nicht verfuegbar: ' . $error->getMessage()]);
      }
    }

    // Diese Aktionsrouten laufen hinter Admin-Auth/CSRF aus admin/api.php.
    // Sie setzen nur kamerabezogenen Sollzustand, starten hier weder SSH noch Capture.
    if (count($segments) === 3 && $segments[2] === 'tunnel' && in_array($method, ['GET', 'POST'], true)) {
      if ($camera === null) return $this->result(404, ['detail' => 'Camera not found']);
      try {
        $leases = new \Webcam\Service\Maintenance\CameraTunnelLease($this->app->paths()->statePath('camera-tunnel'));
        if ($method === 'GET') return $this->result(200, $leases->status($slug));
        $input = $readJson();
        if (!is_array($input) || array_keys($input) !== ['action'] || !in_array($input['action'], ['open', 'close'], true)) {
          return $this->result(400, ['detail' => 'Nur action=open oder action=close erlaubt']);
        }
        if ((string) ($camera->capture()['upload_key'] ?? '') === '') return $this->result(409, ['detail' => 'Kein authentifizierter Helper']);
        $leases->request($slug, $input['action']);
        $this->app->logger()->info('admin camera tunnel requested', ['camera' => $slug, 'action' => $input['action']]);
        return $this->result(202, ['detail' => $input['action'] === 'open'
          ? 'Wartungszugang fuer maximal 30 Minuten freigegeben. Zustellung beim naechsten Poll; noch keine Verbindungsbestaetigung.'
          : 'Schliessen vorgemerkt fuer den naechsten Poll. Die lokale Ablaufgrenze bleibt unabhaengig aktiv.']);
      } catch (\RuntimeException $error) {
        return $this->result($error->getCode() === 409 ? 409 : 503, ['detail' => $error->getCode() === 409 ? $error->getMessage() : 'Tunnelstatus nicht verfuegbar']);
      }
    }

    // Fokus verlangt eine frische explizite gphoto2-Capability. Zustellung beim
    // Helper-Poll ist keine Bestaetigung einer tatsaechlichen Objektivbewegung.
    if (count($segments) === 3 && $segments[2] === 'focus' && $method === 'GET') {
      if ($camera === null) return $this->result(404, ['detail' => 'Camera not found']);
      try {
        $available = (string) ($camera->capture()['upload_key'] ?? '') !== '' && $camera->mode()->value !== 'pull-snapshot'
          && (new \Webcam\Service\Capture\FocusQueue($this->app->paths()->statePath('focus')))->available($slug);
        return $this->result(200, ['available' => $available]);
      } catch (\RuntimeException $error) {
        return $this->result(503, ['detail' => 'Fokusstatus derzeit nicht verfuegbar']);
      }
    }
    if (count($segments) === 3 && $segments[2] === 'focus' && $method === 'POST') {
      if ($camera === null) return $this->result(404, ['detail' => 'Camera not found']);
      $input = $readJson();
      if (!is_array($input) || array_keys($input) !== ['mode'] || !is_string($input['mode'])
        || !in_array($input['mode'], \Webcam\Service\Capture\FocusQueue::MODES, true)) {
        return $this->result(400, ['detail' => 'Nur mode mit --, -, auto, + oder ++ ist erlaubt']);
      }
      if ((string) ($camera->capture()['upload_key'] ?? '') === '' || $camera->mode()->value === 'pull-snapshot') {
        return $this->result(409, ['detail' => 'Fokus erfordert einen authentifizierten externen gphoto2-Helper']);
      }
      try {
        (new \Webcam\Service\Capture\FocusQueue($this->app->paths()->statePath('focus')))->request($slug, $input['mode']);
        $this->app->logger()->info('admin focus queued', ['camera' => $slug, 'mode' => $input['mode']]);
        return $this->result(202, ['camera' => $slug, 'state' => 'queued', 'detail' => 'Fokus vorgemerkt fuer den naechsten Poll; Ablauf nach 15 Minuten. Noch keine Ausfuehrungsbestaetigung.']);
      } catch (\RuntimeException $error) {
        return $this->result($error->getCode() === 409 ? 409 : 503, ['detail' => $error->getCode() === 409 ? $error->getMessage() : 'Fokusauftrag derzeit nicht verfuegbar']);
      }
    }

    // Nur den gewaehlten Slug vormerken; Pull-Tick bzw. externer Helper konsumiert
    // spaeter unter seinen bestehenden Sperren, ohne globale Aufnahme auszulösen.
    if (count($segments) === 3 && $segments[2] === 'capture' && $method === 'POST') {
      if ($camera === null) return $this->result(404, ['detail' => 'Camera not found']);
      if ($camera->mode()->value !== 'pull-snapshot' && (string) ($camera->capture()['upload_key'] ?? '') === '') {
        return $this->result(409, ['detail' => 'Kein authentifizierter Kamera-Helper konfiguriert']);
      }
      try {
        (new \Webcam\Service\Capture\ManualCaptureQueue($this->app->paths()->statePath('manual-capture')))->request($slug);
        $this->app->logger()->info('admin manual capture queued', ['camera' => $slug]);
        return $this->result(202, ['camera' => $slug, 'state' => 'queued', 'detail' => 'Aufnahme vorgemerkt; Ausfuehrung beim naechsten Kamera-Poll, Ablauf nach 5 Minuten.']);
      } catch (\RuntimeException $error) {
        $this->app->logger()->warning('admin manual capture rejected', ['camera' => $slug, 'error' => $error->getMessage()]);
        return $this->result($error->getCode() === 409 ? 409 : 503, ['detail' => $error->getCode() === 409 ? $error->getMessage() : 'Aufnahmeauftrag derzeit nicht verfuegbar']);
      }
    }

    // Die Reihenfolge geht von spezifischen Unterressourcen zum allgemeinen
    // Kameraobjekt. Dadurch kann keine längere Route versehentlich im CRUD-
    // Zweig landen.
    if (count($segments) === 3 && $segments[2] === 'years' && $method === 'GET') {
      return $this->listYears($slug);
    }
    if (count($segments) === 3 && $segments[2] === 'overlay' && $method === 'POST') {
      return $this->uploadOverlay($slug, $files);
    }
    if (count($segments) === 3 && $segments[2] === 'db' && $method === 'GET') {
      return $this->previewDatabase($slug, $cameraExists, $query);
    }
    if (count($segments) === 3 && $segments[2] === 'captures' && $method === 'GET') {
      return $this->queryCaptures($slug, $camera, $query);
    }
    if (count($segments) === 4 && $segments[2] === 'captures' && $method === 'PUT') {
      return $this->updateCapture($slug, $camera, $segments[3], $readJson);
    }
    if (count($segments) === 4 && $segments[2] === 'sections' && $method === 'PUT') {
      return $this->updateCameraSection(
        $slug,
        $segments[3],
        $document,
        $cameraExists,
        $readJson
      );
    }

    if (count($segments) === 2 && $method === 'GET') {
      return $this->readCamera($slug, $document, $cameraExists);
    }
    if (count($segments) === 2 && $method === 'PUT') {
      return $this->updateCamera($slug, $document, $cameraExists, $readJson);
    }
    if (count($segments) === 2 && $method === 'DELETE') {
      return $this->deleteCamera($slug, $document, $cameraExists);
    }

    return $this->result(404, ['detail' => 'Not found']);
  }

  /** @param array<string,mixed> $payload */
  private function createCamera(array $payload): AdminRouteResult
  {
    try {
      $slug = $this->slugFromPayload($payload);
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }
    $document = $this->configStore->load();
    if (isset($document['cameras'][$slug])) {
      return $this->result(409, ['detail' => 'Camera already exists']);
    }

    try {
      $normalized = $this->cameraConfigNormalizer->normalizePayload(
        $payload,
        $slug,
        $document['defaults'] ?? []
      );
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    try {
      $runtime = $this->runtimeAccessInspector->prepare($slug);
    } catch (RuntimeException $exception) {
      return $this->result(503, ['detail' => 'Kamera nicht angelegt: ' . $exception->getMessage()]);
    }
    $document['cameras'][$slug] = $normalized;
    $saved = $this->configStore->save($document, actor: 'api', action: "create:{$slug}");
    $this->invalidatePageCache($slug);
    $camera = $saved['cameras'][$slug];
    // Die bisherige Kameraantwort bleibt abwärtskompatibel. `_runtime` ist
    // ausschließlich ein Admin-Hinweis und wird nie persistiert.
    $camera['_runtime'] = $runtime;
    return $this->result(201, $camera);
  }

  /** @param array<string,mixed> $document */
  private function readCamera(
    string $slug,
    array $document,
    bool $cameraExists
  ): AdminRouteResult {
    if (!$cameraExists) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    return $this->result(200, $document['cameras'][$slug]);
  }

  /**
   * @param array<string,mixed> $document
   * @param callable():array<string,mixed> $readJson
   */
  private function updateCamera(
    string $slug,
    array $document,
    bool $cameraExists,
    callable $readJson
  ): AdminRouteResult {
    if (!$cameraExists) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    $payload = $readJson();

    try {
      $normalized = $this->cameraConfigNormalizer->normalizePayload(
        $payload,
        $slug,
        $document['defaults'] ?? []
      );
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    $document['cameras'][$slug] = $normalized;
    $saved = $this->configStore->save($document, actor: 'api', action: "update:{$slug}");
    $this->invalidatePageCache($slug);
    return $this->result(200, $saved['cameras'][$slug]);
  }

  /**
   * @param array<string,mixed> $document
   * @param callable():array<string,mixed> $readJson
   */
  private function updateCameraSection(
    string $slug,
    string $section,
    array $document,
    bool $cameraExists,
    callable $readJson
  ): AdminRouteResult {
    if (!$cameraExists) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    $payload = $readJson();

    try {
      $normalized = $this->cameraConfigNormalizer->normalizeSectionUpdate(
        $document['cameras'][$slug] ?? [],
        $payload,
        $section,
        $slug,
        $document['defaults'] ?? []
      );
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    $document['cameras'][$slug] = $normalized;
    $saved = $this->configStore->save(
      $document,
      actor: 'api',
      action: "update:{$slug}:{$section}"
    );
    $this->invalidatePageCache($slug);
    return $this->result(200, ['camera' => $saved['cameras'][$slug]]);
  }

  /** @param array<string,mixed> $document */
  private function deleteCamera(
    string $slug,
    array $document,
    bool $cameraExists
  ): AdminRouteResult {
    if (!$cameraExists) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }

    unset($document['cameras'][$slug]);
    $this->configStore->save($document, actor: 'api', action: "delete:{$slug}");
    $this->invalidatePageCache($slug);
    return $this->result(204, null);
  }

  private function listYears(string $slug): AdminRouteResult
  {
    $camera = $this->app->cameras()->find($slug);
    if ($camera === null) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }

    $years = $this->app->archiveBrowser()->listYears($camera);
    $list = array_values(array_unique(array_merge(
      $years['current'] ?? [],
      $years['archive'] ?? []
    )));
    sort($list);
    return $this->result(200, ['years' => $list]);
  }

  /** @param array<string,mixed> $query */
  private function previewDatabase(
    string $slug,
    bool $cameraExists,
    array $query
  ): AdminRouteResult {
    if (!$cameraExists) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    $limit = isset($query['limit']) && is_numeric($query['limit'])
      ? max(1, min(50, (int) $query['limit']))
      : 5;

    try {
      return $this->result(200, [
        'entries' => $this->cameraCaptureEditor->preview($slug, $limit),
      ]);
    } catch (Throwable $exception) {
      $this->app->logger()->error('admin db preview failed', [
        'camera' => $slug,
        'error' => $exception->getMessage(),
      ]);
      return $this->result(500, ['detail' => 'DB preview failed']);
    }
  }

  /** @param array<string,mixed> $query */
  private function queryCaptures(
    string $slug,
    ?Camera $camera,
    array $query
  ): AdminRouteResult {
    if ($camera === null) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }

    $store = new CameraDataStore($this->app, $camera, null, true);
    try {
      $result = $this->cameraCaptureQuery->query($store, $query);
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }
    if ($result === null) {
      return $this->result(404, ['detail' => 'Capture not found']);
    }
    return $this->result(200, $result);
  }

  /** @param callable():array<string,mixed> $readJson */
  private function updateCapture(
    string $slug,
    ?Camera $camera,
    string $slotValue,
    callable $readJson
  ): AdminRouteResult {
    $slotTimestamp = is_numeric($slotValue) ? (int) $slotValue : null;
    if ($slotTimestamp === null) {
      return $this->result(400, ['detail' => 'slot_ts must be numeric']);
    }
    if ($camera === null) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    $payload = $readJson();

    $store = new CameraDataStore($this->app, $camera);
    try {
      $updatePayload = $this->capturePayloadNormalizer->normalizeUpdatePayload(
        $payload,
        $store,
        $slug
      );
    } catch (InvalidArgumentException $exception) {
      return $this->result(400, ['detail' => $exception->getMessage()]);
    }

    try {
      $updated = $this->cameraCaptureEditor->update($store, $slotTimestamp, $updatePayload);
      $this->updateLikeSidecar($camera, $slug, $slotTimestamp, $updatePayload, $updated);
      $this->app->logger()->info('admin capture updated', [
        'camera' => $slug,
        'slot_ts' => $slotTimestamp,
        'fields' => array_keys($updatePayload),
        'variants' => count($updatePayload['variants'] ?? []),
        'exif' => count($updatePayload['exif'] ?? []),
        'weather' => count($updatePayload['weather'] ?? []),
      ]);

      return $this->result(200, [
        'capture' => $this->cameraCaptureEditor->serializeCapture(
          $updated['capture'],
          new DateTimeZone((string) $this->app->config('timezone', 'UTC'))
        ),
        'variants' => $updated['variants'],
        'exif' => $updated['exif'],
        'weather' => $updated['weather'],
        'logo' => $updated['logo'] ?? null,
      ]);
    } catch (RuntimeException $exception) {
      return $this->result(404, ['detail' => $exception->getMessage()]);
    }
  }

  /**
   * Hält die bestehende Ausnahmebehandlung des Like-Sidecars vom eigentlichen
   * SQLite-Update getrennt: Ein Sidecar-Fehler macht eine bereits erfolgreiche
   * Datenbankänderung nicht rückgängig, wird aber sichtbar protokolliert.
   *
   * @param array<string,mixed> $updatePayload
   * @param array<string,mixed> $updated
   */
  private function updateLikeSidecar(
    Camera $camera,
    string $slug,
    int $slotTimestamp,
    array $updatePayload,
    array $updated
  ): void {
    if (
      !array_key_exists('likes_count', $updatePayload)
      && !array_key_exists('relative_path', $updatePayload)
    ) {
      return;
    }

    $relativePath = (string) ($updated['capture']['relative_path'] ?? '');
    if ($relativePath === '') {
      return;
    }

    try {
      (new LikeService($this->app))->updateExifLikesForPath(
        $camera,
        $relativePath,
        (int) ($updated['capture']['likes_count'] ?? 0)
      );
    } catch (Throwable $exception) {
      $this->app->logger()->warning('admin capture like sidecar update failed', [
        'camera' => $slug,
        'slot_ts' => $slotTimestamp,
        'path' => $relativePath,
        'error' => $exception->getMessage(),
      ]);
    }
  }

  /** @param array<string,mixed> $files */
  private function uploadOverlay(string $slug, array $files): AdminRouteResult
  {
    if ($this->app->cameras()->find($slug) === null) {
      return $this->result(404, ['detail' => 'Camera not found']);
    }
    $file = $files['file'] ?? null;
    if ($file === null) {
      return $this->result(400, ['detail' => 'Missing file']);
    }
    if (!is_array($file)) {
      return $this->result(400, ['detail' => 'Invalid upload']);
    }

    $temporaryPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    $validator = $this->uploadedFileValidator;
    if ($temporaryPath === '' || !$validator($temporaryPath)) {
      return $this->result(400, ['detail' => 'Invalid upload']);
    }

    try {
      $result = $this->overlayUploadService->store(
        $slug,
        $temporaryPath,
        is_string($file['name'] ?? null) ? $file['name'] : 'overlay',
        isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : 0
      );
    } catch (OverlayUploadException $exception) {
      $status = match ($exception->reason) {
        OverlayUploadException::CAMERA_NOT_FOUND => 404,
        OverlayUploadException::INVALID_SOURCE => 400,
        OverlayUploadException::TOO_LARGE => 413,
        OverlayUploadException::UNSUPPORTED_MEDIA => 415,
        OverlayUploadException::BUSY => 503,
        OverlayUploadException::STORAGE_FAILED => 500,
        default => 500,
      };
      return $this->result($status, ['detail' => $exception->getMessage()]);
    }

    return $this->result(200, $result);
  }

  /** @param array<string,mixed> $payload */
  private function slugFromPayload(array $payload): string
  {
    $slug = $payload['slug'] ?? $payload['id'] ?? '';
    $slug = is_string($slug) ? trim($slug) : '';
    if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
      throw new InvalidArgumentException('Slug is required and must match [a-z0-9-]');
    }
    return $slug;
  }

  /** Entfernt nur gerenderte HTML-Dateien der tatsächlich geänderten Kamera. */
  private function invalidatePageCache(string $slug): void
  {
    try {
      $directory = $this->app->paths()->statePath('page-cache/' . $slug);
      foreach (glob($directory . '/*.html') ?: [] as $file) {
        if (!unlink($file)) {
          $this->app->logger()->warning('admin page-cache unlink failed', [
            'path' => $file,
            'slug' => $slug,
          ]);
        }
      }
    } catch (Throwable $exception) {
      $this->app->logger()->error('admin page-cache invalidate failed', [
        'slug' => $slug,
        'error' => $exception->getMessage(),
      ]);
    }
  }

  /** @param array<string,mixed>|null $payload */
  private function result(int $status, ?array $payload): AdminRouteResult
  {
    return new AdminRouteResult($status, $payload);
  }
}
