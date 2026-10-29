<?php
declare(strict_types=1);

namespace Webcam\Service\Dayvideo;

use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Service\Security\PublicRateLimiter;

/** Bereitet Tagesframes genau einmal auf und baut das begrenzte MP4 atomar. */
final class DayvideoService
{
  private const FAILURE_COOLDOWN_SECONDS = 60;

  public function __construct(
    private readonly App $app,
    private readonly PublicRateLimiter $rateLimiter
  ) {}

  /**
   * @param callable():void|null $onBuildStarted Wird nach allen Limits/Locks aufgerufen.
   */
  public function handle(
    Camera $camera,
    DayvideoRequest $request,
    string $clientIp,
    ?callable $onBuildStarted = null
  ): DayvideoResult {
    $target = $this->target($camera, $request);
    if (!$this->buildAllowed()) {
      if ($request->refresh) {
        return new DayvideoResult(403, ['detail' => 'dayvideo_build_disabled']);
      }
      return $this->cachedResult($target, $request)
        ?? new DayvideoResult(404, ['detail' => 'dayvideo_not_prebuilt']);
    }

    if (!$request->refresh && ($cached = $this->cachedResult($target, $request)) !== null) {
      return $cached;
    }

    $budgetConsumed = false;
    $videoDir = dirname($target['path']);
    if (!is_dir($videoDir)) {
      if (($limited = $this->consumeBuildBudget($clientIp)) !== null) {
        return $limited;
      }
      $budgetConsumed = true;
    }
    if (!is_dir($videoDir) && !mkdir($videoDir, 0775, true) && !is_dir($videoDir)) {
      return $this->internalError('dayvideo state path unavailable', $camera);
    }
    $failureFile = $target['path'] . '.failed.json';
    if (!$request->refresh && ($cooldown = $this->activeFailureCooldown($failureFile, $camera)) !== null) {
      return new DayvideoResult(503, [
        'detail' => 'ffmpeg_failed_recently',
        'retry_after' => $cooldown,
      ], ['Retry-After' => (string) $cooldown]);
    }

    try {
      $outputLease = FileLockLease::acquire($target['path'] . '.lock', $this->lockMetadata($camera, $request));
    } catch (Throwable $exception) {
      return $this->internalError('dayvideo output lock unavailable', $camera, $exception);
    }
    if ($outputLease === null) {
      return $this->busyResult();
    }

    try {
      if (!$request->refresh && ($cached = $this->cachedResult($target, $request)) !== null) {
        return $cached;
      }
      try {
        $globalLease = $this->slotPool()->acquire($this->lockMetadata($camera, $request));
      } catch (Throwable $exception) {
        return $this->internalError('dayvideo global lock unavailable', $camera, $exception);
      }
      if ($globalLease === null) {
        return $this->busyResult();
      }

      try {
        if (!$budgetConsumed && ($limited = $this->consumeBuildBudget($clientIp)) !== null) {
          return $limited;
        }
        return $this->build($camera, $request, $target, $onBuildStarted);
      } finally {
        $globalLease->release();
      }
    } finally {
      $outputLease->release();
    }
  }

