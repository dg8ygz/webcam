<?php
declare(strict_types=1);

namespace Webcam\Service\Capture;

use RuntimeException;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Domain\Enums\CameraMode;
use Webcam\Domain\Enums\CaptureTransport;
use Webcam\Infrastructure\Http\HttpClient;
use Webcam\Infrastructure\Process\BoundedProcessRunner;
use Webcam\Infrastructure\Security\SecretRedactor;
use Webcam\Service\Status\ResetService;
use Webcam\Service\Status\StatusService;
use Webcam\Service\Storage\ImageStorage;
use Webcam\Service\Stream\RtspInput;

/**
 * Wählt den Aufnahmeablauf passend zum konfigurierten Kameramodus.
 *
 * Active-Capture löst eine externe Kamera aus, Pull-Snapshot lädt ein Bild auf
 * den Server und Passive-Upload wartet normalerweise auf include/upload.php.
 * Erfolgreiche Pull-Bilder gehen an ImageStorage; Fehler werden in Status und
 * Log sichtbar festgehalten und können den konfigurierten Reset auslösen.
 */
final class CaptureManager
{
  private readonly HttpClient $http;
  private readonly StatusService $status;
  private readonly ImageStorage $storage;
  private readonly ResetService $reset;
  private readonly CaptureSourcePriority $sourcePriority;
  private ?string $ffmpegPath = null;
  private bool $rtspTimeoutChecked = false;
  private ?string $rtspTimeoutOption = null;

  public function __construct(private readonly App $app)
  {
    $this->http = new HttpClient();
    $this->status = $app->statusService();
    $this->storage = new ImageStorage($app);
    $this->reset = $app->resetService();
    $this->sourcePriority = new CaptureSourcePriority($app);
  }

  // Einziger öffentlicher Capture-Einstieg: Modus auswählen, unerwartete Fehler
  // in Status/Log übernehmen und immer ein einheitliches CaptureResult liefern.
  public function capture(
    Camera $camera,
    bool $forceNow = false,
    ?int $slotTimestamp = null
  ): CaptureResult
  {
    try {
      return match ($camera->mode()) {
        CameraMode::ActiveCapture => $this->captureWithoutPullState(
          $camera,
          fn(): CaptureResult => $this->handleActiveCapture($camera)
        ),
        CameraMode::PullSnapshot => $this->sourcePriority->runPull(
          $camera,
          fn(): CaptureResult => $this->handlePullSnapshot($camera, $forceNow, $slotTimestamp)
        ),
        CameraMode::PassiveUpload => $this->captureWithoutPullState(
          $camera,
          fn(): CaptureResult => $this->handlePassiveUpload($camera, $forceNow)
        ),
      };
    } catch (RuntimeException $e) {
      $message = SecretRedactor::redact($e->getMessage());
      $this->status->recordFailure($camera, $message);
      $this->maybeTriggerReset($camera, $message);
      $this->app->logger()->error('capture failed', ['camera' => $camera->slug(), 'error' => $message]);
      return CaptureResult::failure($camera, $message);
    }
  }

  /**
   * Nicht-Pull-Modi räumen einen eventuell alten Fallback-Zähler auf, bevor
   * ihr bisheriger Aufnahmeweg unverändert ausgeführt wird.
   *
   * @param callable():CaptureResult $capture
   */
  private function captureWithoutPullState(Camera $camera, callable $capture): CaptureResult
  {
    $this->sourcePriority->clearState($camera);
    return $capture();
  }

  // Sendet nur das konfigurierte Auslösesignal an eine aktive Kamera. Das Bild
  // selbst kommt später über include/upload.php auf den Server.
  private function handleActiveCapture(Camera $camera): CaptureResult
  {
    $capture = $camera->capture();
    $transport = $camera->captureTransport();
    $expectedStatus = (int)($capture['expected_response'] ?? 200);
    if ($transport === CaptureTransport::Rtsp) {
      throw new RuntimeException('Active capture does not support rtsp transport');
    }

    $response = $this->httpGet($capture['endpoint'] ?? '', $capture);

    if (is_int($response)) {
      $statusCode = $response;
    } else {
      $statusCode = $response->status;
    }

    if ($statusCode !== $expectedStatus) {
      throw new RuntimeException("Unexpected response code {$statusCode}, expected {$expectedStatus}");
    }

    $message = 'Capture command dispatched';
    $this->status->recordSuccess($camera, $message);
    $this->app->logger()->info('capture dispatched', ['camera' => $camera->slug()]);
    return CaptureResult::success($camera, $message, null, time());
  }

  // Lädt Snapshotbytes per HTTP oder RTSP, speichert sie über ImageStorage und
  // ergänzt anschließend Wetterdaten sowie Success-State.
  private function handlePullSnapshot(
    Camera $camera,
    bool $forceNow,
    ?int $slotTimestamp
  ): CaptureResult
  {
    $capture = $camera->capture();
    $endpoint = (string) ($capture['endpoint'] ?? '');
    $transport = $camera->captureTransport();
    if ($transport === CaptureTransport::Rtsp) {
      $data = $this->rtspSnapshot($endpoint, $capture);
    } else {
      $source = new SlotSnapshotEndpoint(
        $endpoint,
        $slotTimestamp,
        max(60, (int) ($capture['interval_seconds'] ?? 600)),
        time(),
        (string) $this->app->config('timezone', 'UTC')
      );
      $response = $this->httpGet($source->url, $capture);
      if ($source->isAwaitingPublication($response->status, time())) {
        // Kein Current-Fallback, kein alter Slot-Datensatz und kein langes
        // Blockieren des globalen Pull-Locks. Der naechste Tick versucht erneut.
        return CaptureResult::awaitingSource($camera, $source->slot);
      }
      if (!$response->isSuccessful()) {
        throw new RuntimeException("Snapshot request failed with HTTP {$response->status}");
      }
      if ($source->templated) {
        // Genau den abgefragten Slot speichern, auch wenn der Transfer erst
        // nach einer Intervallgrenze fertig ist. captured_ts bleibt Empfangszeit.
        $slotTimestamp = $source->slot;
      }
      $data = $response->body;
    }
    if ($data === '') {
      throw new RuntimeException('Empty snapshot payload received');
    }

    $stored = $this->storage->storeSnapshot($camera, $data, null, $slotTimestamp);
    $slotTimestamp = (int) ($stored['slot_timestamp'] ?? $stored['timestamp'] ?? time());
    $weatherPath = null;
    $weatherData = null;
    if ($camera->weatherCommands()) {
      $weatherResult = $this->collectWeatherTelemetry($camera, $stored);
      if ($weatherResult && isset($weatherResult['path'])) {
        $this->storage->mirrorWeatherSidecar($camera, $weatherResult['path']);
      }
      if ($weatherResult !== null) {
        $weatherPath = $weatherResult['path'] ?? null;
        $weatherData = $weatherResult['data'] ?? '';
        // Persist weather lines into per-camera DB
        $lines = array_values(array_filter(
          array_map('trim', preg_split('/\r?\n/', (string) $weatherData) ?: []),
          static fn(string $line): bool => $line !== ''
        ));
        if ($lines) {
          $this->storage->storeWeatherLines($camera, $slotTimestamp, $lines);
        }
      } else {
        $weatherData = '';
      }
    } else {
      $weatherData = '';
    }

    if (!empty($stored['duplicate'])) {
      $message = sprintf('Duplicate snapshot discarded [SLOT:%d]', $slotTimestamp);
      $this->status->recordDuplicate(
        $camera,
        $message,
        $this->normalizePathForLog($weatherPath),
        $weatherData
      );
      $this->maybeTriggerReset($camera, $message);
      $this->app->logger()->warning('duplicate snapshot discarded', [
        'camera' => $camera->slug(),
        'slot_timestamp' => $slotTimestamp,
      ]);
      return CaptureResult::failure($camera, $message);
    }

    $message = sprintf('Snapshot stored [SLOT:%d]', $slotTimestamp);
    $this->status->recordSuccess(
      $camera,
      $message,
      $this->normalizePathForLog($stored['status_image_path'] ?? $stored['original'] ?? null),
      $stored['exif'] ?? null,
      $this->normalizePathForLog($stored['exif_path'] ?? null),
      $this->normalizePathForLog($stored['exif_relative'] ?? null),
      $stored['luminance'] ?? null,
      $this->normalizePathForLog($weatherPath),
      $weatherData,
      (int) ($stored['captured_timestamp'] ?? $slotTimestamp),
      $slotTimestamp
    );
    $this->app->logger()->info('snapshot stored', [
      'camera' => $camera->slug(),
      'path' => $this->normalizePathForLog($stored['original'] ?? ''),
    ]);
    return CaptureResult::success($camera, $message, $stored['original'], $slotTimestamp);
  }