  /** @param array{path:string,relative:string} $target */
  private function build(Camera $camera, DayvideoRequest $request, array $target, ?callable $onBuildStarted = null): DayvideoResult
  {
    $failureFile = $target['path'] . '.failed.json';
    $from = $request->date->setTime(0, 0, 0)->getTimestamp();
    $to = $request->date->setTime(23, 59, 59)->getTimestamp();
    try {
      // Absichtlich genau eine Timeline-Abfrage pro Build.
      $timeline = $request->period === 'day'
        ? $this->app->archiveBrowser()->timeline($camera, null, $from, $to)
        : (new \Webcam\Service\Storage\PresentationFrames($this->app))->select(
          $camera, $request->date, $request->period, $request->clock, DayvideoRequest::MAX_FRAMES, $request->maxWidth
        );
    } catch (Throwable $exception) {
      return $this->internalError('dayvideo timeline failed', $camera, $exception);
    }
    $entries = is_array($timeline['entries'] ?? null) ? $timeline['entries'] : [];
    if ($entries === []) {
      return new DayvideoResult(404, ['detail' => 'No entries for date']);
    }

    $imageFiles = (new DayvideoFrameSelector($this->app))->select(
      $entries,
      DayvideoRequest::MAX_FRAMES,
      $request->maxWidth
    );
    $frameCount = count($imageFiles);
    if ($frameCount === 0) {
      return new DayvideoResult(404, ['detail' => 'No local files for date']);
    }

    $today = new \DateTimeImmutable('today', $request->date->getTimezone());
    $minFrames = max(6, (int) ceil($request->fps * 2));
    if ($request->date >= $today && $frameCount < $minFrames) {
      return new DayvideoResult(409, [
        'detail' => 'insufficient_frames',
        'frame_count' => $frameCount,
        'min_frame_count' => $minFrames,
        'fps' => $request->fps,
        'date' => $request->date->format('Y-m-d'),
      ]);
    }

    $ffmpegPath = $this->ffmpegPath();
    if ($ffmpegPath === null) {
      $this->app->logger()->error('dayvideo ffmpeg not available', ['camera' => $camera->slug()]);
      return new DayvideoResult(503, ['detail' => 'ffmpeg_not_available']);
    }
    try {
      $temporaryVideo = $target['path'] . '.tmp-' . bin2hex(random_bytes(8)) . '.mp4';
    } catch (Throwable $exception) {
      return $this->internalError('dayvideo staging path unavailable', $camera, $exception);
    }

    // Validation failures must reach the browser, not disappear behind HTTP 202.
    if ($onBuildStarted !== null) { $onBuildStarted(); }
    $this->unlink($failureFile, $camera, 'dayvideo failure marker cleanup failed');
    $ffmpeg = (new DayvideoFfmpegRunner())->run(
      $imageFiles,
      $temporaryVideo,
      $request->maxWidth,
      $request->fps,
      $this->buildTimeoutSeconds(),
      $ffmpegPath
    );
    if ($ffmpeg['exit_code'] !== 0 || !is_file($temporaryVideo) || filesize($temporaryVideo) <= 1024) {
      $this->unlink($temporaryVideo, $camera, 'dayvideo partial video cleanup failed');
      $stderrTail = array_slice($ffmpeg['output'], -12);
      $this->writeFailureMarker($failureFile, $camera, $ffmpeg['exit_code'], $stderrTail, $ffmpeg['timed_out']);
      $this->app->logger()->error('dayvideo ffmpeg failed', [
        'camera' => $camera->slug(),
        'code' => $ffmpeg['exit_code'],
        'timed_out' => $ffmpeg['timed_out'],
        'stderr_tail' => $stderrTail,
      ]);
      return new DayvideoResult(500, ['detail' => 'Internal error']);
    }
    if (!rename($temporaryVideo, $target['path'])) {
      $this->unlink($temporaryVideo, $camera, 'dayvideo staged video cleanup failed');
      $this->writeFailureMarker($failureFile, $camera, -1, ['atomic publish failed'], false);
      return $this->internalError('dayvideo atomic publish failed', $camera);
    }
    $this->unlink($failureFile, $camera, 'dayvideo failure marker cleanup failed');

    return new DayvideoResult(200, $this->payload($target, $request, false, $frameCount));
  }

  private function consumeBuildBudget(string $clientIp): ?DayvideoResult
  {
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
      $this->app->logger()->warning('dayvideo client ip unavailable');
      return new DayvideoResult(429, ['detail' => 'Rate limit unavailable']);
    }
    $settings = [
      'limit' => $this->configInt('WEBCAM_DAYVIDEO_BUILD_LIMIT', 'dayvideo_build_limit', 3, 1, 20),
      'window_seconds' => $this->configInt('WEBCAM_DAYVIDEO_BUILD_WINDOW_SECONDS', 'dayvideo_build_window_seconds', 600, 30, 3600),
      'ttl_days' => 7,
    ];
    try {
      $decision = $this->rateLimiter->consume('dayvideo_build', 'ip:' . $clientIp, $settings);
    } catch (Throwable $exception) {
      $this->app->logger()->error('dayvideo rate limit unavailable', ['error' => $exception->getMessage()]);
      return new DayvideoResult(429, ['detail' => 'Rate limit unavailable']);
    }
    $headers = [
      'X-RateLimit-Limit' => (string) $decision['limit'],
      'X-RateLimit-Remaining' => (string) $decision['remaining'],
    ];
    if ($decision['allowed']) {
      return null;
    }
    $headers['Retry-After'] = (string) $decision['retry_after'];
    return new DayvideoResult(429, [
      'detail' => 'Rate limit exceeded',
      'retry_after' => $decision['retry_after'],
    ], $headers);
  }

  private function slotPool(): DayvideoBuildSlotPool
  {
    $directory = (string) $this->app->config('dayvideo_global_lock_dir', '/var/local/webcam/dayvideo-build-locks');
    $max = $this->configInt('WEBCAM_DAYVIDEO_MAX_CONCURRENT_BUILDS', 'dayvideo_max_concurrent_builds', 1, 1, 2);
    return new DayvideoBuildSlotPool($directory, $max);
  }

  /** @return array{path:string,relative:string} */
  private function target(Camera $camera, DayvideoRequest $request): array
  {
    $suffix = sprintf(
      '%s/videos/%s/' . ($request->period === 'day' ? 'day' : $request->period . '_' . str_replace(':', '', $request->clock)) . '_w%d_fps%d.mp4',
      $camera->slug(),
      $request->date->format('Y/m/d'),
      $request->maxWidth,
      $request->fps
    );
    return [
      'path' => $this->app->paths()->cachePath($suffix),
      'relative' => 'cache/' . $suffix,
    ];
  }

  /** @param array{path:string,relative:string} $target */
  private function cachedResult(array $target, DayvideoRequest $request): ?DayvideoResult
  {
    if (!is_file($target['path']) || filesize($target['path']) <= 1024) {
      return null;
    }
    return new DayvideoResult(200, $this->payload($target, $request, true));
  }

  /** @param array{path:string,relative:string} $target @return array<string,mixed> */
  private function payload(array $target, DayvideoRequest $request, bool $cached, ?int $frameCount = null): array
  {
    $payload = [
      'video_url' => $target['relative'],
      'download_url' => $target['relative'],
      'cached' => $cached,
      'fps' => $request->fps,
      'max_width' => $request->maxWidth,
      'period' => $request->period,
      'clock' => $request->clock,
    ];
    if ($frameCount !== null) {
      $payload['frame_count'] = $frameCount;
    }
    return $payload;
  }

  private function busyResult(): DayvideoResult
  {
    return new DayvideoResult(202, ['status' => 'building', 'retry_after' => 3], ['Retry-After' => '3']);
  }

  private function ffmpegPath(): ?string
  {
    $configured = trim((string) (getenv('WEBCAM_FFMPEG_PATH') ?: 'ffmpeg'));
    if (str_contains($configured, DIRECTORY_SEPARATOR)) {
      return is_executable($configured) ? $configured : null;
    }
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
      $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $configured;
      if (is_file($candidate) && is_executable($candidate)) {
        return $candidate;
      }
    }
    return null;
  }

  private function buildAllowed(): bool
  {
    $configured = getenv('WEBCAM_DAYVIDEO_PUBLIC_BUILD');
    if ($configured !== false && trim((string) $configured) !== '') {
      return filter_var($configured, FILTER_VALIDATE_BOOL);
    }
    return filter_var($this->app->config('dayvideo_public_build', false), FILTER_VALIDATE_BOOL);
  }

  private function buildTimeoutSeconds(): int
  {
    return $this->configInt('WEBCAM_DAYVIDEO_BUILD_TIMEOUT_SECONDS', 'dayvideo_build_timeout_seconds', 300, 10, 3600);
  }

  private function configInt(string $envName, string $configName, int $default, int $min, int $max): int
  {
    $env = getenv($envName);
    $value = $env !== false && trim((string) $env) !== '' ? $env : $this->app->config($configName, $default);
    return max($min, min($max, is_numeric($value) ? (int) $value : $default));
  }

  private function activeFailureCooldown(string $path, Camera $camera): ?int
  {
    if (!is_file($path)) {
      return null;
    }
    try {
      $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
      $failedAt = is_array($data) ? (int) ($data['failed_at'] ?? 0) : 0;
      $age = $failedAt > 0 ? time() - $failedAt : self::FAILURE_COOLDOWN_SECONDS;
      if ($age >= 0 && $age < self::FAILURE_COOLDOWN_SECONDS) {
        return self::FAILURE_COOLDOWN_SECONDS - $age;
      }
    } catch (Throwable $exception) {
      $this->app->logger()->warning('dayvideo failure marker read failed', [
        'camera' => $camera->slug(), 'error' => $exception->getMessage(),
      ]);
    }
    $this->unlink($path, $camera, 'dayvideo failure marker cleanup failed');
    return null;
  }

  /** @param list<string> $stderr */
  private function writeFailureMarker(string $path, Camera $camera, int $code, array $stderr, bool $timedOut): void
  {
    $ok = \webcam_atomic_write_json($path, [
      'failed_at' => time(),
      'code' => $code,
      'timed_out' => $timedOut,
      'timeout_seconds' => $this->buildTimeoutSeconds(),
      'stderr_tail' => $stderr,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!$ok) {
      $this->app->logger()->warning('dayvideo failure marker write failed', ['camera' => $camera->slug()]);
    }
  }

  private function unlink(string $path, Camera $camera, string $warning): void
  {
    if ((is_file($path) || is_link($path)) && !unlink($path)) {
      $this->app->logger()->warning($warning, ['path' => $path, 'camera' => $camera->slug()]);
    }
  }

  /** @return array<string,mixed> */
  private function lockMetadata(Camera $camera, DayvideoRequest $request): array
  {
    return [
      'camera' => $camera->slug(),
      'date' => $request->date->format('Y-m-d'),
      'pid' => getmypid(),
      'started_at' => time(),
    ];
  }

  private function internalError(string $message, Camera $camera, ?Throwable $exception = null): DayvideoResult
  {
    $context = ['camera' => $camera->slug()];
    if ($exception !== null) {
      $context['error'] = $exception->getMessage();
    }
    $this->app->logger()->error($message, $context);
    return new DayvideoResult(500, ['detail' => 'Internal error']);
  }
}