  // Passive Kameras werden regulär nicht aktiv kontaktiert. --now liefert einen
  // expliziten Status, ändert aber den konfigurierten Transport nicht heimlich.
  private function handlePassiveUpload(Camera $camera, bool $forceNow): CaptureResult
  {
    if ($forceNow) {
      // Forcing a passive camera just records a success note to avoid infinite loops.
      $message = 'Forced passive capture (no-op)';
      $this->app->logger()->info('passive capture forced (no-op)', ['camera' => $camera->slug()]);
      $this->status->recordSuccess($camera, $message);
      return CaptureResult::success($camera, $message);
    }
    $message = 'Passive camera awaiting external upload';
    $this->app->logger()->info('capture skipped for passive camera', ['camera' => $camera->slug()]);
    return CaptureResult::success($camera, $message);
  }

  // Baut den HTTP-Aufruf aus Endpoint, Auth und Timeout-Konfiguration auf.
  private function httpGet(string $endpoint, array $capture): \Webcam\Infrastructure\Http\HttpResponse
  {
    if ($endpoint === '') {
      throw new RuntimeException('Capture endpoint not configured');
    }
    $timeout = (int)($capture['timeout_seconds'] ?? 10);
    return $this->http->get($endpoint, [
      'timeout' => $timeout,
      'max_response_bytes' => \Webcam\Service\Storage\SnapshotResourceLimits::maxBytes($this->app),
    ]);
  }

  // Decodiert die Beruhigungsphase im selben RTSP-Abruf und liefert genau
  // ein späteres JPEG. Keine künstliche Schärfung/Entrauschung. Ausgabe und
  // gesamte Laufzeit bleiben begrenzt; Zugangsdaten bleiben im stdin-Manifest.
  private function rtspSnapshot(string $endpoint, array $capture): string
  {
    if ($endpoint === '') {
      throw new RuntimeException('Capture endpoint not configured');
    }
    $ffmpeg = $this->resolveFfmpegPath();
    $timeout = (int) ($capture['timeout_seconds'] ?? 10);
    if ($timeout <= 0) {
      $timeout = 10;
    }
    $transport = trim((string) getenv('WEBCAM_RTSP_TRANSPORT'));
    if ($transport === '') {
      $transport = 'tcp';
    }
    $timeoutUs = (string) ($timeout * 1000000);
    $timeoutOption = $this->resolveRtspTimeoutOption();
    $manifest = RtspInput::manifest($endpoint, $transport, $timeoutOption, (int) $timeoutUs);
    $command = [
      $ffmpeg,
      '-hide_banner',
      '-loglevel', 'error',
      ...RtspInput::ffmpegArguments(),
      '-map', '0:v:0',
      '-an',
      '-vf', RtspSnapshotSelection::filter($timeout),
      '-fps_mode', 'passthrough',
      '-frames:v', '1',
      '-f', 'image2pipe',
      '-vcodec', 'mjpeg',
      'pipe:1',
    ];
    $result = (new BoundedProcessRunner())->run($command, $timeout, [
      // Ein einzelnes JPEG darf bis zur globalen Upload-Hartgrenze wachsen.
      // Die Quelle bleibt ausschließlich im geschützten stdin-Manifest.
      'input' => $manifest,
      'output_max_bytes' => 64 * 1024 * 1024,
      'output_retention' => 'head',
      'require_process_group' => true,
    ]);
    if (!$result['started']) {
      throw new RuntimeException('RTSP snapshot failed: unable to start ffmpeg or pass protected input');
    }

    $image = $this->extractJpeg($result['stdout']);
    if ($image === null || $image === '') {
      $masked = SecretRedactor::redact($endpoint);
      $tail = trim(preg_replace('/\s+/', ' ', SecretRedactor::redact($result['stderr'])));
      if ($tail !== '') {
        $tail = substr($tail, 0, 200);
      }
      $detail = $tail !== '' ? ": {$tail}" : '';
      $exitInfo = $result['exit_code'] === 0 ? '' : " (ffmpeg exit {$result['exit_code']})";
      throw new RuntimeException("RTSP snapshot failed for {$masked}{$exitInfo}{$detail}");
    }
    return $image;
  }

  // Prüft einmalig, welche ffmpeg-Timeoutoption diese Hostversion unterstützt.
  private function resolveRtspTimeoutOption(): ?string
  {
    if ($this->rtspTimeoutChecked) {
      return $this->rtspTimeoutOption;
    }
    $this->rtspTimeoutChecked = true;
    $ffmpeg = $this->resolveFfmpegPath();
    $command = [
      $ffmpeg,
      '-hide_banner',
      '-h',
      'demuxer=rtsp',
    ];
    $result = (new BoundedProcessRunner())->run($command, 5, [
      'output_max_bytes' => 262144,
      'output_retention' => 'tail',
      'require_process_group' => true,
    ]);
    if (!$result['started']) {
      $this->app->logger()->warning('ffmpeg help probe failed; missing rtsp timeout option');
      return null;
    }
    $output = $result['stdout'] . "\n" . $result['stderr'];
    if ($result['timed_out']) {
      $this->app->logger()->warning('ffmpeg help probe timed out; missing rtsp timeout option');
    }
    if (preg_match('/\\s-timeout\\s/m', $output) === 1) {
      $this->rtspTimeoutOption = '-timeout';
      return $this->rtspTimeoutOption;
    }
    if (preg_match('/\\s-stimeout\\s/m', $output) === 1) {
      $this->rtspTimeoutOption = '-stimeout';
      return $this->rtspTimeoutOption;
    }
    $this->app->logger()->warning('ffmpeg rtsp timeout option not detected; continuing without ffmpeg timeout');
    return null;
  }

  // Löst ffmpeg aus Env oder bekannten Pfaden auf und verlangt Ausführbarkeit.
  private function resolveFfmpegPath(): string
  {
    if ($this->ffmpegPath !== null) {
      return $this->ffmpegPath;
    }
    $configured = trim((string) getenv('WEBCAM_FFMPEG_PATH'));
    if ($configured !== '') {
      if (!is_executable($configured)) {
        throw new RuntimeException('ffmpeg not executable at WEBCAM_FFMPEG_PATH');
      }
      $this->ffmpegPath = $configured;
      return $this->ffmpegPath;
    }
    $candidates = [];
    $pathEnv = getenv('PATH');
    if (is_string($pathEnv) && $pathEnv !== '') {
      foreach (explode(':', $pathEnv) as $dir) {
        $dir = trim($dir);
        if ($dir !== '') {
          $candidates[] = rtrim($dir, '/') . '/ffmpeg';
        }
      }
    }
    $candidates[] = '/usr/bin/ffmpeg';
    $candidates[] = '/usr/local/bin/ffmpeg';
    foreach (array_values(array_unique($candidates)) as $candidate) {
      if (is_file($candidate) && is_executable($candidate)) {
        $this->ffmpegPath = $candidate;
        return $this->ffmpegPath;
      }
    }
    throw new RuntimeException('ffmpeg binary not found; set WEBCAM_FFMPEG_PATH');
  }

  // Schneidet aus möglichem Stream-/Log-Beifang genau den ersten JPEG-Bereich.
  private function extractJpeg(string $data): ?string
  {
    $start = strpos($data, "\xFF\xD8");
    if ($start === false) {
      return null;
    }
    $end = strpos($data, "\xFF\xD9", $start);
    if ($end === false) {
      return null;
    }
    return substr($data, $start, $end - $start + 2);
  }

  // Reset nur dann delegieren, wenn StatusService Schwelle und Cooldown erfüllt.
  private function maybeTriggerReset(Camera $camera, string $reason): void
  {
    if (!$this->status->shouldTriggerReset($camera)) {
      return;
    }
    $this->reset->execute($camera, $reason);
  }

  /**
   * @param array<string,mixed> $stored
   * @return array{path:string,data:string}|null
   */
  // Führt alle konfigurierten Wetterkommandos begrenzt aus, schreibt eine Sidecar
  // neben den Capture und gibt Pfad plus zusammengeführten Inhalt zurück.
  private function collectWeatherTelemetry(Camera $camera, array $stored): ?array
  {
    $commands = $camera->weatherCommands();
    if (!$commands) {
      return null;
    }

    $originalPath = $stored['metadata_base_path'] ?? $stored['original'] ?? null;
    if (!is_string($originalPath) || $originalPath === '') {
      return null;
    }
    $dir = dirname($originalPath);
    $filename = pathinfo($originalPath, PATHINFO_FILENAME);
    if ($dir === '' || $filename === '') {
      return null;
    }

    try {
      return $this->app->weatherRunner()->capture($camera, $dir, $filename);
    } catch (RuntimeException $exception) {
      $this->app->logger()->warning('weather capture failed', [
        'camera' => $camera->slug(),
        'error' => $exception->getMessage(),
      ]);
      return null;
    }
  }

  private function normalizePathForLog(?string $path): ?string
  {
    if ($path === null || $path === '') {
      return $path;
    }
    $candidate = str_replace('\\', '/', $path);
    $real = realpath($candidate);
    if (is_string($real) && $real !== '') {
      return str_replace('\\', '/', $real);
    }
    $collapsed = preg_replace('#/(?:[^/]+/\\.\\.)#', '/', $candidate);
    return $collapsed ?: $candidate;
  }
}
